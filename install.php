<?php
/**
 * SIMLedger - web installer.
 *
 * Creates the database, runs sql/schema.sql, seeds roles / terminals /
 * carriers / settings and creates the first administrator account.
 * Delete this file after installation.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

$errors = [];
$done   = false;

$adminUser = trim((string)($_POST['admin_user'] ?? 'admin'));
$adminPass = (string)($_POST['admin_pass'] ?? '');
$adminName = trim((string)($_POST['admin_name'] ?? 'System Administrator'));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($adminUser === '' || strlen($adminUser) > 50) {
        $errors[] = 'Administrator username is required (max 50 characters).';
    }
    if (strlen($adminPass) < 8) {
        $errors[] = 'Administrator password must be at least 8 characters.';
    }

    if ($errors === []) {
        try {
            // 1. Connect without a database and make sure ours exists.
            $root = new PDO(
                'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
            );
            $dbName = str_replace('`', '', DB_NAME);
            $root->exec(
                'CREATE DATABASE IF NOT EXISTS `' . $dbName . '`
                 DEFAULT CHARACTER SET utf8mb4 DEFAULT COLLATE utf8mb4_unicode_ci'
            );
            $root->exec('USE `' . $dbName . '`');

            // 2. Run the schema (strip CREATE DATABASE / USE directives).
            $sql = file_get_contents(__DIR__ . '/sql/schema.sql');
            if ($sql === false) {
                throw new RuntimeException('Cannot read sql/schema.sql.');
            }
            $sql = preg_replace('/^\s*CREATE DATABASE.*?;\s*$/mis', '', $sql);
            $sql = preg_replace('/^\s*USE\s+.*?;\s*$/mi', '', $sql);

            $root->exec($sql);

            // 3. Seed reference data (idempotent).
            $stmt = $root->prepare('INSERT IGNORE INTO roles (`key`, name) VALUES (?, ?)');
            foreach ([['admin', 'System Administrator'], ['manager', 'Manager / Operations'],
                      ['dispatcher', 'Terminal Dispatcher']] as $r) {
                $stmt->execute($r);
            }

            $stmt = $root->prepare('INSERT IGNORE INTO terminals (name, code) VALUES (?, ?)');
            foreach ([['Dammam', 'DMM'], ['Riyadh', 'RUH'], ['Jeddah', 'JED']] as $t) {
                $stmt->execute($t);
            }

            $stmt = $root->prepare('INSERT IGNORE INTO carriers (name) VALUES (?)');
            foreach (['STC', 'Mobily', 'Zain', 'Other'] as $c) {
                $stmt->execute([$c]);
            }

            $stmt = $root->prepare('INSERT IGNORE INTO app_settings (`key`, `value`) VALUES (?, ?)');
            foreach ([['managers_see_full_numbers', '0'],
                      ['app_name', APP_NAME]] as $s) {
                $stmt->execute($s);
            }

            // 4. First administrator account (freshly generated hash).
            $roleAdmin = (int)$root->query("SELECT id FROM roles WHERE `key`='admin'")->fetchColumn();
            $stmt = $root->prepare(
                'INSERT INTO users (username, password_hash, name, role_id, terminal_id, is_active)
                 VALUES (?, ?, ?, ?, NULL, 1)'
            );
            $stmt->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT), $adminName, $roleAdmin]);

            $done = true;
        } catch (Throwable $e) {
            error_log('[simledger] install failed: ' . $e->getMessage());
            $errors[] = 'Installation failed: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Install &middot; <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">
<div class="login-wrap">
<div class="login-card">
    <div class="login-brand">
        <span class="login-logo"><img src="assets/img/favicon.svg" alt=""></span>
        <h1 class="login-title"><?= e(APP_NAME) ?></h1>
        <p class="login-sub">Installation</p>
    </div>

    <?php if ($done): ?>
        <div class="alert-ok mb-3">
            Installation complete. Database <strong><?= e(DB_NAME) ?></strong> was created,
            reference data seeded and the administrator account set up.
        </div>
        <a class="btn btn-primary w-100" href="login.php">Go to Login</a>
        <p class="login-sub mt-3 mb-0">For safety, delete <code>install.php</code> now.</p>
    <?php else: ?>
        <?php foreach ($errors as $err): ?>
            <p class="login-error"><?= e($err) ?></p>
        <?php endforeach; ?>

        <p style="font-size:.85rem;color:#475569">
            Creates database <strong><?= e(DB_NAME) ?></strong> on <?= e(DB_HOST) ?> and seeds
            Dammam / Riyadh / Jeddah terminals, carriers, roles and the admin account.
        </p>

        <form method="post" autocomplete="off" class="login-form">
            <div class="mb-3">
                <label class="form-label">Admin username</label>
                <input type="text" class="form-control" name="admin_user" value="<?= e($adminUser) ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Admin name</label>
                <input type="text" class="form-control" name="admin_name" value="<?= e($adminName) ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Password (min 8 chars)</label>
                <input type="password" class="form-control" name="admin_pass" minlength="8" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Install</button>
        </form>
    <?php endif; ?>
</div>
<p class="login-footer">Driver SIM &amp; Contact Management &middot; setup</p>
</div>
</body>
</html>
