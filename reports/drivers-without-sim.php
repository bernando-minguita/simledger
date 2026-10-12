<?php
/**
 * SIMLedger - Drivers Without Company SIM (spec section 23).
 * Operational exception report with terminal/status/contact filters.
 */

declare(strict_types=1);

$base = '../';

require_once __DIR__ . '/../includes/lookups.php';
requirePermission('report.view');

$pdo   = $GLOBALS['pdo'];
$scope = currentTerminalId();

$q         = trim((string)($_GET['q'] ?? ''));
$fTerminals = array_map('intval', filterValues('terminal_id'));
$fStatuses  = array_values(array_intersect(
    filterValues('status'), array_keys(driverStatusOptions())
));
$contacts   = array_values(array_intersect(
    filterValues('contact'), ['with', 'none']
));
if ($scope !== null) {
    $fTerminals = [$scope];   // dispatcher: server-enforced terminal scope
}
$statuses = driverStatusOptions();

$where  = ' WHERE d.is_active = 1 AND NOT EXISTS(
                SELECT 1 FROM driver_sim_assignments a
                 WHERE a.driver_id = d.id AND a.returned_at IS NULL)';
$params = [];
if ($fTerminals !== []) {
    $where .= ' AND d.terminal_id IN (' . implode(',', array_fill(0, count($fTerminals), '?')) . ')';
    array_push($params, ...$fTerminals);
}
if ($fStatuses !== []) {
    $where .= ' AND d.status IN (' . implode(',', array_fill(0, count($fStatuses), '?')) . ')';
    array_push($params, ...$fStatuses);
}
if ($q !== '') {
    $where .= ' AND (d.badge_number LIKE ? OR d.name LIKE ?)';
    array_push($params, '%' . $q . '%', '%' . $q . '%');
}
// Contact presence is binary: both checked = either, no filter.
if ($contacts === ['with']) {
    $where .= ' AND EXISTS(SELECT 1 FROM driver_contact_numbers c
                            WHERE c.driver_id = d.id AND c.is_active = 1)';
} elseif ($contacts === ['none']) {
    $where .= ' AND NOT EXISTS(SELECT 1 FROM driver_contact_numbers c
                                WHERE c.driver_id = d.id AND c.is_active = 1)';
}

$baseSql = 'SELECT d.id, d.badge_number, d.name, d.status, d.terminal_id, t.name AS terminal_name,
               (SELECT c.phone_number FROM driver_contact_numbers c
                 WHERE c.driver_id = d.id AND c.is_active = 1
                 ORDER BY c.is_primary DESC, c.id LIMIT 1) AS personal_contact,
               (SELECT s2.mobile_number FROM driver_sim_assignments a2
                  JOIN company_sims s2 ON s2.id = a2.sim_id
                 WHERE a2.driver_id = d.id
                 ORDER BY a2.issued_at DESC LIMIT 1) AS last_company_sim,
               (SELECT MAX(a2.returned_at) FROM driver_sim_assignments a2
                 WHERE a2.driver_id = d.id) AS last_returned_at
          FROM drivers d
          JOIN terminals t ON t.id = d.terminal_id
         ' . $where;

$sortMap = [
    'terminal' => 't.name, d.badge_number',
    'badge'    => 'd.badge_number',
    'driver'   => 'd.name',
    'status'   => 'd.status',
    'contact'  => 'personal_contact',
    'sim'      => 'last_company_sim',
    'returned' => 'last_returned_at',
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
    while ($r = $expStmt->fetch()) {
        $contactDisplay = '';
        if ($r['personal_contact'] !== null) {
            $contactDisplay = canViewFullNumbers((int)$r['terminal_id'])
                ? $r['personal_contact']
                : maskPhone(normalizePhone($r['personal_contact']));
        }
        $out[] = [
            csvSafe((string)$r['badge_number']), csvSafe((string)$r['name']),
            $r['terminal_name'], $statuses[$r['status']] ?? $r['status'],
            csvSafe((string)$contactDisplay),
            $r['last_company_sim'] !== null ? csvSafe((string)$r['last_company_sim']) : '',
            $r['last_returned_at'] !== null ? date('Y-m-d', strtotime((string)$r['last_returned_at'])) : '',
        ];
    }
    exportExcel('drivers-without-sim-' . date('Ymd-His') . '.xlsx', 'Drivers Without SIM',
        ['Badge', 'Driver', 'Terminal', 'Driver Status', 'Personal Contact',
         'Last Company SIM', 'Last Return Date'], $out);
}

