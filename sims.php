<?php
/**
 * SIMLedger - company SIM inventory.
 *
 * Search (number / ICCID / reference), terminal/carrier/status filters,
 * sorting, pagination, CSV export. Status changes here are admin-only
 * overrides - normal flow is through Issue/Return/Transfer transactions.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/lookups.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/services/SimService.php';
requirePermission('sim.view');

$pdo = $GLOBALS['pdo'];

$scope   = currentTerminalId();
// "Add SIM" opens admin/sims.php, whose gate (and sidebar nav entry) is
// reference.manage - use the permission, not the role, so granted users match.
$canAdd  = can('reference.manage');

// --- filters ---------------------------------------------------------------
$q         = trim((string)($_GET['q'] ?? ''));
$fTerminal = $scope ?? reqInt('terminal_id', 0, 0, 100000);
$fCarrier  = reqInt('carrier_id', 0, 0, 100000);
$fStatus   = (string)($_GET['status'] ?? '');
if (!in_array($fStatus, array_keys(simStatusOptions()), true)) {
    $fStatus = '';
}
if ($scope !== null) {
    $fTerminal = $scope;
}

$sortMap = [
    'number'   => 's.mobile_number',
    'iccid'    => 's.iccid',
    'status'   => 's.status',
    'terminal' => 't.name',
    'carrier'  => 'c.name',
    'driver'   => 'd.name',
    'issued'   => 'a.issued_at',
    'created'  => 's.created_at',
    'sim_ref'  => 's.sim_reference',
    'notes'    => 's.notes',
];
$sort    = reqSort('sort', array_keys($sortMap), 'number');
$dir     = reqDir('asc');
$page    = reqInt('page', 1, 1, 100000);
$perPage = 25;

$where  = ' WHERE 1=1';
$params = [];

if ($q !== '') {
    $where .= ' AND (s.mobile_number LIKE ? OR s.normalized_mobile_number LIKE ?'
        . ' OR s.iccid LIKE ? OR s.sim_reference LIKE ?)';
    $like = '%' . $q . '%';
    $qNorm = normalizePhone($q);
    array_push($params, $like, '%' . ($qNorm ?? $q) . '%', $like, $like);
}
if ($fTerminal > 0) {
    $where .= ' AND s.terminal_id = ?';
    $params[] = $fTerminal;
}
if ($fCarrier > 0) {
    $where .= ' AND s.carrier_id = ?';
    $params[] = $fCarrier;
}
if ($fStatus !== '') {
    $where .= ' AND s.status = ?';
    $params[] = $fStatus;
}

$from = ' FROM company_sims s
          JOIN terminals t ON t.id = s.terminal_id
          JOIN carriers c ON c.id = s.carrier_id
          LEFT JOIN driver_sim_assignments a ON a.sim_id = s.id AND a.returned_at IS NULL
          LEFT JOIN drivers d ON d.id = a.driver_id';


// --- admin status override (POST) ------------------------------------------
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrfCheck();
    requirePermission('reference.manage');

    $simId  = reqInt('sim_id', 0, 1, 100000000);
    $action = (string)($_POST['action'] ?? '');

    try {
        $svc = new SimService($pdo);

        if ($action === 'set_status') {
            $target = (string)($_POST['target_status'] ?? '');
            if (!in_array($target, array_keys(simStatusOptions()), true)) {
                throw new RuntimeException('Invalid status.');
            }

            $stmt = $pdo->prepare('SELECT status FROM company_sims WHERE id = ?');
            $stmt->execute([$simId]);
            $current = (string)$stmt->fetchColumn();

            if ($current === $target) {
                throw new RuntimeException('The SIM already has that status.');
            }

            if ($target === 'retired') {
                $svc->retire($simId, cleanStr($_POST['reason'] ?? null, 255), (int)$_SESSION['user_id']);
            } elseif ($current === 'assigned') {
                // Leaving "assigned" always goes through a closing transaction.
                if (!in_array($target, ['lost', 'damaged', 'suspended'], true)) {
                    throw new RuntimeException(
                        'Return or replace this SIM first - a SIM with an open'
                        . ' assignment cannot be set to "'
                        . (simStatusOptions()[$target] ?? $target) . '".'
                    );
                }
                $svc->markLostDamaged(
                    $simId, $target, date('Y-m-d'),
                    cleanStr($_POST['reason'] ?? null, 255),
                    cleanStr($_POST['remarks'] ?? null, 255),
                    (int)$_SESSION['user_id']
                );
            } elseif (in_array($target, ['suspended', 'available', 'lost', 'damaged'], true)) {
                // Controlled override of an unassigned SIM (admin only).
                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    'UPDATE company_sims SET status = ?, updated_by = ? WHERE id = ?'
                );
                $stmt->execute([$target, (int)$_SESSION['user_id'], $simId]);
                logAudit('UPDATE_SIM', 'sim', $simId,
                    ['status' => $current],
                    ['status' => $target, 'override' => true,
                     'reason' => cleanStr($_POST['reason'] ?? null, 60)]);
                $pdo->commit();
            } else {
                throw new RuntimeException('Unsupported status transition.');
            }

            setFlash('ok', 'SIM status updated.');
        } elseif ($action === 'update_notes') {
            $stmt = $pdo->prepare('SELECT notes FROM company_sims WHERE id = ?');
            $stmt->execute([$simId]);
            $oldNotes = $stmt->fetchColumn();
            if ($oldNotes === false) {
                throw new RuntimeException('SIM not found.');
            }
            $newNotes = cleanStr($_POST['notes'] ?? null, 255);

            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'UPDATE company_sims SET notes = ?, updated_by = ? WHERE id = ?'
            );
            $stmt->execute([$newNotes, (int)$_SESSION['user_id'], $simId]);
            logAudit('UPDATE_SIM', 'sim', $simId,
                ['notes' => $oldNotes], ['notes' => $newNotes]);
            $pdo->commit();
            setFlash('ok', 'Notes updated.');
        }

        header('Location: sims.php?' . filtersUrl());
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// --- counts + rows ---------------------------------------------------------
$countStmt = $pdo->prepare('SELECT COUNT(DISTINCT s.id)' . $from . $where);
$countStmt->execute($params);
$total  = (int)$countStmt->fetchColumn();
$pages  = max(1, (int)ceil($total / $perPage));
$page   = min($page, $pages);
$offset = ($page - 1) * $perPage;

$sql = 'SELECT s.id, s.sim_reference, s.mobile_number, s.normalized_mobile_number, s.iccid,
               s.status, s.notes, s.created_at,
               t.name AS terminal_name, c.name AS carrier_name,
               d.name AS driver_name, d.badge_number, a.issued_at
        ' . $from . $where
        . ' GROUP BY s.id, t.id, c.id, a.id, d.id'
        . ' ORDER BY ' . $sortMap[$sort] . ' ' . $dir
        . ' LIMIT ' . $perPage . ' OFFSET ' . $offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// --- CSV export ------------------------------------------------------------
if (isset($_GET['export'])) {
    $expStmt = $pdo->prepare(
        'SELECT s.sim_reference, s.mobile_number, s.iccid, c.name AS carrier_name,
                t.name AS terminal_name, s.status, d.badge_number, d.name AS driver_name,
                a.issued_at, s.notes, s.created_at
         ' . $from . $where . ' GROUP BY s.id, t.id, c.id, a.id, d.id ORDER BY '
         . $sortMap[$sort] . ' ' . $dir
    );
    $expStmt->execute($params);

    $exportRows = [];
    while ($r = $expStmt->fetch()) {
        $exportRows[] = [
            csvSafe((string)$r['sim_reference']),
            csvSafe((string)$r['mobile_number']),
            csvSafe((string)($r['iccid'] ?? '')),
            $r['carrier_name'],
            $r['terminal_name'],
            $r['status'],
            csvSafe((string)($r['badge_number'] ?? '')),
            csvSafe((string)($r['driver_name'] ?? '')),
            $r['issued_at'] ?? '',
            csvSafe((string)($r['notes'] ?? '')),
            $r['created_at'],
        ];
    }

    exportExcel('sim-inventory-' . date('Ymd-His') . '.xlsx', 'SIM Inventory',
        ['SIM ID', 'Mobile Number', 'ICCID', 'Carrier', 'Terminal', 'Status',
         'Current Badge', 'Current Driver', 'Issued At', 'Notes', 'Created At'],
        $exportRows);
}

$pageTitle = 'SIM Inventory';
$activeNav = 'sim-inventory';
require __DIR__ . '/includes/header.php';

$baseUrl = 'sims.php?' . filtersUrl();
?>
<?php if ($error !== ''): ?>
    <div class="alert-error mb-3"><?= e($error) ?></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="page-title">SIM Inventory</h1>
        <p class="page-sub"><?= $total ?> SIM record<?= $total === 1 ? '' : 's' ?> &middot;
            company-owned numbers only (drivers never store these)</p>
    </div>
    <div>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e($baseUrl . '&export=1') ?>">Export Excel</a>
        <?php if ($canAdd): ?>
            <a class="btn btn-primary btn-sm" href="admin/sims.php">Add SIM</a>
        <?php endif; ?>
    </div>
</div>

<form method="get" class="filter-bar mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label">Search</label>
            <input type="text" class="form-control" name="q" value="<?= e($q) ?>"
                   placeholder="Number / ICCID / SIM ID">
        </div>
        <div class="col-md-2">
            <label class="form-label">Terminal</label>
            <?php if ($scope !== null): ?>
                <input type="text" class="form-control" value="<?= e(terminalOptions()[$scope] ?? '') ?>" disabled>
            <?php else: ?>
                <select class="form-select" name="terminal_id">
                    <option value="">All</option>
                    <?php foreach (terminalOptions(false) as $tid => $tname): ?>
                        <option value="<?= $tid ?>"<?= $fTerminal === $tid ? ' selected' : '' ?>><?= e($tname) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </div>
        <div class="col-md-2">
            <label class="form-label">Carrier</label>
            <select class="form-select" name="carrier_id">
                <option value="">All</option>
                <?php foreach (carrierOptions() as $cid => $cname): ?>
                    <option value="<?= $cid ?>"<?= $fCarrier === $cid ? ' selected' : '' ?>><?= e($cname) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Status</label>
            <select class="form-select" name="status">
                <option value="">All</option>
                <?php foreach (simStatusOptions() as $skey => $slabel): ?>
                    <option value="<?= e($skey) ?>"<?= $fStatus === $skey ? ' selected' : '' ?>><?= e($slabel) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <button class="btn btn-outline-primary btn-sm" type="submit">Search</button>
            <a class="btn btn-link btn-sm" href="sims.php">Reset</a>
        </div>
    </div>
</form>

<?php
// Sortable header helper: the table toolkit turns data-sort into click-to-
// sort (?sort=<key>&dir= in the URL - the server sorts across all pages).
$th = static function (string $key, string $label, string $extra = ''): string {
    return '<th data-sort="' . e($key) . '"' . $extra . '>' . e($label) . '</th>';
};
?>
<div class="card table-card" id="table-partial">
    <div class="card-header">SIM Inventory</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped mb-0" data-tt-sort="server"
                   data-offset="<?= ($page - 1) * $perPage ?>">
                <thead>
                <tr>
                    <?= $th('sim_ref', 'SIM ID', ' data-col-default="hide"') ?>
                    <?= $th('number', 'Mobile Number') ?>
                    <?= $th('iccid', 'ICCID') ?>
                    <?= $th('carrier', 'Carrier') ?>
                    <?= $th('terminal', 'Terminal') ?>
                    <?= $th('status', 'Status') ?>
                    <?= $th('driver', 'Current Driver') ?>
                    <?= $th('issued', 'Issued') ?>
                    <?= $th('notes', 'Notes', ' data-col-default="hide"') ?>
                    <th class="text-end" data-no-sort="1">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="10" class="text-center text-muted py-3">No SIMs match the current filters.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= e($r['sim_reference'] ?? '—') ?></td>
                        <td class="phone"><?= e($r['mobile_number']) ?></td>
                        <td class="small text-muted"><?= e($r['iccid'] ?? '—') ?></td>
                        <td><?= e($r['carrier_name']) ?></td>
                        <td><?= e($r['terminal_name']) ?></td>
                        <td><?= simStatusBadge($r['status']) ?></td>
                        <td>
                            <?php if ($r['driver_name'] !== null): ?>
                                <a href="driver.php?id=<?= (int)($r['driver_id'] ?? 0) ?>">
                                    <?= e($r['driver_name']) ?></a>
                                <span class="text-muted small">(<?= e($r['badge_number']) ?>)</span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= $r['issued_at'] !== null
                            ? e(date('d-M-Y', strtotime((string)$r['issued_at']))) : '—' ?></td>
                        <td class="small text-muted"><?= e($r['notes'] ?? '—') ?></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-secondary"
                               href="sim-history.php?sim_id=<?= (int)$r['id'] ?>">History</a>
                            <?php if ($r['status'] === 'available' && can('sim.transact')): ?>
                                <a class="btn btn-sm btn-outline-primary"
                                   href="assign.php?sim_id=<?= (int)$r['id'] ?>">Issue</a>
                            <?php elseif ($r['status'] === 'assigned' && can('sim.transact')): ?>
                                <a class="btn btn-sm btn-outline-warning"
                                   href="return-sim.php?sim_id=<?= (int)$r['id'] ?>">Return</a>
                            <?php endif; ?>
                            <?php if (can('reference.manage')): ?>
                                <button class="btn btn-sm btn-outline-info" type="button"
                                        data-bs-toggle="modal" data-bs-target="#notesModal"
                                        data-sim="<?= (int)$r['id'] ?>"
                                        data-num="<?= e($r['mobile_number']) ?>"
                                        data-notes="<?= e($r['notes'] ?? '') ?>">Notes…</button>
                                <button class="btn btn-sm btn-outline-danger" type="button"
                                        data-bs-toggle="modal" data-bs-target="#statusModal"
                                        data-sim="<?= (int)$r['id'] ?>"
                                        data-num="<?= e($r['mobile_number']) ?>"
                                        data-status="<?= e($r['status']) ?>">Status…</button>
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
            Showing <?= count($rows) ?> of <?= $total ?> &middot; page <?= $page ?> of <?= $pages ?>
        </span>
        <?php renderPagination($page, $pages, 'sims.php?' . filtersUrl()); ?>
    </div>
</div>


<?php if (can('reference.manage')): ?>
<!-- Admin controlled status override -->
<div class="modal fade" id="statusModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="sims.php?<?= e(filtersUrl()) ?>" class="modal-content">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="set_status">
            <input type="hidden" name="sim_id" id="sm-sim" value="">
            <div class="modal-header">
                <h5 class="modal-title">Override SIM status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>SIM <strong id="sm-num"></strong> &mdash; current status <span id="sm-cur"></span></p>
                <div class="mb-3">
                    <label class="form-label">New status</label>
                    <select class="form-select" name="target_status" id="sm-target" required>
                        <?php foreach (simStatusOptions() as $skey => $slabel): ?>
                            <option value="<?= e($skey) ?>"><?= e($slabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Reason</label>
                    <input type="text" class="form-control" name="reason" maxlength="60"
                           placeholder="Why is this override being applied?">
                </div>
                <p class="small text-muted mb-0">
                    Overrides are admin-only and recorded in the audit log. Leaving
                    <em>Assigned</em> closes the active assignment first.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger">Apply status change</button>
            </div>
        </form>
    </div>
</div>

<!-- Admin notes editor -->
<div class="modal fade" id="notesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="sims.php?<?= e(filtersUrl()) ?>" class="modal-content">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_notes">
            <input type="hidden" name="sim_id" id="nm-sim" value="">
            <div class="modal-header">
                <h5 class="modal-title">Edit SIM notes</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>SIM <strong id="nm-num"></strong></p>
                <label class="form-label" for="nm-notes">Notes</label>
                <textarea class="form-control" id="nm-notes" name="notes" maxlength="255" rows="3"
                          placeholder="Operational remarks about this SIM (max 255 characters)"></textarea>
                <p class="small text-muted mb-0">Saved notes are recorded in the audit log.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save notes</button>
            </div>
        </form>
    </div>
</div>
<script>
document.getElementById('statusModal')?.addEventListener('show.bs.modal', function (ev) {
    var btn = ev.relatedTarget;
    document.getElementById('sm-sim').value = btn.getAttribute('data-sim');
    document.getElementById('sm-num').textContent = btn.getAttribute('data-num');
    document.getElementById('sm-cur').textContent = btn.getAttribute('data-status');
    document.getElementById('sm-target').value = btn.getAttribute('data-status');
});
document.getElementById('notesModal')?.addEventListener('show.bs.modal', function (ev) {
    var btn = ev.relatedTarget;
    document.getElementById('nm-sim').value = btn.getAttribute('data-sim');
    document.getElementById('nm-num').textContent = btn.getAttribute('data-num');
    document.getElementById('nm-notes').value = btn.getAttribute('data-notes') || '';
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>

