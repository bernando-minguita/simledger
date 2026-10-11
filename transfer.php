<?php
/**
 * SIMLedger - Transfer SIM workflow (spec section 19).
 *
 * Close the current assignment and open a new one on the SAME sim, inside a
 * single database transaction. The SIM never disappears mid-transaction.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/lookups.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/services/SimService.php';

$pdo   = $GLOBALS['pdo'];
$scope = currentTerminalId();

requirePermission('sim.transact');

$error = '';

// --- POST ------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrfCheck();

    $assignmentId  = reqInt('assignment_id', 0, 1, 100000000);
    $newDriverId   = reqInt('new_driver_id', 0, 1, 100000000);
    $returnDate    = trim((string)($_POST['return_date'] ?? ''));
    $transferReason = cleanStr($_POST['transfer_reason'] ?? null, 60);
    $remarks       = cleanStr($_POST['remarks'] ?? null, 255);
    $confirmed     = ($_POST['confirmed'] ?? '') === '1';

    try {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate)) {
            throw new RuntimeException('A valid transfer date is required.');
        }
        if ($transferReason === null) {
            throw new RuntimeException('A transfer reason is required.');
        }

        $stmt = $pdo->prepare(
            'SELECT a.terminal_id, a.driver_id AS old_driver_id, s.id AS sim_id,
                    s.mobile_number, d.name AS old_driver_name, d.badge_number AS old_badge,
                    d.terminal_id AS holder_terminal
               FROM driver_sim_assignments a
               JOIN company_sims s ON s.id = a.sim_id
               JOIN drivers d ON d.id = a.driver_id
              WHERE a.id = ?'
        );
        $stmt->execute([$assignmentId]);
        $info = $stmt->fetch();

        if ($info === false) {
            throw new RuntimeException('Assignment not found.');
        }
        assertCustodyAccess((int)$info['terminal_id'], (int)$info['holder_terminal']);

        $dStmt = $pdo->prepare(
            'SELECT d.id, d.name, d.badge_number, d.terminal_id, t.name AS terminal_name
               FROM drivers d JOIN terminals t ON t.id = d.terminal_id WHERE d.id = ?'
        );
        $dStmt->execute([$newDriverId]);
        $newDriver = $dStmt->fetch();

        if ($newDriver === false) {
            throw new RuntimeException('Receiving driver not found.');
        }
        assertTerminalAccess((int)$newDriver['terminal_id']);

        if (!$confirmed) {
            $confirm = [
                'assignment_id' => $assignmentId,
                'new_driver_id' => $newDriverId,
                'return_date'   => $returnDate,
                'reason'        => $transferReason,
                'remarks'       => (string)($remarks ?? ''),
                'info'          => $info,
                'new_driver'    => $newDriver,
            ];
        } else {
            $svc = new SimService($pdo);
            $aid = $svc->transfer($assignmentId, $newDriverId, $returnDate,
                $transferReason, $remarks, (int)$_SESSION['user_id']);

            setFlash('ok', sprintf(
                'SIM %s transferred from %s (Badge %s) to %s (Badge %s).',
                $info['mobile_number'], $info['old_driver_name'], $info['old_badge'],
                $newDriver['name'], $newDriver['badge_number']
            ));
            header('Location: sim-history.php?sim_id=' . (int)$info['sim_id']);
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// $confirm holds the review payload (set above on first POST).
$confirm = $confirm ?? null;

// --- GET: search / pagination for both pickers ------------------------------
$aq              = trim((string)($_GET['aq'] ?? ''));
$apage           = reqInt('apage', 1, 1, 100000);
$dq              = trim((string)($_GET['dq'] ?? ''));
$dpage           = reqInt('dpage', 1, 1, 100000);
$prefill         = reqInt('assignment_id', 0, 0, 100000000);
$newDriverPrefill = reqInt('new_driver_id', 0, 0, 100000000);
$perPage         = 10;

$where  = ' WHERE a.returned_at IS NULL';
$params = [];
if ($scope !== null) {
    // Custody scope: own book terminal, or assignments held by own-terminal
    // drivers (e.g. after a cross-terminal transfer).
    $where .= ' AND (a.terminal_id = ? OR d.terminal_id = ?)';
    $params[] = $scope;
    $params[] = $scope;
}
if ($aq !== '') {
    $where .= ' AND (d.badge_number LIKE ? OR d.name LIKE ? OR s.mobile_number LIKE ?)';
    array_push($params, '%' . $aq . '%', '%' . $aq . '%', '%' . $aq . '%');
}
$cnt = $pdo->prepare(
    'SELECT COUNT(*) FROM driver_sim_assignments a
       JOIN company_sims s ON s.id = a.sim_id
       JOIN drivers d ON d.id = a.driver_id' . $where
);
$cnt->execute($params);
$aTotal = (int)$cnt->fetchColumn();
$aPages = max(1, (int)ceil($aTotal / $perPage));
$apage  = min($apage, $aPages);

if ($prefill > 0 && !isset($_GET['apage'])) { // deep link -> its page
    $bp = $pdo->prepare(
        'SELECT d.badge_number FROM driver_sim_assignments a
           JOIN drivers d ON d.id = a.driver_id
          WHERE a.id = ? AND a.returned_at IS NULL'
    );
    $bp->execute([$prefill]);
    $badge = $bp->fetchColumn();
    if ($badge !== false) {
        $pos = $pdo->prepare(
            'SELECT COUNT(*) FROM driver_sim_assignments a
               JOIN company_sims s ON s.id = a.sim_id
               JOIN drivers d ON d.id = a.driver_id' . $where . ' AND d.badge_number < ?'
        );
        $pp = $params;
        $pp[] = $badge;
        $pos->execute($pp);
        $apage = min($aPages, max(1, (int)floor((int)$pos->fetchColumn() / $perPage) + 1));
    }
}
$aOffset = ($apage - 1) * $perPage;

$stmt = $pdo->prepare(
    'SELECT a.id AS assignment_id, s.id AS sim_id, s.mobile_number, d.id AS driver_id,
            d.name AS driver_name, d.badge_number, t.name AS terminal_name, c.name AS carrier_name
       FROM driver_sim_assignments a
       JOIN company_sims s ON s.id = a.sim_id
       JOIN drivers d ON d.id = a.driver_id
       JOIN terminals t ON t.id = a.terminal_id
       JOIN carriers c ON c.id = s.carrier_id'
    . $where . ' ORDER BY d.badge_number LIMIT ' . $perPage . ' OFFSET ' . $aOffset
);
$stmt->execute($params);
$assignments = $stmt->fetchAll();

// Candidate receiving drivers: active, in scope, without an active SIM.
$dWhere  = " WHERE d.is_active = 1 AND d.status = 'active'";
$dParams = [];
if ($scope !== null) {
    $dWhere .= ' AND d.terminal_id = ?';
    $dParams[] = $scope;
}
$dWhere .= ' AND NOT EXISTS(SELECT 1 FROM driver_sim_assignments a2
                              WHERE a2.driver_id = d.id AND a2.returned_at IS NULL)';
if ($dq !== '') {
    $dWhere .= ' AND (d.badge_number LIKE ? OR d.name LIKE ?)';
    array_push($dParams, '%' . $dq . '%', '%' . $dq . '%');
}
$dCnt = $pdo->prepare('SELECT COUNT(*) FROM drivers d' . $dWhere);
$dCnt->execute($dParams);
$dTotal = (int)$dCnt->fetchColumn();
$dPages = max(1, (int)ceil($dTotal / $perPage));
$dpage  = min($dpage, $dPages);

if ($newDriverPrefill > 0 && !isset($_GET['dpage'])) { // deep link -> its page
    $bp = $pdo->prepare('SELECT badge_number FROM drivers WHERE id = ?');
    $bp->execute([$newDriverPrefill]);
    $badge = $bp->fetchColumn();
    if ($badge !== false) {
        $pos = $pdo->prepare('SELECT COUNT(*) FROM drivers d'
            . $dWhere . ' AND d.badge_number < ?');
        $pp = $dParams;
        $pp[] = $badge;
        $pos->execute($pp);
        $dpage = min($dPages, max(1, (int)floor((int)$pos->fetchColumn() / $perPage) + 1));
    }
}
$dOffset = ($dpage - 1) * $perPage;

$driverStmt = $pdo->prepare(
    'SELECT d.id, d.badge_number, d.name, t.name AS terminal_name
       FROM drivers d JOIN terminals t ON t.id = d.terminal_id'
    . $dWhere . ' ORDER BY d.badge_number LIMIT ' . $perPage . ' OFFSET ' . $dOffset
);
$driverStmt->execute($dParams);
$driverRows = $driverStmt->fetchAll();

$qs = static function (array $over = []) use (
    $aq, $apage, $prefill, $dq, $dpage, $newDriverPrefill
): string {
    $p = [];
    if ($aq !== '') { $p['aq'] = $aq; }
    if ($apage > 1) { $p['apage'] = $apage; }
    if ($prefill > 0) { $p['assignment_id'] = $prefill; }
    if ($dq !== '') { $p['dq'] = $dq; }
    if ($dpage > 1) { $p['dpage'] = $dpage; }
    if ($newDriverPrefill > 0) { $p['new_driver_id'] = $newDriverPrefill; }
    foreach ($over as $k => $v) {
        if ($v === null) { unset($p[$k]); } else { $p[$k] = $v; }
    }
    return $p === [] ? '' : '?' . http_build_query($p);
};

$pageTitle = 'Transfer SIM';
$activeNav = 'sim-transfer';
require __DIR__ . '/includes/header.php';
?>
<h1 class="page-title mb-1">Transfer Company SIM</h1>
<p class="page-sub mb-3">
    One atomic transaction: close the current assignment, open a new one on the same SIM.
    Both drivers keep their history.
</p>

<?php if ($error !== ''): ?>
    <div class="alert-error mb-3"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($confirm !== null): ?>
    <div class="col-lg-7">
        <div class="confirm-box">
            <div class="section-label">Confirm transfer</div>
            <h5 class="mb-3">
                Transfer SIM <?= e($confirm['info']['mobile_number']) ?>
                from <?= e($confirm['info']['old_driver_name']) ?>
                (Badge <?= e($confirm['info']['old_badge']) ?>)
                to <?= e($confirm['new_driver']['name']) ?>
                (Badge <?= e($confirm['new_driver']['badge_number']) ?>)?
            </h5>
            <dl class="row mb-0 small">
                <dt class="col-4">Reason</dt>
                <dd class="col-8"><?= e($confirm['reason']) ?></dd>
                <dt class="col-4">Transfer date</dt>
                <dd class="col-8"><?= e($confirm['return_date']) ?></dd>
                <dt class="col-4">Receiving terminal</dt>
                <dd class="col-8"><?= e($confirm['new_driver']['terminal_name']) ?></dd>
                <dt class="col-4">Remarks</dt>
                <dd class="col-8"><?= e($confirm['remarks'] !== '' ? $confirm['remarks'] : '—') ?></dd>
            </dl>
            <p class="small text-muted mt-2 mb-0">
                The current assignment will be closed and a new one opened on the same SIM,
                atomically. Nothing is deleted.
            </p>

            <form method="post" action="transfer.php" class="mt-3">
                <?= csrfField() ?>
                <input type="hidden" name="assignment_id" value="<?= (int)$confirm['assignment_id'] ?>">
                <input type="hidden" name="new_driver_id" value="<?= (int)$confirm['new_driver_id'] ?>">
                <input type="hidden" name="return_date" value="<?= e($confirm['return_date']) ?>">
                <input type="hidden" name="transfer_reason" value="<?= e($confirm['reason']) ?>">
                <input type="hidden" name="remarks" value="<?= e($confirm['remarks']) ?>">
                <input type="hidden" name="confirmed" value="1">
                <button type="submit" class="btn btn-primary">Confirm transfer</button>
                <a href="transfer.php" class="btn btn-outline-secondary">Cancel</a>
            </form>
        </div>
    </div>
<?php else: ?>
<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card h-100" id="a-partial" data-tt-partial>
            <div class="card-header">Current assignment</div>
            <div class="card-body">
                <form method="get" action="transfer.php" class="d-flex gap-2 mb-1"
                      data-tt-target="a-partial" data-tt-page="apage">
                    <input type="text" class="form-control form-control-sm" name="aq"
                           value="<?= e($aq) ?>" placeholder="Search badge / driver / SIM…"
                           autocomplete="off" aria-label="Search assignments">
                    <button class="btn btn-outline-primary btn-sm" type="submit">Search</button>
                    <?php if ($aq !== '' || $apage > 1): ?>
                        <a class="btn btn-link btn-sm" data-tt-ajax
                           href="transfer.php<?= e($qs(['aq' => null, 'apage' => null])) ?>">Reset</a>
                    <?php endif; ?>
                    <input type="hidden" name="assignment_id" data-keep="assignment"
                           value="<?= $prefill ?: '' ?>">
                    <input type="hidden" name="new_driver_id" data-keep="driver"
                           value="<?= $newDriverPrefill ?: '' ?>">
                    <?= $dq !== '' ? '<input type="hidden" name="dq" value="' . e($dq) . '">' : '' ?>
                    <?= $dpage > 1 ? '<input type="hidden" name="dpage" value="' . $dpage . '">' : '' ?>
                </form>
                <div class="form-text mb-1">Click a row to choose the assignment to transfer.</div>
                <?php if ($assignments === []): ?>
                    <div class="form-text text-danger mb-1">
                        <?= $aq !== '' ? 'No assignments match ' . e(json_encode($aq)) . '.'
                            : 'No open assignments in scope.' ?>
                    </div>
                    <?php if ($aq !== ''): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="transfer.php<?= e($qs(['aq' => null, 'apage' => null])) ?>">Clear</a>
                    <?php endif; ?>
                <?php else: ?>
                    <table class="table table-sm table-hover mb-0" id="assignmentPicker"
                           data-picker="assignment" data-status="aPickStatus">
                        <thead>
                        <tr>
                            <th data-no-sort="1">Badge</th>
                            <th data-no-sort="1">Driver</th>
                            <th data-no-sort="1">SIM</th>
                            <th data-no-sort="1">Terminal</th>
                            <th class="text-end" data-no-sort="1">Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($assignments as $a): ?>
                            <tr data-assignment-id="<?= (int)$a['assignment_id'] ?>"
                                <?= $prefill === (int)$a['assignment_id'] ? 'class="row-selected"' : '' ?>
                                data-label="<?= e($a['badge_number'] . ' — ' . $a['driver_name']
                                    . ' (' . $a['mobile_number'] . ')') ?>">
                                <td><?= e($a['badge_number']) ?></td>
                                <td><?= e($a['driver_name']) ?></td>
                                <td class="phone"><?= e($a['mobile_number']) ?></td>
                                <td class="small"><?= e($a['terminal_name']) ?></td>
                                <td class="text-end text-nowrap">
                                    <button type="button" data-role="pick"
                                            class="btn btn-sm btn-outline-primary">Select</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                <div class="form-text mt-1" id="aPickStatus">Selected: —</div>
            </div>
            <div class="card-footer d-flex justify-content-between align-items-center">
                <span class="small text-muted">
                    Showing <?= count($assignments) ?> of <?= $aTotal ?> &middot;
                    page <?= $apage ?> of <?= $aPages ?>
                </span>
                <?php renderPagination($apage, $aPages, 'transfer.php' . $qs(['apage' => null]), 'apage') ?>
            </div>
        </div>
    </div>
        <div class="col-lg-6">
        <div class="card h-100" id="d-partial" data-tt-partial>
            <div class="card-header">Transfer to (drivers without an active SIM)</div>
            <div class="card-body">
                <form method="get" action="transfer.php" class="d-flex gap-2 mb-1"
                      data-tt-target="d-partial" data-tt-page="dpage">
                    <input type="text" class="form-control form-control-sm" name="dq"
                           value="<?= e($dq) ?>" placeholder="Search badge / name…"
                           autocomplete="off" aria-label="Search receiving drivers">
                    <button class="btn btn-outline-primary btn-sm" type="submit">Search</button>
                    <?php if ($dq !== '' || $dpage > 1): ?>
                        <a class="btn btn-link btn-sm" data-tt-ajax
                           href="transfer.php<?= e($qs(['dq' => null, 'dpage' => null])) ?>">Reset</a>
                    <?php endif; ?>
                    <input type="hidden" name="assignment_id" data-keep="assignment"
                           value="<?= $prefill ?: '' ?>">
                    <input type="hidden" name="new_driver_id" data-keep="driver"
                           value="<?= $newDriverPrefill ?: '' ?>">
                    <?= $aq !== '' ? '<input type="hidden" name="aq" value="' . e($aq) . '">' : '' ?>
                    <?= $apage > 1 ? '<input type="hidden" name="apage" value="' . $apage . '">' : '' ?>
                </form>
                <div class="form-text mb-1">Click a row to choose the receiving driver.</div>
                <?php if ($driverRows === []): ?>
                    <div class="form-text text-danger mb-1">
                        <?= $dq !== '' ? 'No drivers match ' . e(json_encode($dq)) . '.'
                            : 'No eligible drivers in scope.' ?>
                    </div>
                    <?php if ($dq !== ''): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="transfer.php<?= e($qs(['dq' => null, 'dpage' => null])) ?>">Clear</a>
                    <?php endif; ?>
                <?php else: ?>
                    <table class="table table-sm table-hover mb-0" id="driverPicker"
                           data-picker="driver" data-status="dPickStatus">
                        <thead>
                        <tr>
                            <th data-no-sort="1">Badge</th>
                            <th data-no-sort="1">Name</th>
                            <th data-no-sort="1">Terminal</th>
                            <th class="text-end" data-no-sort="1">Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($driverRows as $d): ?>
                            <tr data-driver-id="<?= (int)$d['id'] ?>"
                                <?= $newDriverPrefill === (int)$d['id'] ? 'class="row-selected"' : '' ?>
                                data-label="<?= e($d['badge_number'] . ' — ' . $d['name']
                                    . ' (' . $d['terminal_name'] . ')') ?>">
                                <td><?= e($d['badge_number']) ?></td>
                                <td><?= e($d['name']) ?></td>
                                <td class="small"><?= e($d['terminal_name']) ?></td>
                                <td class="text-end text-nowrap">
                                    <button type="button" data-role="pick"
                                            class="btn btn-sm btn-outline-primary">Select</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                <div class="form-text mt-1" id="dPickStatus">Selected: —</div>
            </div>
            <div class="card-footer d-flex justify-content-between align-items-center">
                <span class="small text-muted">
                    Showing <?= count($driverRows) ?> of <?= $dTotal ?> &middot;
                    page <?= $dpage ?> of <?= $dPages ?>
                </span>
                <?php renderPagination($dpage, $dPages, 'transfer.php' . $qs(['dpage' => null]), 'dpage') ?>
            </div>
        </div>
    </div>
</div>
<form method="post" action="transfer.php">
    <?= csrfField() ?>
    <input type="hidden" name="assignment_id" id="assignmentIdInput" data-keep="assignment"
           value="<?= $prefill ?: '' ?>">
    <input type="hidden" name="new_driver_id" id="newDriverIdInput" data-keep="driver"
           value="<?= $newDriverPrefill ?: '' ?>">
    <div class="card">
        <div class="card-header">Transfer details</div>
        <div class="card-body">
            <div class="row g-2 mb-3">
                <div class="col-md-4">
                    <label class="form-label">Transfer date</label>
                    <input type="date" class="form-control" name="return_date"
                           value="<?= e(date('Y-m-d')) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Reason</label>
                    <input type="text" class="form-control" name="transfer_reason"
                           maxlength="60" placeholder="e.g. Driver reassignment" required>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Remarks (optional)</label>
                <input type="text" class="form-control" name="remarks" maxlength="255">
            </div>
            <button type="submit" class="btn btn-primary" id="reviewBtn">Review transfer…</button>
            <a href="assignments.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </div>
</form>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>


