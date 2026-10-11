<?php
/**
 * SIMLedger - country (GeoIP) access guard.
 *
 * Restricts the web application to an allowlist of countries (Saudi Arabia
 * by default) using a local MaxMind DB-format country database - no external
 * calls, no server modules, works on plain FTP hosting.
 *
 * Everything stays OFF until GEO_BLOCK_ENABLED is set to true in the per-host
 * config/config.php (config/ is never shipped in update zips). Settings use
 * defined() fallbacks so older config copies keep working - the same rule as
 * the reCAPTCHA constants.
 *
 * Safety rails:
 *  - CLI (dev scripts, automated tests) is never blocked.
 *  - localhost / 127.0.0.0/8 / ::1 is never blocked (dev + tests).
 *  - GEO_BLOCK_BYPASS_IPS: exact IPs or CIDR ranges always allowed
 *    (office / VPN kill switch).
 *  - Missing or unreadable database: fail OPEN (with a log line) unless
 *    GEO_BLOCK_FAIL = 'closed'.
 *
 * Country data: includes/geoip/GeoLite2-Country.mmdb (MaxMind GeoLite2,
 * free account) or any format-compatible database (e.g. DB-IP country-lite).
 * Reader: vendored maxmind-db/reader (Apache-2.0) under includes/geoip/maxmind/.
 * Update the .mmdb periodically for accuracy; this guard never fatals.
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Settings - override in config/config.php (that file never ships in zips)
// ---------------------------------------------------------------------------
defined('GEO_BLOCK_ENABLED')         || define('GEO_BLOCK_ENABLED', false);
defined('GEO_BLOCK_ALLOW_COUNTRIES') || define('GEO_BLOCK_ALLOW_COUNTRIES', 'SA');
defined('GEO_BLOCK_FAIL')            || define('GEO_BLOCK_FAIL', 'open');   // open|closed
defined('GEO_BLOCK_BYPASS_IPS')      || define('GEO_BLOCK_BYPASS_IPS', '');
defined('GEO_DB_PATH')               || define('GEO_DB_PATH', __DIR__ . '/geoip/GeoLite2-Country.mmdb');

/**
 * Resolved client IP: REMOTE_ADDR, unless a GEO_TEST_IP override constant is
 * defined (automated-test hook - NEVER define it in a production config).
 */
function geoClientIp(): string
{
    if (defined('GEO_TEST_IP')) {
        return (string)GEO_TEST_IP;
    }

    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

/**
 * True for loopback (localhost, 127.0.0.0/8, ::1) - never blocked.
 */
function geoIsLoopback(string $ip): bool
{
    $ip = trim($ip);
    if ($ip === '') {
        return false;
    }
    if (strtolower($ip) === 'localhost') {
        return true;
    }
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return false;
    }
    if (strlen($bin) === 4) {
        return $bin[0] === "\x7f";               // 127.0.0.0/8
    }

    return $bin === str_repeat("\x00", 15) . "\x01";   // ::1
}

/**
 * True when $ip matches any entry of a comma list of exact IPs or CIDRs
 * (v4 and v6). Validation is lenient: bad entries are simply skipped.
 */
function geoIpInList(string $ip, string $list): bool
{
    $bin = @inet_pton(trim($ip));
    if ($bin === false) {
        return false;
    }
    $len = strlen($bin);
    foreach (explode(',', (string)$list) as $entry) {
        $entry = trim($entry);
        if ($entry === '') {
            continue;
        }
        if (str_contains($entry, '/')) {
            [$subnet, $bits] = array_pad(explode('/', $entry, 2), 2, '');
            $sb    = @inet_pton(trim($subnet));
            $bits  = (int)$bits;
            if ($sb === false || strlen($sb) !== $len) {
                continue;
            }
            $max = $len * 8;
            if ($bits < 0 || $bits > $max) {
                continue;
            }
            if ($bits === 0) {
                return true;
            }
            $bytes = intdiv($bits, 8);
            $rem   = $bits % 8;
            if ($bytes > 0 && substr($sb, 0, $bytes) !== substr($bin, 0, $bytes)) {
                continue;
            }
            if ($rem === 0) {
                return true;
            }
            $mask = chr((0xff << (8 - $rem)) & 0xff);
            if ((ord($sb[$bytes]) & ord($mask)) === (ord($bin[$bytes]) & ord($mask))) {
                return true;
            }
        } elseif (@inet_pton($entry) === $bin) {
            return true;
        }
    }

    return false;
}

/**
 * ISO country code for an IP from the local database, or null when the IP is
 * invalid, the database is missing/unreadable, or the IP has no entry.
 * The reader instance is opened once and reused for the request.
 */
