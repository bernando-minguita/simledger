<?php
/**
 * SIMLedger - CSV import (spec section 46).
 *
 * Two-phase import: upload -> preview (valid / duplicates / invalid counts)
 * -> explicit confirmation commits only the valid rows. Partial imports of
 * invalid data never happen without confirmation.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/lookups.php';
require_once __DIR__ . '/includes/audit.php';

$pdo = $GLOBALS['pdo'];
requirePermission('data.import');

$type    = (string)($_GET['type'] ?? $_POST['type'] ?? 'drivers');
$confirm = ($_POST['confirm'] ?? '') === '1';
$error   = '';

if (!in_array($type, ['drivers', 'sims'], true)) {
    $type = 'drivers';
}

/**
 * Parse an uploaded CSV into [header, rows].
 */
function parseCsvUpload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed (error ' . (int)$file['error'] . ').');
    }

    $fh = fopen($file['tmp_name'], 'r');

    if ($fh === false) {
        throw new RuntimeException('Cannot open the uploaded file.');
    }

    $header = fgetcsv($fh);

    if ($header === false) {
        fclose($fh);
        throw new RuntimeException('The file is empty.');
    }

    // Excel/Notepad pad CSVs with a UTF-8 BOM (EF BB BF); strip it so the
    // first column name matches the expected keys ("sim number", "badge
    // number", ...) - otherwise every row fails with a confusing
    // "missing value" error.
    $header = array_map(
        static fn ($h): string => strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string)$h))),
        $header
    );
    $rows   = [];

    while (($line = fgetcsv($fh)) !== false) {
        if ($line === [null] || $line === null || $line === false) {
            continue;
        }
        $combined = count($line) === count($header) ? array_combine($header, $line) : false;
        if ($combined !== false) {
            $rows[] = $combined;
        } else {
            // Wrong column count: keep the row so it surfaces as invalid in
            // the preview instead of being dropped silently.
            $rows[] = array_merge(array_fill_keys($header, ''),
                ['_malformed' => count($line)]);
        }
    }

    fclose($fh);

    return [$header, $rows];
}

/**
 * Validate + classify rows. Returns rows with _state / _errors / _data.
 */
