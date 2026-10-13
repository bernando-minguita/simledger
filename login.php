<?php
/**
 * SIMLedger - sign in page.
 *
 * Optional layers (both additive; off by default / dev-safe):
 *  - reCAPTCHA v2 invisible: enforced only when keys are configured in
 *    config/config.php AND the host is not localhost (Google rejects
 *    localhost site keys - see includes/recaptcha.php).
 *  - Two-factor (TOTP): challenged after a valid password when the account
 *    has 2FA enabled on its Profile page.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/recaptcha.php';
require_once __DIR__ . '/includes/two-factor.php';
require_once __DIR__ . '/includes/login-throttle.php';

twoFactorBootstrap(); // login runs before any permission page can
userAdminBootstrap(); // users.must_change_password (admin resets)
loginThrottleBootstrap(); // login_attempts table on older databases

// Already signed in? Go straight to the dashboard.
if (!empty($_SESSION['user_id']) && refreshUser() !== null) {
    header('Location: index.php');
    exit;
}

// Leave a pending 2FA step.
if (isset($_GET['cancel_2fa'])) {
    unset($_SESSION['2fa_pending'], $_SESSION['2fa_exp'], $_SESSION['2fa_tries']);
}

$error   = '';
$timeout = (isset($_GET['timeout']) && $_GET['timeout'] === '1');

if (!empty($_SESSION['login_error'])) {
    $error = (string)$_SESSION['login_error'];
    unset($_SESSION['login_error']);
}

$username    = '';
$captchaOn   = recaptchaEnabled();
$totpPending = false;

/**
 * Finish a successful sign-in: fresh session id, fresh CSRF token, last
 * login, audit, redirect. Shared by the password-only and 2FA paths.
 */
$finish = static function (array $row, bool $via2fa): void {
    session_regenerate_id(true);
    unset($_SESSION['csrf_token']);

    $_SESSION['user_id']       = (int)$row['id'];
    $_SESSION['last_activity'] = time();
    $_SESSION['user'] = [
        'id'          => (int)$row['id'],
        'username'    => $row['username'],
        'name'        => $row['name'],
        'role_id'     => (int)$row['role_id'],
        'role_key'    => $row['role_key'],
        'terminal_id' => $row['terminal_id'] !== null ? (int)$row['terminal_id'] : null,
    ];

    $GLOBALS['pdo']->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')
        ->execute([(int)$row['id']]);

    logAudit('LOGIN', 'user', (int)$row['id']);
    if ($via2fa) {
        logAudit('LOGIN_2FA', 'user', (int)$row['id']);
    }

    // Security nudge: flag the session so includes/header.php can show a
    // dismissible "enable 2FA" reminder while the account has none. (The
    // 2FA-step query carries no totp_enabled - that path is enrolled by
    // definition - hence the defensive default.)
    if ((int)($row['totp_enabled'] ?? 1) === 0) {
        $_SESSION['two_factor_reminder'] = 1;
    } else {
        unset($_SESSION['two_factor_reminder']);
    }

    // Administrator reset: force a password change before anything else.
    if ((int)($row['must_change_password'] ?? 0) === 1) {
        header('Location: profile.php?must_change=1');
        exit;
    }

    header('Location: index.php');
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrfCheck();

    if (isset($_POST['totp_step'])) {
        // ------------------------------------------------ step 2: TOTP code
        $pendingId = (int)($_SESSION['2fa_pending'] ?? 0);
        $tries     = (int)($_SESSION['2fa_tries'] ?? 0);
        $expired   = (int)($_SESSION['2fa_exp'] ?? 0) < time();

        if ($pendingId === 0 || $expired) {
            unset($_SESSION['2fa_pending'], $_SESSION['2fa_exp'], $_SESSION['2fa_tries']);
            $error = 'Verification timed out. Please sign in again.';
        } elseif ($tries >= 10) {
            unset($_SESSION['2fa_pending'], $_SESSION['2fa_exp'], $_SESSION['2fa_tries']);
            $error = 'Too many incorrect codes. Please sign in again.';
        } else {
            $stmt = $GLOBALS['pdo']->prepare(
                'SELECT u.id, u.username, u.password_hash, u.name, u.is_active,
                        u.role_id, u.terminal_id, r.key AS role_key, u.totp_secret,
                        u.must_change_password
                   FROM users u
                   JOIN roles r ON r.id = u.role_id
                  WHERE u.id = ? AND u.is_active = 1'
            );
            $stmt->execute([$pendingId]);
            $row  = $stmt->fetch();
            $code = (string)($_POST['totp_code'] ?? '');

            if ($row === false || $row['totp_secret'] === null
                || !totpVerify((string)$row['totp_secret'], $code)) {
                $_SESSION['2fa_tries'] = $tries + 1;
                $left = 10 - ($tries + 1);
                $error = 'Incorrect verification code.'
                    . ($left > 0 ? " {$left} attempt(s) remaining." : '');
                $totpPending = true;
            } else {
                unset($_SESSION['2fa_pending'], $_SESSION['2fa_exp'], $_SESSION['2fa_tries']);
                $finish($row, true);
            }
        }
    } else {
        // ------------------------------------------- step 1: captcha + login
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $clientIp = loginClientIp();

        if ($username === '' || $password === '') {
            $error = 'Enter your username and password.';
        } elseif ($captchaOn
            && !recaptchaVerify((string)($_POST['g-recaptcha-response'] ?? ''))) {
            $error = 'Please confirm you are not a robot.';
        } elseif (($lockWait = loginThrottleLockoutSeconds($username, $clientIp)) > 0) {
            // Brute-force lock: no credential check, no extra failure recorded.
            $error = 'Too many failed sign-in attempts. Please try again in '
                . (int)ceil($lockWait / 60) . ' minute(s).';
        } else {
            $stmt = $GLOBALS['pdo']->prepare(
                'SELECT u.id, u.username, u.password_hash, u.name, u.is_active,
                        u.role_id, u.terminal_id, r.key AS role_key,
                        u.totp_secret, u.totp_enabled, u.must_change_password
                   FROM users u
                   JOIN roles r ON r.id = u.role_id
                  WHERE u.username = ?'
            );
            $stmt->execute([$username]);
            $row = $stmt->fetch();

            if ($row === false || !password_verify($password, $row['password_hash'])) {
                $error = 'Invalid username or password.';
                loginThrottleRecordFailure($username, $clientIp);
                logAudit('FAILED_LOGIN', 'user', null,
                    null, ['username' => $username, 'reason' => 'bad_credentials'],
                    null, mb_substr($username, 0, 50));
            } elseif ((int)$row['is_active'] !== 1) {
                $error = 'This account has been deactivated. Contact your administrator.';
                loginThrottleRecordFailure($username, $clientIp);
                logAudit('FAILED_LOGIN', 'user', (int)$row['id'],
                    null, ['username' => $username, 'reason' => 'deactivated'],
                    null, mb_substr($username, 0, 50));
            } elseif ((int)$row['totp_enabled'] === 1 && $row['totp_secret'] !== null) {
                // Password valid: challenge for the 2FA code BEFORE opening
                // the authenticated session.
                loginThrottleClear($username);   // correct password = legitimate user
                session_regenerate_id(true);
                unset($_SESSION['csrf_token']);
                $_SESSION['2fa_pending'] = (int)$row['id'];
                $_SESSION['2fa_exp']     = time() + 300;   // 5 minutes
                $_SESSION['2fa_tries']   = 0;
                $totpPending = true;
            } else {
                loginThrottleClear($username);
                $finish($row, false);
            }
        }
    }
}

