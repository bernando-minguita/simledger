<?php
/**
 * SIMLedger - driver list with fast partial search
 * (spec section 13): badge, name, terminal, company SIM number.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/lookups.php';
requirePermission('driver.view');

$pdo   = $GLOBALS['pdo'];
$scope = currentTerminalId();

$q          = trim((string)($_GET['q'] ?? ''));
$fTerminal  = $scope ?? reqInt('terminal_id', 0, 0, 100000);
$fAgency    = reqInt('agency_id', 0, 0, 100000);
$fStatus    = (string)($_GET['status'] ?? '');
$hasSim     = (string)($_GET['has_sim'] ?? '');
if ($scope !== null) {
    $fTerminal = $scope;
}
$statuses = driverStatusOptions();
if (!isset($statuses[$fStatus])) {
    $fStatus = '';
}

$sortMap = [
    'badge'       => 'd.badge_number',
    'name'        => 'd.name',
    'agency'      => 'ag.name',
    'terminal'    => 't.name',
    'iqama'       => 'd.iqama_number',
    'nationality' => 'd.nationality',
    'status'      => 'd.status',
    'sim'         => 's.mobile_number',
    'contacts'    => 'contact_count',
    'history'     => 'sim_history_count',
];
$sort    = reqSort('sort', array_keys($sortMap), 'badge');
$dir     = reqDir('asc');
$page    = reqInt('page', 1, 1, 100000);
$perPage = 25;

$where  = ' WHERE 1=1';
$params = [];
if ($fTerminal > 0) {
    $where .= ' AND d.terminal_id = ?';
    $params[] = $fTerminal;
}
if ($fAgency > 0) {
    $where .= ' AND d.agency_id = ?';
    $params[] = $fAgency;
}
if ($fStatus !== '') {
    $where .= ' AND d.status = ?';
    $params[] = $fStatus;
}
if ($q !== '') {
    $where .= ' AND (d.badge_number LIKE ? OR d.name LIKE ? OR s.mobile_number LIKE ?'
        . ' OR s.normalized_mobile_number LIKE ? OR d.iqama_number LIKE ?)';
    $like = '%' . $q . '%';
    $qNorm = normalizePhone($q);
    array_push($params, $like, $like, $like, '%' . ($qNorm ?? $q) . '%', $like);
}
if ($hasSim === '1') {
    $where .= ' AND EXISTS(SELECT 1 FROM driver_sim_assignments a
                             WHERE a.driver_id = d.id AND a.returned_at IS NULL)';
} elseif ($hasSim === '0') {
    $where .= ' AND NOT EXISTS(SELECT 1 FROM driver_sim_assignments a
                                WHERE a.driver_id = d.id AND a.returned_at IS NULL)';
}

$from = ' FROM drivers d
          JOIN terminals t ON t.id = d.terminal_id
          LEFT JOIN agencies ag ON ag.id = d.agency_id
          LEFT JOIN driver_sim_assignments a ON a.driver_id = d.id AND a.returned_at IS NULL
          LEFT JOIN company_sims s ON s.id = a.sim_id';

$countStmt = $pdo->prepare('SELECT COUNT(DISTINCT d.id)' . $from . $where);
$countStmt->execute($params);
$total  = (int)$countStmt->fetchColumn();
$pages  = max(1, (int)ceil($total / $perPage));
$page   = min($page, $pages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare(
    'SELECT d.id, d.badge_number, d.name, d.status, d.is_active,
            d.agency_id, d.iqama_number, d.nationality, ag.name AS agency_name,
            t.name AS terminal_name, s.mobile_number, s.status AS sim_status,
            (SELECT COUNT(*) FROM driver_contact_numbers c
              WHERE c.driver_id = d.id AND c.is_active = 1) AS contact_count,
            (SELECT COUNT(*) FROM driver_sim_assignments h WHERE h.driver_id = d.id) AS sim_history_count
     ' . $from . $where
    . ' GROUP BY d.id, t.id, a.id, s.id'
    . ' ORDER BY ' . $sortMap[$sort] . ' ' . $dir
    . ' LIMIT ' . $perPage . ' OFFSET ' . $offset
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

if (isset($_GET['export'])) {
    // Separate query WITHOUT LIMIT - the list query above is paginated,
    // so exporting from $rows would only ever emit one page (sims.php pattern).
    $expStmt = $pdo->prepare(
        'SELECT d.badge_number, d.name, ag.name AS agency_name, t.name AS terminal_name,
                d.iqama_number, d.nationality, d.status,
                s.mobile_number, s.status AS sim_status,
                (SELECT COUNT(*) FROM driver_contact_numbers c
                  WHERE c.driver_id = d.id AND c.is_active = 1) AS contact_count,
                (SELECT COUNT(*) FROM driver_sim_assignments h WHERE h.driver_id = d.id) AS sim_history_count
         ' . $from . $where
        . ' GROUP BY d.id, t.id, a.id, s.id
         ORDER BY ' . $sortMap[$sort] . ' ' . $dir
    );
    $expStmt->execute($params);

    $out = [];
    while ($r = $expStmt->fetch()) {
        $out[] = [
            csvSafe((string)$r['badge_number']), csvSafe((string)$r['name']),
            csvSafe((string)($r['agency_name'] ?? '')),
            $r['terminal_name'],
            csvSafe((string)($r['iqama_number'] ?? '')),
            csvSafe((string)($r['nationality'] ?? '')),
            $r['status'],
            $r['mobile_number'] !== null ? csvSafe((string)$r['mobile_number']) : '',
            $r['sim_status'] ?? '',
            (int)$r['contact_count'], (int)$r['sim_history_count'],
        ];
    }
    exportExcel('drivers-' . date('Ymd-His') . '.xlsx', 'Drivers',
        ['Badge', 'Driver', 'Agency', 'Terminal', 'Iqama', 'Nationality', 'Status',
         'Company SIM', 'SIM Status', 'Personal Contacts', 'SIM History Rows'], $out);
}

$pageTitle = 'Drivers';
$activeNav = 'driver-list';
require __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="page-title">Drivers</h1>
        <p class="page-sub"><?= $total ?> driver<?= $total === 1 ? '' : 's' ?>
            &middot; search by badge, name, SIM number or Iqama.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="drivers.php?<?= e(filtersUrl(['export' => 1])) ?>">Export Excel</a>
</div>

<form method="get" class="filter-bar mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label">Search</label>
            <input type="text" class="form-control" name="q" value="<?= e($q) ?>"
                   placeholder="Badge / name / SIM / Iqama" autofocus>
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
            <label class="form-label">Agency</label>
            <select class="form-select" name="agency_id">
                <option value="">All</option>
                <?php foreach (agencyOptions(false) as $aid => $aname): ?>
                    <option value="<?= $aid ?>"<?= $fAgency === $aid ? ' selected' : '' ?>><?= e($aname) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Status</label>
            <select class="form-select" name="status">
                <option value="">All</option>
                <?php foreach ($statuses as $skey => $slabel): ?>
                    <option value="<?= e($skey) ?>"<?= $fStatus === $skey ? ' selected' : '' ?>><?= e($slabel) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Has company SIM</label>
            <select class="form-select" name="has_sim">
                <option value="">Either</option>
                <option value="1"<?= $hasSim === '1' ? ' selected' : '' ?>>Yes</option>
                <option value="0"<?= $hasSim === '0' ? ' selected' : '' ?>>No</option>
            </select>
        </div>
    </div>
    <div class="row mt-2">
        <div class="col">
            <button class="btn btn-outline-primary btn-sm" type="submit">Search</button>
            <a class="btn btn-link btn-sm" href="drivers.php">Reset</a>
        </div>
    </div>
</form>

<div class="card table-card" id="table-partial">
    <div class="card-header">Drivers</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped mb-0 dt-nowrap" data-tt-sort="server"
                   data-offset="<?= ($page - 1) * $perPage ?>">
                <thead>
                <tr>
                    <th data-sort="badge">Badge</th>
                    <th data-sort="driver">Driver</th>
                    <th data-sort="agency">Agency</th>
                    <th data-sort="terminal">Terminal</th>
                    <th data-sort="iqama">Iqama</th>
                    <th data-sort="nationality">Nationality</th>
                    <th data-sort="status">Status</th>
                    <th data-sort="sim">Company SIM</th>
                    <th data-sort="contacts">Personal Contacts</th>
                    <th data-sort="history">SIM History</th>
                    <th class="text-end" data-no-sort="1">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="11" class="text-center text-muted py-3">No drivers match.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= e($r['badge_number']) ?></td>
                        <td><a href="driver.php?id=<?= (int)$r['id'] ?>"><?= e($r['name']) ?></a></td>
                        <td><?= e($r['agency_name'] ?? '—') ?></td>
                        <td><?= e($r['terminal_name']) ?></td>
                        <td class="small"><?= e($r['iqama_number'] ?? '—') ?></td>
                        <td><?= e($r['nationality'] ?? '—') ?></td>
                        <td>
                            <span class="badge <?= $r['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>">
                                <?= e($statuses[$r['status']] ?? $r['status']) ?></span>
                        </td>
                        <td>
                            <?php if ($r['mobile_number'] !== null): ?>
                                <span class="phone"><?= e($r['mobile_number']) ?></span>
                                <?= simStatusBadge((string)$r['sim_status']) ?>
                            <?php else: ?>
                                <span class="text-muted">None</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int)$r['contact_count'] > 0): ?>
                                <span class="badge bg-info text-dark"><?= (int)$r['contact_count'] ?></span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">None</span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int)$r['sim_history_count'] ?></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-outline-secondary"
                               href="driver.php?id=<?= (int)$r['id'] ?>">Profile</a>
                            <?php if ($r['mobile_number'] === null && $r['status'] === 'active'
                                && can('sim.transact')): ?>
                                <a class="btn btn-sm btn-outline-primary"
                                   href="assign.php?driver_q=<?= e(urlencode($r['badge_number'])) ?>">Issue SIM</a>
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
        <?php renderPagination($page, $pages, 'drivers.php?' . filtersUrl()); ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
