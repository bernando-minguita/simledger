<?php
/**
 * SIMLedger - Return SIM workflow (spec section 18).
 *
 * Dispatcher selects an active assignment, provides return date / reason /
 * remarks, and the assignment is closed (never deleted).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/lookups.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/services/SimService.php';

$pdo   = $GLOBALS['pdo'];
$scope = currentTerminalId();

requirePermission('sim.transact');

$error = '';

// --- POST: either show confirmation or execute -----------------------------
$error   = '';
$confirm = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrfCheck();

    $assignmentId  = reqInt('assignment_id', 0, 1, 100000000);
    $returnDate    = trim((string)($_POST['return_date'] ?? ''));
    $returnReason  = (string)($_POST['return_reason'] ?? '');
    $remarks       = cleanStr($_POST['remarks'] ?? null, 255);
    $simIdBack     = reqInt('sim_id', 0, 0, 100000000);
    $confirmed     = ($_POST['confirmed'] ?? '') === '1';

    try {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $returnDate)) {
            throw new RuntimeException('A valid return date is required.');
        }

        // Terminal/custody permission check before touching the row.
        $stmt = $pdo->prepare(
            'SELECT a.terminal_id, a.sim_id, s.mobile_number, d.name, d.badge_number,
                    d.terminal_id AS holder_terminal, t.name AS terminal_name,
                    c.name AS carrier_name
               FROM driver_sim_assignments a
               JOIN company_sims s ON s.id = a.sim_id
               JOIN drivers d ON d.id = a.driver_id
               JOIN terminals t ON t.id = a.terminal_id
               JOIN carriers c ON c.id = s.carrier_id
              WHERE a.id = ?'
        );
        $stmt->execute([$assignmentId]);
        $info = $stmt->fetch();

        if ($info === false) {
            throw new RuntimeException('Assignment not found.');
        }
        assertCustodyAccess((int)$info['terminal_id'], (int)$info['holder_terminal']);

        if (!$confirmed) {
            // Step 1: build the confirmation screen.
            $confirm = [
                'assignment_id' => $assignmentId,
                'return_date'   => $returnDate,
                'reason'        => $returnReason,
                'remarks'       => (string)($remarks ?? ''),
                'sim_id'        => (int)$info['sim_id'],
                'info'          => $info,
            ];
        } else {
            // Step 2: execute inside the service transaction.
            $svc = new SimService($pdo);
            $svc->returnSim($assignmentId, $returnDate, $returnReason, $remarks,
                (int)$_SESSION['user_id']);

            setFlash('ok', sprintf(
                'SIM %s returned by %s (Badge %s). The assignment remains in history.',
                $info['mobile_number'], $info['name'], $info['badge_number']
            ));
            $back = $simIdBack > 0 ? $simIdBack : (int)$info['sim_id'];
            header('Location: sim-history.php?sim_id=' . $back);
            exit;
        }
    } catch (Throwable $e) {
        $error = friendlyError($e);
    }
}


// --- GET: search / pagination / prefill -------------------------------------
$aq        = trim((string)($_GET['aq'] ?? ''));
$apage     = reqInt('apage', 1, 1, 100000);
$prefill   = reqInt('assignment_id', 0, 0, 100000000);
$simPrefill = reqInt('sim_id', 0, 0, 100000000);
$perPage   = 10;

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

// ?sim_id=N (inventory "Return" button): resolve to its open assignment.
if ($prefill === 0 && $simPrefill > 0) {
    $rp = $pdo->prepare(
        'SELECT a.id FROM driver_sim_assignments a
          WHERE a.sim_id = ? AND a.returned_at IS NULL
          ORDER BY a.id DESC LIMIT 1'
    );
    $rp->execute([$simPrefill]);
    $prefill = (int)$rp->fetchColumn();
}

$cnt = $pdo->prepare(
    'SELECT COUNT(*) FROM driver_sim_assignments a
       JOIN company_sims s ON s.id = a.sim_id
       JOIN drivers d ON d.id = a.driver_id' . $where
);
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$apage = min($apage, $pages);

// Deep link: land on the page containing the preselected assignment.
if ($prefill > 0 && !isset($_GET['apage'])) {
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
               JOIN drivers d ON d.id = a.driver_id'
            . $where . ' AND d.badge_number < ?'
        );
        $posParams = $params;
        $posParams[] = $badge;
        $pos->execute($posParams);
        $apage = min($pages, max(1, (int)floor((int)$pos->fetchColumn() / $perPage) + 1));
    }
}
$offset = ($apage - 1) * $perPage;

$stmt = $pdo->prepare(
    'SELECT a.id AS assignment_id, a.issued_at, s.id AS sim_id, s.mobile_number,
            s.status AS sim_status, d.id AS driver_id, d.name AS driver_name,
            d.badge_number, t.name AS terminal_name, c.name AS carrier_name
       FROM driver_sim_assignments a
       JOIN company_sims s ON s.id = a.sim_id
       JOIN drivers d ON d.id = a.driver_id
       JOIN terminals t ON t.id = a.terminal_id
       JOIN carriers c ON c.id = s.carrier_id'
    . $where . ' ORDER BY d.badge_number LIMIT ' . $perPage . ' OFFSET ' . $offset
);
$stmt->execute($params);
$assignments = $stmt->fetchAll();

// URL-state helper: picker state as a query string, optional overrides.
$qs = static function (array $over = []) use ($aq, $apage, $prefill): string {
    $p = [];
    if ($aq !== '') { $p['aq'] = $aq; }
    if ($apage > 1) { $p['apage'] = $apage; }
    if ($prefill > 0) { $p['assignment_id'] = $prefill; }
    foreach ($over as $k => $v) {
        if ($v === null) { unset($p[$k]); } else { $p[$k] = $v; }
    }
    return $p === [] ? '' : '?' . http_build_query($p);
};

$pageTitle = 'Return SIM';
$activeNav = 'sim-return';
require __DIR__ . '/includes/header.php';
?>
<h1 class="page-title mb-1">Return Company SIM</h1>
<p class="page-sub mb-3">
    Returning closes the current assignment &mdash; history is preserved and never overwritten.
</p>

<?php if ($error !== ''): ?>
    <div class="alert-error mb-3"><?= e($error) ?></div>
<?php endif; ?>


<?php if ($confirm !== null): ?>
    <!-- confirmation step -->
    <div class="col-lg-7">
        <div class="confirm-box">
            <div class="section-label">Confirm return</div>
            <h5 class="mb-3">
                Return SIM <?= e($confirm['info']['mobile_number']) ?>
                from <?= e($confirm['info']['name']) ?>
                (Badge <?= e($confirm['info']['badge_number']) ?>)?
            </h5>
            <dl class="row mb-0 small">
                <dt class="col-4">Assignment terminal</dt>
                <dd class="col-8"><?= e($confirm['info']['terminal_name']) ?></dd>
                <dt class="col-4">Carrier</dt>
                <dd class="col-8"><?= e($confirm['info']['carrier_name']) ?></dd>
                <dt class="col-4">Return date</dt>
                <dd class="col-8"><?= e($confirm['return_date']) ?></dd>
                <dt class="col-4">Return reason</dt>
                <dd class="col-8"><?= e(SimService::RETURN_REASONS[$confirm['reason']]
                    ?? $confirm['reason']) ?></dd>
                <dt class="col-4">Remarks</dt>
                <dd class="col-8"><?= e($confirm['remarks'] !== '' ? $confirm['remarks'] : '—') ?></dd>
            </dl>

            <form method="post" action="return-sim.php" class="mt-3">
                <?= csrfField() ?>
                <input type="hidden" name="confirmed" value="1">
                <input type="hidden" name="assignment_id" value="<?= (int)$confirm['assignment_id'] ?>">
                <input type="hidden" name="return_date" value="<?= e($confirm['return_date']) ?>">
                <input type="hidden" name="return_reason" value="<?= e($confirm['reason']) ?>">
                <input type="hidden" name="remarks" value="<?= e($confirm['remarks']) ?>">
                <input type="hidden" name="sim_id" value="<?= (int)$confirm['sim_id'] ?>">
                <button type="submit" class="btn btn-warning">Confirm return</button>
                <a href="return-sim.php" class="btn btn-outline-secondary">Cancel</a>
            </form>
        </div>
    </div>
<?php else: ?>
    <div class="row g-3 mb-3">
        <div class="col-12">
            <div class="card">
                <div class="card-header">Current assignment</div>
                <div class="card-body">
                    <form method="get" action="return-sim.php" class="d-flex gap-2 mb-1">
                        <input type="text" class="form-control form-control-sm" name="aq"
                               value="<?= e($aq) ?>" placeholder="Search badge / driver / SIM number…"
                               autocomplete="off" aria-label="Search assignments">
                        <button class="btn btn-outline-primary btn-sm" type="submit">Search</button>
                        <?php if ($aq !== '' || $apage > 1): ?>
                            <a class="btn btn-link btn-sm" href="return-sim.php<?= e($qs(['aq' => null, 'apage' => null])) ?>">Reset</a>
                        <?php endif; ?>
                        <input type="hidden" name="assignment_id" data-keep="assignment"
                               value="<?= $prefill ?: '' ?>">
                    </form>
                    <div class="form-text mb-1">Click a row to choose the assignment being returned.</div>
                    <?php if ($assignments === []): ?>
                        <div class="form-text text-danger mb-1">
                            <?= $aq !== '' ? 'No assignments match ' . e(json_encode($aq)) . '.'
                                : 'No open assignments in scope.' ?>
                        </div>
                        <?php if ($aq !== ''): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="return-sim.php<?= e($qs(['aq' => null, 'apage' => null])) ?>">Clear</a>
                        <?php endif; ?>
                    <?php else: ?>
                        <table class="table table-sm table-hover mb-0" id="assignmentPicker"
                               data-picker="assignment" data-status="aPickStatus"
                               data-sim-sync="hiddenSimId">
                            <thead>
                            <tr>
                                <th data-no-sort="1">Badge</th>
                                <th data-no-sort="1">Driver</th>
                                <th data-no-sort="1">SIM</th>
                                <th data-no-sort="1">Carrier</th>
                                <th data-no-sort="1">Terminal</th>
                                <th class="text-end" data-no-sort="1">Action</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($assignments as $a): ?>
                                <tr data-assignment-id="<?= (int)$a['assignment_id'] ?>"
                                    data-sim="<?= (int)$a['sim_id'] ?>"
                                    <?= $prefill === (int)$a['assignment_id'] ? 'class="row-selected"' : '' ?>
                                    data-label="<?= e($a['badge_number'] . ' — ' . $a['driver_name']
                                        . ' (' . $a['mobile_number'] . ')') ?>">
                                    <td><?= e($a['badge_number']) ?></td>
                                    <td><?= e($a['driver_name']) ?></td>
                                    <td class="phone"><?= e($a['mobile_number']) ?></td>
                                    <td class="small"><?= e($a['carrier_name']) ?></td>
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
                        Showing <?= count($assignments) ?> of <?= $total ?> &middot;
                        page <?= $apage ?> of <?= $pages ?>
                    </span>
                    <?php renderPagination($apage, $pages, 'return-sim.php' . $qs(['apage' => null]), 'apage') ?>
                </div>
            </div>
        </div>
    </div>
        <form method="post" action="return-sim.php">
        <?= csrfField() ?>
        <input type="hidden" name="assignment_id" id="assignmentIdInput" data-keep="assignment"
               value="<?= $prefill ?: '' ?>">
        <input type="hidden" name="sim_id" id="hiddenSimId" value="<?= $simPrefill > 0 ? $simPrefill : 0 ?>">
        <div class="card">
            <div class="card-body">
                <div class="row g-2 mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Return date</label>
                        <input type="date" class="form-control" name="return_date"
                               value="<?= e(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Return reason</label>
                        <select class="form-select" name="return_reason" required>
                            <?php foreach (SimService::RETURN_REASONS as $rkey => $rlabel): ?>
                                <option value="<?= e($rkey) ?>"><?= e($rlabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Remarks (optional)</label>
                    <input type="text" class="form-control" name="remarks" maxlength="255">
                </div>
                <button type="submit" class="btn btn-warning" id="reviewBtn">Return SIM</button>
                <a href="assignments.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </div>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
