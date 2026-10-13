<?php
/**
 * reCAPTCHA v2 (invisible) helpers for the sign-in form.
 *
 * The captcha is enforced only when keys are configured in config/config.php
 * AND the client is not on a loopback address - Google rejects localhost site
 * keys, so the check is skipped automatically during local development.
 */

declare(strict_types=1);

// config/ files are never shipped in update zips (they hold per-host values),
// so tolerate config/config.php copies that predate these constants - the
// captcha simply stays disabled until real keys are configured.
defined('RECAPTCHA_SITE_KEY')   || define('RECAPTCHA_SITE_KEY', '');
defined('RECAPTCHA_SECRET_KEY') || define('RECAPTCHA_SECRET_KEY', '');
defined('RECAPTCHA_TIMEOUT')    || define('RECAPTCHA_TIMEOUT', 6);

/**
 * True when the request comes from a loopback address (127.0.0.0/8 or ::1) -
 * the local-development situation where Google rejects site keys anyway.
 *
 * The decision is based on REMOTE_ADDR only, NEVER on the Host header: the
 * Host header is client-controlled, so trusting it would let an attacker send
 * "Host: localhost" against a default vhost and skip the captcha entirely.
 */
function recaptchaIsLocalHost(?string $ip = null): bool
{
    $ip = $ip !== null ? trim($ip) : trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));

    if ($ip === '') {
        return false;
    }

    // Same loopback test as includes/geo-guard.php when it is loaded.
    if (function_exists('geoIsLoopback')) {
        return geoIsLoopback($ip);
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
 * Captcha active only when both keys are set and the client is not loopback.
 */
function recaptchaEnabled(?string $ip = null): bool
{
    if (RECAPTCHA_SITE_KEY === '' || RECAPTCHA_SECRET_KEY === '') {
        return false;
    }

    return !recaptchaIsLocalHost($ip);
}

/**
 * Verify the g-recaptcha-response token against Google (fail-closed:
 * missing token, invalid token, or unreachable service all return false).
 */
function recaptchaVerify(string $token): bool
{
    if ($token === '' || !recaptchaEnabled()) {
        return false;
    }

    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'secret'   => RECAPTCHA_SECRET_KEY,
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]),
        CURLOPT_TIMEOUT        => RECAPTCHA_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => RECAPTCHA_TIMEOUT,
    ]);
    $raw  = curl_exec($ch);
    unset($ch);

    if ($raw === false) {
        return false;
    }

    $json = json_decode((string)$raw, true);

    return is_array($json) && ($json['success'] ?? false) === true;
}