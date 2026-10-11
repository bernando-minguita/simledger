<?php
/**
 * SIMLedger - SIM master records (admin only).
 *
 * Dispatchers cannot create/edit SIM master data, change the mobile number
 * or ICCID (spec section 5.2). Uniqueness is enforced on the normalized
 * number and the ICCID (sections 11 and 36).
 */

declare(strict_types=1);

$base = '../';

require_once __DIR__ . '/../includes/lookups.php';
require_once __DIR__ . '/../includes/audit.php';

$pdo = $GLOBALS['pdo'];
requirePermission('reference.manage');

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrfCheck();

    $action  = (string)($_POST['action'] ?? '');
    $simId   = reqInt('sim_id', 0, 0, 100000000);
    $mobile  = trim((string)($_POST['mobile_number'] ?? ''));
    $iccid   = trim((string)($_POST['iccid'] ?? ''));
    $carrier = reqInt('carrier_id', 0, 0, 100000);
    $termId  = reqInt('terminal_id', 0, 0, 100000);
    $status  = (string)($_POST['status'] ?? 'available');
    $notes   = cleanStr($_POST['notes'] ?? null, 255);

    try {
        $norm = normalizePhone($mobile);

        if ($norm === null) {
            throw new RuntimeException(
                'Enter a valid Saudi mobile number (e.g. 05XXXXXXXX or +9665XXXXXXXX).'
            );
        }
        if ($carrier <= 0) {
            throw new RuntimeException('Carrier is required.');
        }
        if ($termId <= 0) {
            throw new RuntimeException('Terminal is required.');
        }
        assertTerminalAccess($termId);
        if (!isset(simStatusOptions()[$status])) {
            throw new RuntimeException('Invalid status.');
        }

        $iccidSql = $iccid !== '' ? $iccid : null;

        // Pre-flight friendly duplicate messages (DB constraints are the backstop).
        $dup = $pdo->prepare(
            'SELECT id FROM company_sims WHERE normalized_mobile_number = ? AND id <> ?'
        );
        $dup->execute([$norm, $simId]);
        if ($dup->fetch() !== false) {
            throw new RuntimeException('This mobile number already exists in the SIM inventory.');
        }

        if ($iccidSql !== null) {
            $dupI = $pdo->prepare('SELECT id FROM company_sims WHERE iccid = ? AND id <> ?');
            $dupI->execute([$iccidSql, $simId]);
            if ($dupI->fetch() !== false) {
                throw new RuntimeException('This ICCID already exists in the SIM inventory.');
            }
        }

        if ($action === 'create') {
            // Auto SIM reference: SIM-000123 style (section 9).
            $next = (int)$pdo->query(
                'SELECT COALESCE(MAX(id), 0) + 1 FROM company_sims'
            )->fetchColumn();
            $ref = sprintf('SIM-%06d', $next);

            $stmt = $pdo->prepare(
                'INSERT INTO company_sims
                    (sim_reference, mobile_number, normalized_mobile_number, iccid,
                     carrier_id, terminal_id, status, notes, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $ref, displayPhone($norm), $norm, $iccidSql,
                $carrier, $termId, $status, $notes,
                (int)$_SESSION['user_id'], (int)$_SESSION['user_id'],
            ]);
            $newId = (int)$pdo->lastInsertId();
            logAudit('CREATE_SIM', 'sim', $newId, null,
                ['number' => displayPhone($norm), 'iccid' => $iccidSql,
                 'carrier_id' => $carrier, 'terminal_id' => $termId, 'status' => $status]);
            setFlash('ok', 'SIM ' . displayPhone($norm) . ' created.');
        } elseif ($action === 'update') {
            $oldStmt = $pdo->prepare('SELECT * FROM company_sims WHERE id = ?');
            $oldStmt->execute([$simId]);
            $old = $oldStmt->fetch();

            if ($old === false) {
                throw new RuntimeException('SIM not found.');
            }
            assertTerminalAccess((int)$old['terminal_id']);

            if ($old['status'] === 'assigned' && $status !== 'assigned') {
                throw new RuntimeException(
                    'This SIM is assigned to a driver. Use Return / Mark Lost to release it '
                    . 'so the assignment history stays intact.'
                );
            }

            $stmt = $pdo->prepare(
                'UPDATE company_sims
                    SET mobile_number = ?, normalized_mobile_number = ?, iccid = ?,
                        carrier_id = ?, terminal_id = ?, status = ?, notes = ?, updated_by = ?
                  WHERE id = ?'
            );
            $stmt->execute([
                displayPhone($norm), $norm, $iccidSql,
                $carrier, $termId, $status, $notes,
                (int)$_SESSION['user_id'], $simId,
            ]);
            logAudit('UPDATE_SIM', 'sim', $simId,
                ['number' => $old['mobile_number'], 'status' => $old['status'],
                 'carrier_id' => (int)$old['carrier_id'], 'terminal_id' => (int)$old['terminal_id']],
                ['number' => displayPhone($norm), 'status' => $status,
                 'carrier_id' => $carrier, 'terminal_id' => $termId]);
            setFlash('ok', 'SIM updated.');
        }

        header('Location: sims.php');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
            $error = strpos((string)$e->getMessage(), 'normalized_mobile') !== false
                ? 'This mobile number already exists in the SIM inventory.'
                : 'This ICCID already exists in the SIM inventory.';
        }
    }
}

