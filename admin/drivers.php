<?php
/**
 * SIMLedger - driver master CRUD (admin only).
 *
 * The driver master is designed for future synchronization: external_driver_id
 * holds the stable identifier from an external Address Book / HR system.
 * Deleting drivers is a soft deactivation - assignment history must survive.
 */

declare(strict_types=1);

$base = '../';

require_once __DIR__ . '/../includes/lookups.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/services/SimService.php';

$pdo = $GLOBALS['pdo'];
requirePermission('driver.manage');

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrfCheck();

    $action   = (string)($_POST['action'] ?? '');
    $driverId = reqInt('driver_id', 0, 0, 100000000);
    $badge    = trim((string)($_POST['badge_number'] ?? ''));
    $name     = trim((string)($_POST['name'] ?? ''));
    $termRaw  = $_POST['terminal_id'] ?? '';
    $termId   = $termRaw !== '' && $termRaw !== null ? (int)$termRaw : 0;
    $agencyRaw  = $_POST['agency_id'] ?? '';
    $agencyId   = $agencyRaw !== '' && $agencyRaw !== null ? (int)$agencyRaw : 0;
    $iqama      = trim((string)($_POST['iqama_number'] ?? ''));
    $nationality = trim((string)($_POST['nationality'] ?? ''));
    $status   = (string)($_POST['status'] ?? 'active');
    $external = trim((string)($_POST['external_driver_id'] ?? ''));
    $active   = ($_POST['is_active'] ?? '') === '1';
    $notes    = cleanStr($_POST['notes'] ?? null, 255);

    try {
        if ($action === 'create' || $action === 'update') {
            if ($badge === '' || strlen($badge) > 30) {
                throw new RuntimeException('Badge number is required (max 30 chars).');
            }
            if ($name === '' || strlen($name) > 120) {
                throw new RuntimeException('Driver name is required (max 120 chars).');
            }
            if ($termId <= 0) {
                throw new RuntimeException('Terminal is required.');
            }
            if ($agencyId <= 0) {
                throw new RuntimeException('Agency is required.');
            }
            if (strlen($iqama) > 20) {
                throw new RuntimeException('Iqama number must be at most 20 characters.');
            }
            if (strlen($nationality) > 60) {
                throw new RuntimeException('Nationality must be at most 60 characters.');
            }
            assertTerminalAccess($termId);
            if (!isset(driverStatusOptions()[$status])) {
                throw new RuntimeException('Invalid status.');
            }
        }

        if ($action === 'create') {
            $stmt = $pdo->prepare(
                'INSERT INTO drivers (badge_number, name, terminal_id, agency_id, status,
                                      iqama_number, nationality, external_driver_id, is_active, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $badge, $name, $termId, $agencyId, $status,
                $iqama !== '' ? $iqama : null, $nationality !== '' ? $nationality : null,
                $external !== '' ? $external : null, $active ? 1 : 0, $notes,
            ]);
            $newId = (int)$pdo->lastInsertId();
            logAudit('CREATE_DRIVER', 'driver', $newId, null,
                ['badge' => $badge, 'name' => $name, 'terminal_id' => $termId]);
            setFlash('ok', 'Driver created.');
        } elseif ($action === 'update') {
            $oldStmt = $pdo->prepare('SELECT * FROM drivers WHERE id = ?');
            $oldStmt->execute([$driverId]);
            $old = $oldStmt->fetch();

            if ($old === false) {
                throw new RuntimeException('Driver not found.');
            }
            assertTerminalAccess((int)$old['terminal_id']);

            $stmt = $pdo->prepare(
                'UPDATE drivers
                    SET badge_number = ?, name = ?, terminal_id = ?, agency_id = ?, status = ?,
                        iqama_number = ?, nationality = ?,
                        external_driver_id = ?, is_active = ?, notes = ?
                  WHERE id = ?'
            );
            $stmt->execute([
                $badge, $name, $termId, $agencyId, $status,
                $iqama !== '' ? $iqama : null, $nationality !== '' ? $nationality : null,
                $external !== '' ? $external : null, $active ? 1 : 0, $notes, $driverId,
            ]);

            logAudit('UPDATE_DRIVER', 'driver', $driverId,
                ['badge' => $old['badge_number'], 'name' => $old['name'],
                 'status' => $old['status'], 'terminal_id' => (int)$old['terminal_id']],
                ['badge' => $badge, 'name' => $name, 'status' => $status,
                 'terminal_id' => $termId, 'active' => $active]);
            setFlash('ok', 'Driver updated.');
        } elseif ($action === 'deactivate') {
            // Soft delete only - assignment history is never removed.
            // Offboarding safeguard: a driver who still holds a company SIM
            // can only be deactivated with a documented reason (audited).
            $hasStmt = $pdo->prepare(
                'SELECT s.mobile_number FROM driver_sim_assignments a
                   JOIN company_sims s ON s.id = a.sim_id
                  WHERE a.driver_id = ? AND a.returned_at IS NULL
                  LIMIT 1'
            );
            $hasStmt->execute([$driverId]);
            $outstanding = $hasStmt->fetch();
            $reason = cleanStr($_POST['deactivation_reason'] ?? null, 255);

            if ($outstanding !== false && trim((string)($reason ?? '')) === '') {
                throw new RuntimeException(
                    'This driver still holds SIM ' . $outstanding['mobile_number']
                    . '. Return, transfer or report it first - or provide a reason '
                    . 'to deactivate with the SIM outstanding.'
                );
            }

            $stmt = $pdo->prepare('UPDATE drivers SET is_active = 0, status = ? WHERE id = ?');
            $stmt->execute([$status !== 'active' ? $status : 'inactive', $driverId]);
            logAudit('UPDATE_DRIVER', 'driver', $driverId, ['is_active' => 1],
                ['is_active' => 0, 'soft_delete' => true,
                 'outstanding_sim' => $outstanding !== false ? $outstanding['mobile_number'] : null,
                 'reason' => $reason]);
            setFlash('ok', $outstanding === false
                ? 'Driver deactivated (history preserved).'
                : 'Driver deactivated with an outstanding SIM - the reason was recorded.');
        }

        header('Location: drivers.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
            $error = 'A driver with that badge number or Iqama number already exists.';
        }
    }
}

