<?php
/**
 * SIMLedger - Mark Lost / Damaged / Suspended (spec section 42).
 *
 * Requires date + reason + remarks. Closes the active assignment and changes
 * the SIM status atomically. The dispatcher can then issue another SIM.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/lookups.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/services/SimService.php';

$pdo   = $GLOBALS['pdo'];
$scope = currentTerminalId();

requirePermission('sim.transact');

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrfCheck();

    $simId    = reqInt('sim_id', 0, 1, 100000000);
    $target   = (string)($_POST['target_status'] ?? '');
    $date     = trim((string)($_POST['action_date'] ?? ''));
    $reason   = cleanStr($_POST['reason'] ?? null, 60);
    $remarks  = cleanStr($_POST['remarks'] ?? null, 255);

    try {
        if (!in_array($target, ['lost', 'damaged', 'suspended'], true)) {
            throw new RuntimeException('Choose Lost, Damaged or Suspended.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new RuntimeException('A valid date is required.');
        }
        if ($reason === null || $remarks === null) {
            throw new RuntimeException('Reason and remarks are both required.');
        }

        $stmt = $pdo->prepare(
            'SELECT s.terminal_id, d.terminal_id AS holder_terminal
               FROM company_sims s
          LEFT JOIN driver_sim_assignments a ON a.sim_id = s.id AND a.returned_at IS NULL
          LEFT JOIN drivers d ON d.id = a.driver_id
              WHERE s.id = ?'
        );
        $stmt->execute([$simId]);
        $row = $stmt->fetch();

        if ($row === false) {
            throw new RuntimeException('SIM not found.');
        }
        assertCustodyAccess(
            (int)$row['terminal_id'],
            $row['holder_terminal'] !== null ? (int)$row['holder_terminal'] : null
        );

        $svc = new SimService($pdo);
        $svc->markLostDamaged($simId, $target, $date, $reason, $remarks,
            (int)$_SESSION['user_id']);

        setFlash('ok', 'SIM marked ' . $target . '. The assignment was closed and the driver '
            . 'can now be issued another SIM.');
        header('Location: sims.php?status=' . $target);
        exit;
    } catch (Throwable $e) {
        $error = friendlyError($e);
    }
}

// --- GET: search / pagination for the assigned-SIM picker -------------------
$sq      = trim((string)($_GET['sq'] ?? ''));
$spage   = reqInt('spage', 1, 1, 100000);
$prefill = reqInt('sim_id', 0, 0, 100000000);
$perPage = 10;

// Open assignments in scope (custody: book terminal or holder's terminal).
$where  = ' WHERE a.returned_at IS NULL AND s.status = "assigned"';
$params = [];
if ($scope !== null) {
    $where .= ' AND (a.terminal_id = ? OR d.terminal_id = ?)';
    $params[] = $scope;
    $params[] = $scope;
}
if ($sq !== '') {
    $where .= ' AND (s.mobile_number LIKE ? OR d.name LIKE ? OR d.badge_number LIKE ?)';
    array_push($params, '%' . $sq . '%', '%' . $sq . '%', '%' . $sq . '%');
}
$cnt = $pdo->prepare(
    'SELECT COUNT(*) FROM driver_sim_assignments a
       JOIN company_sims s ON s.id = a.sim_id
       JOIN drivers d ON d.id = a.driver_id' . $where
);
$cnt->execute($params);
$total = (int)$cnt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$spage = min($spage, $pages);

// Deep link ?sim_id=N: land on the page containing that SIM (badge order).
if ($prefill > 0 && !isset($_GET['spage'])) {
    $bp = $pdo->prepare(
        'SELECT d.badge_number FROM driver_sim_assignments a
           JOIN company_sims s ON s.id = a.sim_id
           JOIN drivers d ON d.id = a.driver_id
          WHERE s.id = ? AND a.returned_at IS NULL ORDER BY a.id DESC LIMIT 1'
    );
    $bp->execute([$prefill]);
    $badge = $bp->fetchColumn();
    if ($badge !== false) {
        $pos = $pdo->prepare(
            'SELECT COUNT(*) FROM driver_sim_assignments a
               JOIN company_sims s ON s.id = a.sim_id
               JOIN drivers d ON d.id = a.driver_id' . $where . ' AND d.badge_number < ?'
        );
        $posParams = $params;
        $posParams[] = $badge;
        $pos->execute($posParams);
        $spage = min($pages, max(1, (int)floor((int)$pos->fetchColumn() / $perPage) + 1));
    }
}
$offset = ($spage - 1) * $perPage;

$stmt = $pdo->prepare(
    'SELECT s.id AS sim_id, s.mobile_number, d.name AS driver_name, d.badge_number,
            t.name AS terminal_name, c.name AS carrier_name
       FROM driver_sim_assignments a
       JOIN company_sims s ON s.id = a.sim_id
       JOIN drivers d ON d.id = a.driver_id
       JOIN terminals t ON t.id = a.terminal_id
       JOIN carriers c ON c.id = s.carrier_id'
    . $where . ' ORDER BY d.badge_number LIMIT ' . $perPage . ' OFFSET ' . $offset
);
$stmt->execute($params);
$assignments = $stmt->fetchAll();

// URL-state helper.
$qs = static function (array $over = []) use ($sq, $spage, $prefill): string {
    $p = [];
    if ($sq !== '') { $p['sq'] = $sq; }
    if ($spage > 1) { $p['spage'] = $spage; }
    if ($prefill > 0) { $p['sim_id'] = $prefill; }
    foreach ($over as $k => $v) {
        if ($v === null) { unset($p[$k]); } else { $p[$k] = $v; }
    }
    return $p === [] ? '' : '?' . http_build_query($p);
};

$pageTitle = 'Mark Lost / Damaged';
$activeNav = 'sim-mark-lost';
require __DIR__ . '/includes/header.php';
?>
<h1 class="page-title mb-1">Mark SIM Lost / Damaged / Suspended</h1>
<p class="page-sub mb-3">
    Closes the active assignment first, then changes the status. History is preserved.
</p>

<?php if ($error !== ''): ?>
    <div class="alert-error mb-3"><?= e($error) ?></div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-12">
        <div class="card" id="picker-partial" data-tt-partial>
            <div class="card-header">Assigned SIM</div>
            <div class="card-body">
                <form method="get" action="mark-lost.php" class="d-flex gap-2 mb-1"
                      data-tt-target="picker-partial" data-tt-page="spage">
                    <input type="text" class="form-control form-control-sm" name="sq"
                           value="<?= e($sq) ?>" placeholder="Search number / driver / badge…"
                           autocomplete="off" aria-label="Search assigned SIMs">
                    <button class="btn btn-outline-primary btn-sm" type="submit">Search</button>
                    <?php if ($sq !== '' || $spage > 1): ?>
                        <a class="btn btn-link btn-sm" data-tt-ajax
                           href="mark-lost.php<?= e($qs(['sq' => null, 'spage' => null])) ?>">Reset</a>
                    <?php endif; ?>
                    <input type="hidden" name="sim_id" data-keep="sim" value="<?= $prefill ?: '' ?>">
                </form>
                <div class="form-text mb-1">Click a row to choose the SIM being reported.</div>
                <?php if ($assignments === []): ?>
                    <div class="form-text text-danger mb-1">
                        <?= $sq !== '' ? 'No assigned SIMs match ' . e(json_encode($sq)) . '.'
                            : 'No assigned SIMs in scope.' ?>
                    </div>
                    <?php if ($sq !== ''): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="mark-lost.php<?= e($qs(['sq' => null, 'spage' => null])) ?>">Clear</a>
                    <?php endif; ?>
                <?php else: ?>
                    <table class="table table-sm table-hover mb-0 picker-table" id="simLostPicker"
                           data-picker="sim" data-status="sPickStatus">
                        <thead>
                        <tr>
                            <th data-no-sort="1">Mobile</th>
                            <th data-no-sort="1">Driver</th>
                            <th data-no-sort="1">Badge</th>
                            <th data-no-sort="1">Terminal</th>
                            <th class="text-end" data-no-sort="1">Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($assignments as $a): ?>
                            <tr data-sim-id="<?= (int)$a['sim_id'] ?>"
                                <?= $prefill === (int)$a['sim_id'] ? 'class="row-selected"' : '' ?>
                                data-label="<?= e($a['mobile_number'] . ' — ' . $a['driver_name']
                                    . ' (' . $a['badge_number'] . ')') ?>">
                                <td class="phone"><?= e($a['mobile_number']) ?></td>
                                <td><?= e($a['driver_name']) ?></td>
                                <td><?= e($a['badge_number']) ?></td>
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
                <div class="form-text mt-1" id="sPickStatus">Selected: —</div>
            </div>
            <div class="card-footer d-flex justify-content-between align-items-center">
                <span class="small text-muted">
                    Showing <?= count($assignments) ?> of <?= $total ?> &middot;
                    page <?= $spage ?> of <?= $pages ?>
                </span>
                <?php renderPagination($spage, $pages, 'mark-lost.php' . $qs(['spage' => null]), 'spage') ?>
            </div>
        </div>
    </div>
</div>
<form method="post" action="mark-lost.php"
      data-confirm-danger
      data-confirm="Close the assignment and change the SIM status?">
    <?= csrfField() ?>
    <input type="hidden" name="sim_id" id="simIdInput" data-keep="sim" value="<?= $prefill ?: '' ?>">
    <div class="card">
        <div class="card-header">Report a problem SIM</div>
        <div class="card-body">
            <div class="row g-2 mb-3">
                <div class="col-md-4">
                    <label class="form-label">Target status</label>
                    <select class="form-select" name="target_status" required>
                        <option value="lost">Lost</option>
                        <option value="damaged">Damaged</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Date</label>
                    <input type="date" class="form-control" name="action_date"
                           value="<?= e(date('Y-m-d')) ?>" required>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Reason (required)</label>
                <input type="text" class="form-control" name="reason" maxlength="60"
                       placeholder="e.g. Driver reported lost at Riyadh" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Remarks (required)</label>
                <input type="text" class="form-control" name="remarks" maxlength="255" required>
            </div>
            <button type="submit" class="btn btn-danger" id="reviewBtn">
                Mark SIM
            </button>
            <a href="sims.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </div>
</form>
<?php require __DIR__ . '/includes/footer.php'; ?>
