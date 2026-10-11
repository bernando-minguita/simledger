<?php
/**
 * SIMLedger - exit impersonation ("login as user").
 *
 * An administrator who used Administration -> Users -> Login as lands back
 * on their own account here. POST-only (CSRF protected): restores the stored
 * admin identity, regenerates the session id and audits the switch back.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit.php';
requireLogin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: index.php');
    exit;
}

csrfCheck();

$back = $_SESSION['impersonator'] ?? null;
if (!is_array($back) || empty($back['id'])) {
    setFlash('error', 'You are not logged in as another user.');
    header('Location: index.php');
    exit;
}

$targetId   = (int)($_SESSION['user_id'] ?? 0);
$targetUser = (string)($_SESSION['user']['username'] ?? '');

// Restore the administrator's own session identity.
$_SESSION['user_id'] = (int)$back['id'];
unset($_SESSION['impersonator'], $_SESSION['csrf_token']);

if (refreshUser() === null) {
    // The stored administrator is gone or disabled - full logout for safety.
    $_SESSION = [];
    session_destroy();
    header('Location: ' . loginUrl());
    exit;
}
session_regenerate_id(true);

logAudit('EXIT_LOGIN_AS', 'user', (int)$back['id'],
    ['impersonated' => $targetUser], ['returned' => true], null,
    (string)($back['username'] ?? ''));

setFlash('ok', 'Welcome back, ' . (string)($back['username'] ?? 'admin') . '.');
header('Location: admin/users.php');
exit;