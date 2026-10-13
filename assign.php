<?php
/**
 * SIMLedger - Issue SIM workflow (spec section 17).
 *
 * Dispatcher picks a driver + an Available SIM, reviews the confirmation,
 * and the transaction runs inside SimService with a full audit record.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/lookups.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/services/SimService.php';

$pdo   = $GLOBALS['pdo'];
$scope = currentTerminalId();

requirePermission('sim.transact');

/**
 * Load a driver row.
 */
function fetchDriver(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare(
        'SELECT d.*, t.name AS terminal_name
           FROM drivers d JOIN terminals t ON t.id = d.terminal_id
          WHERE d.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if ($row === false) {
        throw new RuntimeException('Driver not found.');
    }

    return $row;
}

/**
 * Load a SIM row.
 */
function fetchSim(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare(
        'SELECT s.*, t.name AS terminal_name, c.name AS carrier_name
           FROM company_sims s
           JOIN terminals t ON t.id = s.terminal_id
           JOIN carriers c ON c.id = s.carrier_id
          WHERE s.id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if ($row === false) {
        throw new RuntimeException('SIM not found.');
    }

    return $row;
}

$error   = '';
$confirm = null;   // confirmation payload for the review step

// --- POST: either show confirmation or execute -----------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrfCheck();

    $driverId  = reqInt('driver_id', 0, 1, 100000000);
    $simId     = reqInt('sim_id', 0, 1, 100000000);
    $remarks   = cleanStr($_POST['remarks'] ?? null, 255);
    $confirmed = ($_POST['confirmed'] ?? '') === '1';

    try {
        $driver = fetchDriver($pdo, $driverId);
        $sim    = fetchSim($pdo, $simId);
        assertTerminalAccess((int)$driver['terminal_id']);
        assertTerminalAccess((int)$sim['terminal_id']);

        if (!$confirmed) {
            // Step 1: build the confirmation (spec: "Assign SIM 0551234567 to
            // Ahmed Khan (Badge 30100)?").
            $confirm = [
                'driver_id' => $driverId,
                'sim_id'    => $simId,
                'remarks'   => (string)($remarks ?? ''),
                'driver'    => $driver,
                'sim'       => $sim,
            ];
        } else {
            // Step 2: execute inside the service transaction.
            $svc = new SimService($pdo);
            $aid = $svc->issue($driverId, $simId, $remarks, (int)$_SESSION['user_id']);

            setFlash('ok', sprintf(
                'SIM %s assigned to %s (Badge %s). Assignment #%d created.',
                $sim['mobile_number'], $driver['name'], $driver['badge_number'], $aid
            ));
            header('Location: sim-history.php?sim_id=' . $simId);
            exit;
        }
    } catch (Throwable $e) {
        $error = friendlyError($e);
    }
}


// --- GET: search / pagination / prefills -----------------------------------
$driverQ         = trim((string)($_GET['driver_q'] ?? ''));
$simQ            = trim((string)($_GET['sim_q'] ?? ''));
$driverIdPrefill = reqInt('driver_id', 0, 0, 100000000);
$simIdPrefill    = reqInt('sim_id', 0, 0, 100000000);
$dpage           = reqInt('dpage', 1, 1, 100000);
$spage           = reqInt('spage', 1, 1, 100000);
$perPage         = 10;

// Driver list: active, in scope, optional search over badge / name.
$driverWhere  = " WHERE d.is_active = 1 AND d.status = 'active'";
$driverParams = [];
if ($scope !== null) {
    $driverWhere .= ' AND d.terminal_id = ?';
    $driverParams[] = $scope;
}
if ($driverQ !== '') {
    $driverWhere .= ' AND (d.badge_number LIKE ? OR d.name LIKE ?)';
    $like = '%' . $driverQ . '%';
    array_push($driverParams, $like, $like);
}
$driverCnt = $pdo->prepare('SELECT COUNT(*) FROM drivers d' . $driverWhere);
$driverCnt->execute($driverParams);
$driverTotal = (int)$driverCnt->fetchColumn();
$driverPages = max(1, (int)ceil($driverTotal / $perPage));
$dpage       = min($dpage, $driverPages);
$doffset     = ($dpage - 1) * $perPage;

// Deep link ?driver_id: land on the page containing that driver.
if ($driverIdPrefill > 0 && !isset($_GET['dpage'])) {
    $tgt = $pdo->prepare('SELECT badge_number FROM drivers WHERE id = ?');
    $tgt->execute([$driverIdPrefill]);
    $badge = $tgt->fetchColumn();
    if ($badge !== false) {
        $pos = $pdo->prepare(
            "SELECT COUNT(*) FROM drivers d
              WHERE d.is_active = 1 AND d.status = 'active'"
            . ($scope !== null ? ' AND d.terminal_id = ?' : '')
            . ' AND d.badge_number < ?'
        );
        $pos->execute($scope !== null ? [$scope, $badge] : [$badge]);
        $dpage   = min($driverPages, max(1, (int)floor((int)$pos->fetchColumn() / $perPage) + 1));
        $doffset = ($dpage - 1) * $perPage;
    }
}
$driverStmt = $pdo->prepare(
    'SELECT d.id, d.badge_number, d.name, t.name AS terminal_name,
            EXISTS(SELECT 1 FROM driver_sim_assignments a
                    WHERE a.driver_id = d.id AND a.returned_at IS NULL) AS has_sim
       FROM drivers d JOIN terminals t ON t.id = d.terminal_id'
    . $driverWhere . ' ORDER BY d.badge_number LIMIT ' . $perPage . ' OFFSET ' . $doffset
);
$driverStmt->execute($driverParams);
$driverRows = $driverStmt->fetchAll();

// Available SIM list: in scope, optional search over number / ICCID / carrier.
$simWhere  = " WHERE s.status = 'available'";
$simParams = [];
if ($scope !== null) {
    $simWhere .= ' AND s.terminal_id = ?';
    $simParams[] = $scope;
}
if ($simQ !== '') {
    $simWhere .= ' AND (s.mobile_number LIKE ? OR s.iccid LIKE ? OR c.name LIKE ?)';
    $like = '%' . $simQ . '%';
    array_push($simParams, $like, $like, $like);
}
$simCnt = $pdo->prepare('SELECT COUNT(*) FROM company_sims s'
    . ' JOIN carriers c ON c.id = s.carrier_id' . $simWhere);
$simCnt->execute($simParams);
$simTotal = (int)$simCnt->fetchColumn();
$simPages = max(1, (int)ceil($simTotal / $perPage));
$spage    = min($spage, $simPages);

// Deep link ?sim_id=N (Available SIMs "Issue" button): land on the page that
// actually contains that SIM when the caller did not pick a page.
if ($simIdPrefill > 0 && !isset($_GET['spage'])) {
    $pos = $pdo->prepare(
        "SELECT COUNT(*) FROM company_sims s
          WHERE s.status = 'available'"
        . ($scope !== null ? ' AND s.terminal_id = ?' : '')
        . ' AND s.mobile_number < (SELECT mobile_number FROM company_sims WHERE id = ?)'
    );
    $pos->execute($scope !== null ? [$scope, $simIdPrefill] : [$simIdPrefill]);
    $spage   = min($simPages, max(1, (int)floor((int)$pos->fetchColumn() / $perPage) + 1));
}
$soffset = ($spage - 1) * $perPage;

$simSelect = 'SELECT s.id, s.mobile_number, s.sim_reference, s.iccid,
                t.name AS terminal_name, c.name AS carrier_name,
                last.driver_name, last.badge_number, last.returned_at
           FROM company_sims s
           JOIN terminals t ON t.id = s.terminal_id
           JOIN carriers c ON c.id = s.carrier_id
           LEFT JOIN (
               SELECT a.sim_id, a.returned_at,
                      d.name AS driver_name, d.badge_number
                 FROM driver_sim_assignments a
                 JOIN drivers d ON d.id = a.driver_id
                WHERE a.id = (SELECT MAX(a2.id) FROM driver_sim_assignments a2
                               WHERE a2.sim_id = a.sim_id AND a2.returned_at IS NOT NULL)
           ) last ON last.sim_id = s.id';
$simStmt = $pdo->prepare($simSelect . $simWhere . ' ORDER BY s.mobile_number'
    . ' LIMIT ' . $perPage . ' OFFSET ' . $soffset);
$simStmt->execute($simParams);
$simRows = $simStmt->fetchAll();

// URL-state helper: full picker state as a query string (?...), with optional
// overrides (null removes a key). Keeps selections across search/page loads.
$qs = static function (array $over = []) use (
    $driverQ, $dpage, $driverIdPrefill, $simQ, $spage, $simIdPrefill
): string {
    $p = [];
    if ($driverQ !== '') { $p['driver_q'] = $driverQ; }
    if ($dpage > 1) { $p['dpage'] = $dpage; }
    if ($driverIdPrefill > 0) { $p['driver_id'] = $driverIdPrefill; }
    if ($simQ !== '') { $p['sim_q'] = $simQ; }
    if ($spage > 1) { $p['spage'] = $spage; }
    if ($simIdPrefill > 0) { $p['sim_id'] = $simIdPrefill; }
    foreach ($over as $k => $v) {
        if ($v === null) { unset($p[$k]); } else { $p[$k] = $v; }
    }
    return $p === [] ? '' : '?' . http_build_query($p);
};

$pageTitle = 'Issue SIM';
$activeNav = 'sim-assign';
require __DIR__ . '/includes/header.php';
?>
<h1 class="page-title mb-1">Issue Company SIM</h1>
<p class="page-sub mb-3">
    The SIM is a company asset &mdash; this transaction records the driver as the current assignee.
</p>

<?php if ($error !== ''): ?>
    <div class="alert-error mb-3"><?= e($error) ?></div>
<?php endif; ?>

<?php if ($confirm !== null): ?>
    <!-- confirmation step -->
    <div class="col-lg-7">
        <div class="confirm-box">
            <div class="section-label">Confirm assignment</div>
            <h5 class="mb-3">
                Assign SIM <?= e($confirm['sim']['mobile_number']) ?>
                to <?= e($confirm['driver']['name']) ?>
                (Badge <?= e($confirm['driver']['badge_number']) ?>)?
            </h5>
            <dl class="row mb-0 small">
                <dt class="col-4">Driver terminal</dt>
                <dd class="col-8"><?= e($confirm['driver']['terminal_name']) ?></dd>
                <dt class="col-4">SIM terminal</dt>
                <dd class="col-8"><?= e($confirm['sim']['terminal_name']) ?></dd>
                <dt class="col-4">Carrier</dt>
                <dd class="col-8"><?= e($confirm['sim']['carrier_name']) ?></dd>
                <dt class="col-4">SIM status now</dt>
                <dd class="col-8"><?= simStatusBadge($confirm['sim']['status']) ?></dd>
                <dt class="col-4">Remarks</dt>
                <dd class="col-8"><?= e($confirm['remarks'] !== '' ? $confirm['remarks'] : '—') ?></dd>
            </dl>

            <form method="post" action="assign.php" class="mt-3">
                <?= csrfField() ?>
                <input type="hidden" name="driver_id" value="<?= (int)$confirm['driver_id'] ?>">
                <input type="hidden" name="sim_id" value="<?= (int)$confirm['sim_id'] ?>">
                <input type="hidden" name="remarks" value="<?= e($confirm['remarks']) ?>">
                <input type="hidden" name="confirmed" value="1">
                <button type="submit" class="btn btn-primary">Confirm assignment</button>
                <a href="assign.php" class="btn btn-outline-secondary">Cancel</a>
            </form>
        </div>
    </div>
<?php else: ?>
    <!-- selection step -->
    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">Driver</div>
                <div class="card-body">
                    <form method="get" action="assign.php" class="d-flex gap-2 mb-1">
                        <input type="text" class="form-control form-control-sm" name="driver_q"
                               value="<?= e($driverQ) ?>" placeholder="Search badge / name…"
                               autocomplete="off" aria-label="Search drivers">
                        <button class="btn btn-outline-primary btn-sm" type="submit">Search</button>
                        <?php if ($driverQ !== '' || $dpage > 1): ?>
                            <a class="btn btn-link btn-sm" href="assign.php<?= e($qs(['driver_q' => null, 'dpage' => null])) ?>">Reset</a>
                        <?php endif; ?>
                        <input type="hidden" name="driver_id" data-keep="driver" value="<?= $driverIdPrefill ?: '' ?>">
                        <input type="hidden" name="sim_id" data-keep="sim" value="<?= $simIdPrefill ?: '' ?>">
                        <?php if ($simQ !== ''): ?>
                            <input type="hidden" name="sim_q" value="<?= e($simQ) ?>">
                        <?php endif; ?>
                        <?php if ($spage > 1): ?>
                            <input type="hidden" name="spage" value="<?= $spage ?>">
                        <?php endif; ?>
                    </form>
                    <div class="form-text mb-1">Click a row to choose &middot;
                        <span class="hassim">HAS SIM</span> is rejected by the server.</div>
                    <?php if ($driverRows === []): ?>
                        <div class="form-text text-danger mb-1">
                            <?= $driverQ !== '' ? 'No drivers match ' . e(json_encode($driverQ)) . '.' : 'No active drivers.' ?>
                        </div>
                        <?php if ($driverQ !== ''): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="assign.php<?= e($qs(['driver_q' => null, 'dpage' => null])) ?>">Clear</a>
                        <?php endif; ?>
                    <?php else: ?>
                        <table class="table table-sm table-hover mb-0" id="driverPicker" data-picker="driver" data-status="driverPickStatus" data-preview="pvDriver">
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
                                    <?= $driverIdPrefill === (int)$d['id'] ? 'class="row-selected"' : '' ?>
                                    data-label="<?= e($d['badge_number'] . ' — ' . $d['name'] . ' (' . $d['terminal_name'] . ')') ?>">
                                    <td><?= e($d['badge_number']) ?></td>
                                    <td><?= e($d['name']) ?><?= $d['has_sim'] ? ' <span class="hassim">HAS SIM</span>' : '' ?></td>
                                    <td><?= e($d['terminal_name']) ?></td>
                                    <td class="text-end text-nowrap">
                                        <button type="button" data-role="pick"
                                                class="btn btn-sm btn-outline-primary">Select</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    <div class="form-text mt-1" id="driverPickStatus">Selected: —</div>
                </div>
                <div class="card-footer d-flex justify-content-between align-items-center">
                    <span class="small text-muted">
                        Showing <?= count($driverRows) ?> of <?= $driverTotal ?> &middot;
                        page <?= $dpage ?> of <?= $driverPages ?>
                    </span>
                    <?php renderPagination($dpage, $driverPages, 'assign.php' . $qs(['dpage' => null]), 'dpage') ?>
                </div>
            </div>
        </div>
                <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header">Company SIM (Available only)</div>
                <div class="card-body">
                    <form method="get" action="assign.php" class="d-flex gap-2 mb-1">
                        <input type="text" class="form-control form-control-sm" name="sim_q"
                               value="<?= e($simQ) ?>" placeholder="Search number / ICCID / carrier…"
                               autocomplete="off" aria-label="Search available SIMs">
                        <button class="btn btn-outline-primary btn-sm" type="submit">Search</button>
                        <?php if ($simQ !== '' || $spage > 1): ?>
                            <a class="btn btn-link btn-sm" href="assign.php<?= e($qs(['sim_q' => null, 'spage' => null])) ?>">Reset</a>
                        <?php endif; ?>
                        <input type="hidden" name="driver_id" data-keep="driver" value="<?= $driverIdPrefill ?: '' ?>">
                        <input type="hidden" name="sim_id" data-keep="sim" value="<?= $simIdPrefill ?: '' ?>">
                        <?= $driverQ !== '' ? '<input type="hidden" name="driver_q" value="' . e($driverQ) . '">' : '' ?>
                        <?= $dpage > 1 ? '<input type="hidden" name="dpage" value="' . $dpage . '">' : '' ?>
                    </form>
                    <div class="form-text mb-1">Only status <strong>available</strong> &middot; click a row to choose.</div>
                    <?php if ($simRows === []): ?>
                        <div class="form-text text-danger mb-1">
                            <?= $simQ !== '' ? 'No available SIMs match ' . e(json_encode($simQ)) . '.'
                                : 'No available SIMs in scope. See <a href="available.php">Available SIMs</a>.' ?>
                        </div>
                        <?php if ($simQ !== ''): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="assign.php<?= e($qs(['sim_q' => null, 'spage' => null])) ?>">Clear</a>
                        <?php endif; ?>
                    <?php else: ?>
                        <table class="table table-sm table-hover mb-0" id="simPicker" data-picker="sim" data-status="simPickStatus" data-preview="pvSim">
                            <thead>
                            <tr>
                                <th data-no-sort="1">Mobile</th>
                                <th data-no-sort="1">Carrier</th>
                                <th data-no-sort="1">Terminal</th>
                                <th data-no-sort="1">Last Badge</th>
                                <th class="text-end" data-no-sort="1">Action</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($simRows as $s): ?>
                                <tr data-sim-id="<?= (int)$s['id'] ?>"
                                    <?= $simIdPrefill === (int)$s['id'] ? 'class="row-selected"' : '' ?>
                                    data-label="<?= e($s['mobile_number'] . ' — ' . $s['carrier_name'] . ' (' . $s['terminal_name'] . ')') ?>">
                                    <td class="phone"><?= e($s['mobile_number']) ?></td>
                                    <td><?= e($s['carrier_name']) ?></td>
                                    <td><?= e($s['terminal_name']) ?></td>
                                    <td class="small" title="<?= e($s['driver_name'] ?? '') ?>"><?= e($s['badge_number'] ?? '—') ?></td>
                                    <td class="text-end text-nowrap">
                                        <button type="button" data-role="pick"
                                                class="btn btn-sm btn-outline-primary">Select</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    <div class="form-text mt-1" id="simPickStatus">Selected: —</div>
                </div>
                <div class="card-footer d-flex justify-content-between align-items-center">
                    <span class="small text-muted">
                        Showing <?= count($simRows) ?> of <?= $simTotal ?> &middot;
                        page <?= $spage ?> of <?= $simPages ?>
                    </span>
                    <?php renderPagination($spage, $simPages, 'assign.php' . $qs(['spage' => null]), 'spage') ?>
                </div>
            </div>
        </div>
    </div>

    <form method="post" action="assign.php" class="mt-3">
        <?= csrfField() ?>
        <input type="hidden" name="driver_id" id="driverIdInput" data-keep="driver" value="<?= $driverIdPrefill ?: '' ?>">
        <input type="hidden" name="sim_id" id="simIdInput" data-keep="sim" value="<?= $simIdPrefill ?: '' ?>">
        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header">Preview</div>
                    <div class="card-body py-2" aria-live="polite" id="pickPreview">
                        <div class="d-flex small mb-1">
                            <span class="text-muted pv-label">Driver</span>
                            <span id="pvDriver">—</span>
                        </div>
                        <div class="d-flex small mb-1">
                            <span class="text-muted pv-label">SIM</span>
                            <span id="pvSim">—</span>
                        </div>
                        <div class="d-flex small">
                            <span class="text-muted pv-label">Action</span>
                            <span>Issue new assignment &middot; <?= date('Y-m-d') ?> (today)</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-body d-flex flex-wrap gap-2 align-items-center py-2">
                        <label class="col-form-label" for="remarksInput">Remarks</label>
                        <input type="text" class="form-control picker-remarks" name="remarks"
                               id="remarksInput" maxlength="255" placeholder="optional">
                        <div class="ms-auto d-flex gap-2">
                            <button type="submit" class="btn btn-primary" id="reviewBtn">Review assignment…</button>
                            <a href="assign.php" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
