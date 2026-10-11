<?php
/**
 * SIMLedger - fine-grained permissions.
 *
 * Roles carry a default permission set (role_permissions - the group-level
 * assignment); individual users can be fine-tuned on top with per-permission
 * allow/deny overrides (user_permissions). The admin role implicitly holds
 * every permission.
 *
 * The supporting schema + default data are self-provisioning:
 * permissionsBootstrap() creates the tables, adds the driver agency/profile
 * columns to existing databases and seeds the catalog + default role grants
 * exactly once (flagged in app_settings), so installations created before
 * this feature upgrade on their next request.
 */

declare(strict_types=1);

/**
 * Permission catalog: key => [name, group, description].
 *
 * Group-level assignment happens in Admin -> Roles & Permissions; the groups
 * below only organize the UI.
 */
function permissionCatalog(): array
{
    return [
        'sim.view'         => ['View SIM inventory', 'SIM Management',
            'Browse SIM lists, current assignments and SIM history.'],
        'sim.transact'     => ['Run SIM transactions', 'SIM Management',
            'Issue, return, transfer, replace and mark-lost SIMs.'],
        'driver.view'      => ['View drivers', 'Drivers',
            'Browse the driver list and driver profiles.'],
        'driver.manage'    => ['Manage driver records', 'Drivers',
            'Create and edit driver master records (badge, terminal, agency...).'],
        'contact.view'     => ['View driver contacts', 'Contacts',
            'Browse driver contact numbers.'],
        'contact.manage'   => ['Manage driver contacts', 'Contacts',
            'Add, edit and deactivate contact numbers.'],
        'report.view'      => ['View reports', 'Reports',
            'Open reports and export report CSVs.'],
        'data.import'      => ['Import CSV data', 'Data',
            'Upload and commit CSV imports.'],
        'user.manage'      => ['Manage users and roles', 'Administration',
            'User accounts, roles and permission assignments.'],
        'reference.manage' => ['Manage reference data', 'Administration',
            'Terminals, carriers, agencies and SIM records.'],
        'audit.view'       => ['View audit log', 'Administration',
            'Read the append-only audit trail.'],
        'api.manage'       => ['Manage API tokens', 'Administration',
            'Mint and revoke bearer tokens for the external read-only API.'],
    ];
}

/**
 * Default role => permission keys (applied once on bootstrap). Mirrors the
 * historical role behaviour exactly so the upgrade changes nothing: after
 * that, admins manage grants in Admin -> Roles & Permissions.
 */
function permissionDefaultRoleGrants(): array
{
    $all = array_keys(permissionCatalog());

    return [
        'admin'      => $all,
        'manager'    => ['sim.view', 'driver.view', 'contact.view', 'contact.manage', 'report.view'],
        'dispatcher' => ['sim.view', 'sim.transact', 'driver.view', 'contact.view',
            'contact.manage', 'report.view'],
    ];
}

/**
 * Default driver outsourcing agencies (RT = the owning company's own drivers).
 *
 * @return array<int, array{0:string,1:string}> [code, name]
 */
function defaultAgencies(): array
{
    return [
        ['RT', 'RT (own drivers)'],
        ['SMASCO', 'SMASCO'],
        ['SRACO', 'SRACO'],
        ['TAMKEEN', 'TAMKEEN'],
    ];
}


/**
 * Remove returned_to_hr from the company_sims.status ENUM on databases whose
 * earlier version of this migration added it (fresh installs never contain
 * the value - sql/schema.sql). Runs once, flagged with its own app_settings
 * key (mig_status_rth_removed) so it also fires on databases that already
 * completed permissionsBootstrap(). While rows still use the value the ENUM
 * is kept and a warning is logged (one attempt only).
 */
