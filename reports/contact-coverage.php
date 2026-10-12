<?php
/**
 * SIMLedger - Contact Coverage report.
 * How many drivers have personal contact numbers, per terminal/status.
 */

declare(strict_types=1);

$base = '../';

require_once __DIR__ . '/../includes/lookups.php';
requirePermission('report.view');

$pdo   = $GLOBALS['pdo'];
$scope = currentTerminalId();

$fTerminals = array_map('intval', filterValues('terminal_id'));
$fStatuses  = array_values(array_intersect(
    filterValues('status'), array_keys(driverStatusOptions())
));
$coverages  = array_values(array_intersect(
    filterValues('coverage'), ['with', 'none']
));
if ($scope !== null) {
    $fTerminals = [$scope];   // dispatcher: server-enforced terminal scope
}
$statuses = driverStatusOptions();

$where  = ' WHERE d.is_active = 1';
$params = [];
if ($fTerminals !== []) {
    $where .= ' AND d.terminal_id IN (' . implode(',', array_fill(0, count($fTerminals), '?')) . ')';
    array_push($params, ...$fTerminals);
}
if ($fStatuses !== []) {
    $where .= ' AND d.status IN (' . implode(',', array_fill(0, count($fStatuses), '?')) . ')';
    array_push($params, ...$fStatuses);
}
// Reachability is binary: both checked = either, no filter.
if ($coverages === ['with']) {
    $where .= ' AND (EXISTS(SELECT 1 FROM driver_contact_numbers c
                             WHERE c.driver_id = d.id AND c.is_active = 1)
                   OR EXISTS(SELECT 1 FROM driver_sim_assignments a
                              WHERE a.driver_id = d.id AND a.returned_at IS NULL))';
} elseif ($coverages === ['none']) {
    $where .= ' AND NOT EXISTS(SELECT 1 FROM driver_contact_numbers c
                                WHERE c.driver_id = d.id AND c.is_active = 1)
              AND NOT EXISTS(SELECT 1 FROM driver_sim_assignments a
                              WHERE a.driver_id = d.id AND a.returned_at IS NULL)';
}

// Per-terminal summary.
$summaryStmt = $pdo->prepare(
    'SELECT t.id, t.name,
            COUNT(*) AS total_drivers,
            SUM(EXISTS(SELECT 1 FROM driver_contact_numbers c
                        WHERE c.driver_id = d.id AND c.is_active = 1)) AS with_contact,
            SUM(NOT EXISTS(SELECT 1 FROM driver_contact_numbers c
                        WHERE c.driver_id = d.id AND c.is_active = 1)) AS without_contact,
            SUM(EXISTS(SELECT 1 FROM driver_sim_assignments a
                        WHERE a.driver_id = d.id AND a.returned_at IS NULL)) AS with_sim,
            SUM(EXISTS(SELECT 1 FROM driver_contact_numbers c
                        WHERE c.driver_id = d.id AND c.is_active = 1)
             OR EXISTS(SELECT 1 FROM driver_sim_assignments a
                        WHERE a.driver_id = d.id AND a.returned_at IS NULL)) AS reachable,
            SUM(NOT EXISTS(SELECT 1 FROM driver_contact_numbers c
                        WHERE c.driver_id = d.id AND c.is_active = 1)
             AND NOT EXISTS(SELECT 1 FROM driver_sim_assignments a
                        WHERE a.driver_id = d.id AND a.returned_at IS NULL)) AS not_reachable
       FROM drivers d
       JOIN terminals t ON t.id = d.terminal_id
      WHERE d.is_active = 1 AND d.status = "active"'
        . ($fTerminals !== [] ? ' AND d.terminal_id IN ('
            . implode(',', array_fill(0, count($fTerminals), '?')) . ')' : '') . '
      GROUP BY t.id, t.name
      ORDER BY t.name'
);
$summaryStmt->execute($fTerminals);
$summary = $summaryStmt->fetchAll();

// Detail rows for the selected coverage filter.
$baseSql = 'SELECT d.id, d.badge_number, d.name, d.status, d.terminal_id, t.name AS terminal_name,
            (SELECT COUNT(*) FROM driver_contact_numbers c
              WHERE c.driver_id = d.id AND c.is_active = 1) AS contact_count,
            EXISTS(SELECT 1 FROM driver_sim_assignments a
                    WHERE a.driver_id = d.id AND a.returned_at IS NULL) AS has_sim,
            (EXISTS(SELECT 1 FROM driver_contact_numbers c
                     WHERE c.driver_id = d.id AND c.is_active = 1)
             OR EXISTS(SELECT 1 FROM driver_sim_assignments a
                        WHERE a.driver_id = d.id AND a.returned_at IS NULL)) AS reachable
       FROM drivers d
       JOIN terminals t ON t.id = d.terminal_id
      ' . $where;

