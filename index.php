<?php
/**
 * SIMLedger - operations dashboard.
 *
 * KPI cards, operational alerts and the recent SIM transaction feed.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/lookups.php';
requireLogin();

$pdo = $GLOBALS['pdo'];

// --- filters ---------------------------------------------------------------
$termOpts   = terminalOptions(false);
$scope      = currentTerminalId();
$fTerminal  = reqInt('terminal_id', $scope ?? 0, 0, 100000);
$fCarrier   = reqInt('carrier_id', 0, 0, 100000);
$fStatus    = (string)($_GET['status'] ?? '');
if (!in_array($fStatus, array_keys(simStatusOptions()), true)) {
    $fStatus = '';
}

// Dispatchers are locked to their terminal regardless of the query string.
if ($scope !== null) {
    $fTerminal = $scope;
}

$where  = ' WHERE 1=1';
$params = [];

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

// --- KPIs ------------------------------------------------------------------
$kpiSql = "
    SELECT COUNT(*) AS total,
           SUM(status = 'assigned')  AS assigned,
           SUM(status = 'available') AS available,
           SUM(status = 'suspended') AS suspended,
           SUM(status IN ('lost', 'damaged')) AS lost_damaged
      FROM company_sims s
";
$stmt = $pdo->prepare($kpiSql . $where);
$stmt->execute($params);
$kpi = $stmt->fetch() ?: ['total' => 0, 'assigned' => 0, 'available' => 0,
                          'suspended' => 0, 'lost_damaged' => 0];

// Drivers with / without company SIM.
$dWhere  = ' WHERE 1=1';
$dParams = [];
if ($fTerminal > 0) {
    $dWhere .= ' AND d.terminal_id = ?';
    $dParams[] = $fTerminal;
}

$driversStmt = $pdo->prepare(
    "SELECT COUNT(*) AS total,
            SUM(EXISTS(SELECT 1 FROM driver_sim_assignments a
                        WHERE a.driver_id = d.id AND a.returned_at IS NULL)) AS with_sim,
            SUM(NOT EXISTS(SELECT 1 FROM driver_sim_assignments a
                        WHERE a.driver_id = d.id AND a.returned_at IS NULL)) AS without_sim
       FROM drivers d
       WHERE d.is_active = 1 AND d.status = 'active'" . ($fTerminal > 0 ? ' AND d.terminal_id = ?' : '')
);
$driversStmt->execute($dParams);
$dkpi = $driversStmt->fetch() ?: ['total' => 0, 'with_sim' => 0, 'without_sim' => 0];

// --- recent activity (spec section 8) --------------------------------------
$rWhere  = ' WHERE 1=1';
$rParams = [];
if ($fTerminal > 0) {
    $rWhere .= ' AND a.terminal_id = ?';
    $rParams[] = $fTerminal;
}
if ($fCarrier > 0) {
    $rWhere .= ' AND s.carrier_id = ?';
    $rParams[] = $fCarrier;
}

$recentStmt = $pdo->prepare(
    "SELECT a.id, a.driver_id, a.sim_id, a.issued_at, a.returned_at, a.assignment_reason, a.return_reason,
            t.name AS terminal_name, s.mobile_number, s.normalized_mobile_number,
            d.name AS driver_name, d.badge_number,
            u.name AS issued_by_name,
            (SELECT d2.name FROM driver_sim_assignments a2
               JOIN drivers d2 ON d2.id = a2.driver_id
              WHERE a2.id = (
                  SELECT MAX(a3.id) FROM driver_sim_assignments a3
                   WHERE a3.sim_id = a.sim_id AND a3.id < a.id AND a3.returned_at IS NOT NULL
              )) AS previous_driver_name,
            a.returned_by
       FROM driver_sim_assignments a
       JOIN company_sims s ON s.id = a.sim_id
       JOIN drivers d ON d.id = a.driver_id
       JOIN terminals t ON t.id = a.terminal_id
       JOIN users u ON u.id = a.issued_by
       " . $rWhere . "
      ORDER BY a.issued_at DESC
      LIMIT 12"
);
$recentStmt->execute($rParams);
$recent = $recentStmt->fetchAll();

// --- alerts (spec section 34) ----------------------------------------------
$alerts = [];

if ((int)$dkpi['without_sim'] > 0) {
    $alerts[] = ['warning', (int)$dkpi['without_sim'] . ' active drivers have no company SIM',
        'reports/drivers-without-sim.php' . ($fTerminal > 0 ? '?terminal_id=' . $fTerminal : '')];
}
if ((int)$kpi['available'] > 0) {
    $alerts[] = ['warning', (int)$kpi['available'] . ' company SIMs are currently unassigned',
        'available.php' . ($fTerminal > 0 ? '?terminal_id=' . $fTerminal : '')];
}
if ((int)$kpi['suspended'] > 0) {
    $alerts[] = ['warning', (int)$kpi['suspended'] . ' SIMs are suspended',
        'sims.php?status=suspended' . ($fTerminal > 0 ? '&terminal_id=' . $fTerminal : '')];
}
if ((int)$kpi['lost_damaged'] > 0) {
    $alerts[] = ['warning', (int)$kpi['lost_damaged'] . ' SIMs are lost or damaged',
        'sims.php?status=lost' . ($fTerminal > 0 ? '&terminal_id=' . $fTerminal : '')];
}

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require __DIR__ . '/includes/header.php';

$filterQuery = http_build_query(array_filter([
    'terminal_id' => $fTerminal > 0 ? $fTerminal : null,
    'carrier_id'  => $fCarrier > 0 ? $fCarrier : null,
    'status'      => $fStatus !== '' ? $fStatus : null,
]));
$fq = $filterQuery !== '' ? '&' . $filterQuery : '';
?>
<!-- page header -->
<div class="page-head">
    <div>
        <h1 class="page-title">Operations Dashboard</h1>
        <p class="page-sub">
            Company SIM estate across
            <?= $scope !== null ? e(terminalOptions()[$scope] ?? '') : 'all terminals' ?>
            &middot; <?= e(date('l, d F Y')) ?>
        </p>
    </div>
    <div class="page-actions">
        <?php if (can('sim.transact')): ?>
            <a class="btn btn-primary btn-sm" href="assign.php">+ Issue SIM</a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary btn-sm" href="assignments.php">Current assignments</a>
        <a class="btn btn-outline-secondary btn-sm" href="available.php">Available SIMs</a>
    </div>
</div>

<!-- filter bar -->
<div class="filter-bar mb-3">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label">Terminal</label>
            <?php if ($scope !== null): ?>
                <input type="text" class="form-control" value="<?= e($termOpts[$scope] ?? '') ?>" disabled>
            <?php else: ?>
                <select class="form-select" name="terminal_id" onchange="this.form.submit()">
                    <option value="">All terminals</option>
                    <?php foreach ($termOpts as $tid => $tname): ?>
                        <option value="<?= $tid ?>"<?= $fTerminal === $tid ? ' selected' : '' ?>><?= e($tname) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
        </div>
        <div class="col-md-3">
            <label class="form-label">Carrier</label>
            <select class="form-select" name="carrier_id" onchange="this.form.submit()">
                <option value="">All carriers</option>
                <?php foreach (carrierOptions() as $cid => $cname): ?>
                    <option value="<?= $cid ?>"<?= $fCarrier === $cid ? ' selected' : '' ?>><?= e($cname) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">SIM status</label>
            <select class="form-select" name="status" onchange="this.form.submit()">
                <option value="">All statuses</option>
                <?php foreach (simStatusOptions() as $skey => $slabel): ?>
                    <option value="<?= e($skey) ?>"<?= $fStatus === $skey ? ' selected' : '' ?>><?= e($slabel) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <button class="btn btn-outline-primary btn-sm" type="submit">Apply filters</button>
            <a class="btn btn-link btn-sm" href="index.php">Reset</a>
        </div>
    </form>
</div>

<!-- KPI cards (spec section 7) -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-4 col-xl">
        <div class="kpi kpi-accent-total">
            <span class="kpi-label">Company SIMs</span>
            <span class="kpi-value"><a href="sims.php<?= e($fq) ?>"><?= (int)$kpi['total'] ?></a></span>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        <div class="kpi kpi-accent-assigned">
            <span class="kpi-label">Assigned</span>
            <span class="kpi-value"><a href="sims.php?status=assigned<?= e($fq) ?>"><?= (int)$kpi['assigned'] ?></a></span>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        <div class="kpi kpi-accent-available">
            <span class="kpi-label">Available</span>
            <span class="kpi-value"><a href="available.php<?= e($fq) ?>"><?= (int)$kpi['available'] ?></a></span>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        <div class="kpi kpi-accent-suspended">
            <span class="kpi-label">Suspended</span>
            <span class="kpi-value"><a href="sims.php?status=suspended<?= e($fq) ?>"><?= (int)$kpi['suspended'] ?></a></span>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        <div class="kpi kpi-accent-lost">
            <span class="kpi-label">Lost / Damaged</span>
            <span class="kpi-value"><a href="sims.php?status=lost<?= e($fq) ?>"><?= (int)$kpi['lost_damaged'] ?></a></span>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        <div class="kpi kpi-accent-drivers">
            <span class="kpi-label">Drivers With SIM</span>
            <span class="kpi-value"><a href="drivers.php?has_sim=1"><?= (int)$dkpi['with_sim'] ?></a></span>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl">
        <div class="kpi kpi-accent-drivers">
            <span class="kpi-label">Drivers Without SIM</span>
            <span class="kpi-value"><a href="reports/drivers-without-sim.php<?= e($fq) ?>"><?= (int)$dkpi['without_sim'] ?></a></span>
        </div>
    </div>
</div>

<!-- operational alerts (spec section 34) -->
<?php foreach ($alerts as $alert): ?>
    <div class="alert-op mb-2">
        &#9888; <?= e($alert[1]) ?> &mdash; <a href="<?= e($alert[2]) ?>">open report</a>
    </div>
<?php endforeach; ?>


<!-- recent SIM transactions (spec section 8) -->
<div class="card mt-3" id="table-partial">
    <div class="card-header d-flex align-items-center gap-2">
        <span class="me-auto">Recent SIM Transactions</span>
        <a class="btn btn-sm btn-outline-secondary" href="sim-history.php">Full history</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped dt-nowrap mb-0">
                <thead>
                <tr>
                    <th>Date / Time</th>
                    <th>Terminal</th>
                    <th>Action</th>
                    <th>SIM</th>
                    <th>Driver</th>
                    <th>Previous Driver</th>
                    <th data-col-default="hide">Performed By</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($recent === []): ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">No transactions yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($recent as $row):
                    $isOpen = $row['returned_at'] === null;
                    $action = $isOpen
                        ? (($row['assignment_reason'] ?? '') === 'transfer' ? 'Transferred'
                            : (($row['assignment_reason'] ?? '') === 'replacement' ? 'Replaced' : 'Assigned'))
                        : (($row['return_reason'] ?? '') === 'transfer' ? 'Transferred' : 'Returned');
                    ?>
                    <tr>
                        <td><?= e(date('d-M H:i', strtotime((string)$row['issued_at']))) ?></td>
                        <td><?= e($row['terminal_name']) ?></td>
                        <td><span class="badge <?= $isOpen ? 'bg-success' : 'bg-secondary' ?>"><?= e($action) ?></span></td>
                        <td><a href="sim-history.php?sim_id=<?= (int)$row['sim_id'] ?>" class="phone"><?= e($row['mobile_number']) ?></a></td>
                        <td><a href="driver.php?id=<?= (int)$row['driver_id'] ?>"><?= e($row['driver_name']) ?></a> (<?= e($row['badge_number']) ?>)</td>
                        <td><?= e($row['previous_driver_name'] ?? '—') ?></td>
                        <td><?= e($row['issued_by_name']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