function migrateSimStatusEnum(): void
{
    if (settingGet('mig_status_rth_removed', '') === '1') {
        return;
    }

    $pdo  = $GLOBALS['pdo'];
    $type = (string)$pdo->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'company_sims' AND COLUMN_NAME = 'status'"
    )->fetchColumn();

    if ($type !== '' && str_contains($type, 'returned_to_hr')) {
        $inUse = (int)$pdo->query(
            "SELECT COUNT(*) FROM company_sims WHERE status = 'returned_to_hr'"
        )->fetchColumn();
        if ($inUse > 0) {
            error_log(
                '[simledger] returned_to_hr still used by ' . $inUse
                . ' SIM row(s); status ENUM kept (delete the mig_status_rth_removed'
                . ' setting after migrating those rows to retry).'
            );
        } else {
            $pdo->exec(
                "ALTER TABLE company_sims MODIFY COLUMN status
                    ENUM('available','assigned','suspended','lost','damaged','retired')
                    NOT NULL DEFAULT 'available'"
            );
        }
    }

    $pdo->exec("INSERT INTO app_settings (`key`, `value`)
                    VALUES ('mig_status_rth_removed', '1')
                    ON DUPLICATE KEY UPDATE `value` = '1'");
    if (isset($GLOBALS['__settings_cache']) && is_array($GLOBALS['__settings_cache'])) {
        $GLOBALS['__settings_cache']['mig_status_rth_removed'] = '1';
    }
}

/**
 * Create api_tokens and register the api.manage permission on databases that
 * already completed permissionsBootstrap() (fresh installs receive both from
 * sql/schema.sql + the catalog seed, making this a no-op). Runs once, flagged
 * with its own app_settings key (mig_api_tokens).
 */
