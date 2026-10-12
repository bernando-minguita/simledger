<?php
/**
 * SIMLedger - SIM Transfers report (spec section 25).
 *
 * Filters: date range, terminal, SIM, previous driver, new driver, dispatcher.
 */

declare(strict_types=1);

$base = '../';

require_once __DIR__ . '/../includes/lookups.php';
require_once __DIR__ . '/../includes/services/SimService.php';
requirePermission('report.view');

$pdo   = $GLOBALS['pdo'];
$scope = currentTerminalId();

$from       = trim((string)($_GET['from'] ?? ''));
$to         = trim((string)($_GET['to'] ?? ''));
$q          = trim((string)($_GET['q'] ?? ''));
$dispatchers = array_map('intval', filterValues('user_id'));
$fTerminals  = array_map('intval', filterValues('terminal_id'));
if ($scope !== null) {
    $fTerminals = [$scope];   // dispatcher: server-enforced terminal scope
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-m-d', strtotime('-30 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = date('Y-m-d');
}

// Transfers = closures with return_reason 'transfer', plus new assignments
// opened with assignment_reason 'transfer'. We list closure rows joined with
// the follow-up assignment to show previous -> new driver.
$where  = " WHERE a.returned_at IS NOT NULL AND a.return_reason = 'transfer'";
$params = [];
if ($fTerminals !== []) {
    $where .= ' AND a.terminal_id IN (' . implode(',', array_fill(0, count($fTerminals), '?')) . ')';
    array_push($params, ...$fTerminals);
}
if ($dispatchers !== []) {
    // Multi-select: any of the chosen dispatchers, on either side of the
    // transfer (returned_by or issued_by).
    $in = implode(',', array_fill(0, count($dispatchers), '?'));
    $where .= " AND (a.returned_by IN ($in) OR a.issued_by IN ($in))";
    array_push($params, ...$dispatchers, ...$dispatchers);
}
if ($q !== '') {
    $where .= ' AND (s.mobile_number LIKE ? OR prev_d.name LIKE ? OR prev_d.badge_number LIKE ?)';
    array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
}
// Date range placeholders come last in the SQL text.
$where .= ' AND a.returned_at BETWEEN ? AND ?';
array_push($params, $from . ' 00:00:00', $to . ' 23:59:59');

$baseSql = 'SELECT a.id, a.returned_at, a.remarks, a.terminal_id,
               s.id AS sim_id, s.mobile_number,
               prev_d.name AS prev_driver, prev_d.badge_number AS prev_badge,
               ru.name AS returned_by_name, t.name AS terminal_name,
               next_a.driver_id AS new_driver_id,
               next_a.issued_at AS new_issued_at,
               (SELECT d2.name FROM drivers d2 WHERE d2.id = next_a.driver_id) AS new_driver,
               (SELECT d2.badge_number FROM drivers d2 WHERE d2.id = next_a.driver_id) AS new_badge
          FROM driver_sim_assignments a
          JOIN company_sims s ON s.id = a.sim_id
          JOIN drivers prev_d ON prev_d.id = a.driver_id
          JOIN users ru ON ru.id = a.returned_by
          JOIN terminals t ON t.id = a.terminal_id
          LEFT JOIN driver_sim_assignments next_a
                 ON next_a.id = (SELECT MIN(a2.id) FROM driver_sim_assignments a2
                                  WHERE a2.sim_id = a.sim_id AND a2.issued_at > a.returned_at)
         ' . $where;

$sortMap = [
    'date'      => 'a.returned_at',
    'sim'       => 's.mobile_number',
    'prev'      => 'prev_d.name',
    'new'       => '(SELECT d2.name FROM drivers d2 WHERE d2.id = next_a.driver_id)',
    'terminal'  => 't.name',
    'performed' => 'ru.name',
];
$sort    = reqSort('sort', array_keys($sortMap), 'date');
$dir     = reqDir('desc');
$page    = reqInt('page', 1, 1, 100000);
$perPage = 25;

$cntStmt = $pdo->prepare('SELECT COUNT(*) FROM (' . $baseSql . ') cnt');
$cntStmt->execute($params);
$total  = (int)$cntStmt->fetchColumn();
$pages  = max(1, (int)ceil($total / $perPage));
$offset = ($page - 1) * $perPage;

// Dispatcher filter dropdown (users who performed transactions).
$users = [];
foreach ($pdo->query('SELECT id, name FROM users WHERE is_active = 1 ORDER BY name') as $u) {
    $users[(int)$u['id']] = $u['name'];
}