function classifyRows(string $type, array $rows, PDO $pdo): array
{
    $terminals = [];
    foreach ($pdo->query('SELECT id, name, code FROM terminals WHERE is_active = 1') as $t) {
        $terminals[strtolower($t['name'])] = (int)$t['id'];
        $terminals[strtolower($t['code'])] = (int)$t['id'];
    }
    $carriers = [];
    foreach ($pdo->query('SELECT id, name FROM carriers WHERE is_active = 1') as $c) {
        $carriers[strtolower($c['name'])] = (int)$c['id'];
    }
    $agencies = [];
    foreach ($pdo->query('SELECT id, name, code FROM agencies WHERE is_active = 1') as $a) {
        $agencies[strtolower($a['name'])] = (int)$a['id'];
        $agencies[strtolower($a['code'])] = (int)$a['id'];
    }

    $out = [];

    foreach ($rows as $row) {
        $errors = [];
        $norm   = [];

        if (isset($row['_malformed'])) {
            $out[] = [
                '_state'  => 'invalid',
                '_errors' => ['wrong number of columns (' . (int)$row['_malformed'] . ' values)'],
                '_data'   => [],
                '_raw'    => $row,
            ];
            continue;
        }

        if ($type === 'drivers') {
            $badge = trim((string)($row['badge number'] ?? $row['badge_number'] ?? $row['badge'] ?? ''));
            $name  = trim((string)($row['driver name'] ?? $row['name'] ?? ''));
            $term  = trim((string)($row['terminal'] ?? ''));
            $stat  = strtolower(trim((string)($row['status'] ?? 'active')));
            $phone = trim((string)($row['personal mobile'] ?? $row['personal_mobile'] ?? ''));
            $agency = trim((string)($row['agency'] ?? $row['agency name'] ?? $row['agency_name'] ?? ''));
            $iqama  = trim((string)($row['iqama'] ?? $row['iqama number'] ?? $row['iqama_number'] ?? ''));
            $nat    = trim((string)($row['nationality'] ?? ''));

            if ($badge === '') {
                $errors[] = 'badge number is required';
            }
            if ($name === '') {
                $errors[] = 'driver name is required';
            }

            $termId = $terminals[strtolower($term)] ?? 0;
            if ($termId === 0) {
                $errors[] = 'unknown terminal: ' . $term;
            }

            $agencyId = 0;
            if ($agency !== '') {
                $agencyId = $agencies[strtolower($agency)] ?? 0;
                if ($agencyId === 0) {
                    $errors[] = 'unknown agency: ' . $agency;
                }
            }

            if (!isset(driverStatusOptions()[$stat])) {
                $stat = 'active';
            }

            $phoneNorm = null;
            if ($phone !== '') {
                $phoneNorm = normalizePhone($phone);
                if ($phoneNorm === null) {
                    $errors[] = 'invalid personal mobile: ' . $phone;
                }
            }

            if ($badge !== '') {
                $dup = $pdo->prepare('SELECT id FROM drivers WHERE badge_number = ?');
                $dup->execute([$badge]);
                if ($dup->fetch() !== false) {
                    $errors[] = 'duplicate badge: ' . $badge;
                }
            }

            $norm = [
                'badge' => $badge, 'name' => $name, 'terminal_id' => $termId,
                'agency_id' => $agencyId, 'iqama' => $iqama, 'nationality' => $nat,
                'status' => $stat, 'phone' => $phone, 'phone_norm' => $phoneNorm,
            ];
        } else {
            $number  = trim((string)($row['sim number'] ?? $row['mobile_number'] ?? $row['sim'] ?? ''));
            $iccid   = trim((string)($row['iccid'] ?? ''));
            $carrier = trim((string)($row['carrier'] ?? ''));
            $term    = trim((string)($row['terminal'] ?? ''));
            // Multi-word labels map to snake_case keys (validated against
            // simStatusOptions(), so retired status values are rejected as rows).
            $stat    = str_replace(' ', '_', strtolower(trim((string)($row['status'] ?? 'available'))));

            $normNum = normalizePhone($number);
            if ($normNum === null) {
                $errors[] = 'invalid SIM number: ' . $number;
            }

            if ($iccid !== '') {
                $dupI = $pdo->prepare('SELECT id FROM company_sims WHERE iccid = ?');
                $dupI->execute([$iccid]);
                if ($dupI->fetch() !== false) {
                    $errors[] = 'duplicate ICCID: ' . $iccid;
                }
            }

            $carrierId = $carriers[strtolower($carrier)] ?? 0;
            if ($carrierId === 0) {
                $errors[] = 'unknown carrier: ' . $carrier;
            }

            $termId = $terminals[strtolower($term)] ?? 0;
            if ($termId === 0) {
                $errors[] = 'unknown terminal: ' . $term;
            }

            if (!isset(simStatusOptions()[$stat])) {
                $errors[] = 'unknown status: ' . str_replace('_', ' ', $stat);
            }

            if ($normNum !== null) {
                $dup = $pdo->prepare(
                    'SELECT id FROM company_sims WHERE normalized_mobile_number = ?'
                );
                $dup->execute([$normNum]);
                if ($dup->fetch() !== false) {
                    $errors[] = 'duplicate mobile number: ' . $number;
                }
            }

            $norm = [
                'number' => $normNum !== null ? displayPhone($normNum) : $number,
                'norm' => $normNum, 'iccid' => $iccid !== '' ? $iccid : null,
                'carrier_id' => $carrierId, 'terminal_id' => $termId, 'status' => $stat,
            ];
        }

        $out[] = [
            '_state'  => $errors === [] ? 'valid' : 'invalid',
            '_errors' => $errors,
            '_data'   => $norm,
            '_raw'    => $row,
        ];
    }

    // In-file duplicates.
    $seen = [];
    foreach ($out as $k => $rec) {
        $key = $type === 'drivers'
            ? ($rec['_data']['badge'] ?? '')
            : ($rec['_data']['norm'] ?? '');
        if ($key !== '' && isset($seen[$key])) {
            $out[$k]['_state'] = 'invalid';
            $out[$k]['_errors'][] = 'duplicate row within the file';
        }
        $seen[$key] = true;
    }

    return $out;
}


