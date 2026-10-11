<?php
/**
 * SIMLedger - driver personal contact list.
 *
 * All recorded personal contact numbers (never company inventory), with
 * search, terminal filter, coverage indicators and CSV export.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/lookups.php';
requirePermission('contact.view');

$pdo   = $GLOBALS['pdo'];
$scope = currentTerminalId();

$q         = trim((string)($_GET['q'] ?? ''));
$fTerminal = $scope ?? reqInt('terminal_id', 0, 0, 100000);
$fType     = (string)($_GET['type'] ?? '');
$coverage  = (string)($_GET['coverage'] ?? '');   // with | none
if ($scope !== null) {
    $fTerminal = $scope;
}

$where  = ' WHERE d.is_active = 1';
$params = [];
if ($fTerminal > 0) {
    $where .= ' AND d.terminal_id = ?';
    $params[] = $fTerminal;
}
if ($q !== '') {
    $where .= ' AND (d.badge_number LIKE ? OR d.name LIKE ? OR c.phone_number LIKE ?'
        . ' OR c.normalized_phone_number LIKE ?)';
    $like = '%' . $q . '%';
    $qNorm = normalizePhone($q);
    array_push($params, $like, $like, $like, '%' . ($qNorm ?? $q) . '%');
}
if (in_array($fType, ['personal', 'emergency', 'other'], true)) {
    $where .= ' AND c.contact_type = ?';
    $params[] = $fType;
}
if ($coverage === 'none') {
    $where .= ' AND NOT EXISTS(SELECT 1 FROM driver_contact_numbers c2
                                WHERE c2.driver_id = d.id AND c2.is_active = 1)';
} elseif ($coverage === 'with') {
    $where .= ' AND EXISTS(SELECT 1 FROM driver_contact_numbers c2
                            WHERE c2.driver_id = d.id AND c2.is_active = 1)';
}

$join = $coverage === 'none' ? ' LEFT JOIN driver_contact_numbers c ON 1 = 0'
    : ' LEFT JOIN driver_contact_numbers c ON c.driver_id = d.id AND c.is_active = 1';

$baseSql = 'SELECT d.id AS driver_id, d.badge_number, d.name, d.status, d.terminal_id,
            t.name AS terminal_name,
            GROUP_CONCAT(CONCAT_WS("|", c.contact_type, c.phone_number, c.is_primary)
                ORDER BY c.is_primary DESC, c.id SEPARATOR ";;") AS contact_blob
       FROM drivers d
       JOIN terminals t ON t.id = d.terminal_id
      ' . $join . $where . '
      GROUP BY d.id';

$sortMap = [
    'badge'    => 'd.badge_number',
    'driver'   => 'd.name',
    'terminal' => 't.name',
    'status'   => 'd.status',
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
    $blobs = $r['contact_blob'] !== null ? explode(';;', (string)$r['contact_blob']) : [];
        if ($blobs === [] || $blobs === ['']) {
            $out[] = [csvSafe((string)$r['badge_number']), csvSafe((string)$r['name']),
                $r['terminal_name'], $r['status'], '', '', ''];
            continue;
        }
        foreach ($blobs as $blob) {
            $p = explode('|', $blob);
            // Same masking policy as the on-screen list: personal numbers are
            // masked for users without full-number visibility.
            $phone = $p[1] ?? '';
            $norm  = normalizePhone($phone);
            $out[] = [
                csvSafe((string)$r['badge_number']), csvSafe((string)$r['name']),
                $r['terminal_name'], $r['status'],
                $p[0] ?? '',
                canViewFullNumbers((int)$r['terminal_id']) ? $phone : maskPhone($norm),
                ($p[2] ?? '0') === '1' ? 'Yes' : 'No',
            ];
        }
    }
    exportExcel('driver-contacts-' . date('Ymd-His') . '.xlsx', 'Driver Contacts',
        ['Badge', 'Driver', 'Terminal', 'Driver Status', 'Contact Type', 'Phone', 'Primary'],
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

$types        = ['personal' => 'Personal', 'emergency' => 'Emergency', 'other' => 'Other'];
$statusesList = driverStatusOptions();

$pageTitle = 'Driver Contacts';
$activeNav = 'driver-contacts';
require __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="page-title">Driver Contacts</h1>
        <p class="page-sub">Personal numbers only &mdash; company SIMs live in
            <a href="sims.php">SIM Inventory</a>, never here.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="contacts.php?<?= e(filtersUrl(['export' => 1])) ?>">Export Excel</a>
</div>

<form method="get" class="filter-bar mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label">Search</label>
            <input type="text" class="form-control" name="q" value="<?= e($q) ?>"
                   placeholder="Badge / name / phone">
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
            <label class="form-label">Contact type</label>
            <select class="form-select" name="type">
                <option value="">All</option>
                <?php foreach ($types as $tkey => $tlabel): ?>
                    <option value="<?= e($tkey) ?>"<?= $fType === $tkey ? ' selected' : '' ?>><?= e($tlabel) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Coverage</label>
            <select class="form-select" name="coverage">
                <option value="">Either</option>
                <option value="with"<?= $coverage === 'with' ? ' selected' : '' ?>>Has number</option>
                <option value="none"<?= $coverage === 'none' ? ' selected' : '' ?>>No number</option>
            </select>
        </div>
        <div class="col-md-3">
            <button class="btn btn-outline-primary btn-sm" type="submit">Filter</button>
            <a class="btn btn-link btn-sm" href="contacts.php">Reset</a>
        </div>
    </div>
</form>

<div class="card table-card" id="table-partial">
    <div class="card-header">Driver Contacts</div>
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
                    <th data-no-sort="1">Contact Type</th>
                    <th data-no-sort="1">Phone</th>
                    <th data-no-sort="1">Primary</th>
                    <th class="text-end" data-no-sort="1">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="8" class="text-center text-muted py-3">No drivers match.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r):
                    $blobs = $r['contact_blob'] !== null ? explode(';;', (string)$r['contact_blob']) : [];
                    if ($blobs === ['']) {
                        $blobs = [];
                    }
                    ?>
                    <?php if ($blobs === []): ?>
                        <tr>
                            <td><?= e($r['badge_number']) ?></td>
                            <td><a href="driver.php?id=<?= (int)$r['driver_id'] ?>"><?= e($r['name']) ?></a></td>
                            <td><?= e($r['terminal_name']) ?></td>
                            <td><?= e($statusesList[$r['status']] ?? $r['status']) ?></td>
                            <td colspan="3"><span class="badge bg-warning text-dark">No contact number</span></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="driver.php?id=<?= (int)$r['driver_id'] ?>">Profile</a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($blobs as $blob):
                            $p = explode('|', $blob);
                            $norm = normalizePhone($p[1] ?? '');
                            ?>
                            <tr>
                                <td><?= e($r['badge_number']) ?></td>
                                <td><a href="driver.php?id=<?= (int)$r['driver_id'] ?>"><?= e($r['name']) ?></a></td>
                                <td><?= e($r['terminal_name']) ?></td>
                                <td><?= e($statusesList[$r['status']] ?? $r['status']) ?></td>
                                <td><?= e($types[$p[0] ?? ''] ?? ($p[0] ?? '')) ?></td>
                                <td class="phone"><?= e(canViewFullNumbers((int)$r['terminal_id'])
                                    ? ($p[1] ?? '') : maskPhone($norm)) ?></td>
                                <td><?= ($p[2] ?? '0') === '1' ? 'Yes' : 'No' ?></td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="driver.php?id=<?= (int)$r['driver_id'] ?>">Profile</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <span class="small text-muted">
            Showing <?= count($rows) ?> of <?= $total ?> &middot; page <?= $page ?> of <?= $pages ?>
        </span>
        <?php renderPagination($page, $pages, 'contacts.php?' . filtersUrl()); ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