function migrateApiTokens(): void
{
    if (settingGet('mig_api_tokens', '') === '1') {
        return;
    }

    $pdo = $GLOBALS['pdo'];

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS api_tokens (
            id           INT          NOT NULL AUTO_INCREMENT,
            name         VARCHAR(64)  NOT NULL,
            token_hash   CHAR(64)     NOT NULL,
            prefix       VARCHAR(16)  NOT NULL,
            is_active    TINYINT(1)   NOT NULL DEFAULT 1,
            created_by   INT          DEFAULT NULL,
            created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME     DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_api_tokens_hash (token_hash),
            KEY idx_api_tokens_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // Existing installs skipped the catalog seeding that contains api.manage.
    [$permName, $permGroup, $permDesc] = permissionCatalog()['api.manage'];
    $pdo->prepare(
        'INSERT IGNORE INTO permissions (`key`, name, group_name, description)
         VALUES (?, ?, ?, ?)'
    )->execute(['api.manage', $permName, $permGroup, $permDesc]);

    $pdo->exec("INSERT INTO app_settings (`key`, `value`)
                    VALUES ('mig_api_tokens', '1')
                    ON DUPLICATE KEY UPDATE `value` = '1'");
    if (isset($GLOBALS['__settings_cache']) && is_array($GLOBALS['__settings_cache'])) {
        $GLOBALS['__settings_cache']['mig_api_tokens'] = '1';
    }
}

/**
 * Create the supporting tables/columns and seed defaults exactly once per
 * database. Idempotent; returns immediately once flagged in app_settings.
 */
function permissionsBootstrap(): void
{
    // Independently flagged schema migrations run before the fast path.
    migrateSimStatusEnum();
    migrateApiTokens();

    if (settingGet('perm_bootstrap_version', '') === '1') {
        return;
    }

    $pdo = $GLOBALS['pdo'];

    // --- supporting tables (no-op when schema.sql already created them) ------
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS agencies (
            id         INT         NOT NULL AUTO_INCREMENT,
            name       VARCHAR(60) NOT NULL,
            code       VARCHAR(10) NOT NULL,
            is_active  TINYINT(1)  NOT NULL DEFAULT 1,
            created_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_agencies_name (name),
            UNIQUE KEY uq_agencies_code (code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS permissions (
            id          INT          NOT NULL AUTO_INCREMENT,
            `key`       VARCHAR(60)  NOT NULL,
            name        VARCHAR(80)  NOT NULL,
            group_name  VARCHAR(60)  NOT NULL,
            description VARCHAR(200) NOT NULL DEFAULT \'\',
            PRIMARY KEY (id),
            UNIQUE KEY uq_permissions_key (`key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS role_permissions (
            role_id       INT NOT NULL,
            permission_id INT NOT NULL,
            PRIMARY KEY (role_id, permission_id),
            CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
            CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS user_permissions (
            user_id       INT NOT NULL,
            permission_id INT NOT NULL,
            effect        ENUM(\'allow\',\'deny\') NOT NULL,
            PRIMARY KEY (user_id, permission_id),
            CONSTRAINT fk_up_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_up_perm FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    // --- driver columns (MySQL has no ADD COLUMN IF NOT EXISTS) --------------
    $has = [];
    foreach ($pdo->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drivers'"
    ) as $r) {
        $has[(string)$r['COLUMN_NAME']] = true;
    }
    $adds = [];
    if (!isset($has['agency_id'])) {
        $adds[] = 'ADD COLUMN agency_id INT DEFAULT NULL AFTER external_driver_id';
    }
    if (!isset($has['iqama_number'])) {
        $adds[] = 'ADD COLUMN iqama_number VARCHAR(20) DEFAULT NULL AFTER agency_id';
    }
    if (!isset($has['nationality'])) {
        $adds[] = 'ADD COLUMN nationality VARCHAR(60) DEFAULT NULL AFTER iqama_number';
    }
    if ($adds !== []) {
        $pdo->exec('ALTER TABLE drivers ' . implode(', ', $adds));
    }

    $idx = [];
    foreach ($pdo->query(
        "SELECT INDEX_NAME FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'drivers'"
    ) as $r) {
        $idx[(string)$r['INDEX_NAME']] = true;
    }
    if (!isset($idx['uq_drivers_iqama'])) {
        $pdo->exec('ALTER TABLE drivers ADD UNIQUE KEY uq_drivers_iqama (iqama_number)');
    }
    if (!isset($idx['idx_drivers_agency'])) {
        $pdo->exec('ALTER TABLE drivers ADD KEY idx_drivers_agency (agency_id)');
    }
    if (!isset($idx['fk_drivers_agency'])) {
        try {
            $pdo->exec('ALTER TABLE drivers ADD CONSTRAINT fk_drivers_agency
                        FOREIGN KEY (agency_id) REFERENCES agencies (id)');
        } catch (Throwable $e) {
            // Non-fatal: integrity is also enforced in the application layer.
            error_log('[simledger] could not add fk_drivers_agency: ' . $e->getMessage());
        }
    }

    // --- default agencies + permission catalog + default role grants --------
    $insAgency = $pdo->prepare('INSERT IGNORE INTO agencies (name, code) VALUES (?, ?)');
    foreach (defaultAgencies() as [$code, $name]) {
        $insAgency->execute([$name, $code]);
    }

    $insPerm = $pdo->prepare(
        'INSERT IGNORE INTO permissions (`key`, name, group_name, description) VALUES (?, ?, ?, ?)'
    );
    foreach (permissionCatalog() as $key => [$name, $group, $desc]) {
        $insPerm->execute([$key, $name, $group, $desc]);
    }

    $permIds = [];
    foreach ($pdo->query('SELECT id, `key` FROM permissions') as $r) {
        $permIds[(string)$r['key']] = (int)$r['id'];
    }
    $roleIds = [];
    foreach ($pdo->query('SELECT id, `key` FROM roles') as $r) {
        $roleIds[(string)$r['key']] = (int)$r['id'];
    }

    $insGrant = $pdo->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)');
    foreach (permissionDefaultRoleGrants() as $roleKey => $keys) {
        if (!isset($roleIds[$roleKey])) {
            continue;
        }
        foreach ($keys as $key) {
            if (isset($permIds[$key])) {
                $insGrant->execute([$roleIds[$roleKey], $permIds[$key]]);
            }
        }
    }

    // Existing drivers belong to the owning company until re-assigned.
    $rtId = (int)$pdo->query("SELECT id FROM agencies WHERE code = 'RT'")->fetchColumn();
    if ($rtId > 0) {
        $pdo->prepare('UPDATE drivers SET agency_id = ? WHERE agency_id IS NULL')
            ->execute([$rtId]);
    }

    // Flag done - every later request takes the fast path above.
    $pdo->exec("INSERT INTO app_settings (`key`, `value`)
                    VALUES ('perm_bootstrap_version', '1')
                    ON DUPLICATE KEY UPDATE `value` = '1'");
    if (isset($GLOBALS['__settings_cache']) && is_array($GLOBALS['__settings_cache'])) {
        $GLOBALS['__settings_cache']['perm_bootstrap_version'] = '1';
    }
}

/**
 * Does the signed-in user hold $permission?
 *
 * Resolution order: admin role -> always true; user override (deny beats
 * allow) -> role default grant. Unknown keys resolve to false.
 */
function can(string $permission): bool
{
    permissionsBootstrap();
    $user = currentUser();

    if ($user === null) {
        return false;
    }

    if (($user['role_key'] ?? '') === 'admin') {
        return true;
    }

    if (!isset($GLOBALS['__perm_state'])) {
        $GLOBALS['__perm_state'] = ['grants' => [], 'overrides' => []];

        $stmt = $GLOBALS['pdo']->prepare(
            'SELECT p.`key`,
                    IF(rp.permission_id IS NULL, 0, 1) AS role_grant,
                    up.effect
               FROM permissions p
               LEFT JOIN role_permissions rp
                      ON rp.permission_id = p.id AND rp.role_id = ?
               LEFT JOIN user_permissions up
                      ON up.permission_id = p.id AND up.user_id = ?'
        );
        $stmt->execute([(int)$user['role_id'], (int)$user['id']]);

        foreach ($stmt->fetchAll() as $row) {
            $key = (string)$row['key'];
            $GLOBALS['__perm_state']['grants'][$key]    = (int)$row['role_grant'] === 1;
            $GLOBALS['__perm_state']['overrides'][$key] =
                $row['effect'] !== null ? (string)$row['effect'] : '';
        }
    }

    $state   = $GLOBALS['__perm_state'];
    $effect  = $state['overrides'][$permission] ?? '';

    if ($effect === 'deny') {
        return false;
    }
    if ($effect === 'allow') {
        return true;
    }

    return (bool)($state['grants'][$permission] ?? false);
}

/**
 * Abort with 403 unless the signed-in user holds $permission.
 * Mirrors requireRole(): always sends a full HTML 403 page.
 */
function requirePermission(string $permission): void
{
    requireLogin();

    if (!can($permission)) {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:40px">'
            . '<h1>403 - Access denied</h1>'
            . '<p>Your account does not have permission to view this page.</p>'
            . '<p><a href="' . e(homeUrl()) . '">Back to dashboard</a></p>'
            . '</body></html>';
        exit;
    }
}

/**
 * Catalog grouped for UI rendering.
 *
 * @return array<string, list<array{key:string,name:string,description:string}>>
 */
function permissionGroups(): array
{
    $groups = [];
    foreach (permissionCatalog() as $key => [$name, $group, $desc]) {
        $groups[$group][] = ['key' => $key, 'name' => $name, 'description' => $desc];
    }

    return $groups;
}

/**
 * Permission keys granted to a role: array<string, bool>.
 */
function rolePermissionMap(int $roleId): array
{
    $out = [];
    $stmt = $GLOBALS['pdo']->prepare(
        'SELECT p.`key` FROM role_permissions rp
           JOIN permissions p ON p.id = rp.permission_id
          WHERE rp.role_id = ?'
    );
    $stmt->execute([$roleId]);

    foreach ($stmt->fetchAll() as $row) {
        $out[(string)$row['key']] = true;
    }

    return $out;
}

/**
 * A user's fine-tune overrides: array<string, 'allow'|'deny'>.
 */
function userPermissionOverrides(int $userId): array
{
    $out = [];
    $stmt = $GLOBALS['pdo']->prepare(
        'SELECT p.`key`, up.effect FROM user_permissions up
           JOIN permissions p ON p.id = up.permission_id
          WHERE up.user_id = ?'
    );
    $stmt->execute([$userId]);

    foreach ($stmt->fetchAll() as $row) {
        $out[(string)$row['key']] = (string)$row['effect'];
    }

    return $out;
}

/**
 * Replace a user's overrides from perm_effect['KEY'] = ''|'allow'|'deny'.
 * Empty / unknown entries mean "follow the role default" and are removed.
 */
function setUserPermissionOverrides(int $userId, array $effects): void
{
    permissionsBootstrap();
    $pdo = $GLOBALS['pdo'];

    $ids = [];
    foreach ($pdo->query('SELECT id, `key` FROM permissions') as $row) {
        $ids[(string)$row['key']] = (int)$row['id'];
    }

    $pdo->prepare('DELETE FROM user_permissions WHERE user_id = ?')->execute([$userId]);

    $ins = $pdo->prepare(
        'INSERT INTO user_permissions (user_id, permission_id, effect) VALUES (?, ?, ?)'
    );
    foreach ($effects as $key => $effect) {
        $effect = (string)$effect;
        $key    = (string)$key;

        if (in_array($effect, ['allow', 'deny'], true) && isset($ids[$key])) {
            $ins->execute([$userId, $ids[$key], $effect]);
        }
    }
}
