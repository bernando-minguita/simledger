<?php
/**
 * reCAPTCHA v2 (invisible) helpers for the sign-in form.
 *
 * The captcha is enforced only when keys are configured in config/config.php
 * AND the host is not localhost - Google rejects localhost site keys, so the
 * check is skipped automatically during local development.
 */

declare(strict_types=1);

// config/ files are never shipped in update zips (they hold per-host values),
// so tolerate config/config.php copies that predate these constants - the
// captcha simply stays disabled until real keys are configured.
defined('RECAPTCHA_SITE_KEY')   || define('RECAPTCHA_SITE_KEY', '');
defined('RECAPTCHA_SECRET_KEY') || define('RECAPTCHA_SECRET_KEY', '');
defined('RECAPTCHA_TIMEOUT')    || define('RECAPTCHA_TIMEOUT', 6);

/**
 * True for local development hosts (any port, incl. IPv6 loopback).
 */
function recaptchaIsLocalHost(?string $host = null): bool
{
    $h = strtolower((string)($host ?? ($_SERVER['HTTP_HOST'] ?? '')));
    $h = trim($h);

    if (str_starts_with($h, '[')) {           // [::1]:8097
        $h = (string)explode(']', substr($h, 1))[0];
    } else {
        $h = (string)explode(':', $h)[0];     // localhost:8097
    }

    return in_array($h, ['localhost', '127.0.0.1', '::1'], true);
}

/**
 * Captcha active only when both keys are set and we are not on localhost.
 */
function recaptchaEnabled(?string $host = null): bool
{
    if (RECAPTCHA_SITE_KEY === '' || RECAPTCHA_SECRET_KEY === '') {
        return false;
    }

    return !recaptchaIsLocalHost($host);
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