$stmt = $pdo->prepare($baseSql . ' ORDER BY ' . $sortMap[$sort] . ' ' . $dir
    . " LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pageTitle = 'Drivers Without SIM';
$activeNav = 'driver-nosim';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="page-title">Drivers Without Company SIM</h1>
        <p class="page-sub"><?= $total ?> driver<?= $total === 1 ? '' : 's' ?>
            currently hold no company SIM.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm"
       href="drivers-without-sim.php?<?= e(filtersUrl(['export' => 1])) ?>">Export Excel</a>
</div>

<form method="get" class="filter-bar mb-3">
    <div class="d-flex flex-wrap align-items-end gap-2 gap-md-3">
        <div>
            <label class="form-label">Search</label>
            <div class="d-flex gap-2">
                <input type="text" class="form-control" name="q" value="<?= e($q) ?>" placeholder="Badge / name">
                <button class="btn btn-outline-primary" type="submit">Search</button>
                <a class="btn btn-link" href="drivers-without-sim.php">Reset</a>
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
            <label class="form-label">Driver status</label>
            <?= checkboxFilterGroup('status', $statuses, array_map('strval', $fStatuses)) ?>
        </div>
        <div>
            <label class="form-label">Has personal number</label>
            <?= checkboxFilterGroup('contact', ['with' => 'Yes', 'none' => 'No'], array_map('strval', $contacts)) ?>
        </div>
    </div>
</form>

<div class="card table-card" id="table-partial">
    <div class="card-header">Drivers Without Company SIM</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped mb-0 dt-nowrap" data-tt-sort="server"
                   data-offset="<?= $offset ?>">
                <thead>
                <tr>
                    <th data-sort="badge">Badge</th>
                    <th data-sort="driver">Driver</th>
                    <th data-sort="terminal">Terminal</th>
                    <th data-sort="status">Driver Status</th>
                    <th data-sort="contact">Personal Contact</th>
                    <th data-sort="sim">Last Company SIM</th>
                    <th class="text-end" data-no-sort="1">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7" class="text-center text-success py-3">
                        Every matching driver has a company SIM.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= e($r['badge_number']) ?></td>
                        <td><a href="../driver.php?id=<?= (int)$r['id'] ?>"><?= e($r['name']) ?></a></td>
                        <td><?= e($r['terminal_name']) ?></td>
                        <td><span class="badge <?= $r['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>">
                            <?= e($statuses[$r['status']] ?? $r['status']) ?></span></td>
                        <td class="phone"><?= $r['personal_contact'] !== null
                            ? e(canViewFullNumbers((int)$r['terminal_id'])
                                ? $r['personal_contact'] : maskPhone(normalizePhone($r['personal_contact'])))
                            : '<span class="badge bg-warning text-dark">None</span>' ?></td>
                        <td class="phone"><?= e($r['last_company_sim'] ?? '—') ?></td>
                        <td class="text-end">
                            <?php if ($r['status'] === 'active' && can('sim.transact')): ?>
                                <a class="btn btn-sm btn-outline-primary"
                                   href="../assign.php?driver_q=<?= e(urlencode($r['badge_number'])) ?>">Issue SIM</a>
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
        <?php renderPagination($page, $pages, 'drivers-without-sim.php?' . filtersUrl()); ?>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