// --- POST: upload -> preview, confirm -> commit -----------------------------
$classified = null;
$committed  = 0;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrfCheck();

    try {
        if (empty($_FILES['csv'])) {
            throw new RuntimeException('Choose a CSV file to upload.');
        }

        [, $rawRows] = parseCsvUpload($_FILES['csv']);

        if ($rawRows === []) {
            throw new RuntimeException('The file contains no data rows.');
        }

        $classified = classifyRows($type, $rawRows, $pdo);

        if ($confirm) {
            $valid = array_filter($classified, static fn ($r): bool => $r['_state'] === 'valid');

            if ($valid === []) {
                throw new RuntimeException('No valid rows to import - nothing was written.');
            }

            $pdo->beginTransaction();

            if ($type === 'drivers') {
                $ins = $pdo->prepare(
                    'INSERT IGNORE INTO drivers
                        (badge_number, name, terminal_id, agency_id, status, iqama_number, nationality)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $contact = $pdo->prepare(
                    'INSERT INTO driver_contact_numbers
                        (driver_id, contact_type, phone_number, normalized_phone_number,
                         is_primary, created_by, updated_by)
                     VALUES (?, "personal", ?, ?, 1, ?, ?)'
                );

                foreach ($valid as $rec) {
                    $d = $rec['_data'];
                    $ins->execute([
                        $d['badge'], $d['name'], $d['terminal_id'],
                        $d['agency_id'] ?: null, $d['status'],
                        $d['iqama'] !== '' ? $d['iqama'] : null,
                        $d['nationality'] !== '' ? $d['nationality'] : null,
                    ]);

                    if ((int)$ins->rowCount() > 0) {
                        $committed++;
                        $newId = (int)$pdo->lastInsertId();

                        if ($d['phone_norm'] !== null) {
                            $contact->execute([
                                $newId, displayPhone($d['phone_norm']), $d['phone_norm'],
                                (int)$_SESSION['user_id'], (int)$_SESSION['user_id'],
                            ]);
                        }
                    }
                }
            } else {
                $ins = $pdo->prepare(
                    'INSERT IGNORE INTO company_sims
                        (sim_reference, mobile_number, normalized_mobile_number, iccid,
                         carrier_id, terminal_id, status, created_by, updated_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );

                foreach ($valid as $rec) {
                    $d    = $rec['_data'];
                    $next = (int)$pdo->query(
                        'SELECT COALESCE(MAX(id), 0) + 1 FROM company_sims'
                    )->fetchColumn();
                    $ins->execute([
                        sprintf('SIM-%06d', $next), $d['number'], $d['norm'], $d['iccid'],
                        $d['carrier_id'], $d['terminal_id'], $d['status'],
                        (int)$_SESSION['user_id'], (int)$_SESSION['user_id'],
                    ]);

                    if ((int)$ins->rowCount() > 0) {
                        $committed++;
                    }
                }
            }

            $pdo->commit();

            logAudit($type === 'drivers' ? 'IMPORT_DRIVERS' : 'IMPORT_SIMS',
                $type, null, null,
                ['committed' => $committed, 'total' => count($classified)]);

            setFlash('ok', sprintf(
                'Import complete: %d row(s) committed, %d invalid/duplicate row(s) skipped.',
                $committed, count($classified) - $committed
            ));
            header('Location: import.php?type=' . $type);
            exit;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = friendlyError($e);
    }
}

$pageTitle = 'Import Data';
$activeNav = 'admin-import';
require __DIR__ . '/includes/header.php';

$validCount = $classified !== null
    ? count(array_filter($classified, static fn ($r): bool => $r['_state'] === 'valid'))
    : 0;
$invalidCount = $classified !== null ? count($classified) - $validCount : 0;
?>
<h1 class="page-title mb-1">Import Data</h1>
<p class="page-sub mb-3">
    CSV import with preview &mdash; valid / duplicate / invalid counts appear before
    anything is written, and only confirmed rows are committed.
</p>

<?php if ($error !== ''): ?>
    <div class="alert-error mb-3"><?= e($error) ?></div>
<?php endif; ?>

<div class="col-lg-8">
    <div class="card mb-3">
        <div class="card-header">1. Choose record type and file</div>
        <div class="card-body">
            <ul class="nav nav-pills mb-3">
                <li class="nav-item">
                    <a class="nav-link<?= $type === 'drivers' ? ' active' : '' ?>"
                       href="import.php?type=drivers">Driver import</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?= $type === 'sims' ? ' active' : '' ?>"
                       href="import.php?type=sims">SIM import</a>
                </li>
            </ul>

            <p class="small text-muted mb-2">
                <?php if ($type === 'drivers'): ?>
                    Expected headers: <code>Badge Number, Driver Name, Terminal, Status, Personal Mobile,
                    Agency, Iqama, Nationality</code> (Agency/Iqama/Nationality optional)
                <?php else: ?>
                    Expected headers: <code>SIM Number, ICCID, Carrier, Terminal, Status</code>
                <?php endif; ?>
            </p>

            <form method="post" action="import.php?type=<?= e($type) ?>"
                  enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="type" value="<?= e($type) ?>">
                <div class="mb-3">
                    <input type="file" name="csv" accept=".csv,text/csv" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary">Preview import…</button>
            </form>
        </div>
    </div>

    <?php if ($classified !== null): ?>
        <div class="card mb-3">
            <div class="card-header">2. Preview &mdash; <?= count($classified) ?> records found</div>
            <div class="card-body">
                <div class="row text-center g-2 mb-3">
                    <div class="col">
                        <div class="kpi kpi-accent-total">
                            <span class="kpi-label">Records found</span>
                            <span class="kpi-value"><?= count($classified) ?></span>
                        </div>
                    </div>
                    <div class="col">
                        <div class="kpi kpi-accent-assigned">
                            <span class="kpi-label">Valid</span>
                            <span class="kpi-value"><?= $validCount ?></span>
                        </div>
                    </div>
                    <div class="col">
                        <div class="kpi kpi-accent-lost">
                            <span class="kpi-label">Duplicates / Invalid</span>
                            <span class="kpi-value"><?= $invalidCount ?></span>
                        </div>
                    </div>
                </div>

                <?php if ($validCount === 0): ?>
                    <div class="alert-error">No valid rows to import. Fix the file and try again.</div>
                <?php else: ?>
                    <div class="alert-op mb-3">
                        <?= $validCount ?> valid row<?= $validCount === 1 ? '' : 's' ?> ready.
                        <?= $invalidCount ?> row(s) will be <strong>skipped</strong>.
                        Nothing is written until you confirm.
                    </div>

                    <form method="post" action="import.php?type=<?= e($type) ?>"
                          enctype="multipart/form-data"
                          data-confirm="Import <?= $validCount ?> row(s) now?">
                        <?= csrfField() ?>
                        <input type="hidden" name="type" value="<?= e($type) ?>">
                        <input type="hidden" name="confirm" value="1">
                        <div class="mb-2" style="max-width:420px">
                            <input type="file" name="csv" accept=".csv,text/csv"
                                   class="form-control" required>
                            <div class="form-text">Choose the same file again to commit it.</div>
                        </div>
                        <button type="submit" class="btn btn-success">
                            Confirm import of <?= $validCount ?> row(s)
                        </button>
                        <a href="import.php?type=<?= e($type) ?>" class="btn btn-outline-secondary">Cancel</a>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Row detail (first 200)</div>
            <div class="card-body p-0">
                <div class="table-responsive" style="max-height:420px;overflow:auto">
                    <table class="table table-sm table-striped mb-0">
                        <thead>
                        <tr><th>#</th><th>State</th><th>Data</th><th>Issues</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach (array_slice($classified, 0, 200) as $i => $rec): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?php if ($rec['_state'] === 'valid'): ?>
                                        <span class="badge bg-success">Valid</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Invalid</span>
                                    <?php endif; ?></td>
                                <td class="small"><code><?= e(json_encode($rec['_data'])) ?></code></td>
                                <td class="small text-danger"><?= e(implode('; ', $rec['_errors'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>


