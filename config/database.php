<?php
/**
 * SIMLedger - database bootstrap and session.
 *
 * require_once this file from every page; it exposes a global $pdo handle
 * and starts a hardened session.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

date_default_timezone_set(APP_TIMEZONE);

// ---------------------------------------------------------------------------
// Global PDO handle
// ---------------------------------------------------------------------------
$pdo = null;

try {
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    // Log the real reason server-side only - never print credentials/DSN.
    error_log('[simledger] database connection failed: ' . $e->getMessage());

    die(
        'Database connection failed. Run install.php to create the database, '
        . 'or check the credentials in config/config.php. '
        . 'Technical details were written to the PHP error log.'
    );
}

// ---------------------------------------------------------------------------
// Session (started after the DB handle so flash data can be used safely)
// ---------------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_only_cookies', '1');

    // HTTPS: direct TLS or a TLS-terminating reverse proxy that sets the
    // standard X-Forwarded-Proto header (nginx, Apache, ALB, Cloudflare...).
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    if ($isHttps) {
        ini_set('session.cookie_secure', '1');

        if (!headers_sent()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    session_start();
}
