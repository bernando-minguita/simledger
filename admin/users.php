<?php
/**
 * SIMLedger - user management (admin only).
 * Create / edit / activate / deactivate accounts, assign roles + terminals.
 */

declare(strict_types=1);

$base = '../';

require_once __DIR__ . '/../includes/lookups.php';
require_once __DIR__ . '/../includes/audit.php';

$pdo = $GLOBALS['pdo'];
requirePermission('user.manage');
userAdminBootstrap();   // must_change_password on older databases

$error = '';
$plainPassword = '';   // shown once, this request only
$resetTarget   = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrfCheck();

    $action  = (string)($_POST['action'] ?? '');
    $userId  = reqInt('user_id', 0, 0, 100000000);
    $username = trim((string)($_POST['username'] ?? ''));
    $name     = trim((string)($_POST['name'] ?? ''));
    $roleId   = reqInt('role_id', 0, 1, 100000);
    $termRaw  = $_POST['terminal_id'] ?? '';
    $termId   = $termRaw !== '' && $termRaw !== null ? (int)$termRaw : null;
    $isActive = ($_POST['is_active'] ?? '') === '1';
    $password = (string)($_POST['password'] ?? '');

    try {
        if ($action === 'reset_2fa') {
            $stmt = $pdo->prepare('SELECT username, totp_enabled FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if ($row === false) {
                throw new RuntimeException('User not found.');
            }
            twoFactorAdminReset($userId);
            logAudit('RESET_2FA', 'user', $userId,
                ['had_2fa' => (int)$row['totp_enabled'] === 1], null);
            setFlash('ok', 'Two-factor authentication was reset for ' . $row['username'] . '.');
            header('Location: users.php');
            exit;
        }

        if ($action === 'reset_password') {
            $stmt = $pdo->prepare('SELECT id, username FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if ($row === false) {
                throw new RuntimeException('User not found.');
            }

            $plainPassword = 'Reset-' . bin2hex(random_bytes(4));
            $resetTarget   = (string)$row['username'];
            $pdo->prepare(
                'UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?'
            )->execute([password_hash($plainPassword, PASSWORD_DEFAULT), $userId]);
            logAudit('RESET_PASSWORD', 'user', $userId, null, ['must_change' => true]);

            // Deliberately NO redirect: the temporary password is shown once,
            // on the reloaded page below, and is never stored in plain form.
        } elseif ($action === 'login_as') {
            $stmt = $pdo->prepare('SELECT id, username, is_active FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $row = $stmt->fetch();
            if ($row === false) {
                throw new RuntimeException('User not found.');
            }
            if ((int)$row['is_active'] !== 1) {
                throw new RuntimeException('Cannot log in as an inactive user.');
            }
            if ($userId === (int)($_SESSION['user_id'] ?? 0)) {
                throw new RuntimeException('You are already logged in as yourself.');
            }
            if (!empty($_SESSION['impersonator'])) {
                throw new RuntimeException('Return to your admin account first (banner at the top).');
            }

            logAudit('LOGIN_AS', 'user', $userId, null,
                ['impersonator' => (string)($_SESSION['user']['username'] ?? '')]);

            $_SESSION['impersonator'] = [
                'id'       => (int)$_SESSION['user_id'],
                'username' => (string)($_SESSION['user']['username'] ?? ''),
            ];
            $_SESSION['user_id'] = $userId;
            unset($_SESSION['csrf_token']);
            session_regenerate_id(true);
            if (refreshUser() === null) {
                $_SESSION = [];
                session_destroy();
                header('Location: ../login.php');
                exit;
            }

            setFlash('ok', 'Now logged in as ' . $row['username']
                . '. Use "Return to admin account" in the top banner to switch back.');
            header('Location: ../index.php');
            exit;
        } elseif ($action === 'create' || $action === 'update') {
            if ($username === '' || strlen($username) > 50) {
                throw new RuntimeException('Username is required (max 50 chars).');
            }
            if ($name === '') {
                throw new RuntimeException('Name is required.');
            }

            // Dispatchers MUST have a terminal; admins/managers must NOT.
            $stmt = $pdo->prepare('SELECT `key` FROM roles WHERE id = ?');
            $stmt->execute([$roleId]);
            $roleKey = (string)$stmt->fetchColumn();

            if ($roleKey === 'dispatcher' && $termId === null) {
                throw new RuntimeException('A dispatcher must be assigned to a terminal.');
            }
            if (in_array($roleKey, ['admin', 'manager'], true)) {
                $termId = null;
            }

            if ($action === 'create') {
                if (strlen($password) < 8) {
                    throw new RuntimeException('Password must be at least 8 characters.');
                }
                $stmt = $pdo->prepare(
                    'INSERT INTO users (username, password_hash, name, role_id, terminal_id, is_active)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $username, password_hash($password, PASSWORD_DEFAULT), $name,
                    $roleId, $termId, $isActive ? 1 : 0,
                ]);
                $newId = (int)$pdo->lastInsertId();
                logAudit('CREATE_USER', 'user', $newId, null,
                    ['username' => $username, 'role_id' => $roleId,
                     'terminal_id' => $termId, 'active' => $isActive]);
                setFlash('ok', 'User created.');
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE users SET username = ?, name = ?, role_id = ?, terminal_id = ?,
                            is_active = ? WHERE id = ?'
                );
                $stmt->execute([$username, $name, $roleId, $termId, $isActive ? 1 : 0, $userId]);

                if ($password !== '') {
                    if (strlen($password) < 8) {
                        throw new RuntimeException('Password must be at least 8 characters.');
                    }
                    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                        ->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
                }

                // Fine-tune: per-permission overrides for this account only.
                setUserPermissionOverrides($userId, $_POST['perm_effect'] ?? []);

                logAudit('UPDATE_USER', 'user', $userId,
                    ['username' => $username], ['role_id' => $roleId,
                     'terminal_id' => $termId, 'active' => $isActive,
                     'password_changed' => $password !== '']);
                setFlash('ok', 'User updated.');
            }

            header('Location: users.php');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
            $error = 'That username already exists.';
        }
    }
}

