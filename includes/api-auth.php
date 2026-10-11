<?php
/**
 * SIMLedger - bearer-token authentication + JSON helpers for the external
 * read-only API (api/*.php).
 *
 * Server-to-server calls: no session login, no CSRF, no 2FA, no geo-guard -
 * the token IS the security boundary (see SIMLedger-Documentation.md,
 * "External API"). Tokens are stored as SHA-256 hashes (api_tokens.token_hash);
 * the plaintext is displayed exactly once when an administrator mints it.
 */

declare(strict_types=1);

/**
 * Emit a JSON response and stop.
 *
 * @param array<string, mixed> $payload
 */
function apiJson(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Resolve the calling system's token. Accepts "Authorization: Bearer <token>"
 * (primary) or "X-API-Key: <token>" (clients that cannot set arbitrary
 * headers). Exits with a 401 JSON body on any failure.
 *
 * @return array{id:int, name:string} the matching api_tokens row
 */
function apiAuthenticate(): array
{
    $raw    = '';
    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');

    // Some SAPIs hide Authorization from $_SERVER - scan the raw headers too.
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $hName => $hValue) {
            if (strcasecmp((string)$hName, 'Authorization') === 0) {
                $header = (string)$hValue;
                break;
            }
        }
    }
    if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m) === 1) {
        $raw = $m[1];
    }
    if ($raw === '') {
        $raw = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
    }

    // Shaped like a token this application could have minted (sl_ + hex).
    // Anything else fails fast without touching the database.
    if (preg_match('/^sl_[0-9a-f]{16,128}$/', $raw) !== 1) {
        apiJson(401, ['ok' => false, 'error' => 'unauthorized']);
    }

    $stmt = $GLOBALS['pdo']->prepare(
        'SELECT id, name FROM api_tokens WHERE token_hash = ? AND is_active = 1'
    );
    $stmt->execute([hash('sha256', $raw)]);
    $row = $stmt->fetch();

    if ($row === false) {
        apiJson(401, ['ok' => false, 'error' => 'unauthorized']);
    }

    // Best-effort usage stamp - never fail a real call over telemetry.
    try {
        $GLOBALS['pdo']->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?')
            ->execute([(int)$row['id']]);
    } catch (Throwable $e) {
        error_log('[simledger] could not stamp api token use: ' . $e->getMessage());
    }

    return ['id' => (int)$row['id'], 'name' => (string)$row['name']];
}