<?php
/**
 * SIMLedger - driver profile (spec sections 16, 21, 39).
 *
 * Shows driver information, the CURRENT company SIM derived from the open
 * assignment (never an editable field), personal contact numbers and the
 * full company SIM history. Personal contacts are managed here.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/lookups.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/services/SimService.php';
requirePermission('driver.view');

$pdo   = $GLOBALS['pdo'];
$scope = currentTerminalId();

$driverId = reqInt('id', 0, 1, 100000000);
$error    = '';

// --- load driver -----------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT d.*, t.name AS terminal_name, ag.name AS agency_name
       FROM drivers d JOIN terminals t ON t.id = d.terminal_id
  LEFT JOIN agencies ag ON ag.id = d.agency_id
      WHERE d.id = ?'
);
$stmt->execute([$driverId]);
$driver = $stmt->fetch() ?: null;

if ($driver === null) {
    http_response_code(404);
    $pageTitle = 'Driver not found';
    $activeNav = 'driver-list';
    require __DIR__ . '/includes/header.php';
    echo '<div class="alert-error">Driver not found.</div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}
assertTerminalAccess((int)$driver['terminal_id']);

// --- POST: personal contact management -------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    requirePermission('contact.manage');
    csrfCheck();

    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'add_contact' || $action === 'update_contact') {
            $contactId   = reqInt('contact_id', 0, 0, 100000000);
            $phoneRaw    = (string)($_POST['phone_number'] ?? '');
            $contactType = (string)($_POST['contact_type'] ?? 'personal');
            $isPrimary   = ($_POST['is_primary'] ?? '') === '1';
            $isActive    = ($_POST['is_active'] ?? '') === '1' || $action === 'add_contact';
            $notes       = cleanStr($_POST['notes'] ?? null, 255);

            if (!in_array($contactType, ['personal', 'emergency', 'other'], true)) {
                throw new RuntimeException('Invalid contact type.');
            }

            $norm = normalizePhone($phoneRaw);

            if ($norm === null) {
                throw new RuntimeException(
                    'Enter a valid Saudi mobile number (e.g. 05XXXXXXXX or +9665XXXXXXXX).'
                );
            }

            // Duplicate detection on the normalized number so different input
            // formats cannot create duplicates (spec section 31).
            $dupStmt = $pdo->prepare(
                'SELECT driver_id FROM driver_contact_numbers
                  WHERE normalized_phone_number = ? AND is_active = 1 AND id <> ?'
            );
            $dupStmt->execute([$norm, $contactId]);
            $dup = $dupStmt->fetch();

            if ($dup !== false) {
                $ownStmt = $pdo->prepare('SELECT badge_number FROM drivers WHERE id = ?');
                $ownStmt->execute([(int)$dup['driver_id']]);
                throw new RuntimeException(
                    'This number is already recorded as a contact for driver badge '
                    . $ownStmt->fetchColumn() . '.'
                );
            }

            if ($isPrimary) {
                $pdo->prepare(
                    'UPDATE driver_contact_numbers SET is_primary = 0 WHERE driver_id = ?'
                )->execute([$driverId]);
            }

            if ($action === 'add_contact') {
                $ins = $pdo->prepare(
                    'INSERT INTO driver_contact_numbers
                        (driver_id, contact_type, phone_number, normalized_phone_number,
                         is_primary, is_active, notes, created_by, updated_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $ins->execute([
                    $driverId, $contactType, displayPhone($norm), $norm,
                    $isPrimary ? 1 : 0, $isActive ? 1 : 0, $notes,
                    (int)$_SESSION['user_id'], (int)$_SESSION['user_id'],
                ]);
                $contactId = (int)$pdo->lastInsertId();
                logAudit('ADD_DRIVER_CONTACT', 'driver_contact', $contactId, null,
                    ['driver_id' => $driverId, 'type' => $contactType,
                     'phone' => displayPhone($norm), 'primary' => $isPrimary]);
            } else {
                $own = $pdo->prepare(
                    'SELECT driver_id FROM driver_contact_numbers WHERE id = ?'
                );
                $own->execute([$contactId]);
                $owner = $own->fetch();

                if ($owner === false || (int)$owner['driver_id'] !== $driverId) {
                    throw new RuntimeException('Contact not found for this driver.');
                }

                $oldStmt = $pdo->prepare(
                    'SELECT * FROM driver_contact_numbers WHERE id = ?'
                );
                $oldStmt->execute([$contactId]);
                $old = $oldStmt->fetch();

                $upd = $pdo->prepare(
                    'UPDATE driver_contact_numbers
                        SET contact_type = ?, phone_number = ?, normalized_phone_number = ?,
                            is_primary = ?, is_active = ?, notes = ?, updated_by = ?
                      WHERE id = ? AND driver_id = ?'
                );
                $upd->execute([
                    $contactType, displayPhone($norm), $norm,
                    $isPrimary ? 1 : 0, $isActive ? 1 : 0, $notes,
                    (int)$_SESSION['user_id'], $contactId, $driverId,
                ]);
                logAudit('UPDATE_DRIVER_CONTACT', 'driver_contact', $contactId,
                    ['phone' => $old['phone_number'], 'type' => $old['contact_type'],
                     'primary' => (int)$old['is_primary'], 'active' => (int)$old['is_active']],
                    ['phone' => displayPhone($norm), 'type' => $contactType,
                     'primary' => $isPrimary, 'active' => $isActive]);
            }

            setFlash('ok', 'Contact number saved.');
            header('Location: driver.php?id=' . $driverId);
            exit;
        }

        if ($action === 'deactivate_contact') {
            $contactId = reqInt('contact_id', 0, 1, 100000000);
            $own = $pdo->prepare(
                'SELECT driver_id FROM driver_contact_numbers WHERE id = ?'
            );
            $own->execute([$contactId]);
            $owner = $own->fetch();

            if ($owner === false || (int)$owner['driver_id'] !== $driverId) {
                throw new RuntimeException('Contact not found for this driver.');
            }

            // Soft delete only - the row stays for audit purposes (spec 47).
            $pdo->prepare(
                'UPDATE driver_contact_numbers SET is_active = 0, is_primary = 0, updated_by = ?
                  WHERE id = ?'
            )->execute([(int)$_SESSION['user_id'], $contactId]);

            logAudit('DELETE_DRIVER_CONTACT', 'driver_contact', $contactId,
                ['active' => 1], ['active' => 0, 'soft_delete' => true]);

            setFlash('ok', 'Contact number deactivated.');
            header('Location: driver.php?id=' . $driverId);
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// --- GET data: current assignment, contacts, history, activity -------------
$aStmt = $pdo->prepare(
    'SELECT a.*, s.mobile_number, s.status AS sim_status, s.carrier_id,
            c.name AS carrier_name, u.name AS issued_by_name
       FROM driver_sim_assignments a
       JOIN company_sims s ON s.id = a.sim_id
       JOIN carriers c ON c.id = s.carrier_id
       JOIN users u ON u.id = a.issued_by
      WHERE a.driver_id = ? AND a.returned_at IS NULL
      LIMIT 1'
);
$aStmt->execute([$driverId]);
$currentSim = $aStmt->fetch() ?: null;

$cStmt = $pdo->prepare(
    'SELECT * FROM driver_contact_numbers
      WHERE driver_id = ? AND is_active = 1
      ORDER BY is_primary DESC, contact_type, id'
);
$cStmt->execute([$driverId]);
$contacts = $cStmt->fetchAll();

$hStmt = $pdo->prepare(
    'SELECT a.*, s.mobile_number, s.status AS sim_status, c.name AS carrier_name,
            u1.name AS issued_by_name, u2.name AS returned_by_name
       FROM driver_sim_assignments a
       JOIN company_sims s ON s.id = a.sim_id
       JOIN carriers c ON c.id = s.carrier_id
       JOIN users u1 ON u1.id = a.issued_by
  LEFT JOIN users u2 ON u2.id = a.returned_by
      WHERE a.driver_id = ?
      ORDER BY a.issued_at DESC, a.id DESC'
);
$hStmt->execute([$driverId]);
$simHistory = $hStmt->fetchAll();

// Recent audit activity for this driver (contacts + SIM transactions).
$actStmt = $pdo->prepare(
    'SELECT action, entity_type, entity_id, new_values, created_at, username
       FROM audit_logs
      WHERE (entity_type = "driver" AND entity_id = ?)
         OR (entity_type = "driver_contact"
             AND entity_id IN (SELECT id FROM driver_contact_numbers WHERE driver_id = ?))
         OR (entity_type = "sim" AND entity_id IN (
                SELECT sim_id FROM driver_sim_assignments WHERE driver_id = ?))
         OR (entity_type = "sim" AND JSON_EXTRACT(new_values, "$.driver_id") = ?)
      ORDER BY created_at DESC
      LIMIT 15'
);
$actStmt->execute([$driverId, $driverId, $driverId, $driverId]);
$activity = $actStmt->fetchAll();

$contactTypes = ['personal' => 'Personal', 'emergency' => 'Emergency', 'other' => 'Other'];
$statuses     = driverStatusOptions();

$pageTitle = $driver['name'] . ' (Badge ' . $driver['badge_number'] . ')';
$activeNav = 'driver-list';
require __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="page-title"><?= e($driver['name']) ?></h1>
        <p class="page-sub">
            Badge <?= e($driver['badge_number']) ?> &middot; <?= e($driver['agency_name'] ?? '—') ?>
            &middot; <?= e($driver['terminal_name']) ?> &middot;
            <span class="badge <?= $driver['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>">
                <?= e($statuses[$driver['status']] ?? $driver['status']) ?></span>
            <?php if ((int)$driver['is_active'] !== 1): ?>
                <span class="badge bg-dark">Inactive</span>
            <?php endif; ?>
        </p>
    </div>
    <div>
        <?php if (can('sim.transact') && $driver['status'] === 'active'): ?>
            <?php if ($currentSim === null): ?>
                <a class="btn btn-primary btn-sm"
                   href="assign.php?driver_q=<?= e(urlencode($driver['badge_number'])) ?>">Issue SIM</a>
            <?php else: ?>
                <a class="btn btn-outline-warning btn-sm"
                   href="return-sim.php?q=<?= e(urlencode($driver['badge_number'])) ?>">Return SIM</a>
                <a class="btn btn-outline-primary btn-sm"
                   href="transfer.php?q=<?= e(urlencode($driver['badge_number'])) ?>">Transfer SIM</a>
                <a class="btn btn-outline-secondary btn-sm" href="replace-sim.php">Replace SIM</a>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (can('driver.manage')): ?>
            <a class="btn btn-outline-danger btn-sm"
               href="admin/drivers.php?edit=<?= (int)$driverId ?>">Edit driver</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($error !== ''): ?>
    <div class="alert-error mb-3"><?= e($error) ?></div>
<?php endif; ?>

<?php if (in_array($driver['status'], ['resigned', 'terminated', 'inactive'], true)
    && $currentSim !== null): ?>
    <div class="alert-op mb-3">
        &#9888; Driver is <strong><?= e($statuses[$driver['status']] ?? $driver['status']) ?></strong>
        but still has company SIM <?= e($currentSim['mobile_number']) ?> &mdash;
        <a href="return-sim.php?assignment_id=<?= (int)$currentSim['id'] ?>">return the SIM</a>.
    </div>
<?php endif; ?>


<div class="row g-3 mb-3">
    <!-- driver information -->
    <div class="col-lg-3">
        <div class="card h-100">
            <div class="card-header">Driver Information</div>
            <div class="card-body small">
                <div class="section-label">Badge Number</div>
                <div class="mb-2"><strong><?= e($driver['badge_number']) ?></strong></div>
                <div class="section-label">Driver Name</div>
                <div class="mb-2"><?= e($driver['name']) ?></div>
                <div class="section-label">Terminal</div>
                <div class="mb-2"><?= e($driver['terminal_name']) ?></div>
                <div class="section-label">Agency</div>
                <div class="mb-2"><?= e($driver['agency_name'] ?? '—') ?></div>
                <div class="section-label">Iqama Number</div>
                <div class="mb-2"><?= e($driver['iqama_number'] ?? '—') ?></div>
                <div class="section-label">Nationality</div>
                <div class="mb-2"><?= e($driver['nationality'] ?? '—') ?></div>
                <div class="section-label">Status</div>
                <div class="mb-2"><?= e($statuses[$driver['status']] ?? $driver['status']) ?></div>
                <?php if ($driver['external_driver_id'] !== null): ?>
                    <div class="section-label">External ID</div>
                    <div class="mb-2"><?= e($driver['external_driver_id']) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- current company SIM: derived from the open assignment, never editable -->
    <div class="col-lg-5">
        <div class="company-sim-box h-100">
            <div class="box-label">Company SIM (company asset)</div>
            <?php if ($currentSim !== null): ?>
                <div class="fs-5 phone"><strong><?= e($currentSim['mobile_number']) ?></strong></div>
                <div class="small">
                    Carrier: <?= e($currentSim['carrier_name']) ?> &middot;
                    SIM Status: <?= simStatusBadge($currentSim['sim_status']) ?><br>
                    Issued: <?= e(date('d-M-Y H:i', strtotime((string)$currentSim['issued_at']))) ?>
                    by <?= e($currentSim['issued_by_name']) ?><br>
                    Assignment: <span class="badge bg-success">CURRENT</span>
                    <?php if ($currentSim['assignment_reason'] !== null): ?>
                        &middot; reason: <em><?= e($currentSim['assignment_reason']) ?></em>
                    <?php endif; ?>
                </div>
                <a class="btn btn-sm btn-outline-secondary mt-2"
                   href="sim-history.php?sim_id=<?= (int)$currentSim['sim_id'] ?>">SIM history</a>
            <?php else: ?>
                <div class="text-muted">No company SIM assigned.</div>
                <p class="small mb-0 mt-1">
                    This value is derived from the assignment transaction &mdash;
                    it is never typed directly into the driver record.
                </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- personal contacts -->
    <div class="col-lg-4">
        <div class="personal-box h-100">
            <div class="box-label">Personal Contacts (driver owned)</div>
            <?php if ($contacts === []): ?>
                <div class="text-muted small">No personal contact numbers recorded.</div>
            <?php else: ?>
                <ul class="list-unstyled small mb-2">
                    <?php foreach ($contacts as $c): ?>
                        <li class="mb-1">
                            <span class="phone"><?= e(canViewFullNumbers((int)$driver['terminal_id'])
                                ? $c['phone_number'] : maskPhone($c['normalized_phone_number'])) ?></span>
                            <span class="badge bg-light text-dark border">
                                <?= e($contactTypes[$c['contact_type']] ?? $c['contact_type']) ?></span>
                            <?php if ((int)$c['is_primary'] === 1): ?>
                                <span class="badge bg-primary">Primary</span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if (can('contact.manage')): ?>
                <button class="btn btn-sm btn-outline-success" type="button" data-bs-toggle="modal"
                        data-bs-target="#contactModal" data-action="add">+ Add contact</button>
            <?php endif; ?>
        </div>
    </div>
</div>


<div class="row g-3 mb-3">
    <!-- company SIM history (spec section 21) -->
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header">Company SIM History</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped mb-0 dt-nowrap">
                        <thead>
                        <tr>
                            <th>SIM</th>
                            <th>Carrier</th>
                            <th>Issued</th>
                            <th>Returned</th>
                            <th>Reason</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($simHistory === []): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">
                                No company SIM assignments in the past.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($simHistory as $h): ?>
                            <tr>
                                <td><a href="sim-history.php?sim_id=<?= (int)$h['sim_id'] ?>" class="phone">
                                    <?= e($h['mobile_number']) ?></a></td>
                                <td><?= e($h['carrier_name']) ?></td>
                                <td class="small"><?= e(date('M-Y', strtotime((string)$h['issued_at']))) ?></td>
                                <td class="small"><?= $h['returned_at'] !== null
                                    ? e(date('M-Y', strtotime((string)$h['returned_at'])))
                                    : '<span class="badge bg-success">CURRENT</span>' ?></td>
                                <td class="small"><?= e($h['return_reason'] !== null
                                    ? (SimService::RETURN_REASONS[$h['return_reason']] ?? $h['return_reason'])
                                    : ($h['assignment_reason'] ?? '—')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>


    <!-- contact management + recent activity -->
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header">Personal Contact Numbers</div>
            <div class="card-body p-0">
                <table class="table table-striped mb-0">
                    <thead>
                    <tr>
                        <th>Type</th>
                        <th>Number</th>
                        <th>Primary</th>
                        <th class="text-end">Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($contacts === []): ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">None recorded.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($contacts as $c): ?>
                        <tr>
                            <td><?= e($contactTypes[$c['contact_type']] ?? $c['contact_type']) ?></td>
                            <td class="phone"><?= e(canViewFullNumbers((int)$driver['terminal_id'])
                                ? $c['phone_number'] : maskPhone($c['normalized_phone_number'])) ?></td>
                            <td><?= (int)$c['is_primary'] === 1 ? 'Yes' : 'No' ?></td>
                            <td class="text-end text-nowrap">
                                <?php if (can('contact.manage')): ?>
                                    <button class="btn btn-sm btn-outline-secondary" type="button"
                                            data-bs-toggle="modal" data-bs-target="#contactModal"
                                            data-action="update"
                                            data-id="<?= (int)$c['id'] ?>"
                                            data-type="<?= e($c['contact_type']) ?>"
                                            data-phone="<?= e($c['phone_number']) ?>"
                                            data-primary="<?= (int)$c['is_primary'] ?>"
                                            data-notes="<?= e($c['notes'] ?? '') ?>">Edit</button>
                                    <form method="post" action="driver.php?id=<?= (int)$driverId ?>"
                                          class="d-inline" data-confirm-danger
                                          data-confirm="Deactivate this number?">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="deactivate_contact">
                                        <input type="hidden" name="contact_id" value="<?= (int)$c['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Recent Activity</div>
            <div class="card-body">
                <?php if ($activity === []): ?>
                    <p class="text-muted small mb-0">No recorded activity yet.</p>
                <?php else: ?>
                    <ul class="list-unstyled small mb-0">
                        <?php foreach ($activity as $act): ?>
                            <li class="mb-1">
                                <span class="badge bg-light text-dark border"><?= e($act['action']) ?></span>
                                <?= e(date('d-M-y H:i', strtotime((string)$act['created_at']))) ?>
                                by <?= e($act['username'] ?? '—') ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>


<?php if (can('contact.manage')): ?>
<!-- add / edit personal contact -->
<div class="modal fade" id="contactModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" action="driver.php?id=<?= (int)$driverId ?>" class="modal-content">
            <?= csrfField() ?>
            <input type="hidden" name="action" id="cm-action" value="add_contact">
            <input type="hidden" name="contact_id" id="cm-id" value="">
            <div class="modal-header">
                <h5 class="modal-title" id="cm-title">Add personal contact</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Contact type</label>
                    <select class="form-select" name="contact_type" id="cm-type">
                        <option value="personal">Personal</option>
                        <option value="emergency">Emergency</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Phone number</label>
                    <input type="text" class="form-control" name="phone_number" id="cm-phone"
                           placeholder="05XXXXXXXX or +9665XXXXXXXX" required>
                    <div class="form-text">Stored internally as +9665XXXXXXXX &mdash;
                        format variants never create duplicates.</div>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="is_primary"
                           id="cm-primary" value="1">
                    <label class="form-check-label" for="cm-primary">Primary contact</label>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="is_active"
                           id="cm-active" value="1" checked>
                    <label class="form-check-label" for="cm-active">Active</label>
                </div>
                <div class="mb-2">
                    <label class="form-label">Notes</label>
                    <input type="text" class="form-control" name="notes" id="cm-notes" maxlength="255">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save contact</button>
            </div>
        </form>
    </div>
</div>
<script>
document.getElementById('contactModal')?.addEventListener('show.bs.modal', function (ev) {
    var btn = ev.relatedTarget;
    var isUpdate = btn && btn.getAttribute('data-action') === 'update';
    document.getElementById('cm-action').value = isUpdate ? 'update_contact' : 'add_contact';
    document.getElementById('cm-title').textContent = isUpdate ? 'Edit personal contact' : 'Add personal contact';
    document.getElementById('cm-id').value = isUpdate ? btn.getAttribute('data-id') : '';
    document.getElementById('cm-type').value = isUpdate ? btn.getAttribute('data-type') : 'personal';
    document.getElementById('cm-phone').value = isUpdate ? btn.getAttribute('data-phone') : '';
    document.getElementById('cm-primary').checked = isUpdate && btn.getAttribute('data-primary') === '1';
    document.getElementById('cm-active').checked = true;
    document.getElementById('cm-notes').value = isUpdate ? btn.getAttribute('data-notes') : '';
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>


