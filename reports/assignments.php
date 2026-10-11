<?php
/**
 * SIMLedger - Assignment History report.
 * Full issue/return/transfer log with date range, terminal, driver filters.
 */

declare(strict_types=1);

$base = '../';

require_once __DIR__ . '/../includes/lookups.php';
require_once __DIR__ . '/../includes/services/SimService.php';
requirePermission('report.view');

$pdo   = $GLOBALS['pdo'];
$scope = currentTerminalId();

$from      = trim((string)($_GET['from'] ?? ''));
$to        = trim((string)($_GET['to'] ?? ''));
$q         = trim((string)($_GET['q'] ?? ''));
$fTerminal = $scope ?? reqInt('terminal_id', 0, 0, 100000);
$state     = (string)($_GET['state'] ?? '');   // open | closed | ''
if ($scope !== null) {
    $fTerminal = $scope;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-m-d', strtotime('-90 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = date('Y-m-d');
}

$where  = ' WHERE a.issued_at <= ?';
$params = [$to . ' 23:59:59'];
if ($fTerminal > 0) {
    $where .= ' AND a.terminal_id = ?';
    $params[] = $fTerminal;
}
if ($state === 'open') {
    $where .= ' AND a.returned_at IS NULL AND a.issued_at >= ?';
    $params[] = $from . ' 00:00:00';
} elseif ($state === 'closed') {
    $where .= ' AND a.returned_at IS NOT NULL AND a.returned_at >= ?';
    $params[] = $from . ' 00:00:00';
} else {
    $where .= ' AND (a.issued_at >= ? OR (a.returned_at IS NOT NULL AND a.returned_at >= ?))';
    $params[] = $from . ' 00:00:00';
    $params[] = $from . ' 00:00:00';
}
if ($q !== '') {
    $where .= ' AND (s.mobile_number LIKE ? OR d.name LIKE ? OR d.badge_number LIKE ?)';
    array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
}

$baseSql = 'SELECT a.*, s.mobile_number, d.name AS driver_name, d.badge_number,
               t.name AS terminal_name, u1.name AS issued_by_name, u2.name AS returned_by_name
          FROM driver_sim_assignments a
          JOIN company_sims s ON s.id = a.sim_id
          JOIN drivers d ON d.id = a.driver_id
          JOIN terminals t ON t.id = a.terminal_id
          JOIN users u1 ON u1.id = a.issued_by
     LEFT JOIN users u2 ON u2.id = a.returned_by
         ' . $where;

$sortMap = [
    'issued'     => 'a.issued_at',
    'returned'   => 'a.returned_at',
    'terminal'   => 't.name',
    'sim'        => 's.mobile_number',
    'badge'      => 'd.badge_number',
    'driver'     => 'd.name',
    'issuedby'   => 'u1.name',
    'returnedby' => 'u2.name',
];
$sort    = reqSort('sort', array_keys($sortMap), 'issued');
$dir     = reqDir('desc');
$page    = reqInt('page', 1, 1, 100000);
$perPage = 25;

$cntStmt = $pdo->prepare('SELECT COUNT(*) FROM (' . $baseSql . ') cnt');
$cntStmt->execute($params);
$total  = (int)$cntStmt->fetchColumn();
$pages  = max(1, (int)ceil($total / $perPage));
$offset = ($page - 1) * $perPage;

if (isset($_GET['export'])) {
    $expStmt = $pdo->prepare($baseSql . ' ORDER BY ' . $sortMap[$sort] . ' ' . $dir);
    $expStmt->execute($params);
    $out = [];
    while ($r = $expStmt->fetch()) {
        $out[] = [
            date('Y-m-d H:i', strtotime((string)$r['issued_at'])),
            $r['returned_at'] !== null ? date('Y-m-d H:i', strtotime((string)$r['returned_at'])) : 'CURRENT',
            $r['terminal_name'], csvSafe((string)$r['mobile_number']),
            csvSafe((string)$r['badge_number']), csvSafe((string)$r['driver_name']),
            csvSafe((string)$r['issued_by_name']), csvSafe((string)($r['returned_by_name'] ?? '')),
            $r['return_reason'] ?? $r['assignment_reason'] ?? '',
            csvSafe((string)($r['remarks'] ?? '')),
        ];
    }
    exportExcel('assignment-history-' . date('Ymd-His') . '.xlsx', 'Assignment History',
        ['Issued At', 'Returned At', 'Terminal', 'SIM', 'Badge', 'Driver',
         'Issued By', 'Returned By', 'Reason', 'Remarks'], $out);
}

