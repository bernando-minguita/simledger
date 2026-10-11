<?php
/**
 * SIMLedger - Current Assignments list.
 *
 * All open (active) SIM assignments with quick links to return/transfer.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/lookups.php';
require_once __DIR__ . '/includes/services/SimService.php';
requirePermission('sim.view');

$pdo   = $GLOBALS['pdo'];
$scope = currentTerminalId();

$q         = trim((string)($_GET['q'] ?? ''));
$fTerminal = $scope ?? reqInt('terminal_id', 0, 0, 100000);
$fCarrier  = reqInt('carrier_id', 0, 0, 100000);
if ($scope !== null) {
    $fTerminal = $scope;
}

$where  = ' WHERE a.returned_at IS NULL';
$params = [];
if ($fTerminal > 0) {
    $where .= ' AND a.terminal_id = ?';
    $params[] = $fTerminal;
}
if ($fCarrier > 0) {
    $where .= ' AND s.carrier_id = ?';
    $params[] = $fCarrier;
}
if ($q !== '') {
    $where .= ' AND (d.badge_number LIKE ? OR d.name LIKE ? OR s.mobile_number LIKE ?)';
    array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
}

$baseSql = 'SELECT a.id AS assignment_id, a.issued_at, s.id AS sim_id, s.mobile_number, s.status,
            d.id AS driver_id, d.name AS driver_name, d.badge_number,
            t.name AS terminal_name, c.name AS carrier_name, u.name AS issued_by_name
       FROM driver_sim_assignments a
       JOIN company_sims s ON s.id = a.sim_id
       JOIN drivers d ON d.id = a.driver_id
       JOIN terminals t ON t.id = a.terminal_id
       JOIN carriers c ON c.id = s.carrier_id
       JOIN users u ON u.id = a.issued_by
      ' . $where;

$sortMap = [
    'terminal' => 't.name',
    'number'   => 's.mobile_number',
    'carrier'  => 'c.name',
    'status'   => 's.status',
    'badge'    => 'd.badge_number',
    'driver'   => 'd.name',
    'issued'   => 'a.issued_at',
    'issuedby' => 'u.name',
];
$sort    = reqSort('sort', array_keys($sortMap), 'badge');
$dir     = reqDir('asc');
$page    = reqInt('page', 1, 1, 100000);
$perPage = 25;

if (isset($_GET['export'])) {
    $expStmt = $pdo->prepare($baseSql . ' ORDER BY ' . $sortMap[$sort] . ' ' . $dir);
    $expStmt->execute($params);
    $out = [];
    while ($r = $expStmt->fetch()) {
        $out[] = [
            $r['terminal_name'], csvSafe((string)$r['mobile_number']), $r['carrier_name'],
            $r['status'], csvSafe((string)$r['badge_number']), csvSafe((string)$r['driver_name']),
            date('Y-m-d H:i', strtotime((string)$r['issued_at'])),
            csvSafe((string)$r['issued_by_name']),
        ];
    }
    exportExcel('current-assignments-' . date('Ymd-His') . '.xlsx', 'Current Assignments',
        ['Terminal', 'SIM', 'Carrier', 'SIM Status', 'Badge', 'Driver', 'Issued At', 'Issued By'],
        $out);
}

$cntStmt = $pdo->prepare('SELECT COUNT(*) FROM (' . $baseSql . ') cnt');
$cntStmt->execute($params);
$total  = (int)$cntStmt->fetchColumn();
$pages  = max(1, (int)ceil($total / $perPage));
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare($baseSql . ' ORDER BY ' . $sortMap[$sort] . ' ' . $dir
    . " LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pageTitle = 'Current Assignments';
$activeNav = 'sim-assignments';
require __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="page-title">Current Assignments</h1>
        <p class="page-sub"><?= $total ?> open assignment<?= $total === 1 ? '' : 's' ?>
            &mdash; one active SIM per driver, one active driver per SIM.</p>
    </div>
    <div>
        <a class="btn btn-outline-secondary btn-sm"
           href="assignments.php?<?= e(filtersUrl(['export' => 1])) ?>">Export Excel</a>
        <?php if (can('sim.transact')): ?>
            <a class="btn btn-primary btn-sm" href="assign.php">Issue SIM</a>
        <?php endif; ?>
    </div>
</div>

<form method="get" class="filter-bar mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label">Search</label>
            <input type="text" class="form-control" name="q" value="<?= e($q) ?>"
                   placeholder="Badge / driver / SIM number">
        </div>
        <div class="col-md-3">
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
        <div class="col-md-3">
            <label class="form-label">Carrier</label>
            <select class="form-select" name="carrier_id">
                <option value="">All</option>
                <?php foreach (carrierOptions() as $cid => $cname): ?>
                    <option value="<?= $cid ?>"<?= $fCarrier === $cid ? ' selected' : '' ?>><?= e($cname) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <button class="btn btn-outline-primary btn-sm" type="submit">Filter</button>
            <a class="btn btn-link btn-sm" href="assignments.php">Reset</a>
        </div>
    </div>
</form>

<div class="card table-card" id="table-partial">
    <div class="card-header">Current Assignments</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped mb-0 dt-nowrap" data-tt-sort="server"
                   data-offset="<?= $offset ?>">
                <thead>
                <tr>
                    <th data-sort="terminal">Terminal</th>
                    <th data-sort="number">SIM</th>
                    <th data-sort="carrier">Carrier</th>
                    <th data-sort="status">Status</th>
                    <th data-sort="badge">Badge</th>
                    <th data-sort="driver">Driver</th>
                    <th data-sort="issued">Issued</th>
                    <th data-sort="issuedby" data-col-default="hide">Issued By</th>
                    <th class="text-end" data-no-sort="1">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="9" class="text-center text-muted py-3">
                        No open assignments match the current filters.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= e($r['terminal_name']) ?></td>
                        <td><a href="sim-history.php?sim_id=<?= (int)$r['sim_id'] ?>" class="phone">
                            <?= e($r['mobile_number']) ?></a></td>
                        <td><?= e($r['carrier_name']) ?></td>
                        <td><?= simStatusBadge($r['status']) ?></td>
                        <td><?= e($r['badge_number']) ?></td>
                        <td><a href="driver.php?id=<?= (int)$r['driver_id'] ?>"><?= e($r['driver_name']) ?></a></td>
                        <td class="small"><?= e(date('d-M-Y H:i', strtotime((string)$r['issued_at']))) ?></td>
                        <td class="small"><?= e($r['issued_by_name']) ?></td>
                        <td class="text-end text-nowrap">
                            <?php if (can('sim.transact')): ?>
                                <a class="btn btn-sm btn-outline-warning"
                                   href="return-sim.php?assignment_id=<?= (int)$r['assignment_id'] ?>">Return</a>
                                <a class="btn btn-sm btn-outline-primary"
                                   href="transfer.php?assignment_id=<?= (int)$r['assignment_id'] ?>">Transfer</a>
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
        <?php renderPagination($page, $pages, 'assignments.php?' . filtersUrl()); ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