$roles = [];
foreach ($pdo->query('SELECT id, `key`, name FROM roles ORDER BY id') as $r) {
    $roles[(int)$r['id']] = $r;
}

$editId = reqInt('edit', 0, 0, 100000000);
$editRow = null;
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$editId]);
    $editRow = $stmt->fetch() ?: null;
}

$users = $pdo->query(
    'SELECT u.*, r.name AS role_name, r.`key` AS role_key, t.name AS terminal_name
       FROM users u
       JOIN roles r ON r.id = u.role_id
  LEFT JOIN terminals t ON t.id = u.terminal_id
      ORDER BY u.username'
)->fetchAll();

// Permission maps for the modal's per-user payload (3 queries total).
$permKeyById = [];
foreach ($pdo->query('SELECT id, `key` FROM permissions') as $p) {
    $permKeyById[(int)$p['id']] = (string)$p['key'];
}
$roleGrants = [];   // role_id => [perm key => true]
foreach ($pdo->query('SELECT role_id, permission_id FROM role_permissions') as $rp) {
    $k = $permKeyById[(int)$rp['permission_id']] ?? null;
    if ($k !== null) {
        $roleGrants[(int)$rp['role_id']][$k] = true;
    }
}
$userEffects = [];  // user_id => [perm key => allow|deny]
foreach ($pdo->query('SELECT user_id, permission_id, effect FROM user_permissions') as $up) {
    $k = $permKeyById[(int)$up['permission_id']] ?? null;
    if ($k !== null) {
        $userEffects[(int)$up['user_id']][$k] = (string)$up['effect'];
    }
}
$userPayload = static function (array $u) use ($permKeyById, $roleGrants, $userEffects): array {
    $rid    = (int)$u['role_id'];
    $grants = (string)($u['role_key'] ?? '') === 'admin'
        ? array_values($permKeyById)                       // admin implicitly holds all
        : array_keys($roleGrants[$rid] ?? []);

    return [
        'id'          => (int)$u['id'],
        'username'    => (string)$u['username'],
        'name'        => (string)$u['name'],
        'role_id'     => $rid,
        'terminal_id' => $u['terminal_id'] !== null ? (int)$u['terminal_id'] : 0,
        'active'      => (int)$u['is_active'],
        'grants'      => $grants,
        'effects'     => $userEffects[(int)$u['id']] ?? [],
    ];
};

$pageTitle = 'Users';
$activeNav = 'admin-users';
require __DIR__ . '/../includes/header.php';
?><?php if ($plainPassword !== ''): ?>
    <div class="alert-op mb-3">
        Temporary password for <strong><?= e($resetTarget) ?></strong>:
        <code class="user-select-all fs-6"><?= e($plainPassword) ?></code>
        &mdash; share it securely with the user. They will be required to
        choose a new password at their next sign-in.
    </div>
