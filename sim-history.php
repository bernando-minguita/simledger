<?php
/**
 * SIMLedger - SIM assignment history / chain of custody.
 *
 * Shows the current holder and the full, immutable history for one SIM
 * (spec sections 20 and 22), or the global transaction list when no SIM
 * is selected.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/lookups.php';
require_once __DIR__ . '/includes/services/SimService.php';
requirePermission('sim.view');

$pdo   = $GLOBALS['pdo'];
$scope = currentTerminalId();

$simId = reqInt('sim_id', 0, 0, 100000000);

// Terminal guard: a dispatcher may only view their own terminal's SIMs.
$sim = null;
if ($simId > 0) {
    $stmt = $pdo->prepare(
        'SELECT s.*, t.name AS terminal_name, c.name AS carrier_name
           FROM company_sims s
           JOIN terminals t ON t.id = s.terminal_id
           JOIN carriers c ON c.id = s.carrier_id
          WHERE s.id = ?'
    );
    $stmt->execute([$simId]);
    $sim = $stmt->fetch() ?: null;

    if ($sim !== null) {
        assertTerminalAccess((int)$sim['terminal_id']);
    }
}

$assignments = [];
if ($sim !== null) {
    $stmt = $pdo->prepare(
        'SELECT a.*, d.name AS driver_name, d.badge_number,
                u1.name AS issued_by_name, u2.name AS returned_by_name,
                t.name AS terminal_name
           FROM driver_sim_assignments a
           JOIN drivers d ON d.id = a.driver_id
           JOIN users u1 ON u1.id = a.issued_by
      LEFT JOIN users u2 ON u2.id = a.returned_by
           JOIN terminals t ON t.id = a.terminal_id
          WHERE a.sim_id = ?
          ORDER BY a.issued_at DESC, a.id DESC'
    );
    $stmt->execute([$simId]);
    $assignments = $stmt->fetchAll();
}

// Excel export of one SIM's full chain of custody (no display cap).
if ($sim !== null && isset($_GET['export'])) {
    $out = [];
    foreach ($assignments as $a) {
        $out[] = [
            date('Y-m-d H:i', strtotime((string)$a['issued_at'])),
            $a['terminal_name'],
            csvSafe((string)$sim['mobile_number']),
            csvSafe((string)$a['driver_name']),
            csvSafe((string)$a['badge_number']),
            $a['returned_at'] !== null ? 'Closed' : 'Current',
            $a['returned_at'] !== null
                ? date('Y-m-d H:i', strtotime((string)$a['returned_at'])) : 'CURRENT',
            csvSafe((string)$a['issued_by_name']),
            csvSafe((string)($a['return_reason'] ?? $a['assignment_reason'] ?? '')),
            csvSafe((string)($a['remarks'] ?? '')),
        ];
    }
    exportExcel('sim-' . $sim['mobile_number'] . '-history-' . date('Ymd-His') . '.xlsx',
        'Chain of Custody - SIM ' . $sim['mobile_number'],
        ['Issued At', 'Terminal', 'SIM', 'Driver', 'Badge', 'State', 'Returned At',
         'Performed By', 'Reason', 'Remarks'], $out);
}

// Global feed when no SIM selected.
$global = [];
if ($sim === null) {
    $q     = trim((string)($_GET['q'] ?? ''));
    $where  = ' WHERE 1=1';
    $params = [];
    if ($scope !== null) {
        $where .= ' AND a.terminal_id = ?';
        $params[] = $scope;
    }
    if ($q !== '') {
        $where .= ' AND (s.mobile_number LIKE ? OR d.name LIKE ? OR d.badge_number LIKE ?)';
        array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
    }

    $baseSql = 'SELECT a.*, d.name AS driver_name, d.badge_number, s.mobile_number,
                u1.name AS issued_by_name, u2.name AS returned_by_name, t.name AS terminal_name
           FROM driver_sim_assignments a
           JOIN company_sims s ON s.id = a.sim_id
           JOIN drivers d ON d.id = a.driver_id
           JOIN users u1 ON u1.id = a.issued_by
      LEFT JOIN users u2 ON u2.id = a.returned_by
           JOIN terminals t ON t.id = a.terminal_id
          ' . $where;
    // NOTE: no "s.id AS sim_id" here - a.* already carries sim_id and a
    // duplicate column name would break the COUNT(*) derived-table wrapper.

    $sortMap = [
        'issued'    => 'a.issued_at',
        'terminal'  => 't.name',
        'sim'       => 's.mobile_number',
        'driver'    => 'd.name',
        'badge'     => 'd.badge_number',
        'issuedby'  => 'u1.name',
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

    $stmt = $pdo->prepare($baseSql . ' ORDER BY ' . $sortMap[$sort] . ' ' . $dir
        . " LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    $global = $stmt->fetchAll();

    // Excel export: ALL matching rows (the display is paginated, the export
    // is not), respecting the same terminal scope + search filter.
    if (isset($_GET['export'])) {
        $expStmt = $pdo->prepare(
            'SELECT a.*, d.name AS driver_name, d.badge_number, s.mobile_number, s.id AS sim_id,
                    u1.name AS issued_by_name, u2.name AS returned_by_name, t.name AS terminal_name
               FROM driver_sim_assignments a
               JOIN company_sims s ON s.id = a.sim_id
               JOIN drivers d ON d.id = a.driver_id
               JOIN users u1 ON u1.id = a.issued_by
          LEFT JOIN users u2 ON u2.id = a.returned_by
               JOIN terminals t ON t.id = a.terminal_id
              ' . $where . '
              ORDER BY a.issued_at DESC, a.id DESC'
        );
        $expStmt->execute($params);
        $out = [];
        while ($a = $expStmt->fetch()) {
            $out[] = [
                date('Y-m-d H:i', strtotime((string)$a['issued_at'])),
                $a['terminal_name'],
                csvSafe((string)$a['mobile_number']),
                csvSafe((string)$a['driver_name']),
                csvSafe((string)$a['badge_number']),
                $a['returned_at'] !== null ? 'Closed' : 'Current',
                $a['returned_at'] !== null
                    ? date('Y-m-d H:i', strtotime((string)$a['returned_at'])) : 'CURRENT',
                csvSafe((string)$a['issued_by_name']),
                csvSafe((string)($a['return_reason'] ?? $a['assignment_reason'] ?? '')),
                csvSafe((string)($a['remarks'] ?? '')),
            ];
        }
        exportExcel('sim-history-' . date('Ymd-His') . '.xlsx', 'SIM History',
            ['Issued At', 'Terminal', 'SIM', 'Driver', 'Badge', 'State', 'Returned At',
             'Performed By', 'Reason', 'Remarks'], $out);
    }
}

$pageTitle = 'SIM History';
$activeNav = 'sim-history';
require __DIR__ . '/includes/header.php';
?>
<?php if ($sim !== null): ?>
    <!-- ============ one SIM: chain of custody ============ -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="page-title">SIM: <span class="phone"><?= e($sim['mobile_number']) ?></span></h1>
            <p class="page-sub">
                <?= e($sim['sim_reference'] ?? '') ?>
                &middot; <?= e($sim['carrier_name']) ?>
                &middot; <?= e($sim['terminal_name']) ?>
                &middot; <?= simStatusBadge($sim['status']) ?>
            </p>
        </div>
        <div>
            <a class="btn btn-outline-secondary btn-sm"
               href="sim-history.php?sim_id=<?= (int)$sim['id'] ?>&amp;export=1">Export Excel</a>
            <a class="btn btn-outline-secondary btn-sm" href="sims.php">Back to inventory</a>
            <?php if (can('sim.transact') && $sim['status'] === 'available'): ?>
                <a class="btn btn-primary btn-sm" href="assign.php?sim_id=<?= (int)$sim['id'] ?>">Issue this SIM</a>
            <?php endif; ?>
        </div>
    </div>

    <?php
    $current = null;
    foreach ($assignments as $a) {
        if ($a['returned_at'] === null) {
            $current = $a;
            break;
        }
    }
    ?>

    <div class="company-sim-box mb-3 col-lg-6">
        <div class="box-label">Who currently has this SIM?</div>
        <?php if ($current !== null): ?>
            <strong><?= e($current['driver_name']) ?></strong>
            (Badge <?= e($current['badge_number']) ?>) &middot; <?= e($current['terminal_name']) ?><br>
            <span class="small">Issued <?= e(date('d-M-Y H:i', strtotime((string)$current['issued_at']))) ?>
                by <?= e($current['issued_by_name']) ?></span>
            <?php if ($current['assignment_reason'] !== null): ?>
                &middot; reason: <em><?= e($current['assignment_reason']) ?></em>
            <?php endif; ?>
        <?php else: ?>
            <span class="text-muted">Nobody &mdash; the SIM is
                <?= e(simStatusOptions()[$sim['status']] ?? $sim['status']) ?>.</span>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-header">Assignment History (immutable)</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped mb-0 dt-nowrap sim-history-rows">
                    <thead>
                    <tr>
                        <th>Driver</th>
                        <th>Badge</th>
                        <th>Terminal</th>
                        <th>Issued</th>
                        <th data-col-default="hide">Issued By</th>
                        <th>Returned</th>
                        <th data-col-default="hide">Returned By</th>
                        <th>Reason</th>
                        <th>Remarks</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($assignments === []): ?>
                        <tr><td colspan="9" class="text-center text-muted py-3">
                            No assignments recorded for this SIM yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($assignments as $a): ?>
                        <tr>
                            <td><a href="driver.php?id=<?= (int)$a['driver_id'] ?>"><?= e($a['driver_name']) ?></a></td>
                            <td><?= e($a['badge_number']) ?></td>
                            <td><?= e($a['terminal_name']) ?></td>
                            <td><?= e(date('d-M-Y H:i', strtotime((string)$a['issued_at']))) ?></td>
                            <td><?= e($a['issued_by_name']) ?></td>
                            <td><?= $a['returned_at'] !== null
                                ? e(date('d-M-Y H:i', strtotime((string)$a['returned_at'])))
                                : '<span class="badge bg-success">CURRENT</span>' ?></td>
                            <td><?= e($a['returned_by_name'] ?? '—') ?></td>
                            <td><?= e($a['return_reason'] !== null
                                ? (SimService::RETURN_REASONS[$a['return_reason']] ?? $a['return_reason'])
                                : ($a['assignment_reason'] ?? '—')) ?></td>
                            <td class="small"><?= e($a['remarks'] ?? '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php else: ?>
    <!-- ============ global transaction history ============ -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="page-title">SIM History</h1>
            <p class="page-sub">Every issue / return / transfer across
                <?= $scope !== null ? e(terminalOptions()[$scope] ?? '') : 'all terminals' ?>.</p>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="sim-history.php?export=1">Export Excel</a>
    </div>

    <form method="get" class="mb-3" data-tt-target="history-partial">
        <div class="input-group" style="max-width:420px">
            <input type="text" class="form-control" name="q"
                   value="<?= e($_GET['q'] ?? '') ?>" placeholder="SIM number / driver / badge">
            <button class="btn btn-outline-primary" type="submit">Search</button>
        </div>
    </form>


    <div class="card" id="history-partial" data-tt-partial>
        <div class="card-header">SIM History</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped mb-0 dt-nowrap" data-tt-sort="server"
                       data-offset="<?= $offset ?>">
                    <thead>
                    <tr>
                        <th data-sort="issued">Date / Time</th>
                        <th data-sort="terminal">Terminal</th>
                        <th data-sort="sim">SIM</th>
                        <th data-sort="driver">Driver</th>
                        <th data-sort="badge">Badge</th>
                        <th data-no-sort="1">Action</th>
                        <th data-no-sort="1">Previous / New</th>
                        <th data-sort="issuedby" data-col-default="hide">Performed By</th>
                        <th data-no-sort="1">Reason</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($global === []): ?>
                        <tr><td colspan="9" class="text-center text-muted py-3">No transactions found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($global as $a):
                        $isOpen = $a['returned_at'] === null;
                        $label  = $isOpen
                            ? (($a['assignment_reason'] ?? '') === 'transfer' ? 'Transferred'
                                : (($a['assignment_reason'] ?? '') === 'replacement' ? 'Replaced' : 'Assigned'))
                            : (($a['return_reason'] ?? '') === 'transfer' ? 'Transferred' : 'Returned');
                        $reasonLabel = $isOpen
                            ? ($a['assignment_reason'] ?? '—')
                            : (SimService::RETURN_REASONS[$a['return_reason'] ?? ''] ?? ($a['return_reason'] ?? '—'));
                        ?>
                        <tr>
                            <td><?= e(date('d-M-y H:i', strtotime((string)$a['issued_at']))) ?></td>
                            <td><?= e($a['terminal_name']) ?></td>
                            <td><a href="sim-history.php?sim_id=<?= (int)$a['sim_id'] ?>" class="phone">
                                <?= e($a['mobile_number']) ?></a></td>
                            <td><a href="driver.php?id=<?= (int)$a['driver_id'] ?>"><?= e($a['driver_name']) ?></a></td>
                            <td><?= e($a['badge_number']) ?></td>
                            <td><span class="badge <?= $isOpen ? 'bg-success' : 'bg-secondary' ?>"><?= e($label) ?></span></td>
                            <td class="small"><?= $a['returned_at'] !== null
                                ? 'closed ' . e(date('d-M-y H:i', strtotime((string)$a['returned_at'])))
                                : 'CURRENT' ?></td>
                            <td><?= e($a['issued_by_name']) ?></td>
                            <td class="small"><?= e(is_string($reasonLabel) ? $reasonLabel : '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-between align-items-center">
            <span class="small text-muted">
                Showing <?= count($global) ?> of <?= $total ?> &middot; page <?= $page ?> of <?= $pages ?>
            </span>
            <?php renderPagination($page, $pages, 'sim-history.php?' . filtersUrl()); ?>
        </div>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