// --- GET data ---------------------------------------------------------------
$q      = trim((string)($_GET['q'] ?? ''));
$where  = ' WHERE 1=1';
$params = [];
if ($q !== '') {
    $where .= ' AND (s.mobile_number LIKE ? OR s.iccid LIKE ? OR s.sim_reference LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
$baseSql = 'SELECT s.*, t.name AS terminal_name, c.name AS carrier_name
       FROM company_sims s
       JOIN terminals t ON t.id = s.terminal_id
       JOIN carriers c ON c.id = s.carrier_id
      ' . $where;

$sortMap = [
    'sim_ref'  => 's.sim_reference',
    'number'   => 's.mobile_number',
    'iccid'    => 's.iccid',
    'carrier'  => 'c.name',
    'terminal' => 't.name',
    'status'   => 's.status',
];
$sort    = reqSort('sort', array_keys($sortMap), 'number');
$dir     = reqDir('asc');
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
$sims = $stmt->fetchAll();

$editId  = reqInt('edit', 0, 0, 100000000);
$editRow = null;
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM company_sims WHERE id = ?');
    $stmt->execute([$editId]);
    $editRow = $stmt->fetch() ?: null;
    if ($editRow !== null) {
        assertTerminalAccess((int)$editRow['terminal_id']);
    }
}

$pageTitle = 'SIM Records';
$activeNav = 'admin-sims';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="page-title">SIM Records</h1>
        <p class="page-sub">Master data for company-owned SIMs &mdash; admin only.</p>
    </div>
    <a class="btn btn-link btn-sm" href="../sims.php">Back to inventory</a>
</div>


<div class="row g-3">
    <div class="col-lg-7">
        <div class="card" id="sims-partial" data-tt-partial>
            <div class="card-header">
                SIM master
                <form method="get" class="d-inline float-end" data-tt-target="sims-partial">
                    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Number / ICCID / SIM ID"
                           class="form-control form-control-sm d-inline-block" style="width:210px">
                    <button class="btn btn-sm btn-outline-primary" type="submit">Search</button>
                </form>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped mb-0 dt-nowrap" data-tt-sort="server"
                           data-offset="<?= $offset ?>">
                        <thead>
                        <tr>
                            <th data-sort="sim_ref" data-col-default="hide">SIM ID</th><th data-sort="number">Number</th>
                            <th data-sort="iccid">ICCID</th><th data-sort="carrier">Carrier</th>
                            <th data-sort="terminal">Terminal</th><th data-sort="status">Status</th>
                            <th class="text-end" data-no-sort="1">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($sims as $s): ?>
                            <tr>
                                <td><?= e($s['sim_reference'] ?? '—') ?></td>
                                <td class="phone"><?= e($s['mobile_number']) ?></td>
                                <td class="small text-muted"><?= e($s['iccid'] ?? '—') ?></td>
                                <td><?= e($s['carrier_name']) ?></td>
                                <td><?= e($s['terminal_name']) ?></td>
                                <td><?= simStatusBadge($s['status']) ?></td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-secondary"
                                       href="sims.php?edit=<?= (int)$s['id'] ?>">Edit</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-between align-items-center">
                <span class="small text-muted">
                    Showing <?= count($sims) ?> of <?= $total ?> &middot; page <?= $page ?> of <?= $pages ?>
                </span>
                <?php renderPagination($page, $pages, 'sims.php?' . filtersUrl()); ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><?= $editRow !== null
                ? 'Edit: ' . e($editRow['mobile_number']) : 'Add SIM' ?></div>
            <div class="card-body">
                <?php if ($error !== ''): ?>
                    <div class="alert-error mb-3"><?= e($error) ?></div>
                <?php endif; ?>
                <form method="post" action="sims.php">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $editRow !== null ? 'update' : 'create' ?>">
                    <?php if ($editRow !== null): ?>
                        <input type="hidden" name="sim_id" value="<?= (int)$editRow['id'] ?>">
                    <?php endif; ?>
                    <div class="mb-2">
                        <label class="form-label">Mobile number</label>
                        <input type="text" class="form-control" name="mobile_number" required
                               value="<?= e($editRow['mobile_number'] ?? '') ?>"
                               placeholder="05XXXXXXXX">
                        <div class="form-text">Stored as +9665XXXXXXXX; format variants are
                            recognized as the same number.</div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">ICCID (optional, unique)</label>
                        <input type="text" class="form-control" name="iccid" maxlength="25"
                               value="<?= e($editRow['iccid'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Carrier</label>
                        <select class="form-select" name="carrier_id" required>
                            <?php foreach (carrierOptions(false) as $cid => $cname): ?>
                                <option value="<?= $cid ?>"
                                    <?= (int)($editRow['carrier_id'] ?? 0) === $cid ? 'selected' : '' ?>>
                                    <?= e($cname) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Terminal</label>
                        <?= terminalSelect($editRow['terminal_id'] ?? null) ?>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Status</label>
                        <?php $locked = $editRow !== null && $editRow['status'] === 'assigned'; ?>
                        <select class="form-select" name="status" <?= $locked ? 'disabled' : '' ?>>
                            <?php foreach (simStatusOptions() as $skey => $slabel): ?>
                                <option value="<?= e($skey) ?>"
                                    <?= ($editRow['status'] ?? 'available') === $skey ? 'selected' : '' ?>>
                                    <?= e($slabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($locked): ?>
                            <div class="form-text text-warning">
                                Assigned SIMs release only through Return / Mark Lost / Transfer.</div>
                            <input type="hidden" name="status" value="assigned">
                        <?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <input type="text" class="form-control" name="notes" maxlength="255"
                               value="<?= e($editRow['notes'] ?? '') ?>">
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <?= $editRow !== null ? 'Save changes' : 'Create SIM' ?></button>
                    <?php if ($editRow !== null): ?>
                        <a class="btn btn-outline-secondary" href="sims.php">Cancel</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>