<?php endif; ?>
<div class="row g-3">
    <!-- user list -->
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex align-items-center gap-2">
                <span class="me-auto">Users</span>
                <!-- table-toolkit.js appends the "Columns" show/hide menu as the
                     last header child; me-auto + gap groups these two together:
                     Users .... [Create User] [Columns ▼] -->
                <button type="button" class="btn btn-primary btn-sm"
                        data-action="create-user">Create User</button>
            </div>
            <div class="card-body p-0">
                <table class="table table-striped mb-0 dt-nowrap">
                    <thead>
                    <tr>
                        <th>Username</th><th>Name</th><th>Role</th><th>Terminal</th>
                        <th>Status</th><th>Last Login</th><th class="text-end">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?= e($u['username']) ?></td>
                            <td><?= e($u['name']) ?></td>
                            <td><span class="badge bg-secondary"><?= e($u['role_name']) ?></span></td>
                            <td><?= e($u['terminal_name'] ?? 'All') ?></td>
                            <td><?= (int)$u['is_active'] === 1
                                ? '<span class="badge bg-success">Active</span>'
                                : '<span class="badge bg-danger">Inactive</span>' ?>
                                <?php if (!empty($u['must_change_password'])): ?>
                                    <span class="badge bg-warning text-dark">Must change password</span>
                                <?php endif; ?></td>
                            <td class="small"><?= $u['last_login_at'] !== null
                                ? e(date('d-M-y H:i', strtotime((string)$u['last_login_at']))) : '—' ?></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        data-action="edit-user" data-id="<?= (int)$u['id'] ?>"
                                        data-user='<?= json_encode($userPayload($u),
                                            JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG) ?>'>Edit</button>
                                <form method="post" action="users.php" class="d-inline"
                                      data-confirm-danger
                                      data-confirm="Reset the password for <?= e($u['username']) ?>? A temporary password will be shown once; they must change it at next login.">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Reset Password</button>
                                </form>
                                <?php if ((int)$u['is_active'] === 1
                                    && (int)$u['id'] !== (int)($_SESSION['user_id'] ?? 0)
                                    && empty($_SESSION['impersonator'])): ?>
                                    <form method="post" action="users.php" class="d-inline"
                                          data-confirm="Log in as <?= e($u['username']) ?>? A banner will let you return to your admin account.">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="login_as">
                                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-primary">Login as</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ((int)($u['totp_enabled'] ?? 0) === 1): ?>
                                    <form method="post" action="users.php" class="d-inline"
                                          data-confirm-danger
                                          data-confirm="Reset two-factor authentication for this user? They will have to enrol again.">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="reset_2fa">
                                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-warning">Reset 2FA</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div><!-- /.row - create/edit live in the modal below -->

