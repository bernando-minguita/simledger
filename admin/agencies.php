<?php
/**
 * SIMLedger - driver agency master (admin only).
 *
 * Drivers are sourced from outsourcing providers (SMASCO / SRACO / TAMKEEN)
 * in addition to RT (the owning company's own drivers). Agencies are
 * configuration - never hard-coded in application logic.
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
    $aid    = reqInt('agency_id', 0, 0, 100000000);
    $name   = trim((string)($_POST['name'] ?? ''));
    $code   = strtoupper(trim((string)($_POST['code'] ?? '')));
    $active = ($_POST['is_active'] ?? '') === '1';

    try {
        if ($name === '' || strlen($name) > 60) {
            throw new RuntimeException('Name is required (max 60 chars).');
        }
        if ($code === '' || strlen($code) > 10 || !preg_match('/^[A-Z0-9_-]+$/', $code)) {
            throw new RuntimeException('Code is required (max 10 letters/digits, e.g. SMASCO).');
        }

        if ($action === 'create') {
            $stmt = $pdo->prepare('INSERT INTO agencies (name, code, is_active) VALUES (?, ?, ?)');
            $stmt->execute([$name, $code, $active ? 1 : 0]);
            $newId = (int)$pdo->lastInsertId();
            logAudit('CREATE_AGENCY', 'agency', $newId, null, ['name' => $name, 'code' => $code]);
            setFlash('ok', 'Agency created.');
        } elseif ($action === 'update') {
            $stmt = $pdo->prepare('UPDATE agencies SET name = ?, code = ?, is_active = ? WHERE id = ?');
            $stmt->execute([$name, $code, $active ? 1 : 0, $aid]);
            logAudit('UPDATE_AGENCY', 'agency', $aid, null,
                ['name' => $name, 'code' => $code, 'active' => $active]);
            setFlash('ok', 'Agency updated.');
        }

        header('Location: agencies.php');
        exit;
    } catch (Throwable $e) {
        $error = friendlyError($e, 'An agency with that name or code already exists.');
    }
}

$editId  = reqInt('edit', 0, 0, 100000000);
$editRow = null;
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM agencies WHERE id = ?');
    $stmt->execute([$editId]);
    $editRow = $stmt->fetch() ?: null;
}

$agencies = $pdo->query(
    'SELECT a.*,
            (SELECT COUNT(*) FROM drivers d WHERE d.agency_id = a.id) AS driver_count,
            (SELECT COUNT(*) FROM drivers d WHERE d.agency_id = a.id AND d.is_active = 1) AS active_drivers
       FROM agencies a ORDER BY a.name'
)->fetchAll();

$pageTitle = 'Agencies';
$activeNav = 'admin-agencies';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="page-title">Driver Agencies</h1>
        <p class="page-sub">Outsourcing providers (SMASCO, SRACO, TAMKEEN) plus RT &mdash;
            every driver belongs to exactly one agency.</p>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">Agencies</div>
            <div class="card-body p-0">
                <table class="table table-striped mb-0">
                    <thead>
                    <tr><th>Code</th><th>Name</th><th>Drivers</th><th>Active</th><th class="text-end">Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php if ($error !== ''): ?>
                        <tr><td colspan="5"><div class="alert-error"><?= e($error) ?></div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($agencies as $a): ?>
                        <tr>
                            <td><span class="badge bg-secondary"><?= e($a['code']) ?></span></td>
                            <td><?= e($a['name']) ?></td>
                            <td><?= (int)$a['driver_count'] ?>
                                <?php if ((int)$a['active_drivers'] < (int)$a['driver_count']): ?>
                                    <span class="small text-muted">(<?= (int)$a['active_drivers'] ?> active)</span>
                                <?php endif; ?>
                            </td>
                            <td><?= (int)$a['is_active'] === 1
                                ? '<span class="badge bg-success">Active</span>'
                                : '<span class="badge bg-secondary">Inactive</span>' ?></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="agencies.php?edit=<?= (int)$a['id'] ?>">Edit</a>
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
                ? 'Edit: ' . e($editRow['name']) : 'Add agency' ?></div>
            <div class="card-body">
                <form method="post" action="agencies.php">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $editRow !== null ? 'update' : 'create' ?>">
                    <?php if ($editRow !== null): ?>
                        <input type="hidden" name="agency_id" value="<?= (int)$editRow['id'] ?>">
                    <?php endif; ?>
                    <div class="mb-2">
                        <label class="form-label">Name</label>
                        <input type="text" class="form-control" name="name" maxlength="60" required
                               value="<?= e($editRow['name'] ?? '') ?>" placeholder="e.g. SMASCO">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Code</label>
                        <input type="text" class="form-control" name="code" maxlength="10" required
                               value="<?= e($editRow['code'] ?? '') ?>" placeholder="e.g. SMASCO"
                               style="text-transform:uppercase">
                        <div class="form-text">Short unique key used in CSV imports.</div>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="is_active" id="aa"
                               value="1" <?= (int)($editRow['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="aa">Active</label>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <?= $editRow !== null ? 'Save' : 'Create' ?></button>
                    <?php if ($editRow !== null): ?>
                        <a class="btn btn-outline-secondary" href="agencies.php">Cancel</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
