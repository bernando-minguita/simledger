<?php
/**
 * SIMLedger - Available / Unassigned SIMs (spec section 24).
 *
 * Shows SIMs available for issuance with their last driver, return date
 * and return reason.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/lookups.php';
require_once __DIR__ . '/includes/services/SimService.php';
requirePermission('sim.view');

$pdo   = $GLOBALS['pdo'];
$scope = currentTerminalId();

$q         = trim((string)($_GET['q'] ?? ''));
$fTerminals = array_map('intval', filterValues('terminal_id'));
$fCarriers  = array_map('intval', filterValues('carrier_id'));
if ($scope !== null) {
    $fTerminals = [$scope];   // dispatcher: server-enforced terminal scope
}

$where  = " WHERE s.status = 'available'";
$params = [];
if ($fTerminals !== []) {
    $where .= ' AND s.terminal_id IN (' . implode(',', array_fill(0, count($fTerminals), '?')) . ')';
    array_push($params, ...$fTerminals);
}
if ($fCarriers !== []) {
    $where .= ' AND s.carrier_id IN (' . implode(',', array_fill(0, count($fCarriers), '?')) . ')';
    array_push($params, ...$fCarriers);
}
if ($q !== '') {
    $where .= ' AND (s.mobile_number LIKE ? OR s.iccid LIKE ? OR s.sim_reference LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}

$baseSql = 'SELECT s.id, s.sim_reference, s.mobile_number, s.iccid, s.status,
               t.name AS terminal_name, c.name AS carrier_name,
               last.driver_name, last.badge_number, last.returned_at, last.return_reason
          FROM company_sims s
          JOIN terminals t ON t.id = s.terminal_id
          JOIN carriers c ON c.id = s.carrier_id
          LEFT JOIN (
              SELECT a.sim_id, a.returned_at, a.return_reason,
                     d.name AS driver_name, d.badge_number
                FROM driver_sim_assignments a
                JOIN drivers d ON d.id = a.driver_id
                WHERE a.id = (SELECT MAX(a2.id) FROM driver_sim_assignments a2
                               WHERE a2.sim_id = a.sim_id AND a2.returned_at IS NOT NULL)
          ) last ON last.sim_id = s.id
         ' . $where;

$sortMap = [
    'number'   => 's.mobile_number',
    'carrier'  => 'c.name',
    'iccid'    => 's.iccid',
    'terminal' => 't.name',
    'status'   => 's.status',
    'driver'   => 'last.driver_name',
    'returned' => 'last.returned_at',
    'reason'   => 'last.return_reason',
];
$sort    = reqSort('sort', array_keys($sortMap), 'number');
$dir     = reqDir('asc');
$page    = reqInt('page', 1, 1, 100000);
$perPage = 25;

if (isset($_GET['export'])) {
    $expStmt = $pdo->prepare($baseSql . ' ORDER BY ' . $sortMap[$sort] . ' ' . $dir);
    $expStmt->execute($params);
    $out = [];
    while ($r = $expStmt->fetch()) {
        $out[] = [
            csvSafe((string)$r['mobile_number']), $r['carrier_name'],
            csvSafe((string)($r['iccid'] ?? '')), $r['terminal_name'], $r['status'],
            csvSafe((string)($r['driver_name'] ?? '')), csvSafe((string)($r['badge_number'] ?? '')),
            $r['returned_at'] !== null ? date('Y-m-d', strtotime((string)$r['returned_at'])) : '',
            $r['return_reason'] !== null
                ? (SimService::RETURN_REASONS[$r['return_reason']] ?? $r['return_reason']) : '',
        ];
    }
    exportExcel('available-sims-' . date('Ymd-His') . '.xlsx', 'Available SIMs',
        ['SIM Number', 'Carrier', 'ICCID', 'Terminal', 'Status', 'Last Driver',
         'Last Badge', 'Returned Date', 'Return Reason'], $out);
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

$pageTitle = 'Available SIMs';
$activeNav = 'sim-available';
require __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="page-title">Available / Unassigned SIMs</h1>
        <p class="page-sub"><?= $total ?> SIM<?= $total === 1 ? '' : 's' ?> ready for issuance.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="available.php?<?= e(filtersUrl(['export' => 1])) ?>">Export Excel</a>
</div>

<form method="get" class="filter-bar mb-3">
    <div class="d-flex flex-wrap align-items-end gap-2 gap-md-3">
        <div>
            <label class="form-label">Search</label>
            <div class="d-flex gap-2">
                <input type="text" class="form-control" name="q" value="<?= e($q) ?>"
                       placeholder="Number / ICCID / SIM ID">
                <button class="btn btn-outline-primary" type="submit">Search</button>
                <a class="btn btn-link" href="available.php">Reset</a>
            </div>
        </div>
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
    </div>
</form>

<div class="card table-card" id="table-partial">
    <div class="card-header">Available / Unassigned SIMs</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped mb-0 dt-nowrap" data-tt-sort="server"
                   data-offset="<?= $offset ?>">
                <thead>
                <tr>
                    <th data-sort="number">SIM Number</th>
                    <th data-sort="carrier">Carrier</th>
                    <th data-sort="iccid">ICCID</th>
                    <th data-sort="terminal">Terminal</th>
                    <th data-sort="status">Status</th>
                    <th data-sort="driver">Last Driver</th>
                    <th data-sort="returned">Returned Date</th>
                    <th data-sort="reason">Return Reason</th>
                    <th class="text-end" data-no-sort="1">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="9" class="text-center text-muted py-3">
                        No available SIMs match the current filters.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="phone"><?= e($r['mobile_number']) ?></td>
                        <td><?= e($r['carrier_name']) ?></td>
                        <td class="small text-muted"><?= e($r['iccid'] ?? '—') ?></td>
                        <td><?= e($r['terminal_name']) ?></td>
                        <td><?= simStatusBadge($r['status']) ?></td>
                        <td><?= $r['driver_name'] !== null
                            ? e($r['driver_name']) . ' (' . e($r['badge_number']) . ')' : '—' ?></td>
                        <td><?= $r['returned_at'] !== null
                            ? e(date('d-M-Y', strtotime((string)$r['returned_at']))) : '—' ?></td>
                        <td class="small"><?= e($r['return_reason'] !== null
                            ? (SimService::RETURN_REASONS[$r['return_reason']] ?? $r['return_reason']) : '—') ?></td>
                        <td class="text-end text-nowrap">
                            <?php if (can('sim.transact')): ?>
                                <a class="btn btn-sm btn-primary"
                                   href="assign.php?sim_id=<?= (int)$r['id'] ?>">Issue</a>
                            <?php endif; ?>
                            <a class="btn btn-sm btn-outline-secondary"
                               href="sim-history.php?sim_id=<?= (int)$r['id'] ?>">History</a>
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
        <?php renderPagination($page, $pages, 'available.php?' . filtersUrl()); ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
