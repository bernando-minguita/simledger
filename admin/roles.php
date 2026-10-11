<?php
/**
 * SIMLedger - roles & permissions (admin only).
 *
 * Group-level access control: each role holds a set of permissions
 * (role_permissions). Individual users are fine-tuned on the Users page with
 * per-permission allow/deny overrides. The admin role always has everything.
 */

declare(strict_types=1);

$base = '../';

require_once __DIR__ . '/../includes/lookups.php';
require_once __DIR__ . '/../includes/audit.php';

$pdo = $GLOBALS['pdo'];
requirePermission('user.manage');
permissionsBootstrap();

$error  = '';
$roleId = reqInt('role', 0, 0, 100000000);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrfCheck();

    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'create') {
            $name = trim((string)($_POST['name'] ?? ''));
            $key  = strtolower(trim((string)($_POST['key'] ?? '')));

            if ($name === '' || strlen($name) > 60) {
                throw new RuntimeException('Role name is required (max 60 chars).');
            }
            if (!preg_match('/^[a-z][a-z0-9_]{2,29}$/', $key)) {
                throw new RuntimeException('Key must be 3-30 lowercase letters/digits/underscores.');
            }

            $stmt = $pdo->prepare('INSERT INTO roles (`key`, name) VALUES (?, ?)');
            $stmt->execute([$key, $name]);
            $newId = (int)$pdo->lastInsertId();
            logAudit('CREATE_ROLE', 'role', $newId, null, ['key' => $key, 'name' => $name]);
            setFlash('ok', 'Role created - now assign its permissions.');

            header('Location: roles.php?role=' . $newId);
            exit;
        }

        if ($action === 'save') {
            if ($roleId <= 0) {
                throw new RuntimeException('No role selected.');
            }

            $stmt = $pdo->prepare('SELECT * FROM roles WHERE id = ?');
            $stmt->execute([$roleId]);
            $role = $stmt->fetch();

            if ($role === false) {
                throw new RuntimeException('Role not found.');
            }

            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '' || strlen($name) > 60) {
                throw new RuntimeException('Role name is required (max 60 chars).');
            }

            $pdo->prepare('UPDATE roles SET name = ? WHERE id = ?')->execute([$name, $roleId]);

            // Replace the role's permission set with the submitted checkboxes.
            $permIds = [];
            foreach ($pdo->query('SELECT id, `key` FROM permissions') as $p) {
                $permIds[(string)$p['key']] = (int)$p['id'];
            }
            $checked = [];
            foreach (($_POST['perm'] ?? []) as $key => $v) {
                if ((string)$v === '1' && isset($permIds[(string)$key])) {
                    $checked[] = $permIds[(string)$key];
                }
            }

            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$roleId]);
            $ins = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)');
            foreach ($checked as $pid) {
                $ins->execute([$roleId, $pid]);
            }
            $pdo->commit();

            logAudit('UPDATE_ROLE', 'role', $roleId,
                ['name' => $role['name']], ['name' => $name, 'permissions' => count($checked)]);
            setFlash('ok', 'Role permissions saved.');

            header('Location: roles.php?role=' . $roleId);
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
            $error = 'A role with that key already exists.';
        }
    }
}

$roles = $pdo->query(
    'SELECT r.*,
            (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS user_count,
            (SELECT COUNT(*) FROM role_permissions rp WHERE rp.role_id = r.id) AS perm_count
       FROM roles r ORDER BY r.id'
)->fetchAll();

$editRole    = null;
$grants      = [];
$isAdminRole = false;
foreach ($roles as $r) {
    if ((int)$r['id'] === $roleId) {
        $editRole    = $r;
        $isAdminRole = $r['key'] === 'admin';
        $grants      = rolePermissionMap($roleId);
    }
}

$groups = permissionGroups();

$pageTitle = 'Roles & Permissions';
$activeNav = 'admin-roles';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="page-title">Roles &amp; Permissions</h1>
        <p class="page-sub">Permissions are assigned to roles (the group level);
            individual accounts can be fine-tuned on the
            <a href="users.php">Users</a> page.</p>
    </div>
</div>

<div class="row g-3">
    <!-- roles -->
    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header">Roles</div>
            <div class="card-body p-0">
                <?php if ($error !== ''): ?>
                    <div class="mx-3 mt-3 alert-error"><?= e($error) ?></div>
                <?php endif; ?>
                <table class="table table-striped mb-0">
                    <thead>
                    <tr><th>Role</th><th>Users</th><th>Perms</th><th class="text-end">&nbsp;</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($roles as $r): ?>
                        <tr<?= (int)$r['id'] === $roleId ? ' class="table-primary"' : '' ?>>
                            <td>
                                <?= e($r['name']) ?><br>
                                <span class="small text-muted"><?= e($r['key']) ?></span>
                            </td>
                            <td><?= (int)$r['user_count'] ?></td>
                            <td><?= $r['key'] === 'admin' ? 'all' : (int)$r['perm_count'] ?></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="roles.php?role=<?= (int)$r['id'] ?>">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Add role</div>
            <div class="card-body">
                <form method="post" action="roles.php">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="create">
                    <div class="mb-2">
                        <label class="form-label">Role name</label>
                        <input type="text" class="form-control" name="name" maxlength="60" required
                               placeholder="e.g. Senior Dispatcher">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Key</label>
                        <input type="text" class="form-control" name="key" maxlength="30" required
                               pattern="[a-z][a-z0-9_]{2,29}" placeholder="e.g. senior_dispatcher">
                        <div class="form-text">Lowercase identifier; unique.</div>
                    </div>
                    <button type="submit" class="btn btn-primary">Create role</button>
                </form>
            </div>
        </div>
    </div>

    <!-- permission editor -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><?= $editRole !== null
                ? 'Permissions: ' . e($editRole['name']) : 'Permission assignment' ?></div>
            <div class="card-body">
                <?php if ($editRole === null): ?>
                    <p class="text-muted mb-0">Select a role on the left to assign its permissions,
                        or create a new one.</p>
                <?php else: ?>
                    <?php if ($isAdminRole): ?>
                        <div class="alert-op mb-3">The <strong>admin</strong> role always holds every
                            permission regardless of the checkboxes below.</div>
                    <?php endif; ?>
                    <form method="post" action="roles.php">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="role" value="<?= (int)$editRole['id'] ?>">
                        <div class="mb-3" style="max-width:420px">
                            <label class="form-label">Role name</label>
                            <input type="text" class="form-control" name="name" maxlength="60" required
                                   value="<?= e($editRole['name']) ?>">
                        </div>

                        <?php foreach ($groups as $group => $perms): ?>
                            <div class="section-label mt-3"><?= e($group) ?></div>
                            <div class="row">
                                <?php foreach ($perms as $p): ?>
                                    <div class="col-md-6">
                                        <div class="form-check mb-1">
                                            <input class="form-check-input" type="checkbox"
                                                   name="perm[<?= e($p['key']) ?>]" value="1" id="p_<?= e($p['key']) ?>"
                                                   <?= !empty($grants[$p['key']]) ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="p_<?= e($p['key']) ?>">
                                                <?= e($p['name']) ?>
                                                <span class="small text-muted d-block"><?= e($p['description']) ?></span>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>

                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary">Save permissions</button>
                            <a class="btn btn-outline-secondary" href="roles.php">Cancel</a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