// --- GET data ---------------------------------------------------------------
$q      = trim((string)($_GET['q'] ?? ''));
$where  = ' WHERE 1=1';
$params = [];
if ($q !== '') {
    $where .= ' AND (d.badge_number LIKE ? OR d.name LIKE ?)';
    array_push($params, '%' . $q . '%', '%' . $q . '%');
}
$baseSql = 'SELECT d.*, t.name AS terminal_name, ag.name AS agency_name,
            EXISTS(SELECT 1 FROM driver_sim_assignments a
                    WHERE a.driver_id = d.id AND a.returned_at IS NULL) AS has_sim
       FROM drivers d JOIN terminals t ON t.id = d.terminal_id
  LEFT JOIN agencies ag ON ag.id = d.agency_id
      ' . $where;

$sortMap = [
    'badge'    => 'd.badge_number',
    'name'     => 'd.name',
    'agency'   => 'ag.name',
    'terminal' => 't.name',
    'status'   => 'd.status',
];
$sort    = reqSort('sort', array_keys($sortMap), 'badge');
$dir     = reqDir('asc');
$page    = reqInt('page', 1, 1, 100000);
$perPage = 25;

$cntStmt = $pdo->prepare('SELECT COUNT(*) FROM (' . $baseSql . ') cnt');
$cntStmt->execute($params);
$total  = (int)$cntStmt->fetchColumn();
$pages  = max(1, (int)ceil($total / $perPage));
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare($baseSql . ' ORDER BY ' . $sortMap[$sort] . ' ' . $dir
    . " LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$drivers = $stmt->fetchAll();

$editId  = reqInt('edit', 0, 0, 100000000);
$editRow = null;
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM drivers WHERE id = ?');
    $stmt->execute([$editId]);
    $editRow = $stmt->fetch() ?: null;
    if ($editRow !== null) {
        assertTerminalAccess((int)$editRow['terminal_id']);
    }
}

$statuses = driverStatusOptions();

$pageTitle = 'Driver Management';
$activeNav = 'admin-drivers';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="page-title">Driver Management</h1>
        <p class="page-sub">Master records &mdash; drivers are soft-deactivated, never deleted.</p>
    </div>
    <a class="btn btn-link btn-sm" href="../drivers.php">Back to driver list</a>
</div>


