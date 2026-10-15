<?php
/**
 * SIMLedger - page header (sidebar layout, flash,
 * content open).
 *
 * Set $pageTitle and $activeNav before including this file. Pages inside
 * /admin or /reports must set $base = '../' before including this file.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit.php';

requireLogin();

// Dismiss the session's "enable 2FA" reminder. GET, like login.php's
// ?cancel_2fa=1: it only clears a cosmetic flag, never real state.
if (isset($_GET['dismiss_2fa'])) {
    unset($_SESSION['two_factor_reminder']);
}

$user      = currentUser();
$pageTitle = isset($pageTitle) ? (string)$pageTitle : 'Dashboard';
$activeNav = isset($activeNav) ? (string)$activeNav : '';
$base      = isset($base) ? (string)$base : '';

$flash = takeFlash();

// "Enable 2FA" reminder (flagged at sign-in while the account had no TOTP).
// Live-checked so it disappears the moment enrolment completes anywhere;
// never shown while impersonating, and Dismiss clears it for the session.
$show2faReminder = false;
if (empty($_SESSION['impersonator'])
    && (int)($_SESSION['two_factor_reminder'] ?? 0) === 1) {
    $st2fa = $GLOBALS['pdo']->prepare('SELECT totp_enabled FROM users WHERE id = ?');
    $st2fa->execute([(int)($_SESSION['user_id'] ?? 0)]);
    $r2fa  = $st2fa->fetch();
    $show2faReminder = $r2fa === false || (int)$r2fa['totp_enabled'] !== 1;
    if (!$show2faReminder) {
        unset($_SESSION['two_factor_reminder']);
    }
}

/**
 * Navigation sections. Each item declares the roles allowed to see it;
 * items link only to files that exist.
 */
$navSections = [
    'dashboard' => [
        'label' => 'Dashboard',
        'items' => [
            'dashboard' => ['label' => 'Dashboard', 'file' => 'index.php'],
        ],
    ],
    'sim' => [
        'label' => 'SIM Management',
        'items' => [
            'sim-inventory'   => ['label' => 'SIM Inventory',       'file' => 'sims.php',       'perm' => 'sim.view'],
            'sim-available'   => ['label' => 'Available SIMs',      'file' => 'available.php',  'perm' => 'sim.view'],
            'sim-assignments' => ['label' => 'Current Assignments', 'file' => 'assignments.php', 'perm' => 'sim.view'],
            'sim-assign'      => ['label' => 'Issue SIM',           'file' => 'assign.php',      'perm' => 'sim.transact'],
            'sim-return'      => ['label' => 'Return SIM',          'file' => 'return-sim.php',  'perm' => 'sim.transact'],
            'sim-transfer'    => ['label' => 'Transfer SIM',        'file' => 'transfer.php',    'perm' => 'sim.transact'],
            'sim-replace'     => ['label' => 'Replace SIM',         'file' => 'replace-sim.php', 'perm' => 'sim.transact'],
            'sim-mark-lost'   => ['label' => 'Mark Lost / Damaged', 'file' => 'mark-lost.php',   'perm' => 'sim.transact'],
            'sim-history'     => ['label' => 'SIM History',         'file' => 'sim-history.php', 'perm' => 'sim.view'],
        ],
    ],
    'drivers' => [
        'label' => 'Drivers',
        'items' => [
            'driver-list'     => ['label' => 'Driver List',         'file' => 'drivers.php',                  'perm' => 'driver.view'],
            'driver-contacts' => ['label' => 'Driver Contacts',     'file' => 'contacts.php',                 'perm' => 'contact.view'],
            'driver-nosim'    => ['label' => 'Drivers Without SIM', 'file' => 'reports/drivers-without-sim.php', 'perm' => 'report.view'],
        ],
    ],
    'reports' => [
        'label' => 'Reports',
        'items' => [
            'report-sims'        => ['label' => 'SIM Inventory',      'file' => 'reports/inventory.php',         'perm' => 'report.view'],
            'report-transfers'   => ['label' => 'SIM Transfers',      'file' => 'reports/transfers.php',         'perm' => 'report.view'],
            'report-assignments' => ['label' => 'Assignment History', 'file' => 'reports/assignments.php',       'perm' => 'report.view'],
            'report-coverage'    => ['label' => 'Contact Coverage',   'file' => 'reports/contact-coverage.php',  'perm' => 'report.view'],
        ],
    ],
    'admin' => [
        'label' => 'Administration',
        'items' => [
            'admin-users'     => ['label' => 'Users',                'file' => 'admin/users.php',      'perm' => 'user.manage'],
            'admin-roles'     => ['label' => 'Roles & Permissions',  'file' => 'admin/roles.php',      'perm' => 'user.manage'],
            'admin-terminals' => ['label' => 'Terminals',            'file' => 'admin/terminals.php',  'perm' => 'reference.manage'],
            'admin-carriers'  => ['label' => 'Carriers',             'file' => 'admin/carriers.php',   'perm' => 'reference.manage'],
            'admin-agencies'  => ['label' => 'Agencies',             'file' => 'admin/agencies.php',   'perm' => 'reference.manage'],
            'admin-drivers'   => ['label' => 'Drivers',              'file' => 'admin/drivers.php',    'perm' => 'driver.manage'],
            'admin-sims'      => ['label' => 'SIM Records',          'file' => 'admin/sims.php',       'perm' => 'reference.manage'],
            'admin-import'    => ['label' => 'Import Data',          'file' => 'import.php',           'perm' => 'data.import'],
            'admin-audit'     => ['label' => 'Audit Log',            'file' => 'admin/audit-log.php',  'perm' => 'audit.view'],
            'admin-api'       => ['label' => 'API Tokens',           'file' => 'admin/api-tokens.php', 'perm' => 'api.manage'],
        ],
    ],
];