// Keep showing the code form across refreshes while the step is pending.
$totpPending = $totpPending || (!empty($_SESSION['2fa_pending'])
    && (int)($_SESSION['2fa_exp'] ?? 0) >= time());
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign In &middot; <?= e(APP_NAME) ?></title>
    <link rel="icon" href="assets/img/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <?php if ($captchaOn && !$totpPending): ?>
    <script>
        /* Invisible reCAPTCHA v2: render on load, execute on first submit. */
        window.simledgerRecaptchaReady = function () {
            window.__simledgerRc = grecaptcha.render('recaptcha-box', {
                sitekey: <?= json_encode(RECAPTCHA_SITE_KEY) ?>,
                size: 'invisible',
                callback: function () {
                    document.getElementById('login-form').submit();
                }
            });
        };
    </script>
    <script src="https://www.google.com/recaptcha/api.js?onload=simledgerRecaptchaReady&render=explicit"
            async defer></script>
    <?php endif; ?>
</head>
<body class="login-page">
<div class="login-wrap">
    <div class="login-card">
        <div class="login-brand">
            <span class="login-logo"><img src="assets/img/favicon.svg" alt=""></span>
            <h1 class="login-title">Driver SIM &amp; Contact Management</h1>
            <p class="login-sub">Company SIM assets &amp; driver contacts</p>
        </div>

        <?php if ($error !== ''): ?>
            <p class="login-error"><?= e($error) ?></p>
        <?php elseif ($timeout): ?>
            <p class="login-error">Your session expired. Please sign in again.</p>
        <?php endif; ?>

        <?php if ($totpPending): ?>
            <!-- Step 2: two-factor verification code -->
            <form method="post" autocomplete="off" class="login-form" id="login-form">
                <div class="mb-3">
                    <label class="form-label">Verification code</label>
                    <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6"
                           class="form-control text-center" name="totp_code"
                           placeholder="6-digit code" autocomplete="one-time-code"
                           required autofocus>
                    <div class="form-text text-center">
                        Open your authenticator app and enter the 6-digit code.
                    </div>
                </div>
                <input type="hidden" name="totp_step" value="1">
                <?= csrfField() ?>
                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary">Verify</button>
                    <a class="btn btn-link btn-sm" href="login.php?cancel_2fa=1">Back to sign in</a>
                </div>
            </form>
        <?php else: ?>
            <!-- Step 1: credentials (+ optional invisible reCAPTCHA) -->
            <form method="post" autocomplete="off" class="login-form" id="login-form">
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input type="text" class="form-control" name="username"
                           value="<?= e($username) ?>" placeholder="Enter your username" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input type="password" class="form-control" name="password"
                           placeholder="Enter your password" required>
                </div>
                <?php if ($captchaOn): ?>
                    <div id="recaptcha-box" class="mb-2"></div>
                <?php endif; ?>
                <?= csrfField() ?>
                <div class="d-grid">
                    <button type="submit" class="btn btn-primary">Sign In</button>
                </div>
            </form>
            <?php if ($captchaOn): ?>
            <script>
            (function () {
                var form = document.getElementById('login-form');
                var gaveUp = false;
                // If Google's script never loads, stop blocking submit - the
                // server still rejects a missing token (fail-closed).
                setTimeout(function () { if (!window.grecaptcha) { gaveUp = true; } }, 6000);
                form.addEventListener('submit', function (e) {
                    if (gaveUp) { return; }
                    var t = form.querySelector('[name="g-recaptcha-response"]');
                    if (t && t.value) { return; }   // token present: submit
                    e.preventDefault();
                    grecaptcha.execute(window.__simledgerRc);
                });
            })();
            </script>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <p class="login-footer">
        &copy; <?= date('Y') ?> <?= e(APP_NAME) ?> &mdash; company SIM assets
        are tracked by assignment, never overwritten.
    </p>
</div>
</body>
</html>