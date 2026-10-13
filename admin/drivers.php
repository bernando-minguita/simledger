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

// Dropdown (multi-select) filters - the same controls as the driver list page
// (drivers.php): Terminal / Agency / Status / Has company SIM. An empty
// selection means "no filter" (all records).
$statuses   = driverStatusOptions();
$fTerminals = array_map('intval', filterValues('terminal_id'));
$fAgencies  = array_map('intval', filterValues('agency_id'));
$fStatuses  = array_values(array_intersect(filterValues('status'), array_keys($statuses)));
$fHasSims   = array_values(array_intersect(filterValues('has_sim'), ['0', '1']));

// Filter state is preserved across redirects, links and the modal form action.
// edit / page / export are transient parameters and never carried over.
$filterParams = $_GET;
unset($filterParams['edit'], $filterParams['page'], $filterParams['export']);
$pageUrl = static function (array $extra = []) use ($filterParams): string {
    $qs = http_build_query(array_merge($filterParams, $extra));

    return 'drivers.php' . ($qs !== '' ? '?' . $qs : '');
};

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

        header('Location: ' . $pageUrl());
        exit;
    } catch (Throwable $e) {
        $error = friendlyError($e, 'A driver with that badge number or Iqama number already exists.');
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
if ($fTerminals !== []) {
    $where .= ' AND d.terminal_id IN (' . implode(',', array_fill(0, count($fTerminals), '?')) . ')';
    array_push($params, ...$fTerminals);
}
if ($fAgencies !== []) {
    $where .= ' AND d.agency_id IN (' . implode(',', array_fill(0, count($fAgencies), '?')) . ')';
    array_push($params, ...$fAgencies);
}
if ($fStatuses !== []) {
    $where .= ' AND d.status IN (' . implode(',', array_fill(0, count($fStatuses), '?')) . ')';
    array_push($params, ...$fStatuses);
}
// has_sim is binary: ticking both boxes means "either", so only a single
// selection actually narrows the result set.
if ($fHasSims === ['1']) {
    $where .= ' AND EXISTS(SELECT 1 FROM driver_sim_assignments a2
                             WHERE a2.driver_id = d.id AND a2.returned_at IS NULL)';
} elseif ($fHasSims === ['0']) {
    $where .= ' AND NOT EXISTS(SELECT 1 FROM driver_sim_assignments a2
                                WHERE a2.driver_id = d.id AND a2.returned_at IS NULL)';
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

// --- Excel export (admin only; honours the active search + dropdown filters) -
// Exports every filtered driver (not just the current page) so the file can be
// edited offline and re-imported. Current assignment columns are context only:
// issuing / returning / transferring a SIM stays in the audited transaction
// pages because driver_sim_assignments is append-only history.
if (($_GET['export'] ?? '') === '1') {
    $simByDriver = [];
    foreach ($pdo->query(
        'SELECT a.driver_id, s.mobile_number, s.status AS sim_status, a.issued_at
           FROM driver_sim_assignments a
           JOIN company_sims s ON s.id = a.sim_id
          WHERE a.returned_at IS NULL'
    ) as $asg) {
        $simByDriver[(int)$asg['driver_id']] = $asg;
    }

    $expStmt = $pdo->prepare($baseSql . ' ORDER BY ' . $sortMap[$sort] . ' ' . $dir);
    $expStmt->execute($params);

    $exportRows = [];
    foreach ($expStmt as $d) {
        $asg = $simByDriver[(int)$d['id']] ?? null;
        $exportRows[] = [
            (int)$d['id'],
            csvSafe((string)$d['badge_number']),
            csvSafe((string)$d['name']),
            csvSafe((string)$d['terminal_name']),
            csvSafe((string)($d['agency_name'] ?? '')),
            csvSafe((string)($d['iqama_number'] ?? '')),
            csvSafe((string)($d['nationality'] ?? '')),
            $statuses[$d['status']] ?? (string)$d['status'],
            (int)$d['is_active'] === 1 ? 'Yes' : 'No',
            $asg !== null ? csvSafe((string)$asg['mobile_number']) : '',
            $asg !== null ? csvSafe((string)$asg['sim_status']) : '',
            $asg !== null ? date('Y-m-d H:i', strtotime((string)$asg['issued_at'])) : '',
            csvSafe((string)($d['external_driver_id'] ?? '')),
            csvSafe((string)($d['notes'] ?? '')),
        ];
    }

    exportExcel(
        'Driver-Management-' . date('Ymd-His') . '.xlsx',
        'Driver Management',
        ['Driver ID', 'Badge Number', 'Driver Name', 'Terminal', 'Agency', 'Iqama Number',
         'Nationality', 'Status', 'Active', 'Current SIM', 'SIM Status', 'SIM Issued',
         'External Driver ID', 'Notes'],
        $exportRows
    );
}

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