$appRoot = dirname(__DIR__);

$navVisible = static function (array $items) use ($user, $appRoot): array {
    $visible = [];
    $role    = (string)($user['role_key'] ?? '');

    foreach ($items as $key => $item) {
        // Permission keys are authoritative when present (the admin role
        // implicitly holds every permission); otherwise fall back to roles.
        if (isset($item['perm'])) {
            if (!can($item['perm'])) {
                continue;
            }
        } else {
            $roles = $item['roles'] ?? ['admin', 'manager', 'dispatcher'];

            if (!in_array($role, $roles, true)) {
                continue;
            }
        }

        if (!is_file($appRoot . '/' . $item['file'])) {
            continue;
        }

        $visible[$key] = $item;
    }

    return $visible;
};

/**
 * Small stroke icons per nav target (lucide-style, 24px viewBox rendered at
 * 16px). Unknown files simply render without an icon.
 */
$navIcons = [
    'index.php'                     => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/>',
    'sims.php'                      => '<rect x="7" y="7" width="10" height="10" rx="2"/><path d="M9 2v3M15 2v3M9 19v3M15 19v3M2 9h3M2 15h3M19 9h3M19 15h3"/>',
    'available.php'                 => '<circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 4.5-5"/>',
    'assignments.php'               => '<path d="M8 6h13M8 12h13M8 18h13"/><path d="M3.5 6h.01M3.5 12h.01M3.5 18h.01"/>',
    'assign.php'                    => '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/>',
    'return-sim.php'                => '<path d="M9 10 4 15l5 5"/><path d="M20 4v7a4 4 0 0 1-4 4H4"/>',
    'transfer.php'                  => '<path d="M17 3l4 4-4 4"/><path d="M21 7H7a4 4 0 0 0-4 4"/><path d="M7 21l-4-4 4-4"/><path d="M3 17h14a4 4 0 0 0 4-4"/>',
    'replace-sim.php'               => '<path d="M21 12a9 9 0 1 1-2.6-6.4"/><path d="M21 3v6h-6"/>',
    'mark-lost.php'                 => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
    'sim-history.php'               => '<path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 3v6h6"/><path d="M12 7v5l3 2"/>',
    'drivers.php'                   => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9"/><path d="M16 3.1a4 4 0 0 1 0 7.8"/>',
    'contacts.php'                  => '<path d="M6.6 2.5h3l1.5 4-2 1.5a12 12 0 0 0 5.9 5.9l1.5-2 4 1.5v3a2 2 0 0 1-2.1 2A16.5 16.5 0 0 1 4.6 4.6a2 2 0 0 1 2-2.1z"/>',
    'reports/drivers-without-sim.php' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m17 8 5 5M22 8l-5 5"/>',
    'reports/inventory.php'         => '<path d="M21 8 12 3 3 8v8l9 5 9-5z"/><path d="M3 8l9 5 9-5"/><path d="M12 13v8"/>',
    'reports/transfers.php'         => '<path d="M17 3l4 4-4 4"/><path d="M21 7H7a4 4 0 0 0-4 4"/><path d="M7 21l-4-4 4-4"/><path d="M3 17h14a4 4 0 0 0 4-4"/>',
    'reports/assignments.php'       => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8M16 17H8"/>',
    'reports/contact-coverage.php'  => '<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="m9 14 2 2 4-4"/>',
    'admin/users.php'               => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9"/><path d="M16 3.1a4 4 0 0 1 0 7.8"/>',
    'admin/terminals.php'           => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/>',
    'admin/carriers.php'            => '<path d="M4.9 19.1a10 10 0 0 1 0-14.2M19.1 4.9a10 10 0 0 1 0 14.2"/><path d="M7.8 16.2a6 6 0 0 1 0-8.4M16.2 7.8a6 6 0 0 1 0 8.4"/><circle cx="12" cy="12" r="2"/>',
    'admin/drivers.php'             => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9"/><path d="M16 3.1a4 4 0 0 1 0 7.8"/>',
    'admin/sims.php'                => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
    'import.php'                    => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
    'admin/audit-log.php'           => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    'admin/api-tokens.php'          => '<path d="M12 22v-5"/><path d="M9 8V2"/><path d="M15 8V2"/><path d="M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8z"/>',
    'admin/agencies.php'            => '<path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-6h6v6"/>',
    'admin/roles.php'               => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
];

