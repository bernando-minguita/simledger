<?php
/**
 * SIMLedger - my profile / change password.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit.php';
requireLogin();

$user    = currentUser();
$error   = '';
$tfError = '';
$ok     = '';
$pdo    = $GLOBALS['pdo'];

// Administrator reset pending? (requireLogin's gate already forced the
// user here; the banner below explains why.)
$mustChange = (int)$pdo->query(
    'SELECT must_change_password FROM users WHERE id = ' . (int)$user['id']
)->fetchColumn() === 1;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrfCheck();

    $action  = (string)($_POST['action'] ?? 'password');
    $tfError = '';

    if ($action === '2fa_start') {
        twoFactorStart((int)$user['id']);
        setFlash('ok', 'Scan the QR code with your authenticator app, then enter the code to confirm.');
        header('Location: profile.php');
        exit;
    }

    if ($action === '2fa_cancel') {
        twoFactorCancel((int)$user['id']);
        setFlash('ok', 'Two-factor enrolment cancelled.');
        header('Location: profile.php');
        exit;
    }

    if ($action === '2fa_confirm') {
        if (twoFactorConfirm((int)$user['id'], (string)($_POST['totp_code'] ?? ''))) {
            logAudit('ENABLE_2FA', 'user', (int)$user['id']);
            unset($_SESSION['two_factor_reminder']);   // the reminder did its job
            setFlash('ok', 'Two-factor authentication is now enabled.');
            header('Location: profile.php');
            exit;
        }
        $tfError = 'That code is not correct. Check the clock on your phone and try again.';
    } elseif ($action === '2fa_disable') {
        if (twoFactorDisable((int)$user['id'], (string)($_POST['totp_code'] ?? ''))) {
            logAudit('DISABLE_2FA', 'user', (int)$user['id']);
            setFlash('ok', 'Two-factor authentication disabled.');
            header('Location: profile.php');
            exit;
        }
        $tfError = 'That code is not correct - two-factor authentication was not disabled.';
    } else {
        // ------------------------------------------- change password
        $current  = (string)($_POST['current_password'] ?? '');
        $new      = (string)($_POST['new_password'] ?? '');
        $confirm  = (string)($_POST['confirm_password'] ?? '');

        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([(int)$user['id']]);
        $hash = (string)$stmt->fetchColumn();

        if (!password_verify($current, $hash)) {
            $error = 'Your current password is incorrect.';
        } elseif (strlen($new) < 8) {
            $error = 'The new password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $error = 'The new password and confirmation do not match.';
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), (int)$user['id']]);

            logAudit('CHANGE_PASSWORD', 'user', (int)$user['id'], null,
                ['forced_change_completed' => $mustChange === 1]);

            setFlash('ok', 'Password changed successfully.');
            header('Location: profile.php');
            exit;
        }
    }
}

$tf    = twoFactorStatus((int)$user['id']);
$tfUri = $tf['secret'] !== null ? otpauthUri($tf['secret'], (string)$user['username']) : '';

$pageTitle = 'My Profile';
$activeNav = 'profile';
require __DIR__ . '/includes/header.php';
?>
<?php if ($mustChange): ?>
    <div class="alert-error mb-3">
        <strong>Password reset notice.</strong>
        An administrator reset your password. Choose a new password in the
        Change Password form below before you can continue.
    </div>
<?php endif; ?>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">Account</div>
            <div class="card-body">
                <dl class="mb-0">
                    <dt class="section-label mt-1">Name</dt>
                    <dd><?= e($user['name']) ?></dd>
                    <dt class="section-label">Username</dt>
                    <dd><?= e($user['username']) ?></dd>
                    <dt class="section-label">Role</dt>
                    <dd><?= e($user['role_name']) ?></dd>
                    <dt class="section-label">Terminal</dt>
                    <dd><?= e($user['terminal_name'] ?? 'All terminals') ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">Change Password</div>
            <div class="card-body">
                <?php if ($error !== ''): ?>
                    <div class="alert-error mb-3"><?= e($error) ?></div>
                <?php endif; ?>

                <form method="post" autocomplete="off">
                    <?= csrfField() ?>
                    <div class="mb-3">
                        <label class="form-label">Current password</label>
                        <input type="password" class="form-control" name="current_password" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New password (min 8 chars)</label>
                        <input type="password" class="form-control" name="new_password" minlength="8" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm new password</label>
                        <input type="password" class="form-control" name="confirm_password" minlength="8" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Change Password</button>
                </form>
            </div>
        </div>
    </div>
</div>
<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">Two-Factor Authentication</div>
            <div class="card-body">
                <?php if ($tfError !== ''): ?>
                    <div class="alert-error mb-3"><?= e($tfError) ?></div>
                <?php endif; ?>

                <?php if ($tf['enabled']): ?>
                    <p class="mb-2">
                        <span class="badge bg-success">Enabled</span>
                        Signing in now also requires a 6-digit code from your authenticator app.
                    </p>
                    <form method="post" action="profile.php" class="row g-2 align-items-end"
                          autocomplete="off">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="2fa_disable">
                        <div class="col-auto">
                            <label class="form-label" for="tfDisableCode">Current code</label>
                            <input type="text" class="form-control" name="totp_code" id="tfDisableCode"
                                   inputmode="numeric" maxlength="6" pattern="[0-9]*"
                                   autocomplete="one-time-code" required>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-outline-danger">Disable</button>
                        </div>
                    </form>
                <?php elseif ($tf['pending']): ?>
                    <p class="mb-2">
                        <span class="badge bg-warning text-dark">Not confirmed</span>
                        Scan this QR code with Google Authenticator, Authy or 1Password, then
                        enter the 6-digit code to finish enrolment.
                    </p>
                    <div class="row g-3 align-items-start">
                        <div class="col-auto">
                            <div id="tf-qr" class="border rounded p-2 d-inline-block bg-white"></div>
                        </div>
                        <div class="col">
                            <div class="section-label">Or enter this key manually</div>
                            <code class="user-select-all d-block mb-2"><?= e(implode(' ', str_split((string)$tf['secret'], 4))) ?></code>
                            <form method="post" action="profile.php" class="row g-2 align-items-end"
                                  autocomplete="off">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="2fa_confirm">
                                <div class="col-auto">
                                    <label class="form-label" for="tfCode">Verification code</label>
                                    <input type="text" class="form-control" name="totp_code" id="tfCode"
                                           inputmode="numeric" maxlength="6" pattern="[0-9]*"
                                           autocomplete="one-time-code" required>
                                </div>
                                <div class="col-auto">
                                    <button type="submit" class="btn btn-primary">Confirm &amp; enable</button>
                                </div>
                            </form>
                            <form method="post" action="profile.php" class="mt-2" autocomplete="off">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="2fa_cancel">
                                <button type="submit" class="btn btn-link btn-sm p-0">Cancel enrolment</button>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="mb-2">
                        <span class="badge bg-secondary">Not enabled</span>
                        Add a second step at sign-in: a 6-digit code from an authenticator app.
                    </p>
                    <form method="post" action="profile.php" autocomplete="off">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="2fa_start">
                        <button type="submit" class="btn btn-primary">Enable two-factor</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php if ($tf['pending']): ?>
<script src="assets/js/qrcode.min.js"></script>
<script>
    new QRCode(document.getElementById('tf-qr'), {
        text: <?= json_encode($tfUri, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
        width: 176,
        height: 176,
        correctLevel: QRCode.CorrectLevel.M
    });
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
