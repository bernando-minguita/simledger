<?php
/**
 * SIMLedger - SIM Inventory report (spec section 38).
 * Aggregate view by terminal / carrier / status with export.
 */

declare(strict_types=1);

$base = '../';

require_once __DIR__ . '/../includes/lookups.php';
requirePermission('report.view');

$pdo   = $GLOBALS['pdo'];
$scope = currentTerminalId();

$fTerminals = array_map('intval', filterValues('terminal_id'));
$fCarriers  = array_map('intval', filterValues('carrier_id'));
$fStatuses  = array_values(array_intersect(
    filterValues('status'), array_keys(simStatusOptions())
));
if ($scope !== null) {
    $fTerminals = [$scope];   // dispatcher: server-enforced terminal scope
}

$where  = ' WHERE 1=1';
$params = [];
if ($fTerminals !== []) {
    $where .= ' AND s.terminal_id IN (' . implode(',', array_fill(0, count($fTerminals), '?')) . ')';
    array_push($params, ...$fTerminals);
}
if ($fCarriers !== []) {
    $where .= ' AND s.carrier_id IN (' . implode(',', array_fill(0, count($fCarriers), '?')) . ')';
    array_push($params, ...$fCarriers);
}
if ($fStatuses !== []) {
    $where .= ' AND s.status IN (' . implode(',', array_fill(0, count($fStatuses), '?')) . ')';
    array_push($params, ...$fStatuses);
}

// Breakdown by terminal + status.
$breakStmt = $pdo->prepare(
    'SELECT t.name AS terminal_name,
            COUNT(*) AS total,
            SUM(s.status = "available") AS available,
            SUM(s.status = "assigned") AS assigned,
            SUM(s.status = "suspended") AS suspended,
            SUM(s.status = "lost") AS lost,
            SUM(s.status = "damaged") AS damaged,
            SUM(s.status = "retired") AS retired
       FROM company_sims s
       JOIN terminals t ON t.id = s.terminal_id
      ' . $where . '
      GROUP BY t.id, t.name
      ORDER BY t.name'
);
$breakStmt->execute($params);
$breakdown = $breakStmt->fetchAll();

// Full row detail.
$baseSql = 'SELECT s.id, s.sim_reference, s.mobile_number, s.iccid, s.status,
            t.name AS terminal_name, c.name AS carrier_name,
            d.name AS driver_name, d.badge_number
       FROM company_sims s
       JOIN terminals t ON t.id = s.terminal_id
       JOIN carriers c ON c.id = s.carrier_id
       LEFT JOIN driver_sim_assignments a ON a.sim_id = s.id AND a.returned_at IS NULL
       LEFT JOIN drivers d ON d.id = a.driver_id
      ' . $where;

$sortMap = [
    'sim_ref'  => 's.sim_reference',
    'number'   => 's.mobile_number',
    'iccid'    => 's.iccid',
    'carrier'  => 'c.name',
    'terminal' => 't.name, s.mobile_number',
    'status'   => 's.status',
    'driver'   => 'd.name',
];
$sort    = reqSort('sort', array_keys($sortMap), 'terminal');
$dir     = reqDir('asc');
$page    = reqInt('page', 1, 1, 100000);
$perPage = 25;

