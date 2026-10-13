<?php
/**
 * SIMLedger - terminal/branch master (admin only).
 * Terminals are configuration - never hard-coded in application code.
 */

declare(strict_types=1);

$base = '../';

require_once __DIR__ . '/../includes/lookups.php';
require_once __DIR__ . '/../includes/audit.php';

$pdo = $GLOBALS['pdo'];
requirePermission('reference.manage');

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrfCheck();

    $action = (string)($_POST['action'] ?? '');
    $tid    = reqInt('terminal_id', 0, 0, 100000000);
    $name   = trim((string)($_POST['name'] ?? ''));
    $code   = strtoupper(trim((string)($_POST['code'] ?? '')));
    $active = ($_POST['is_active'] ?? '') === '1';

    try {
        if ($name === '' || strlen($name) > 60) {
            throw new RuntimeException('Name is required (max 60 chars).');
        }
        if ($code === '' || strlen($code) > 10) {
            throw new RuntimeException('Code is required (max 10 chars).');
        }

        if ($action === 'create') {
            $stmt = $pdo->prepare(
                'INSERT INTO terminals (name, code, is_active) VALUES (?, ?, ?)'
            );
            $stmt->execute([$name, $code, $active ? 1 : 0]);
            $newId = (int)$pdo->lastInsertId();
            logAudit('CREATE_TERMINAL', 'terminal', $newId, null,
                ['name' => $name, 'code' => $code]);
            setFlash('ok', 'Terminal created.');
        } elseif ($action === 'update') {
            $stmt = $pdo->prepare(
                'UPDATE terminals SET name = ?, code = ?, is_active = ? WHERE id = ?'
            );
            $stmt->execute([$name, $code, $active ? 1 : 0, $tid]);
            logAudit('UPDATE_TERMINAL', 'terminal', $tid, null,
                ['name' => $name, 'code' => $code, 'active' => $active]);
            setFlash('ok', 'Terminal updated.');
        }

        header('Location: terminals.php');
        exit;
    } catch (Throwable $e) {
        $error = friendlyError($e, 'A terminal with that name or code already exists.');
    }
}

$editId  = reqInt('edit', 0, 0, 100000000);
$editRow = null;
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM terminals WHERE id = ?');
    $stmt->execute([$editId]);
    $editRow = $stmt->fetch() ?: null;
}

$terminals = $pdo->query(
    'SELECT t.*,
            (SELECT COUNT(*) FROM drivers d WHERE d.terminal_id = t.id) AS driver_count,
            (SELECT COUNT(*) FROM company_sims s WHERE s.terminal_id = t.id) AS sim_count,
            (SELECT COUNT(*) FROM users u WHERE u.terminal_id = t.id AND u.is_active = 1) AS user_count
       FROM terminals t ORDER BY t.name'
)->fetchAll();

$pageTitle = 'Terminals';
$activeNav = 'admin-terminals';
require __DIR__ . '/../includes/header.php';
?>
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">Terminals</div>
            <div class="card-body p-0">
                <table class="table table-striped mb-0">
                    <thead>
                    <tr><th>Code</th><th>Name</th><th>Drivers</th><th>SIMs</th><th>Users</th>
                        <th>Status</th><th class="text-end">Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php if ($error !== ''): ?>
                        <tr><td colspan="7"><div class="alert-error"><?= e($error) ?></div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($terminals as $t): ?>
                        <tr>
                            <td><code><?= e($t['code']) ?></code></td>
                            <td><?= e($t['name']) ?></td>
                            <td><?= (int)$t['driver_count'] ?></td>
                            <td><?= (int)$t['sim_count'] ?></td>
                            <td><?= (int)$t['user_count'] ?></td>
                            <td><?= (int)$t['is_active'] === 1
                                ? '<span class="badge bg-success">Active</span>'
                                : '<span class="badge bg-secondary">Inactive</span>' ?></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="terminals.php?edit=<?= (int)$t['id'] ?>">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><?= $editRow !== null
                ? 'Edit: ' . e($editRow['name']) : 'Add terminal' ?></div>
            <div class="card-body">
                <form method="post" action="terminals.php">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $editRow !== null ? 'update' : 'create' ?>">
                    <?php if ($editRow !== null): ?>
                        <input type="hidden" name="terminal_id" value="<?= (int)$editRow['id'] ?>">
                    <?php endif; ?>
                    <div class="mb-2">
                        <label class="form-label">Name</label>
                        <input type="text" class="form-control" name="name" maxlength="60" required
                               value="<?= e($editRow['name'] ?? '') ?>" placeholder="e.g. Dammam">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Code</label>
                        <input type="text" class="form-control" name="code" maxlength="10" required
                               value="<?= e($editRow['code'] ?? '') ?>" placeholder="e.g. DMM">
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="is_active" id="ta"
                               value="1" <?= (int)($editRow['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="ta">Active</label>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <?= $editRow !== null ? 'Save' : 'Create' ?></button>
                    <?php if ($editRow !== null): ?>
                        <a class="btn btn-outline-secondary" href="terminals.php">Cancel</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>

