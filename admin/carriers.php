<?php
/**
 * SIMLedger - carrier master (admin only).
 * Carriers are configuration - never hard-coded in application logic.
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
    $cid    = reqInt('carrier_id', 0, 0, 100000000);
    $name   = trim((string)($_POST['name'] ?? ''));
    $active = ($_POST['is_active'] ?? '') === '1';

    try {
        if ($name === '' || strlen($name) > 60) {
            throw new RuntimeException('Name is required (max 60 chars).');
        }

        if ($action === 'create') {
            $stmt = $pdo->prepare('INSERT INTO carriers (name, is_active) VALUES (?, ?)');
            $stmt->execute([$name, $active ? 1 : 0]);
            $newId = (int)$pdo->lastInsertId();
            logAudit('CREATE_CARRIER', 'carrier', $newId, null, ['name' => $name]);
            setFlash('ok', 'Carrier created.');
        } elseif ($action === 'update') {
            $stmt = $pdo->prepare('UPDATE carriers SET name = ?, is_active = ? WHERE id = ?');
            $stmt->execute([$name, $active ? 1 : 0, $cid]);
            logAudit('UPDATE_CARRIER', 'carrier', $cid, null,
                ['name' => $name, 'active' => $active]);
            setFlash('ok', 'Carrier updated.');
        }

        header('Location: carriers.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
            $error = 'A carrier with that name already exists.';
        }
    }
}

$editId  = reqInt('edit', 0, 0, 100000000);
$editRow = null;
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM carriers WHERE id = ?');
    $stmt->execute([$editId]);
    $editRow = $stmt->fetch() ?: null;
}

$carriers = $pdo->query(
    'SELECT c.*,
            (SELECT COUNT(*) FROM company_sims s WHERE s.carrier_id = c.id) AS sim_count
       FROM carriers c ORDER BY c.name'
)->fetchAll();

$pageTitle = 'Carriers';
$activeNav = 'admin-carriers';
require __DIR__ . '/../includes/header.php';
?>
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">Carriers</div>
            <div class="card-body p-0">
                <table class="table table-striped mb-0">
                    <thead>
                    <tr><th>Name</th><th>SIMs</th><th>Status</th><th class="text-end">Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php if ($error !== ''): ?>
                        <tr><td colspan="4"><div class="alert-error"><?= e($error) ?></div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($carriers as $c): ?>
                        <tr>
                            <td><?= e($c['name']) ?></td>
                            <td><?= (int)$c['sim_count'] ?></td>
                            <td><?= (int)$c['is_active'] === 1
                                ? '<span class="badge bg-success">Active</span>'
                                : '<span class="badge bg-secondary">Inactive</span>' ?></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="carriers.php?edit=<?= (int)$c['id'] ?>">Edit</a>
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
                ? 'Edit: ' . e($editRow['name']) : 'Add carrier' ?></div>
            <div class="card-body">
                <form method="post" action="carriers.php">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $editRow !== null ? 'update' : 'create' ?>">
                    <?php if ($editRow !== null): ?>
                        <input type="hidden" name="carrier_id" value="<?= (int)$editRow['id'] ?>">
                    <?php endif; ?>
                    <div class="mb-2">
                        <label class="form-label">Name</label>
                        <input type="text" class="form-control" name="name" maxlength="60" required
                               value="<?= e($editRow['name'] ?? '') ?>" placeholder="e.g. STC">
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="is_active" id="ca"
                               value="1" <?= (int)($editRow['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="ca">Active</label>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <?= $editRow !== null ? 'Save' : 'Create' ?></button>
                    <?php if ($editRow !== null): ?>
                        <a class="btn btn-outline-secondary" href="carriers.php">Cancel</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