if (isset($_GET['export'])) {
    $expStmt = $pdo->prepare($baseSql . ' ORDER BY ' . $sortMap[$sort] . ' ' . $dir);
    $expStmt->execute($params);
    $out = [];
    while ($r = $expStmt->fetch()) {
        $out[] = [
            csvSafe((string)$r['sim_reference']), csvSafe((string)$r['mobile_number']),
            csvSafe((string)($r['iccid'] ?? '')), $r['carrier_name'], $r['terminal_name'],
            $r['status'], csvSafe((string)($r['driver_name'] ?? '')),
            csvSafe((string)($r['badge_number'] ?? '')),
        ];
    }
    exportExcel('sim-inventory-report-' . date('Ymd-His') . '.xlsx', 'SIM Inventory Report',
        ['SIM ID', 'Mobile Number', 'ICCID', 'Carrier', 'Terminal', 'Status',
         'Current Driver', 'Badge'], $out);
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

$pageTitle = 'SIM Inventory Report';
$activeNav = 'report-sims';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="page-title">SIM Inventory Report</h1>
        <p class="page-sub"><?= $total ?> SIM record<?= $total === 1 ? '' : 's' ?> matched.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm"
       href="inventory.php?<?= e(filtersUrl(['export' => 1])) ?>">Export Excel</a>
</div>

<form method="get" class="filter-bar mb-3">
    <div class="d-flex flex-wrap align-items-end gap-2 gap-md-3">
        <div>
            <label class="form-label">Terminal</label>
            <?php if ($scope !== null): ?>
                <input type="text" class="form-control" value="<?= e(terminalOptions()[$scope] ?? '') ?>" disabled>
            <?php else: ?>
                <?= checkboxFilterGroup('terminal_id', terminalOptions(false), array_map('strval', $fTerminals)) ?>
            <?php endif; ?>
        </div>
        <div>
            <label class="form-label">Carrier</label>
            <?= checkboxFilterGroup('carrier_id', carrierOptions(), array_map('strval', $fCarriers)) ?>
        </div>
        <div>
            <label class="form-label">Status</label>
            <?= checkboxFilterGroup('status', simStatusOptions(), array_map('strval', $fStatuses)) ?>
        </div>
        <div>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-primary btn-sm" type="submit">Apply filters</button>
                <a class="btn btn-link btn-sm" href="inventory.php">Reset</a>
            </div>
        </div>
    </div>
</form>

<!-- breakdown table -->
<div class="card mb-3">
    <div class="card-header">Breakdown by terminal</div>
    <div class="card-body p-0">
        <table class="table table-striped mb-0 dt-nowrap">
            <thead>
            <tr>
                <th>Terminal</th><th>Total</th><th>Available</th><th>Assigned</th>
                <th>Suspended</th><th>Lost</th><th>Damaged</th><th>Retired</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($breakdown as $b): ?>
                <tr>
                    <td><?= e($b['terminal_name']) ?></td>
                    <td><strong><?= (int)$b['total'] ?></strong></td>
                    <td><?= (int)$b['available'] ?></td>
                    <td><?= (int)$b['assigned'] ?></td>
                    <td><?= (int)$b['suspended'] ?></td>
                    <td><?= (int)$b['lost'] ?></td>
                    <td><?= (int)$b['damaged'] ?></td>
                    <td><?= (int)$b['retired'] ?></td>
                </tr>
            <?php endforeach; ?>
            <?php
            $bTotals = ['total' => 0, 'available' => 0, 'assigned' => 0,
                'suspended' => 0, 'lost' => 0, 'damaged' => 0, 'retired' => 0];
            foreach ($breakdown as $b) {
                foreach ($bTotals as $k => $v) {
                    $bTotals[$k] += (int)($b[$k] ?? 0);
                }
            }
            ?>
            <?php if ($breakdown !== []): ?>
                <tr class="fw-bold border-top" data-tt-total="1">
                    <td>All terminals</td>
                    <td><?= $bTotals['total'] ?></td>
                    <td><?= $bTotals['available'] ?></td>
                    <td><?= $bTotals['assigned'] ?></td>
                    <td><?= $bTotals['suspended'] ?></td>
                    <td><?= $bTotals['lost'] ?></td>
                    <td><?= $bTotals['damaged'] ?></td>
                    <td><?= $bTotals['retired'] ?></td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>


<div class="card table-card" id="table-partial">
    <div class="card-header">SIM Inventory Report</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped mb-0 dt-nowrap" data-tt-sort="server"
                   data-offset="<?= $offset ?>">
                <thead>
                <tr>
                    <th data-sort="sim_ref" data-col-default="hide">SIM ID</th><th data-sort="number">Number</th>
                    <th data-sort="iccid">ICCID</th><th data-sort="carrier">Carrier</th>
                    <th data-sort="terminal">Terminal</th><th data-sort="status">Status</th>
                    <th data-sort="driver">Current Driver</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">No SIMs match.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= e($r['sim_reference'] ?? '—') ?></td>
                        <td class="phone"><?= e($r['mobile_number']) ?></td>
                        <td class="small text-muted"><?= e($r['iccid'] ?? '—') ?></td>
                        <td><?= e($r['carrier_name']) ?></td>
                        <td><?= e($r['terminal_name']) ?></td>
                        <td><?= simStatusBadge($r['status']) ?></td>
                        <td><?= $r['driver_name'] !== null
                            ? e($r['driver_name']) . ' (' . e($r['badge_number']) . ')' : '—' ?></td>
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
        <?php renderPagination($page, $pages, 'inventory.php?' . filtersUrl()); ?>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