$sortMap = [
    'terminal' => 't.name, d.badge_number',
    'badge'    => 'd.badge_number',
    'driver'   => 'd.name',
    'status'   => 'd.status',
    'contacts' => 'contact_count',
    'sim'      => 'has_sim',
    'reachable' => 'reachable',
];
$sort    = reqSort('sort', array_keys($sortMap), 'terminal');
$dir     = reqDir('asc');
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
    while ($d = $expStmt->fetch()) {
        $out[] = [
            csvSafe((string)$d['badge_number']), csvSafe((string)$d['name']),
            $d['terminal_name'], $statuses[$d['status']] ?? $d['status'],
            (int)$d['contact_count'] > 0 ? 'Yes' : 'No',
            $d['has_sim'] ? 'Yes' : 'No',
            $d['reachable'] ? 'Yes' : 'No',
        ];
    }
    exportExcel('contact-coverage-' . date('Ymd-His') . '.xlsx', 'Contact Coverage',
        ['Badge', 'Driver', 'Terminal', 'Status', 'Has Personal Number', 'Has Company SIM',
         'Reachable'],
        $out);
}

$stmt = $pdo->prepare($baseSql . ' ORDER BY ' . $sortMap[$sort] . ' ' . $dir
    . " LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$details = $stmt->fetchAll();

$pageTitle = 'Contact Coverage';
$activeNav = 'report-coverage';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="page-title">Contact Coverage</h1>
        <p class="page-sub">Driver reachability across active drivers &mdash; a driver counts as
            <strong>reachable</strong> with an active personal contact number <em>or</em> a currently
            held company SIM. The two remain tracked separately (SIMs are assets, numbers are contacts).</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm"
       href="contact-coverage.php?<?= e(filtersUrl(['export' => 1])) ?>">Export Excel</a>
</div>

<!-- per-terminal summary -->
<div class="row g-3 mb-3">
    <?php foreach ($summary as $s):
        $pct = (int)$s['total_drivers'] > 0
            ? round(100 * (int)$s['reachable'] / (int)$s['total_drivers']) : 0;
        ?>
        <div class="col-md-4">
            <div class="kpi kpi-accent-available">
                <span class="kpi-label"><?= e($s['name']) ?> &mdash; reachable drivers</span>
                <span class="kpi-value"><?= (int)$s['reachable'] ?> / <?= (int)$s['total_drivers'] ?>
                    <span class="fs-6 text-muted">(<?= $pct ?>%)</span></span>
                <span class="small text-muted">
                    <?= (int)$s['not_reachable'] ?> not reachable &middot;
                    <?= (int)$s['with_contact'] ?> with personal number &middot;
                    <?= (int)$s['with_sim'] ?> with company SIM
                </span>
            </div>
        </div>
    <?php endforeach; ?>
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
            <label class="form-label">Coverage</label>
            <?= checkboxFilterGroup('coverage', ['with' => 'Reachable (number or SIM)', 'none' => 'Not reachable'], array_map('strval', $coverages)) ?>
        </div>
        <div>
            <label class="form-label">Driver status</label>
            <?= checkboxFilterGroup('status', $statuses, array_map('strval', $fStatuses)) ?>
        </div>
        <div>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-primary btn-sm" type="submit">Apply filters</button>
                <a class="btn btn-link btn-sm" href="contact-coverage.php">Reset</a>
            </div>
        </div>
    </div>
</form>


<div class="card table-card" id="table-partial">
    <div class="card-header">Contact Coverage</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped mb-0 dt-nowrap" data-tt-sort="server"
                   data-offset="<?= $offset ?>">
                <thead>
                <tr>
                    <th data-sort="badge">Badge</th>
                    <th data-sort="driver">Driver</th>
                    <th data-sort="terminal">Terminal</th>
                    <th data-sort="status">Status</th>
                    <th data-sort="contacts">Personal Number</th>
                    <th data-sort="sim">Company SIM</th>
                    <th data-sort="reachable">Reachable</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($details === []): ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">No drivers match.</td></tr>
                <?php endif; ?>
                <?php foreach ($details as $d): ?>
                    <tr>
                        <td><?= e($d['badge_number']) ?></td>
                        <td><a href="../driver.php?id=<?= (int)$d['id'] ?>"><?= e($d['name']) ?></a></td>
                        <td><?= e($d['terminal_name']) ?></td>
                        <td><?= e($statuses[$d['status']] ?? $d['status']) ?></td>
                        <td><?= (int)$d['contact_count'] > 0
                            ? '<span class="badge bg-success">' . (int)$d['contact_count'] . ' number(s)</span>'
                            : '<span class="badge bg-warning text-dark">None</span>' ?></td>
                        <td><?= $d['has_sim']
                            ? '<span class="badge bg-info text-dark">Assigned</span>'
                            : '<span class="badge bg-secondary">No SIM</span>' ?></td>
                        <td><?= $d['reachable']
                            ? '<span class="badge bg-success">Yes</span>'
                            : '<span class="badge bg-danger">No</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <span class="small text-muted">
            Showing <?= count($details) ?> of <?= $total ?> &middot; page <?= $page ?> of <?= $pages ?>
        </span>
        <?php renderPagination($page, $pages, 'contact-coverage.php?' . filtersUrl()); ?>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