$navIcon = static function (string $file) use ($navIcons): string {
    if (!isset($navIcons[$file])) {
        return '';
    }

    return '<svg class="nav-icon" width="16" height="16" viewBox="0 0 24 24" fill="none"'
        . ' stroke="currentColor" stroke-width="1.8" stroke-linecap="round"'
        . ' stroke-linejoin="round" aria-hidden="true">' . $navIcons[$file] . '</svg>';
};

// Initials for the topbar avatar (first letters of up to two name words).
$userName  = trim((string)($user['name'] ?? $user['username'] ?? ''));
$nameParts = preg_split('/\s+/', $userName);
$initials  = strtoupper(substr($nameParts[0] ?? 'U', 0, 1)
    . (isset($nameParts[1]) ? substr($nameParts[1], 0, 1) : ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> &middot; <?= e(APP_NAME) ?></title>
    <link rel="icon" href="<?= e($base) ?>assets/img/favicon.svg" type="image/svg+xml">
    <link rel="shortcut icon" href="<?= e($base) ?>assets/img/favicon.ico">
    <link rel="stylesheet" href="<?= e($base . asset('assets/css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e($base . asset('assets/css/style.css')) ?>">
    <!-- Warm the app scripts. All three run during parsing and adjust the DOM
         BEFORE the first paint - the "#" row-number column, the hidden columns,
         the sidebar scroll offset, the picker's pre-selected row. Preloading
         means they are already fetched when the parser reaches them, so that
         work always lands ahead of the paint rather than after it: the same
         "the structure is already there, only the data changed" behaviour a
         server-rendered table gets for free. The URL must match the <script>
         tag in footer.php exactly (both use asset()) or the browser fetches
         the file twice. -->
    <link rel="preload" as="script" href="<?= e($base . asset('assets/js/table-toolkit.js')) ?>">
    <link rel="preload" as="script" href="<?= e($base . asset('assets/js/picker.js')) ?>">
    <link rel="preload" as="script" href="<?= e($base . asset('assets/js/shared.js')) ?>">
</head>
<body>

<!-- ============================================================== sidebar -->
<aside class="app-sidebar" id="appSidebar">
    <a class="sidebar-brand" href="<?= e($base) ?>index.php">
        <img src="<?= e($base) ?>assets/img/favicon.svg" alt="">
        <span>
            <span class="sidebar-brand-text">Driver SIM &amp; Contacts</span>
            <span class="sidebar-brand-sub">Terminal Operations</span>
        </span>
    </a>

    <nav class="sidebar-nav">
        <?php foreach ($navSections as $sectionKey => $section): ?>
            <?php $sectionItems = $navVisible($section['items']); ?>
            <?php if ($sectionItems === []): ?>
                <?php continue; ?>
            <?php endif; ?>

            <?php if ($sectionKey === 'dashboard'): ?>
                <?php foreach ($sectionItems as $key => $item): ?>
                    <a class="nav-item-link<?= $activeNav === $key ? ' active' : '' ?>"
                       href="<?= e($base . $item['file']) ?>">
                        <?= $navIcon($item['file']) ?><span class="nav-text"><?= e($item['label']) ?></span>
                    </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="nav-section">
                    <div class="nav-section-label"><?= e($section['label']) ?></div>
                    <?php foreach ($sectionItems as $key => $item): ?>
                        <a class="nav-item-link<?= $activeNav === $key ? ' active' : '' ?>"
                           href="<?= e($base . $item['file']) ?>">
                            <?= $navIcon($item['file']) ?><span class="nav-text"><?= e($item['label']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
</aside>

<!-- ============================================================ app shell -->
<div class="app-content">
    <header class="app-topbar">
        <button class="sidebar-toggle" type="button" data-sidebar-toggle
                aria-controls="appSidebar" aria-expanded="false" aria-label="Toggle navigation">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round">
                <path d="M3 6h18M3 12h18M3 18h18"/>
            </svg>
        </button>
        <span class="app-topbar-title"><?= e($pageTitle) ?></span>
        <div class="app-topbar-right">
            <span class="topbar-user">
                <span class="user-avatar"><?= e($initials) ?></span>
                <span class="meta">
                    <span class="name"><?= e($user['name'] ?? '') ?></span>
                    <span class="role"><?= e($user['role_name'] ?? '') ?><?= !empty($user['terminal_name']) ? ' &middot; ' . e($user['terminal_name']) : '' ?></span>
                </span>
            </span>
            <a class="btn btn-outline-secondary btn-sm" href="<?= e($base) ?>profile.php">Profile</a>
            <a class="btn btn-primary btn-sm" href="<?= e($base) ?>logout.php">Logout</a>
        </div>
    </header>

    <main class="app-main">
<?php if (!empty($_SESSION['impersonator'])): ?>
    <div class="alert-op mb-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span>
            Logging in as <strong><?= e($_SESSION['user']['name'] ?? '') ?></strong>
            (<code><?= e($_SESSION['user']['username'] ?? '') ?></code>,
            <?= e($_SESSION['user']['role_name'] ?? '') ?>)
            on behalf of <strong><?= e($_SESSION['impersonator']['username'] ?? '') ?></strong>.
        </span>
        <form method="post" action="<?= e($base) ?>impersonate.php" class="m-0">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="return">
            <button type="submit" class="btn btn-sm btn-primary">Return to admin account</button>
        </form>
    </div>
<?php endif; ?>
<?php if ($show2faReminder): ?>
    <div class="alert-op mb-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span>
            Your account does not have <strong>two-factor authentication</strong>
            yet. Enable it on your profile for better security.
        </span>
        <span class="d-flex gap-2">
            <a class="btn btn-sm btn-primary" href="<?= e($base) ?>profile.php">Set up 2FA</a>
            <a class="btn btn-sm btn-outline-secondary" href="?dismiss_2fa=1">Dismiss</a>
        </span>
    </div>
<?php endif; ?>
<?php if ($flash !== null): ?>
    <div class="<?= $flash['type'] === 'ok' ? 'alert-ok' : 'alert-error' ?> mb-3"><?= e($flash['msg']) ?></div>
<?php endif; ?>

