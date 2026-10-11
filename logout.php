<?php
/**
 * SIMLedger - logout.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/audit.php';

if (!empty($_SESSION['user_id'])) {
    logAudit('LOGOUT', 'user', (int)$_SESSION['user_id']);
}

$_SESSION = [];
session_destroy();

// Expire the session cookie.
setcookie(session_name(), '', time() - 42000,
    dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')), '', !empty($_SERVER['HTTPS']), true);

header('Location: login.php');
exit;