<!-- create / edit modal -->
<div class="modal fade" id="userModal" tabindex="-1" aria-hidden="true"
     data-open="<?= $editRow !== null ? 'edit-' . (int)$editRow['id'] : ($error !== '' ? 'create' : '') ?>">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form method="post" action="users.php" id="um-form" class="modal-content">
            <?= csrfField() ?>
            <input type="hidden" name="action" id="um-action"
                   value="<?= $editRow !== null ? 'update' : 'create' ?>">
            <input type="hidden" name="user_id" id="um-user"
                   value="<?= $editRow !== null ? (int)$editRow['id'] : '' ?>">
            <div class="modal-header">
                <h5 class="modal-title"><?= $editRow !== null
                    ? 'Edit user: ' . e($editRow['username']) : 'Create user' ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php if ($error !== ''): ?>
                    <div class="alert-error mb-3"><?= e($error) ?></div>
                <?php endif; ?>

                    <div class="mb-2">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" name="username" maxlength="50" required
                               value="<?= e($editRow['username'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Full name</label>
                        <input type="text" class="form-control" name="name" maxlength="100" required
                               value="<?= e($editRow['name'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Role</label>
                        <select class="form-select" name="role_id" required>
                            <?php foreach ($roles as $rid => $r): ?>
                                <option value="<?= $rid ?>"
                                    <?= (int)($editRow['role_id'] ?? 0) === $rid ? 'selected' : '' ?>>
                                    <?= e($r['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Dispatchers are locked to one terminal.</div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Terminal (required for dispatchers)</label>
                        <?= terminalSelect($editRow['terminal_id'] ?? null) ?>
                    </div>
                    <div class="mb-2">
                        <label class="form-label" id="um-pwlabel">
                            Password <?= $editRow !== null ? '(leave blank to keep current)' : '(min 8 chars)' ?>
                        </label>
                        <input type="password" class="form-control" name="password" id="um-pw"
                               <?= $editRow === null ? 'required' : '' ?> minlength="8" autocomplete="new-password">
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="is_active" id="ua"
                               value="1" <?= (int)($editRow['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="ua">Active</label>
                    </div>
                    <div id="um-overrides" class="d-none">
                        <div class="mb-0">
                            <label class="form-label">Permission overrides</label>
                            <div class="form-text mb-2">
                                Defaults come from the selected role. Override individual
                                permissions for this account only.
                            </div>
                            <?php foreach (permissionGroups() as $ovGroup => $ovPerms): ?>
                                <div class="section-label mt-2"><?= e($ovGroup) ?></div>
                                <?php foreach ($ovPerms as $ovp): ?>
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="small" style="flex:1"><?= e($ovp['name']) ?></span>
                                        <select class="form-select form-select-sm" style="width:auto"
                                                name="perm_effect[<?= e($ovp['key']) ?>]">
                                            <option value="" selected>Role default</option>
                                            <option value="allow">Always allow</option>
                                            <option value="deny">Always deny</option>
                                        </select>
                                    </div>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
            </div><!-- /.modal-body -->
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary" id="um-submit">
                    <?= $editRow !== null ? 'Save changes' : 'Create user' ?>
                </button>
                <button type="button" class="btn btn-outline-secondary"
                        data-bs-dismiss="modal">Cancel</button>
            </div>
        </form>
    </div>
</div>
<script>
/* Instant create/edit modal - no page reloads, no flicker.
   Each Edit button carries its row (+ permission grants/effects) as JSON in
   data-user; clicking fills the shared #userModal and shows it in place.
   bootstrap.bundle.min.js loads in includes/footer.php BELOW this block, so
   all Bootstrap access is deferred to DOMContentLoaded.
   A failed save posts to users.php?edit=ID and the server re-renders with
   data-open="edit-ID" / "create" so the modal reopens automatically. */
(function () {
    'use strict';

    function boot() {
        var modalEl = document.getElementById('userModal');
        if (!modalEl || !window.bootstrap) { return; }

        var modal      = new bootstrap.Modal(modalEl);
        var form       = document.getElementById('um-form');
        var actionIn   = document.getElementById('um-action');
        var userIn     = document.getElementById('um-user');
        var title      = modalEl.querySelector('.modal-title');
        var pw         = document.getElementById('um-pw');
        var pwLabel    = document.getElementById('um-pwlabel');
        var submitBtn  = document.getElementById('um-submit');
        var overrides  = document.getElementById('um-overrides');

        function applyOverrides(data) {
            modalEl.querySelectorAll('select[name^="perm_effect["]').forEach(function (sel) {
                var key = sel.getAttribute('name').slice(12, -1);
                sel.value = (data && data.effects && data.effects[key]) ? data.effects[key] : '';
                var opt = sel.querySelector('option[value=""]');
                if (opt) {
                    opt.textContent = 'Role default (' +
                        (data && data.grants && data.grants.indexOf(key) !== -1
                            ? 'granted' : 'denied') + ')';
                }
            });
        }

        function openUserModal(data) {
            if (data) {
                title.textContent = 'Edit user: ' + data.username;
                actionIn.value = 'update';
                userIn.value = String(data.id);
                form.action = 'users.php?edit=' + data.id;
                form.elements['username'].value = data.username;
                form.elements['name'].value = data.name;
                form.elements['role_id'].value = String(data.role_id);
                form.elements['terminal_id'].value = data.terminal_id ? String(data.terminal_id) : '';
                form.elements['is_active'].checked = !!data.active;
                pwLabel.textContent = 'Password (leave blank to keep current)';
                pw.required = false;
                submitBtn.textContent = 'Save changes';
                overrides.classList.remove('d-none');
                applyOverrides(data);
            } else {
                title.textContent = 'Create user';
                form.action = 'users.php';
                form.reset();                       // FIRST: restore server defaults...
                actionIn.value = 'create';          // ...then force create mode (an ?edit=
                userIn.value = '';                  // page renders update/N as server defaults)
                pwLabel.textContent = 'Password (min 8 chars)';
                pw.required = true;
                submitBtn.textContent = 'Create user';
                overrides.classList.add('d-none');
            }
            history.replaceState({}, '', 'users.php');
            modal.show();
        }

        document.querySelectorAll('[data-action="edit-user"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                openUserModal(JSON.parse(btn.getAttribute('data-user')));
            });
        });
        document.querySelectorAll('[data-action="create-user"]').forEach(function (btn) {
            btn.addEventListener('click', function () { openUserModal(null); });
        });

        modalEl.addEventListener('hidden.bs.modal', function () {
            history.replaceState({}, '', 'users.php');
        });

        // Server-driven reopen (failed save, or a ?edit= deep link).
        var open = modalEl.getAttribute('data-open') || '';
        if (open.indexOf('edit-') === 0) {
            var src = document.querySelector(
                '[data-action="edit-user"][data-id="' + open.slice(5) + '"]');
            if (src) { openUserModal(JSON.parse(src.getAttribute('data-user'))); }
        } else if (open === 'create') {
            openUserModal(null);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>