<div class="row g-3">
    <div class="col-lg-7">
        <div class="card" id="drivers-partial" data-tt-partial>
            <div class="card-header">
                Drivers
                <form method="get" class="d-inline float-end" data-tt-target="drivers-partial">
                    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search badge / name"
                           class="form-control form-control-sm d-inline-block" style="width:200px">
                    <button class="btn btn-sm btn-outline-primary" type="submit">Search</button>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped mb-0 dt-nowrap" data-tt-sort="server"
                           data-offset="<?= $offset ?>">
                        <thead>
                        <tr>
                            <th data-sort="badge">Badge</th><th data-sort="name">Name</th>
                            <th data-sort="agency">Agency</th><th data-sort="terminal">Terminal</th>
                            <th data-sort="status">Status</th>
                            <th data-no-sort="1">SIM</th><th data-no-sort="1">Ext. ID</th>
                            <th class="text-end" data-no-sort="1">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($drivers as $d): ?>
                            <tr<?= (int)$d['is_active'] === 1 ? '' : ' class="table-secondary"' ?>>
                                <td><?= e($d['badge_number']) ?></td>
                                <td><?= e($d['name']) ?></td>
                                <td><?= e($d['agency_name'] ?? '—') ?></td>
                                <td><?= e($d['terminal_name']) ?></td>
                                <td><span class="badge <?= $d['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>">
                                    <?= e($statuses[$d['status']] ?? $d['status']) ?></span></td>
                                <td><?= $d['has_sim']
                                    ? '<span class="badge bg-info text-dark">Has SIM</span>' : '' ?>
                                    <?= (int)$d['is_active'] === 1 ? '' : '<span class="badge bg-dark">Inactive</span>' ?></td>
                                <td class="small"><?= e($d['external_driver_id'] ?? '—') ?></td>
                                <td class="text-end text-nowrap">
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="drivers.php?edit=<?= (int)$d['id'] ?>">Edit</a>
                                    <?php if ((int)$d['is_active'] === 1): ?>
                                        <form method="post" action="drivers.php" class="d-inline"
                                              data-confirm-danger
                                              data-confirm="Deactivate this driver? Existing SIM history is preserved."
<?php if ((int)$d['has_sim'] === 1): ?>
                                              data-prompt="deactivation_reason"
                                              data-prompt-label="This driver still holds a company SIM. Enter a reason to deactivate anyway (recorded in the audit log):"
<?php endif; ?>
                                        >
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="deactivate">
                                            <input type="hidden" name="driver_id" value="<?= (int)$d['id'] ?>">
                                            <input type="hidden" name="status" value="<?= e($d['status']) ?>">
                                            <input type="hidden" name="deactivation_reason" value="">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Deactivate</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-between align-items-center">
                <span class="small text-muted">
                    Showing <?= count($drivers) ?> of <?= $total ?> &middot; page <?= $page ?> of <?= $pages ?>
                </span>
                <?php renderPagination($page, $pages, 'drivers.php?' . filtersUrl()); ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><?= $editRow !== null
                ? 'Edit: ' . e($editRow['name']) : 'Create driver' ?></div>
            <div class="card-body">
                <?php if ($error !== ''): ?>
                    <div class="alert-error mb-3"><?= e($error) ?></div>
                <?php endif; ?>
                <form method="post" action="drivers.php">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $editRow !== null ? 'update' : 'create' ?>">
                    <?php if ($editRow !== null): ?>
                        <input type="hidden" name="driver_id" value="<?= (int)$editRow['id'] ?>">
                    <?php endif; ?>
                    <div class="mb-2">
                        <label class="form-label">Badge number</label>
                        <input type="text" class="form-control" name="badge_number" maxlength="30" required
                               value="<?= e($editRow['badge_number'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Driver name</label>
                        <input type="text" class="form-control" name="name" maxlength="120" required
                               value="<?= e($editRow['name'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Terminal</label>
                        <?= terminalSelect($editRow['terminal_id'] ?? null) ?>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Agency</label>
                        <select class="form-select" name="agency_id" required>
                            <option value="">Select agency&hellip;</option>
                            <?php foreach (agencyOptions(false) as $agid => $agname): ?>
                                <option value="<?= $agid ?>"
                                    <?= (int)($editRow['agency_id'] ?? 0) === $agid ? 'selected' : '' ?>><?= e($agname) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">RT = own company; SMASCO / SRACO / TAMKEEN are outsourced providers.</div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Iqama number (residency permit)</label>
                        <input type="text" class="form-control" name="iqama_number" maxlength="20"
                               value="<?= e($editRow['iqama_number'] ?? '') ?>" placeholder="2XXXXXXXXX">
                        <div class="form-text">Optional; must be unique when present.</div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Nationality</label>
                        <input type="text" class="form-control" name="nationality" maxlength="60"
                               value="<?= e($editRow['nationality'] ?? '') ?>" placeholder="e.g. Saudi">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <?php foreach ($statuses as $skey => $slabel): ?>
                                <option value="<?= e($skey) ?>"
                                    <?= ($editRow['status'] ?? 'active') === $skey ? 'selected' : '' ?>>
                                    <?= e($slabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">External driver ID (future sync)</label>
                        <input type="text" class="form-control" name="external_driver_id" maxlength="60"
                               value="<?= e($editRow['external_driver_id'] ?? '') ?>"
                               placeholder="Stable ID from Address Book / HR">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Notes</label>
                        <input type="text" class="form-control" name="notes" maxlength="255"
                               value="<?= e($editRow['notes'] ?? '') ?>">
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="is_active" id="da"
                               value="1" <?= (int)($editRow['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="da">Active</label>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <?= $editRow !== null ? 'Save changes' : 'Create driver' ?></button>
                    <?php if ($editRow !== null): ?>
                        <a class="btn btn-outline-secondary" href="drivers.php">Cancel</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