// Per-row payload for the create/edit modal (filled client-side, no reload).
$driverPayload = static fn (array $d): array => [
    'id'                 => (int)$d['id'],
    'badge_number'       => (string)$d['badge_number'],
    'name'               => (string)$d['name'],
    'terminal_id'        => (int)$d['terminal_id'],
    'agency_id'          => $d['agency_id'] !== null ? (int)$d['agency_id'] : 0,
    'iqama_number'       => (string)($d['iqama_number'] ?? ''),
    'nationality'        => (string)($d['nationality'] ?? ''),
    'status'             => (string)$d['status'],
    'external_driver_id' => (string)($d['external_driver_id'] ?? ''),
    'notes'              => (string)($d['notes'] ?? ''),
    'active'             => (int)$d['is_active'],
];

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
    <!-- Full-width list; create / edit live in the modal at the bottom. -->
    <div class="col-12">
        <div class="card" id="drivers-partial" data-tt-partial>
            <div class="card-header d-flex align-items-center gap-2">
                <span class="me-auto">Drivers</span>
                <!-- table-toolkit.js appends the "Columns" show/hide menu as the
                     last header child; me-auto + gap groups these together:
                     Drivers .... [Export Excel] [New driver] [Columns] -->
                <a class="btn btn-outline-secondary btn-sm"
                   href="../import.php?type=drivers-update">Bulk update&hellip;</a>
                <a class="btn btn-outline-secondary btn-sm"
                   href="<?= e($pageUrl(['export' => 1])) ?>">Export Excel</a>
                <button type="button" class="btn btn-primary btn-sm"
                        data-action="create-driver">New driver</button>
            </div>
            <div class="card-body py-2 border-bottom">
                <!-- One GET form so the search box and every dropdown filter
                     submit together. Unticked = "All" = no filter. -->
                <form method="get" action="drivers.php" class="row g-2 align-items-end"
                      data-tt-target="drivers-partial">
                    <div class="col-auto">
                        <label class="form-label small mb-1">Search</label>
                        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Badge / name"
                               class="form-control form-control-sm" style="width:190px">
                    </div>
                    <div class="col-auto">
                        <label class="form-label small mb-1">Terminal</label>
                        <?= checkboxFilterGroup('terminal_id', terminalOptions(false),
                            array_map('strval', $fTerminals)) ?>
                    </div>
                    <div class="col-auto">
                        <label class="form-label small mb-1">Agency</label>
                        <?= checkboxFilterGroup('agency_id', agencyOptions(false),
                            array_map('strval', $fAgencies)) ?>
                    </div>
                    <div class="col-auto">
                        <label class="form-label small mb-1">Status</label>
                        <?= checkboxFilterGroup('status', $statuses, $fStatuses) ?>
                    </div>
                    <div class="col-auto">
                        <label class="form-label small mb-1">Has company SIM</label>
                        <?= checkboxFilterGroup('has_sim', ['1' => 'Yes', '0' => 'No'], $fHasSims) ?>
                    </div>
                    <div class="col-auto d-flex gap-2">
                        <button class="btn btn-sm btn-outline-primary" type="submit">Apply</button>
                        <a class="btn btn-sm btn-outline-secondary" href="drivers.php">Reset</a>
                    </div>
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
                        <?php if ($drivers === []): ?>
                            <tr><td colspan="8" class="text-center text-muted py-3">
                                No drivers match the current filters.</td></tr>
                        <?php endif; ?>
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
                                    <button type="button" class="btn btn-sm btn-outline-secondary"
                                            data-action="edit-driver" data-id="<?= (int)$d['id'] ?>"
                                            data-driver='<?= json_encode($driverPayload($d),
                                                JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_HEX_TAG) ?>'>Edit</button>
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

</div><!-- /.row - create/edit live in the modal below -->

