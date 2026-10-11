<?php
/**
 * SIMLedger - session authentication and access control.
 *
 * Roles: admin (full access), manager (read across terminals), dispatcher
 * (terminal-scoped). Every permission decision goes through the functions
 * below - never rely on hiding buttons in the UI.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/two-factor.php';
require_once __DIR__ . '/geo-guard.php';
geoGuardEnforce();

/**
 * Relative URL of login.php seen from the running script.
 */
function loginUrl(): string
{
    $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string)$_SERVER['SCRIPT_NAME']) : '';
    $dir    = rtrim(dirname($script), '/');

    if ($dir !== '' && preg_match('#/(admin|reports)$#', $dir)) {
        return '../login.php';
    }

    return 'login.php';
}

/**
 * Re-read the signed-in user from the database so role/terminal/status
 * changes take effect on the very next request.
 */
function refreshUser(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = $GLOBALS['pdo']->prepare(
        'SELECT u.id, u.username, u.name, u.role_id, u.terminal_id, u.is_active,
                r.key AS role_key, r.name AS role_name, t.name AS terminal_name
           FROM users u
           JOIN roles r ON r.id = u.role_id
      LEFT JOIN terminals t ON t.id = u.terminal_id
          WHERE u.id = ? AND u.is_active = 1'
    );
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if ($user === false) {
        return null;
    }

    $_SESSION['user'] = [
        'id'             => (int)$user['id'],
        'username'       => $user['username'],
        'name'           => $user['name'],
        'role_id'        => (int)$user['role_id'],
        'role_key'       => $user['role_key'],
        'role_name'      => $user['role_name'],
        'terminal_id'    => $user['terminal_id'] !== null ? (int)$user['terminal_id'] : null,
        'terminal_name'  => $user['terminal_name'],
    ];

    return $_SESSION['user'];
}

/**
 * Add must_change_password to users on older databases (administrator
 * password resets force a change at the next sign-in). Idempotent;
 * returns immediately once flagged in app_settings (per-request cached).
 */
function userAdminBootstrap(): void
{
    if (settingGet('mig_user_admin', '') === '1') {
        return;
    }

    $pdo = $GLOBALS['pdo'];
    $has = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'users' AND COLUMN_NAME = 'must_change_password'"
    )->fetchColumn();

    if ($has === 0) {
        $pdo->exec(
            'ALTER TABLE users
                ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0
                    AFTER totp_enabled'
        );
    }

    $pdo->exec("INSERT INTO app_settings (`key`, `value`) VALUES ('mig_user_admin', '1')
                    ON DUPLICATE KEY UPDATE `value` = '1'");
    if (isset($GLOBALS['__settings_cache']) && is_array($GLOBALS['__settings_cache'])) {
        $GLOBALS['__settings_cache']['mig_user_admin'] = '1';
    }
}

/**
 * Redirect to the login page unless an authenticated session exists.
 */
function requireLogin(): void
{
    if (refreshUser() === null) {
        if (!empty($_SESSION['user_id'])) {
            $_SESSION = [];
            session_destroy();
        }

        header('Location: ' . loginUrl());
        exit;
    }

    // Ensure users.totp_* columns exist (flag-guarded, settings cached).
    twoFactorBootstrap();

    // Older databases: users.must_change_password (admin password resets).
    userAdminBootstrap();

    // Idle timeout.
    $now = time();
    if (isset($_SESSION['last_activity'])
        && ($now - (int)$_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT) {
        $_SESSION = [];
        session_destroy();
        header('Location: ' . loginUrl() . '?timeout=1');
        exit;
    }
    $_SESSION['last_activity'] = $now;

    // Forced password change: after an administrator reset, every page
    // except the profile's change-password form is blocked until the user
    // picks a new password (the flag clears on success).
    $mustChange = (int)$GLOBALS['pdo']->query(
        'SELECT must_change_password FROM users WHERE id = ' . (int)$_SESSION['user_id']
    )->fetchColumn();
    $onProfile = basename(isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '')
        === 'profile.php';
    if ($mustChange === 1 && !$onProfile) {
        $script = isset($_SERVER['SCRIPT_NAME'])
            ? str_replace('\\', '/', (string)$_SERVER['SCRIPT_NAME']) : '';
        $dir    = rtrim(dirname($script), '/');
        header('Location: '
            . ($dir !== '' && preg_match('#/(admin|reports)$#', $dir) ? '../profile.php' : 'profile.php')
            . '?must_change=1');
        exit;
    }
}

/**
 * The signed-in user, or null.
 */
function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

/**
 * True when the signed-in user has one of the given role keys.
 *
 * @param string|string[] $roles
 */
function hasRole($roles): bool
{
    $allowed = is_array($roles) ? $roles : [$roles];
    $user    = currentUser();

    return in_array($user['role_key'] ?? '', $allowed, true);
}

/**
 * Require one of the given role keys. Sends HTTP 403 otherwise.
 *
 * @param string|string[] $roles
 */
function requireRole($roles): void
{
    requireLogin();

    if (!hasRole($roles)) {
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
 * Dashboard URL respecting the running script's directory.
 */
function homeUrl(): string
{
    $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string)$_SERVER['SCRIPT_NAME']) : '';
    $dir    = rtrim(dirname($script), '/');

    if ($dir !== '' && preg_match('#/(admin|reports)$#', $dir)) {
        return '../index.php';
    }

    return 'index.php';
}

/**
 * The terminal the current user is confined to, or null for all-terminals
 * users (admin / manager).
 */
function currentTerminalId(): ?int
{
    $user = currentUser();

    if ($user === null) {
        return null;
    }

    if (hasRole(['admin', 'manager'])) {
        return null;
    }

    return $user['terminal_id'];
}

/**
 * Server-side terminal authorization. Returns true when the current user
 * may act on rows belonging to $terminalId.
 */
function canAccessTerminal(?int $terminalId): bool
{
    $scope = currentTerminalId();

    if ($scope === null) {
        return true; // all-terminals role
    }

    return $terminalId !== null && $scope === $terminalId;
}

/**
 * Abort with 403 unless the current user may act on $terminalId.
 */
function assertTerminalAccess(?int $terminalId): void
{
    if (!canAccessTerminal($terminalId)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Access denied: this record belongs to another terminal.';
        exit;
    }
}

/**
 * Full phone numbers: admins always; dispatchers for their own terminal;
 * managers masked by default (setting can widen this).
 */
function canViewFullNumbers(?int $terminalId = null): bool
{
    if (hasRole('admin')) {
        return true;
    }

    if (hasRole('dispatcher')) {
        return canAccessTerminal($terminalId);
    }

    return settingBool('managers_see_full_numbers', false);
}

/**
 * Abort with 403 unless the current user may act on ANY of the given
 * terminals. Custody model: access is granted by the record's book terminal
 * OR by the terminal of the driver physically holding the SIM (so the
 * receiving terminal can return/replace/report SIMs held by its drivers
 * after a cross-terminal transfer).
 */
function assertCustodyAccess(?int ...$terminals): void
{
    $scope = currentTerminalId();

    if ($scope === null) {
        return; // all-terminals roles
    }

    foreach ($terminals as $terminalId) {
        if ($terminalId !== null && $terminalId === $scope) {
            return;
        }
    }

    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Access denied: this record belongs to another terminal.';
    exit;
}