$stmt = $pdo->prepare($baseSql . ' ORDER BY ' . $sortMap[$sort] . ' ' . $dir
    . " LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pageTitle = 'Assignment History';
$activeNav = 'report-assignments';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="page-title">Assignment History</h1>
        <p class="page-sub"><?= $total ?> record<?= $total === 1 ? '' : 's' ?>
            between <?= e($from) ?> and <?= e($to) ?> &mdash; append-only.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm"
       href="assignments.php?<?= e(filtersUrl(['export' => 1])) ?>">Export Excel</a>
</div>

<form method="get" class="filter-bar mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-2">
            <label class="form-label">From</label>
            <input type="date" class="form-control" name="from" value="<?= e($from) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label">To</label>
            <input type="date" class="form-control" name="to" value="<?= e($to) ?>">
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
            <label class="form-label">State</label>
            <select class="form-select" name="state">
                <option value="">Either</option>
                <option value="open"<?= $state === 'open' ? ' selected' : '' ?>>Open (current)</option>
                <option value="closed"<?= $state === 'closed' ? ' selected' : '' ?>>Closed</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">SIM / driver</label>
            <input type="text" class="form-control" name="q" value="<?= e($q) ?>">
        </div>
        <div class="col-md-1">
            <button class="btn btn-outline-primary btn-sm w-100" type="submit">Go</button>
        </div>
    </div>
</form>

<div class="card table-card" id="table-partial">
    <div class="card-header">Assignment History</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped mb-0 dt-nowrap" data-tt-sort="server"
                   data-offset="<?= $offset ?>">
                <thead>
                <tr>
                    <th data-sort="issued">Issued</th>
                    <th data-sort="returned">Returned</th>
                    <th data-sort="terminal">Terminal</th>
                    <th data-sort="sim">SIM</th>
                    <th data-sort="badge">Badge</th>
                    <th data-sort="driver">Driver</th>
                    <th data-sort="issuedby" data-col-default="hide">Issued By</th>
                    <th data-sort="returnedby" data-col-default="hide">Returned By</th>
                    <th data-no-sort="1">Reason</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="9" class="text-center text-muted py-3">No assignment records in range.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= e(date('d-M-y H:i', strtotime((string)$r['issued_at']))) ?></td>
                        <td><?= $r['returned_at'] !== null
                            ? e(date('d-M-y H:i', strtotime((string)$r['returned_at'])))
                            : '<span class="badge bg-success">CURRENT</span>' ?></td>
                        <td><?= e($r['terminal_name']) ?></td>
                        <td><a href="../sim-history.php?sim_id=<?= (int)$r['sim_id'] ?>" class="phone">
                            <?= e($r['mobile_number']) ?></a></td>
                        <td><?= e($r['badge_number']) ?></td>
                        <td><a href="../driver.php?id=<?= (int)$r['driver_id'] ?>"><?= e($r['driver_name']) ?></a></td>
                        <td class="small"><?= e($r['issued_by_name']) ?></td>
                        <td class="small"><?= e($r['returned_by_name'] ?? '—') ?></td>
                        <td class="small"><?= e($r['return_reason'] !== null
                            ? (SimService::RETURN_REASONS[$r['return_reason']] ?? $r['return_reason'])
                            : ($r['assignment_reason'] ?? '—')) ?></td>
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
<?php require __DIR__ . '/../includes/footer.php'; ?>
