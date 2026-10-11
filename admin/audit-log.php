<?php
/**
 * SIMLedger - audit log (admin only, read-only).
 *
 * Audit rows are append-only: this page never updates or deletes them
 * (spec sections 26 and 48).
 */

declare(strict_types=1);

$base = '../';

require_once __DIR__ . '/../includes/lookups.php';

$pdo = $GLOBALS['pdo'];
requirePermission('audit.view');

// --- filters ---------------------------------------------------------------
$q        = trim((string)($_GET['q'] ?? ''));
$fAction  = trim((string)($_GET['action'] ?? ''));
$fUser    = reqInt('user_id', 0, 0, 100000000);
$fTerm    = reqInt('terminal_id', 0, 0, 100000);
$from     = trim((string)($_GET['from'] ?? ''));
$to       = trim((string)($_GET['to'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-m-d', strtotime('-14 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = date('Y-m-d');
}

$sortMap = [
    'created'  => 'l.created_at',
    'user'     => 'l.username',
    'action'   => 'l.action',
    'entity'   => 'l.entity_type',
    'terminal' => 't.name',
    'ip'       => 'l.ip_address',
    'changes'  => 'l.old_values',
];
$sort    = reqSort('sort', array_keys($sortMap), 'created');
$dir     = reqDir('desc');
$page    = reqInt('page', 1, 1, 100000);
$perPage = 50;

$where  = ' WHERE l.created_at BETWEEN ? AND ?';
$params = [$from . ' 00:00:00', $to . ' 23:59:59'];
if ($q !== '') {
    $where .= ' AND (l.username LIKE ? OR l.entity_id LIKE ? OR l.new_values LIKE ?'
        . ' OR l.old_values LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($fAction !== '') {
    $where .= ' AND l.action = ?';
    $params[] = $fAction;
}
if ($fUser > 0) {
    $where .= ' AND l.user_id = ?';
    $params[] = $fUser;
}
if ($fTerm > 0) {
    $where .= ' AND l.terminal_id = ?';
    $params[] = $fTerm;
}

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM audit_logs l' . $where);
$countStmt->execute($params);
$total  = (int)$countStmt->fetchColumn();
$pages  = max(1, (int)ceil($total / $perPage));
$page   = min($page, $pages);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare(
    'SELECT l.*, t.name AS terminal_name
       FROM audit_logs l
  LEFT JOIN terminals t ON t.id = l.terminal_id
      ' . $where . '
      ORDER BY ' . $sortMap[$sort] . ' ' . $dir . '
      LIMIT ' . $perPage . ' OFFSET ' . $offset
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$actions = [];
foreach ($pdo->query('SELECT DISTINCT action FROM audit_logs ORDER BY action') as $a) {
    $actions[] = $a['action'];
}
$users = [];
foreach ($pdo->query('SELECT id, username FROM users ORDER BY username') as $u) {
    $users[(int)$u['id']] = $u['username'];
}

if (isset($_GET['export'])) {
    $expStmt = $pdo->prepare(
        'SELECT l.*, t.name AS terminal_name FROM audit_logs l
          LEFT JOIN terminals t ON t.id = l.terminal_id
         ' . $where . ' ORDER BY l.created_at DESC LIMIT 50000'
    );
    $expStmt->execute($params);
    $out = [];
    foreach ($expStmt as $r) {
        $out[] = [
            date('Y-m-d H:i:s', strtotime((string)$r['created_at'])),
            csvSafe((string)($r['username'] ?? '')), $r['action'],
            $r['entity_type'], $r['entity_id'] !== null ? (string)$r['entity_id'] : '',
            $r['terminal_name'] ?? '', csvSafe((string)($r['ip_address'] ?? '')),
            csvSafe((string)($r['old_values'] ?? '')), csvSafe((string)($r['new_values'] ?? '')),
        ];
    }
    exportExcel('audit-log-' . date('Ymd-His') . '.xlsx', 'Audit Log',
        ['Created At', 'Username', 'Action', 'Entity Type', 'Entity ID',
         'Terminal', 'IP Address', 'Old Values', 'New Values'], $out);
}

$pageTitle = 'Audit Log';
$activeNav = 'admin-audit';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="page-title">Audit Log</h1>
        <p class="page-sub"><?= $total ?> record<?= $total === 1 ? '' : 's' ?>
            between <?= e($from) ?> and <?= e($to) ?> &mdash; append-only, never edited or deleted.</p>
    </div>
    <a class="btn btn-outline-secondary btn-sm" href="audit-log.php?<?= e(filtersUrl(['export' => 1])) ?>">Export Excel</a>
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
            <label class="form-label">Action</label>
            <select class="form-select" name="action">
                <option value="">All</option>
                <?php foreach ($actions as $a): ?>
                    <option value="<?= e($a) ?>"<?= $fAction === $a ? ' selected' : '' ?>><?= e($a) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">User</label>
            <select class="form-select" name="user_id">
                <option value="">All</option>
                <?php foreach ($users as $uid => $uname): ?>
                    <option value="<?= $uid ?>"<?= $fUser === $uid ? ' selected' : '' ?>><?= e($uname) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Terminal</label>
            <select class="form-select" name="terminal_id">
                <option value="">All</option>
                <?php foreach (terminalOptions(false) as $tid => $tname): ?>
                    <option value="<?= $tid ?>"<?= $fTerm === $tid ? ' selected' : '' ?>><?= e($tname) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Search</label>
            <input type="text" class="form-control" name="q" value="<?= e($q) ?>" placeholder="payload…">
        </div>
    </div>
    <div class="row mt-2">
        <div class="col">
            <button class="btn btn-outline-primary btn-sm" type="submit">Filter</button>
            <a class="btn btn-link btn-sm" href="audit-log.php">Reset</a>
        </div>
    </div>
</form>

<div class="card table-card" id="table-partial">
    <div class="card-header">Audit Log</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped mb-0 dt-nowrap" data-tt-sort="server"
                   data-offset="<?= ($page - 1) * $perPage ?>">
                <thead>
                <tr>
                    <th data-sort="created">Date / Time</th>
                    <th data-sort="user">User</th>
                    <th data-sort="action">Action</th>
                    <th data-sort="entity">Entity</th>
                    <th data-sort="terminal">Terminal</th>
                    <th data-sort="ip">IP</th>
                    <th data-sort="changes">Old → New</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7" class="text-center text-muted py-3">No audit records in range.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= e(date('d-M-y H:i:s', strtotime((string)$r['created_at']))) ?></td>
                        <td><?= e($r['username'] ?? '—') ?></td>
                        <td><span class="badge bg-light text-dark border"><?= e($r['action']) ?></span></td>
                        <td class="small"><?= e($r['entity_type']) ?>
                            #<?= e($r['entity_id'] ?? '—') ?></td>
                        <td><?= e($r['terminal_name'] ?? '—') ?></td>
                        <td class="small"><?= e($r['ip_address'] ?? '—') ?></td>
                        <td class="small" style="max-width:340px;white-space:normal">
                            <?php if ($r['old_values'] !== null || $r['new_values'] !== null): ?>
                                <code class="d-block text-muted"><?= e((string)($r['old_values'] ?? '—')) ?></code>
                                <code class="d-block"><?= e((string)($r['new_values'] ?? '—')) ?></code>
                            <?php else: ?>
                                —
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
        <?php renderPagination($page, $pages, 'audit-log.php?' . filtersUrl()); ?>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>

