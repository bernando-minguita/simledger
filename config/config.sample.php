<?php
/**
 * SIMLedger - application configuration SAMPLE.
 *
 * Copy this file to config/config.php (which is git-ignored) and edit the
 * constants for your environment. install.php rewrites DB_NAME / DB_USER /
 * DB_PASS with the values entered in the installer.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Database connection
// ---------------------------------------------------------------------------
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'simledger');
define('DB_USER', 'root');
define('DB_PASS', 'root');

// ---------------------------------------------------------------------------
// Application
// ---------------------------------------------------------------------------
define('APP_NAME', 'SIMLedger');
define('APP_TIMEZONE', 'Asia/Riyadh');

// Session idle timeout in seconds (8 hours by default).
define('SESSION_IDLE_TIMEOUT', 8 * 60 * 60);

// ---------------------------------------------------------------------------
// reCAPTCHA v2 (invisible) for the login form
// ---------------------------------------------------------------------------
// Get keys at https://www.google.com/recaptcha/admin (reCAPTCHA v2 -> Invisible).
// Leave empty to disable the captcha entirely. Even with keys set, the captcha
// is automatically skipped on localhost / 127.0.0.1 (Google rejects localhost
// site keys anyway) - see includes/recaptcha.php.
define('RECAPTCHA_SITE_KEY', '');
define('RECAPTCHA_SECRET_KEY', '');

// Seconds to wait for Google's siteverify response (fail-closed when exceeded).
define('RECAPTCHA_TIMEOUT', 6);

// ---------------------------------------------------------------------------
// Login brute-force throttle (optional overrides - defaults shown)
// ---------------------------------------------------------------------------
// A sign-in is locked for the remainder of LOGIN_WINDOW_SECONDS once the
// username has LOGIN_MAX_ATTEMPTS_USER failures, or the client IP has
// LOGIN_MAX_ATTEMPTS_IP failures, inside that window. Failed attempts are
// audited as FAILED_LOGIN. See includes/login-throttle.php.
// define('LOGIN_MAX_ATTEMPTS_USER', 5);
// define('LOGIN_MAX_ATTEMPTS_IP', 20);
// define('LOGIN_WINDOW_SECONDS', 900);
// define('LOGIN_ATTEMPT_RETENTION', 86400);
