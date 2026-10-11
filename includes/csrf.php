<?php
/**
 * SIMLedger - CSRF protection.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * Return (creating if needed) the session CSRF token.
 */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Render a hidden CSRF input for forms.
 */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

/**
 * Abort the request when the CSRF token is missing or wrong.
 */
function csrfCheck(): void
{
    $sent = $_POST['csrf_token'] ?? '';

    if (!is_string($sent) || $sent === '' || !hash_equals(csrfToken(), $sent)) {
        http_response_code(419);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Invalid or expired form token. Go back, reload the page and try again.';
        exit;
    }
}
