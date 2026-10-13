<?php
/**
 * SIMLedger - login brute-force throttle.
 *
 * Counts failed sign-in attempts per username and per client IP in a small
 * login_attempts table (self-provisioning migration, same pattern as
 * twoFactorBootstrap) and blocks further sign-in attempts for the remainder
 * of the window once a threshold is crossed. Successful sign-ins clear that
 * username's counter (IP evidence is kept so sustained attacks stay blocked).
 * Every failure is additionally written to the audit trail as FAILED_LOGIN
 * by login.php.
 *
 * Thresholds can be tuned in config/config.php via defined() fallbacks -
 * the same rule as the geo-guard / reCAPTCHA constants.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Settings - override in config/config.php
// ---------------------------------------------------------------------------
defined('LOGIN_MAX_ATTEMPTS_USER') || define('LOGIN_MAX_ATTEMPTS_USER', 5);
defined('LOGIN_MAX_ATTEMPTS_IP')   || define('LOGIN_MAX_ATTEMPTS_IP', 20);
defined('LOGIN_WINDOW_SECONDS')    || define('LOGIN_WINDOW_SECONDS', 900);      // 15 min
defined('LOGIN_ATTEMPT_RETENTION') || define('LOGIN_ATTEMPT_RETENTION', 86400); // keep 24 h

/**
 * Add the login_attempts table on databases created before this feature.
 * Idempotent; returns immediately once flagged in app_settings (per-request
 * cached), so the overhead after the first request is a single cache hit.
 */
function loginThrottleBootstrap(): void
{
    if (settingGet('mig_login_attempts', '') === '1') {
        return;
    }

    $pdo = $GLOBALS['pdo'];

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS login_attempts (
            id         BIGINT       NOT NULL AUTO_INCREMENT,
            username   VARCHAR(50)  NOT NULL,
            ip_address VARCHAR(45)  NOT NULL,
            created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_la_username (username, created_at),
            KEY idx_la_ip (ip_address, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec("INSERT INTO app_settings (`key`, `value`) VALUES ('mig_login_attempts', '1')
                    ON DUPLICATE KEY UPDATE `value` = '1'");
    if (isset($GLOBALS['__settings_cache']) && is_array($GLOBALS['__settings_cache'])) {
        $GLOBALS['__settings_cache']['mig_login_attempts'] = '1';
    }
}

/**
 * The submitting client's address. REMOTE_ADDR only - never a forwarded
 * header, which an attacker controls.
 */
function loginClientIp(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
}

/**
 * Seconds the caller must still wait before trying again, 0 when not locked.
 * Locked when the username hit LOGIN_MAX_ATTEMPTS_USER or the client IP hit
 * LOGIN_MAX_ATTEMPTS_IP failures inside LOGIN_WINDOW_SECONDS.
 */
function loginThrottleLockoutSeconds(string $username, string $ip): int
{
    loginThrottleBootstrap();

    $window = max(60, LOGIN_WINDOW_SECONDS);
    $since  = date('Y-m-d H:i:s', time() - $window);

    $stmt = $GLOBALS['pdo']->prepare(
        'SELECT COALESCE(SUM(username   = ?), 0) AS user_fails,
                COALESCE(SUM(ip_address = ?), 0) AS ip_fails
           FROM login_attempts
          WHERE created_at >= ?'
    );
    $stmt->execute([$username, $ip, $since]);
    $row = $stmt->fetch();

    $overUser = (int)($row['user_fails'] ?? 0) >= LOGIN_MAX_ATTEMPTS_USER;
    $overIp   = (int)($row['ip_fails'] ?? 0) >= LOGIN_MAX_ATTEMPTS_IP;

    if (!$overUser && !$overIp) {
        return 0;
    }

    // Remaining wait = until the oldest relevant failure exits the window.
    // Both timestamps are computed in the database's clock domain.
    $cond  = $overUser ? 'username = ?' : 'ip_address = ?';
    $arg   = $overUser ? $username : $ip;
    $stmt2 = $GLOBALS['pdo']->prepare(
        'SELECT GREATEST(0, UNIX_TIMESTAMP(MIN(created_at)) + ' . $window
        . ' - UNIX_TIMESTAMP(NOW()))
           FROM login_attempts
          WHERE created_at >= ? AND ' . $cond
    );
    $stmt2->execute([$since, $arg]);

    return (int)$stmt2->fetchColumn();
}

/**
 * Record one failed sign-in attempt (plus opportunistic pruning - no cron
 * job needed to keep the table tiny).
 */
function loginThrottleRecordFailure(string $username, string $ip): void
{
    loginThrottleBootstrap();

    $GLOBALS['pdo']->prepare(
        'INSERT INTO login_attempts (username, ip_address) VALUES (?, ?)'
    )->execute([substr($username, 0, 50), substr($ip, 0, 45)]);

    // Prune rows older than the retention period (~5% of requests).
    if (random_int(1, 20) === 1) {
        $GLOBALS['pdo']->exec(
            'DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL '
            . max(3600, LOGIN_ATTEMPT_RETENTION) . ' SECOND'
        );
    }
}

/**
 * Successful sign-in: forget this username's failures (IP history stays, so
 * an attacker cannot clear an IP lock by compromising one account).
 */
function loginThrottleClear(string $username): void
{
    loginThrottleBootstrap();

    $GLOBALS['pdo']->prepare('DELETE FROM login_attempts WHERE username = ?')
        ->execute([$username]);
}