if (isset($_GET['export'])) {
    $expStmt = $pdo->prepare($baseSql . ' ORDER BY ' . $sortMap[$sort] . ' ' . $dir);
    $expStmt->execute($params);
    $out = [];
    while ($r = $expStmt->fetch()) {
        $out[] = [
            date('Y-m-d H:i', strtotime((string)$r['returned_at'])),
            $r['terminal_name'], csvSafe((string)$r['mobile_number']),
            csvSafe((string)$r['prev_driver']), csvSafe((string)$r['prev_badge']),
            csvSafe((string)($r['new_driver'] ?? '')), csvSafe((string)($r['new_badge'] ?? '')),
            csvSafe((string)$r['returned_by_name']), csvSafe((string)($r['remarks'] ?? '')),
        ];
    }
    exportExcel('sim-transfers-' . date('Ymd-His') . '.xlsx', 'SIM Transfers',
        ['Date', 'Terminal', 'SIM', 'Previous Driver', 'Previous Badge',
         'New Driver', 'New Badge', 'Performed By', 'Remarks'], $out);
}

$stmt = $pdo->prepare($baseSql . ' ORDER BY ' . $sortMap[$sort] . ' ' . $dir
    . " LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pageTitle = 'SIM Transfers';
$activeNav = 'report-transfers';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="page-title">SIM Transfers</h1>
        <p class="page-sub"><?= $total ?> transfer<?= $total === 1 ? '' : 's' ?>
            between <?= e($from) ?> and <?= e($to) ?>.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm"
       href="transfers.php?<?= e(filtersUrl(['export' => 1])) ?>">Export Excel</a>
</div>

<form method="get" class="filter-bar mb-3">
    <div class="d-flex flex-wrap align-items-end gap-2 gap-md-3">
        <div>
            <label class="form-label">From</label>
            <input type="date" class="form-control" name="from" value="<?= e($from) ?>">
        </div>
        <div>
            <label class="form-label">To</label>
            <input type="date" class="form-control" name="to" value="<?= e($to) ?>">
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
            <label class="form-label">SIM / previous driver</label>
            <div class="d-flex gap-2">
                <input type="text" class="form-control" name="q" value="<?= e($q) ?>"
                       placeholder="SIM number / name / badge">
                <button class="btn btn-outline-primary" type="submit">Search</button>
                <a class="btn btn-link" href="reports/transfers.php">Reset</a>
            </div>
        </div>
        <div>
            <label class="form-label">Dispatcher</label>
            <?= checkboxFilterGroup('user_id', $users, array_map('strval', $dispatchers)) ?>
        </div>
    </div>
</form>

<div class="card table-card" id="table-partial">
    <div class="card-header">SIM Transfers</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped mb-0 dt-nowrap" data-tt-sort="server"
                   data-offset="<?= $offset ?>">
                <thead>
                <tr>
                    <th data-sort="date">Date</th>
                    <th data-sort="sim">SIM</th>
                    <th data-sort="prev">Previous Driver</th>
                    <th data-sort="new">New Driver</th>
                    <th data-sort="terminal">Terminal</th>
                    <th data-no-sort="1">Reason</th>
                    <th data-sort="performed" data-col-default="hide">Performed By</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">No transfers in this range.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= e(date('d-M-Y H:i', strtotime((string)$r['returned_at']))) ?></td>
                        <td><a href="../sim-history.php?sim_id=<?= (int)$r['sim_id'] ?>" class="phone">
                            <?= e($r['mobile_number']) ?></a></td>
                        <td><?= e($r['prev_driver']) ?> (<?= e($r['prev_badge']) ?>)</td>
                        <td><?php if ($r['new_driver'] !== null): ?>
                                <a href="../driver.php?id=<?= (int)$r['new_driver_id'] ?>">
                                    <?= e($r['new_driver']) ?></a> (<?= e($r['new_badge']) ?>)
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?></td>
                        <td><?= e($r['terminal_name']) ?></td>
                        <td>Driver reassignment<?= $r['remarks'] !== null
                            ? '<div class="small text-muted">' . e($r['remarks']) . '</div>' : '' ?></td>
                        <td><?= e($r['returned_by_name']) ?></td>
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
        <?php renderPagination($page, $pages, 'transfers.php?' . filtersUrl()); ?>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