function geoCountryOf(string $ip): ?string
{
    static $reader = false;      // false = not attempted yet, null = unavailable

    if (@inet_pton(trim($ip)) === false) {
        return null;
    }
    if ($reader === false) {
        if (!is_file(GEO_DB_PATH)) {
            error_log('SIMLedger geo: database not found at ' . GEO_DB_PATH);
            $reader = null;
        } else {
            try {
                require_once __DIR__ . '/geoip/maxmind/Reader.php';
                require_once __DIR__ . '/geoip/maxmind/Reader/Decoder.php';
                require_once __DIR__ . '/geoip/maxmind/Reader/InvalidDatabaseException.php';
                require_once __DIR__ . '/geoip/maxmind/Reader/Metadata.php';
                require_once __DIR__ . '/geoip/maxmind/Reader/UnsupportedPlatformException.php';
                require_once __DIR__ . '/geoip/maxmind/Reader/Util.php';
                $reader = new MaxMind\Db\Reader(GEO_DB_PATH);
            } catch (Throwable $e) {
                error_log('SIMLedger geo: cannot open database: ' . $e->getMessage());
                $reader = null;
            }
        }
    }
    if ($reader === null) {
        return null;
    }
    try {
        $data = $reader->get($ip);
        if (!is_array($data)) {
            return null;
        }
        foreach (['country', 'registered_country', 'represented_country'] as $key) {
            if (isset($data[$key]['iso_code']) && is_string($data[$key]['iso_code'])) {
                return strtoupper($data[$key]['iso_code']);
            }
        }

        return null;
    } catch (Throwable $e) {
        error_log('SIMLedger geo: lookup failed: ' . $e->getMessage());

        return null;
    }
}

/**
 * Pure decision, testable without side effects:
 *   ['block' => bool, 'reason' => string, 'country' => ?string]
 */
function geoGuardDecision(): array
{
    if (!GEO_BLOCK_ENABLED) {
        return ['block' => false, 'reason' => 'disabled', 'country' => null];
    }

    $ip = geoClientIp();
    if ($ip === '') {
        $strict = strtolower((string)GEO_BLOCK_FAIL) === 'closed';

        return ['block' => $strict,
            'reason' => $strict ? 'fail-closed:no-ip' : 'fail-open:no-ip',
            'country' => null];
    }
    if (geoIsLoopback($ip)) {
        return ['block' => false, 'reason' => 'localhost', 'country' => null];
    }
    if (GEO_BLOCK_BYPASS_IPS !== ''
        && geoIpInList($ip, (string)GEO_BLOCK_BYPASS_IPS)) {
        return ['block' => false, 'reason' => 'bypass', 'country' => null];
    }

    $country = geoCountryOf($ip);
    if ($country === null) {
        $strict = strtolower((string)GEO_BLOCK_FAIL) === 'closed';

        return ['block' => $strict,
            'reason' => $strict ? 'fail-closed:unknown-country'
                : 'fail-open:unknown-country',
            'country' => null];
    }

    $allowed = [];
    foreach (explode(',', (string)GEO_BLOCK_ALLOW_COUNTRIES) as $code) {
        $code = strtoupper(trim($code));
        if ($code !== '') {
            $allowed[] = $code;
        }
    }
    $ok = in_array($country, $allowed, true);

    return ['block' => !$ok, 'reason' => $ok ? 'allowed' : 'country',
        'country' => $country];
}

/**
 * Enforce the decision once per request: log + standalone 403 page.
 * Never throws - the worst case is an allowed request.
 */
function geoGuardEnforce(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (PHP_SAPI === 'cli') {
        return;                    // dev scripts / tests are never blocked
    }

    $decision = geoGuardDecision();
    if (!$decision['block']) {
        return;
    }

    error_log(sprintf(
        'SIMLedger geo-block: ip=%s country=%s reason=%s uri=%s ua=%s',
        geoClientIp() !== '' ? geoClientIp() : '-',
        $decision['country'] ?? '-',
        $decision['reason'],
        $_SERVER['REQUEST_URI'] ?? '-',
        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? '-'), 0, 200)
    ));

    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Access restricted</title><style>'
        . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
        . 'background:#101828;color:#e5e9f0;font-family:system-ui,-apple-system,"Segoe UI",sans-serif}'
        . '.card{max-width:420px;padding:32px;text-align:center;background:#18233b;'
        . 'border:1px solid #26324f;border-radius:10px}'
        . 'h1{font-size:20px;margin:0 0 10px}'
        . 'p{color:#9aa7c0;font-size:14px;line-height:1.6;margin:0}'
        . '</style></head><body><div class="card"><h1>Access restricted</h1>'
        . '<p>Access to this service is not available from your region. '
        . 'If you believe this is an error, please contact your administrator.</p>'
        . '</div></body></html>';
    exit;
}

// No side effects at include time - includes/auth.php calls geoGuardEnforce()
// explicitly after requiring this file, which keeps the decision logic
// directly testable from CLI probes.