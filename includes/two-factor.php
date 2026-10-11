<?php
/**
 * Two-factor authentication (TOTP, RFC 6238) for user sign-in.
 *
 * - Secrets are 16 random bytes, base32-encoded (26 chars), stored per user.
 * - SHA-1 / 6 digits / 30 s period, verified with a +/-1 step window.
 * - The QR code encodes an otpauth:// URI rendered client-side
 *   (assets/js/qrcode.min.js, MIT) so the secret never leaves the page.
 * - twoFactorBootstrap() self-provisions users.totp_* on older databases.
 */

declare(strict_types=1);

/**
 * Add totp_secret / totp_enabled to users on pre-2FA databases. Idempotent;
 * returns immediately once flagged in app_settings (per-request cached).
 */
function twoFactorBootstrap(): void
{
    if (settingGet('mig_2fa', '') === '1') {
        return;
    }

    $pdo = $GLOBALS['pdo'];
    $has = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'users' AND COLUMN_NAME = 'totp_secret'"
    )->fetchColumn();

    if ($has === 0) {
        $pdo->exec(
            'ALTER TABLE users
                ADD COLUMN totp_secret VARCHAR(64) DEFAULT NULL AFTER last_login_at,
                ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_secret'
        );
    }

    $pdo->exec("INSERT INTO app_settings (`key`, `value`) VALUES ('mig_2fa', '1')
                    ON DUPLICATE KEY UPDATE `value` = '1'");
    if (isset($GLOBALS['__settings_cache']) && is_array($GLOBALS['__settings_cache'])) {
        $GLOBALS['__settings_cache']['mig_2fa'] = '1';
    }
}

// --------------------------------------------------------------------- base32
function totpBase32Encode(string $bin): string
{
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out   = '';
    $bits  = 0;
    $value = 0;

    foreach (str_split($bin) as $ch) {
        $value = ($value << 8) | ord($ch);
        $bits += 8;
        while ($bits >= 5) {
            $out .= $alpha[($value >> ($bits - 5)) & 31];
            $bits -= 5;
        }
    }
    if ($bits > 0) {
        $out .= $alpha[($value << (5 - $bits)) & 31];
    }

    return $out;
}

function totpBase32Decode(string $str): string
{
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $str   = strtoupper(str_replace([' ', '='], '', $str));
    $out   = '';
    $bits  = 0;
    $value = 0;

    for ($i = 0, $len = strlen($str); $i < $len; $i++) {
        $pos = strpos($alpha, $str[$i]);
        if ($pos === false) {
            continue;
        }
        $value = ($value << 5) | $pos;
        $bits += 5;
        if ($bits >= 8) {
            $out .= chr(($value >> ($bits - 8)) & 255);
            $bits -= 8;
        }
    }

    return $out;
}

// ------------------------------------------------------------ secret + codes
function totpGenerateSecret(int $bytes = 16): string
{
    return totpBase32Encode(random_bytes($bytes));
}

/**
 * RFC 6238 TOTP code for a point in time (defaults to now).
 */
function totpCode(string $secret, ?int $at = null, int $digits = 6, int $period = 30): string
{
    $counter = intdiv($at ?? time(), $period);
    $hi      = intdiv($counter, 0x100000000);
    $lo      = $counter % 0x100000000;
    $hmac    = hash_hmac('sha1', pack('NN', $hi, $lo), totpBase32Decode($secret), true);

    $offset = ord($hmac[19]) & 0x0f;
    $bin    = ((ord($hmac[$offset]) & 0x7f) << 24)
            | ((ord($hmac[$offset + 1]) & 0xff) << 16)
            | ((ord($hmac[$offset + 2]) & 0xff) << 8)
            | (ord($hmac[$offset + 3]) & 0xff);

    return str_pad((string)($bin % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

/**
 * Accept the current code or one step (30 s) before/after (clock drift).
 */
function totpVerify(string $secret, string $code, ?int $at = null): bool
{
    $code = trim($code);
    if ($secret === '' || !preg_match('/^\d{6}$/', $code)) {
        return false;
    }

    $at = $at ?? time();
    foreach ([-1, 0, 1] as $step) {
        if (hash_equals(totpCode($secret, $at + $step * 30), $code)) {
            return true;
        }
    }

    return false;
}

/**
 * otpauth:// URI encoded by authenticator apps (issuer shown as "SIMLedger").
 */
function otpauthUri(string $secret, string $account): string
{
    $issuer = 'SIMLedger';

    return 'otpauth://TOTP/' . rawurlencode($issuer . ':' . $account)
        . '?secret=' . rawurlencode($secret)
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
}

// ---------------------------------------------------------------------- state
function twoFactorStatus(int $userId): array
{
    $stmt = $GLOBALS['pdo']->prepare('SELECT totp_secret, totp_enabled FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    if ($row === false) {
        return ['enabled' => false, 'pending' => false, 'secret' => null];
    }

    $enabled = (int)$row['totp_enabled'] === 1;
    $secret  = $row['totp_secret'] !== null ? (string)$row['totp_secret'] : null;

    return [
        'enabled' => $enabled,
        'pending' => !$enabled && $secret !== null,
        'secret'  => $secret,
    ];
}

/**
 * Begin enrolment: generate (or reuse) a pending secret. Returns the secret.
 */
function twoFactorStart(int $userId): string
{
    $status = twoFactorStatus($userId);
    if ($status['secret'] !== null) {
        return $status['secret'];
    }

    $secret = totpGenerateSecret();
    $GLOBALS['pdo']->prepare('UPDATE users SET totp_secret = ? WHERE id = ?')
        ->execute([$secret, $userId]);

    return $secret;
}

/**
 * Verify the code against the pending secret and switch 2FA on.
 */
function twoFactorConfirm(int $userId, string $code): bool
{
    $status = twoFactorStatus($userId);
    if ($status['enabled'] || $status['secret'] === null) {
        return false;
    }
    if (!totpVerify($status['secret'], $code)) {
        return false;
    }

    $GLOBALS['pdo']->prepare('UPDATE users SET totp_enabled = 1 WHERE id = ?')
        ->execute([$userId]);

    return true;
}

/** Discard a pending (unconfirmed) secret. */
function twoFactorCancel(int $userId): void
{
    $GLOBALS['pdo']->prepare(
        'UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?'
    )->execute([$userId]);
}

/** Switch 2FA off; requires a current valid code. */
function twoFactorDisable(int $userId, string $code): bool
{
    $status = twoFactorStatus($userId);
    if (!$status['enabled'] || $status['secret'] === null) {
        return false;
    }
    if (!totpVerify($status['secret'], $code)) {
        return false;
    }

    twoFactorCancel($userId);

    return true;
}

/** Admin recovery: clear secret + flag unconditionally. */
function twoFactorAdminReset(int $userId): bool
{
    $status = twoFactorStatus($userId);
    if (!$status['enabled'] && $status['secret'] === null) {
        return false;
    }

    twoFactorCancel($userId);

    return true;
}