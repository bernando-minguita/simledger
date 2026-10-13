<?php
/**
 * SIMLedger - SIM replacement (spec section 41).
 *
 * Close the old assignment, mark the old SIM damaged, issue the replacement
 * - all atomically inside SimService::replace().
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/lookups.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/services/SimService.php';

$pdo   = $GLOBALS['pdo'];
$scope = currentTerminalId();

requirePermission('sim.transact');

$error   = '';
$confirm = null;

// --- POST ------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrfCheck();

    $assignmentId = reqInt('assignment_id', 0, 1, 100000000);
    $newSimId     = reqInt('new_sim_id', 0, 1, 100000000);
    $returnDate   = trim((string)($_POST['return_date'] ?? ''));
    $reason       = cleanStr($_POST['reason'] ?? null, 60);
    $remarks      = cleanStr($_POST['remarks'] ?? null, 255);
    $confirmed    = ($_POST['confirmed'] ?? '') === '1';

    try {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate)) {
            throw new RuntimeException('A valid date is required.');
        }
        if ($reason === null) {
            throw new RuntimeException('A replacement reason is required.');
        }

        $stmt = $pdo->prepare(
            'SELECT a.id, a.terminal_id, a.driver_id, s.id AS sim_id, s.mobile_number,
                    d.name AS driver_name, d.badge_number, d.terminal_id AS holder_terminal
               FROM driver_sim_assignments a
               JOIN company_sims s ON s.id = a.sim_id
               JOIN drivers d ON d.id = a.driver_id
              WHERE a.id = ? AND a.returned_at IS NULL'
        );
        $stmt->execute([$assignmentId]);
        $old = $stmt->fetch();

        if ($old === false) {
            throw new RuntimeException('Active assignment not found.');
        }
        assertCustodyAccess((int)$old['terminal_id'], (int)$old['holder_terminal']);

        $nStmt = $pdo->prepare(
            'SELECT s.id, s.mobile_number, c.name AS carrier_name, t.name AS terminal_name
               FROM company_sims s
               JOIN carriers c ON c.id = s.carrier_id
               JOIN terminals t ON t.id = s.terminal_id
              WHERE s.id = ?'
        );
        $nStmt->execute([$newSimId]);
        $newSim = $nStmt->fetch();

        if ($newSim === false) {
            throw new RuntimeException('Replacement SIM not found.');
        }
        assertTerminalAccess((int)$newSim['terminal_id']);

        if (!$confirmed) {
            $confirm = [
                'assignment_id' => $assignmentId, 'new_sim_id' => $newSimId,
                'return_date' => $returnDate, 'reason' => $reason,
                'remarks' => (string)($remarks ?? ''),
                'old' => $old, 'new_sim' => $newSim,
            ];
        } else {
            $svc = new SimService($pdo);
            $aid = $svc->replace($assignmentId, $newSimId, $returnDate, $reason,
                $remarks, (int)$_SESSION['user_id']);

            setFlash('ok', sprintf(
                'Replacement complete: %s retired as Damaged, %s assigned to %s (Badge %s).',
                $old['mobile_number'], $newSim['mobile_number'],
                $old['driver_name'], $old['badge_number']
            ));
            header('Location: sim-history.php?sim_id=' . $newSimId);
            exit;
        }
    } catch (Throwable $e) {
        $error   = friendlyError($e);
        $confirm = null;
    }
}

// --- GET: search / pagination for both pickers ------------------------------
$aq          = trim((string)($_GET['aq'] ?? ''));
$apage       = reqInt('apage', 1, 1, 100000);
$sq          = trim((string)($_GET['sq'] ?? ''));
$spage       = reqInt('spage', 1, 1, 100000);
$prefill     = reqInt('assignment_id', 0, 0, 100000000);
$newSimPrefill = reqInt('new_sim_id', 0, 0, 100000000);
$perPage     = 10;

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
$aCnt = $pdo->prepare(
    'SELECT COUNT(*) FROM driver_sim_assignments a
       JOIN company_sims s ON s.id = a.sim_id
       JOIN drivers d ON d.id = a.driver_id' . $where
);
$aCnt->execute($params);
$aTotal = (int)$aCnt->fetchColumn();
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

$aStmt = $pdo->prepare(
    'SELECT a.id AS assignment_id, s.mobile_number, d.name AS driver_name, d.badge_number,
            t.name AS terminal_name
       FROM driver_sim_assignments a
       JOIN company_sims s ON s.id = a.sim_id
       JOIN drivers d ON d.id = a.driver_id
       JOIN terminals t ON t.id = a.terminal_id'
    . $where . ' ORDER BY d.badge_number LIMIT ' . $perPage . ' OFFSET ' . $aOffset
);
$aStmt->execute($params);
$assignments = $aStmt->fetchAll();

$sWhere  = " WHERE s.status = 'available'";
$sParams = [];
if ($scope !== null) {
    $sWhere .= ' AND s.terminal_id = ?';
    $sParams[] = $scope;
}
if ($sq !== '') {
    $sWhere .= ' AND (s.mobile_number LIKE ? OR c.name LIKE ? OR t.name LIKE ?)';
    array_push($sParams, '%' . $sq . '%', '%' . $sq . '%', '%' . $sq . '%');
}
$sCnt = $pdo->prepare('SELECT COUNT(*) FROM company_sims s'
    . ' JOIN carriers c ON c.id = s.carrier_id
       JOIN terminals t ON t.id = s.terminal_id' . $sWhere);
$sCnt->execute($sParams);
$sTotal = (int)$sCnt->fetchColumn();
$sPages = max(1, (int)ceil($sTotal / $perPage));
$spage  = min($spage, $sPages);
$sOffset = ($spage - 1) * $perPage;

$sStmt = $pdo->prepare(
    'SELECT s.id, s.mobile_number, c.name AS carrier_name, t.name AS terminal_name
       FROM company_sims s
       JOIN carriers c ON c.id = s.carrier_id
       JOIN terminals t ON t.id = s.terminal_id'
    . $sWhere . ' ORDER BY s.mobile_number LIMIT ' . $perPage . ' OFFSET ' . $sOffset
);
$sStmt->execute($sParams);
$availableSims = $sStmt->fetchAll();

$qs = static function (array $over = []) use (
    $aq, $apage, $prefill, $sq, $spage, $newSimPrefill
): string {
    $p = [];
    if ($aq !== '') { $p['aq'] = $aq; }
    if ($apage > 1) { $p['apage'] = $apage; }
    if ($prefill > 0) { $p['assignment_id'] = $prefill; }
    if ($sq !== '') { $p['sq'] = $sq; }
    if ($spage > 1) { $p['spage'] = $spage; }
    if ($newSimPrefill > 0) { $p['new_sim_id'] = $newSimPrefill; }
    foreach ($over as $k => $v) {
        if ($v === null) { unset($p[$k]); } else { $p[$k] = $v; }
    }
    return $p === [] ? '' : '?' . http_build_query($p);
};

$pageTitle = 'Replace SIM';
$activeNav = 'sim-replace';
require __DIR__ . '/includes/header.php';
?>
<h1 class="page-title mb-1">Replace Company SIM</h1>
<p class="page-sub mb-3">
    Closes the old assignment, marks the old SIM <strong>Damaged</strong> and issues the
    replacement &mdash; both assignments remain in history.
</p>

<?php if ($error !== ''): ?>
    <div class="alert-error mb-3"><?= e($error) ?></div>
<?php endif; ?>


<?php if ($confirm !== null): ?>
    <div class="col-lg-7">
        <div class="confirm-box">
            <div class="section-label">Confirm replacement</div>
            <h5 class="mb-3">
                Replace SIM <?= e($confirm['old']['mobile_number']) ?>
                (<?= e($confirm['old']['driver_name']) ?>, Badge <?= e($confirm['old']['badge_number']) ?>)
                with <?= e($confirm['new_sim']['mobile_number']) ?>?
            </h5>
            <dl class="row mb-0 small">
                <dt class="col-4">Old SIM becomes</dt>
                <dd class="col-8">Damaged (kept in history)</dd>
                <dt class="col-4">Replacement carrier</dt>
                <dd class="col-8"><?= e($confirm['new_sim']['carrier_name']) ?></dd>
                <dt class="col-4">Date</dt>
                <dd class="col-8"><?= e($confirm['return_date']) ?></dd>
                <dt class="col-4">Reason</dt>
                <dd class="col-8"><?= e($confirm['reason']) ?></dd>
                <dt class="col-4">Remarks</dt>
                <dd class="col-8"><?= e($confirm['remarks'] !== '' ? $confirm['remarks'] : '—') ?></dd>
            </dl>
            <form method="post" action="replace-sim.php" class="mt-3">
                <?= csrfField() ?>
                <input type="hidden" name="assignment_id" value="<?= (int)$confirm['assignment_id'] ?>">
                <input type="hidden" name="new_sim_id" value="<?= (int)$confirm['new_sim_id'] ?>">
                <input type="hidden" name="return_date" value="<?= e($confirm['return_date']) ?>">
                <input type="hidden" name="reason" value="<?= e($confirm['reason']) ?>">
                <input type="hidden" name="remarks" value="<?= e($confirm['remarks']) ?>">
                <input type="hidden" name="confirmed" value="1">
                <button type="submit" class="btn btn-primary">Confirm replacement</button>
                <a href="replace-sim.php" class="btn btn-outline-secondary">Cancel</a>
            </form>
        </div>
    </div>
<?php else: ?>
<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card h-100" id="a-partial" data-tt-partial>
            <div class="card-header">Current assignment</div>
            <div class="card-body">
                <form method="get" action="replace-sim.php" class="d-flex gap-2 mb-1"
                      data-tt-target="a-partial" data-tt-page="apage">
                    <input type="text" class="form-control form-control-sm" name="aq"
                           value="<?= e($aq) ?>" placeholder="Search badge / driver / SIM…"
                           autocomplete="off" aria-label="Search assignments">
                    <button class="btn btn-outline-primary btn-sm" type="submit">Search</button>
                    <?php if ($aq !== '' || $apage > 1): ?>
                        <a class="btn btn-link btn-sm" data-tt-ajax
                           href="replace-sim.php<?= e($qs(['aq' => null, 'apage' => null])) ?>">Reset</a>
                    <?php endif; ?>
                    <input type="hidden" name="assignment_id" data-keep="assignment"
                           value="<?= $prefill ?: '' ?>">
                    <input type="hidden" name="new_sim_id" data-keep="sim"
                           value="<?= $newSimPrefill ?: '' ?>">
                    <?= $sq !== '' ? '<input type="hidden" name="sq" value="' . e($sq) . '">' : '' ?>
                    <?= $spage > 1 ? '<input type="hidden" name="spage" value="' . $spage . '">' : '' ?>
                </form>
                <div class="form-text mb-1">Click a row to choose the assignment to replace.</div>
                <?php if ($assignments === []): ?>
                    <div class="form-text text-danger mb-1">
                        <?= $aq !== '' ? 'No assignments match ' . e(json_encode($aq)) . '.'
                            : 'No open assignments in scope.' ?>
                    </div>
                    <?php if ($aq !== ''): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="replace-sim.php<?= e($qs(['aq' => null, 'apage' => null])) ?>">Clear</a>
                    <?php endif; ?>
                <?php else: ?>
                    <table class="table table-sm table-hover mb-0" id="assignmentPicker"
                           data-picker="assignment" data-status="aPickStatus">
                        <thead>
                        <tr>
                            <th data-no-sort="1">Badge</th>
                            <th data-no-sort="1">Driver</th>
                            <th data-no-sort="1">Old SIM</th>
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
                <?php renderPagination($apage, $aPages, 'replace-sim.php' . $qs(['apage' => null]), 'apage') ?>
            </div>
        </div>
    </div>
        <div class="col-lg-6">
        <div class="card h-100" id="s-partial" data-tt-partial>
            <div class="card-header">Replacement SIM (Available only)</div>
            <div class="card-body">
                <form method="get" action="replace-sim.php" class="d-flex gap-2 mb-1"
                      data-tt-target="s-partial" data-tt-page="spage">
                    <input type="text" class="form-control form-control-sm" name="sq"
                           value="<?= e($sq) ?>" placeholder="Search number / carrier / terminal…"
                           autocomplete="off" aria-label="Search replacement SIMs">
                    <button class="btn btn-outline-primary btn-sm" type="submit">Search</button>
                    <?php if ($sq !== '' || $spage > 1): ?>
                        <a class="btn btn-link btn-sm" data-tt-ajax
                           href="replace-sim.php<?= e($qs(['sq' => null, 'spage' => null])) ?>">Reset</a>
                    <?php endif; ?>
                    <input type="hidden" name="assignment_id" data-keep="assignment"
                           value="<?= $prefill ?: '' ?>">
                    <input type="hidden" name="new_sim_id" data-keep="sim"
                           value="<?= $newSimPrefill ?: '' ?>">
                    <?= $aq !== '' ? '<input type="hidden" name="aq" value="' . e($aq) . '">' : '' ?>
                    <?= $apage > 1 ? '<input type="hidden" name="apage" value="' . $apage . '">' : '' ?>
                </form>
                <div class="form-text mb-1">Only status <strong>available</strong> &middot; click a row to choose.</div>
                <?php if ($availableSims === []): ?>
                    <div class="form-text text-danger mb-1">
                        <?= $sq !== '' ? 'No available SIMs match ' . e(json_encode($sq)) . '.'
                            : 'No available SIMs at this terminal.' ?>
                    </div>
                    <?php if ($sq !== ''): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="replace-sim.php<?= e($qs(['sq' => null, 'spage' => null])) ?>">Clear</a>
                    <?php endif; ?>
                <?php else: ?>
                    <table class="table table-sm table-hover mb-0" id="simPicker"
                           data-picker="sim" data-status="sPickStatus">
                        <thead>
                        <tr>
                            <th data-no-sort="1">Mobile</th>
                            <th data-no-sort="1">Carrier</th>
                            <th data-no-sort="1">Terminal</th>
                            <th class="text-end" data-no-sort="1">Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($availableSims as $s): ?>
                            <tr data-sim-id="<?= (int)$s['id'] ?>"
                                <?= $newSimPrefill === (int)$s['id'] ? 'class="row-selected"' : '' ?>
                                data-label="<?= e($s['mobile_number'] . ' — ' . $s['carrier_name']
                                    . ' (' . $s['terminal_name'] . ')') ?>">
                                <td class="phone"><?= e($s['mobile_number']) ?></td>
                                <td><?= e($s['carrier_name']) ?></td>
                                <td class="small"><?= e($s['terminal_name']) ?></td>
                                <td class="text-end text-nowrap">
                                    <button type="button" data-role="pick"
                                            class="btn btn-sm btn-outline-primary">Select</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                <div class="form-text mt-1" id="sPickStatus">Selected: —</div>
            </div>
            <div class="card-footer d-flex justify-content-between align-items-center">
                <span class="small text-muted">
                    Showing <?= count($availableSims) ?> of <?= $sTotal ?> &middot;
                    page <?= $spage ?> of <?= $sPages ?>
                </span>
                <?php renderPagination($spage, $sPages, 'replace-sim.php' . $qs(['spage' => null]), 'spage') ?>
            </div>
        </div>
    </div>
</div>
<form method="post" action="replace-sim.php">
    <?= csrfField() ?>
    <input type="hidden" name="assignment_id" id="assignmentIdInput" data-keep="assignment"
           value="<?= $prefill ?: '' ?>">
    <input type="hidden" name="new_sim_id" id="newSimIdInput" data-keep="sim"
           value="<?= $newSimPrefill ?: '' ?>">
    <div class="card">
        <div class="card-header">Replacement details</div>
        <div class="card-body">
            <div class="row g-2 mb-3">
                <div class="col-md-4">
                    <label class="form-label">Date</label>
                    <input type="date" class="form-control" name="return_date"
                           value="<?= e(date('Y-m-d')) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Reason</label>
                    <input type="text" class="form-control" name="reason" maxlength="60"
                           placeholder="e.g. Damaged SIM" required>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Remarks (optional)</label>
                <input type="text" class="form-control" name="remarks" maxlength="255">
            </div>
            <button type="submit" class="btn btn-primary" id="reviewBtn">Review replacement…</button>
            <a href="assignments.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </div>
</form>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