<!-- create / edit modal -->
<div class="modal fade" id="driverModal" tabindex="-1" aria-hidden="true"
     data-open="<?= $editRow !== null ? 'edit-' . (int)$editRow['id'] : ($error !== '' ? 'create' : '') ?>">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form method="post" action="<?= e($pageUrl()) ?>" id="dm-form" class="modal-content">
            <?= csrfField() ?>
            <input type="hidden" name="action" id="dm-action"
                   value="<?= $editRow !== null ? 'update' : 'create' ?>">
            <input type="hidden" name="driver_id" id="dm-driver"
                   value="<?= $editRow !== null ? (int)$editRow['id'] : '' ?>">
            <div class="modal-header">
                <h5 class="modal-title"><?= $editRow !== null
                    ? 'Edit driver: ' . e($editRow['name']) : 'Create driver' ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?php if ($error !== ''): ?>
                    <div class="alert-error mb-3"><?= e($error) ?></div>
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
            </div><!-- /.modal-body -->
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary" id="dm-submit">
                    <?= $editRow !== null ? 'Save changes' : 'Create driver' ?>
                </button>
                <button type="button" class="btn btn-outline-secondary"
                        data-bs-dismiss="modal">Cancel</button>
            </div>
        </form>
    </div>
</div>
<script>
/* Instant create/edit modal - no page reloads, no flicker.
   Each Edit button carries its row as JSON in data-driver; clicking fills the
   shared #driverModal and shows it in place. bootstrap.bundle.min.js loads in
   includes/footer.php BELOW this block, so all Bootstrap access is deferred to
   DOMContentLoaded. A failed save re-renders with data-open="edit-ID" /
   "create" so the modal reopens automatically. FILTERS keeps the active
   search + dropdown filters in the POST action and the visible URL. */
(function () {
    'use strict';

    var FILTERS = <?= json_encode($pageUrl()) ?>;

    function boot() {
        var modalEl = document.getElementById('driverModal');
        if (!modalEl || !window.bootstrap) { return; }

        var modal     = new bootstrap.Modal(modalEl);
        var form      = document.getElementById('dm-form');
        var actionIn  = document.getElementById('dm-action');
        var driverIn  = document.getElementById('dm-driver');
        var title     = modalEl.querySelector('.modal-title');
        var submitBtn = document.getElementById('dm-submit');

        function openDriverModal(data) {
            var el = form.elements;

            if (data) {
                title.textContent = 'Edit driver: ' + data.name;
                actionIn.value = 'update';
                driverIn.value = String(data.id);
                form.action = FILTERS + (FILTERS.indexOf('?') === -1 ? '?' : '&')
                    + 'edit=' + data.id;
                el['badge_number'].value       = data.badge_number;
                el['name'].value               = data.name;
                el['iqama_number'].value       = data.iqama_number;
                el['nationality'].value        = data.nationality;
                el['status'].value             = data.status;
                el['external_driver_id'].value = data.external_driver_id;
                el['notes'].value              = data.notes;
                el['agency_id'].value          = data.agency_id ? String(data.agency_id) : '';
                // A terminal-scoped user gets a locked hidden input, not a select.
                if (el['terminal_id'].tagName === 'SELECT') {
                    el['terminal_id'].value = data.terminal_id ? String(data.terminal_id) : '';
                }
                el['is_active'].checked = !!data.active;
                submitBtn.textContent = 'Save changes';
            } else {
                title.textContent = 'Create driver';
                form.action = FILTERS;
                form.reset();                       // restore server-rendered defaults...
                actionIn.value = 'create';          // ...then force create mode (an ?edit=
                driverIn.value = '';                // page renders update/N as defaults)
                ['badge_number', 'name', 'iqama_number', 'nationality',
                 'external_driver_id', 'notes'].forEach(function (n) { el[n].value = ''; });
                el['status'].value = 'active';
                el['agency_id'].value = '';
                if (el['terminal_id'].tagName === 'SELECT') { el['terminal_id'].value = ''; }
                el['is_active'].checked = true;
                submitBtn.textContent = 'Create driver';
            }
            history.replaceState({}, '', FILTERS);
            modal.show();
        }

        document.querySelectorAll('[data-action="edit-driver"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                openDriverModal(JSON.parse(btn.getAttribute('data-driver')));
            });
        });
        document.querySelectorAll('[data-action="create-driver"]').forEach(function (btn) {
            btn.addEventListener('click', function () { openDriverModal(null); });
        });

        // Server-driven reopen (failed save, or a ?edit= deep link). The row may
        // be filtered out of the current page - the server-rendered form already
        // holds the right values, so just show it.
        var open = modalEl.getAttribute('data-open') || '';
        if (open.indexOf('edit-') === 0) {
            var src = document.querySelector(
                '[data-action="edit-driver"][data-id="' + open.slice(5) + '"]');
            if (src) {
                openDriverModal(JSON.parse(src.getAttribute('data-driver')));
            } else {
                history.replaceState({}, '', FILTERS);
                modal.show();
            }
        } else if (open === 'create') {
            openDriverModal(null);
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
