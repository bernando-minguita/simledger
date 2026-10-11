<?php
/**
 * SIMLedger - audit trail.
 *
 * Every important action is recorded with WHO / WHAT / WHEN / WHERE / WHY.
 * Audit rows are append-only: no application code ever updates or deletes
 * them, and only administrators can read them.
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

/**
 * Write an audit log entry.
 *
 * @param array<string, mixed>|null $oldValues Snapshot before the change.
 * @param array<string, mixed>|null $newValues Snapshot after the change.
 * @param string|null $username Overrides the WHO column for actors without a
 *        session user (external API calls identify as "api:<token name>").
 */
function logAudit(
    string $action,
    string $entityType,
    ?int $entityId = null,
    ?array $oldValues = null,
    ?array $newValues = null,
    ?int $terminalId = null,
    ?string $username = null
): void {
    $user = function_exists('currentUser') ? currentUser() : null;

    // Fall back to session data when auth.php is not loaded (CLI tools).
    if ($user === null && !empty($_SESSION['user_id'])) {
        $user = [
            'id'       => (int)$_SESSION['user_id'],
            'username' => $_SESSION['user']['username'] ?? $_SESSION['username'] ?? '',
        ];
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    $stmt = $GLOBALS['pdo']->prepare(
        'INSERT INTO audit_logs
            (user_id, username, action, entity_type, entity_id,
             old_values, new_values, terminal_id, ip_address, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );

    $stmt->execute([
        $user['id'] ?? null,
        $username !== null ? mb_substr($username, 0, 50) : ($user['username'] ?? null),
        $action,
        $entityType,
        $entityId,
        $oldValues !== null ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null,
        $newValues !== null ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null,
        $terminalId,
        $ip,
    ]);
}
