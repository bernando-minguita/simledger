<?php
/**
 * SIMLedger - API token management (admin only).
 *
 * One named bearer token per consuming system (Leave Management, payroll...).
 * The plaintext token is displayed exactly once, immediately after minting;
 * only its SHA-256 hash is stored. The tokens authorize the read-only
 * external API (api/drivers.php).
 */

declare(strict_types=1);

$base = '../';

require_once __DIR__ . '/../includes/lookups.php';
require_once __DIR__ . '/../includes/audit.php';

$pdo = $GLOBALS['pdo'];
requirePermission('api.manage');
permissionsBootstrap();   // older databases: creates api_tokens via migration

$error      = '';
$plainToken = '';   // shown once, this request only

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrfCheck();

    $action = (string)($_POST['action'] ?? '');
    $tid    = reqInt('token_id', 0, 0, 100000000);

    try {
        if ($action === 'create') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '' || strlen($name) > 64) {
                throw new RuntimeException('Name is required (max 64 chars) - e.g. "Leave Management".');
            }

            $dup = $pdo->prepare('SELECT id FROM api_tokens WHERE name = ?');
            $dup->execute([$name]);
            if ($dup->fetch() !== false) {
                throw new RuntimeException('A token with that name already exists.');
            }

            $plainToken = 'sl_' . bin2hex(random_bytes(24));
            $ins = $pdo->prepare(
                'INSERT INTO api_tokens (name, token_hash, prefix, is_active, created_by)
                 VALUES (?, ?, ?, 1, ?)'
            );
            $ins->execute([
                $name,
                hash('sha256', $plainToken),
                substr($plainToken, 0, 11),   // sl_ + 8 hex - display only
                (int)$_SESSION['user_id'],
            ]);
            logAudit('CREATE_API_TOKEN', 'api_token', (int)$pdo->lastInsertId(),
                null, ['name' => $name, 'prefix' => substr($plainToken, 0, 11)]);

            // Deliberately NO redirect: the plaintext must be visible right now.
        } elseif ($action === 'toggle') {
            $stmt = $pdo->prepare('SELECT * FROM api_tokens WHERE id = ?');
            $stmt->execute([$tid]);
            $row = $stmt->fetch();
            if ($row === false) {
                throw new RuntimeException('Token not found.');
            }

            $new = (int)$row['is_active'] === 1 ? 0 : 1;
            $pdo->prepare('UPDATE api_tokens SET is_active = ? WHERE id = ?')
                ->execute([$new, $tid]);
            logAudit('UPDATE_API_TOKEN', 'api_token', $tid,
                ['is_active' => (int)$row['is_active']], ['is_active' => $new]);
            setFlash('ok', $new === 1
                ? 'Token "' . $row['name'] . '" re-enabled.'
                : 'Token "' . $row['name'] . '" revoked. The calling system will get 401.');

            header('Location: api-tokens.php');
            exit;
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        $error      = $e->getMessage();
        $plainToken = '';
        if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
            $error = 'A token with that name already exists.';
        }
    }
}

$tokens = $pdo->query('SELECT * FROM api_tokens ORDER BY id DESC')->fetchAll();

$pageTitle = 'API Tokens';
$activeNav = 'admin-api';
require __DIR__ . '/../includes/header.php';
?>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header">Issue new token</div>
            <div class="card-body">
                <?php if ($plainToken !== ''): ?>
                    <div class="alert alert-success">
                        <strong>Copy the token now - it is shown only once.</strong>
                        <div class="mt-2"><code class="user-select-all"><?= e($plainToken) ?></code></div>
                        <div class="small mt-2 mb-0">
                            Store it in the consuming system's configuration. If it is
                            lost, revoke this token and mint a new one.
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($error !== ''): ?>
                    <div class="alert-error"><?= e($error) ?></div>
                <?php endif; ?>
                <form method="post" action="api-tokens.php">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="create">
                    <div class="mb-2">
                        <label class="form-label">Token name (the consuming system)</label>
                        <input type="text" class="form-control" name="name" maxlength="64" required
                               placeholder="e.g. Leave Management">
                    </div>
                    <button type="submit" class="btn btn-primary">Mint token</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">How systems call the API</div>
            <div class="card-body small">
                <p class="mb-2">Read-only driver endpoint (active roster + per-badge lookup):</p>
                <pre class="bg-light border rounded p-2 mb-2 user-select-all">GET /api/drivers.php
GET /api/drivers.php?badge=4321

Authorization: Bearer &lt;token&gt;</pre>
                <p class="mb-1">Example:</p>
                <pre class="bg-light border rounded p-2 mb-2 user-select-all">curl -H "Authorization: Bearer &lt;token&gt;" "https://YOUR-HOST/simledger/api/drivers.php?badge=4321"</pre>
                <p class="mb-0">Every call is recorded in the audit log under the token
                name. Revoke a token here to cut a system off immediately.</p>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">Tokens</div>
            <div class="card-body p-0">
                <table class="table table-striped mb-0">
                    <thead>
                    <tr><th>Name</th><th>Prefix</th><th>Status</th><th>Last used</th><th>Created</th><th class="text-end">Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php if ($tokens === []): ?>
                        <tr><td colspan="6" class="text-body-secondary">No tokens yet - issue one on the left.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($tokens as $tk): ?>
                        <tr>
                            <td><?= e($tk['name']) ?></td>
                            <td><code><?= e($tk['prefix']) ?>…</code></td>
                            <td><?= (int)$tk['is_active'] === 1
                                ? '<span class="badge bg-success">Active</span>'
                                : '<span class="badge bg-secondary">Revoked</span>' ?></td>
                            <td><?= $tk['last_used_at'] !== null ? e($tk['last_used_at']) : '<span class="text-body-secondary">never</span>' ?></td>
                            <td><?= e($tk['created_at']) ?></td>
                            <td class="text-end">
                                <form method="post" action="api-tokens.php" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="token_id" value="<?= (int)$tk['id'] ?>">
                                    <button type="submit" class="btn btn-sm <?= (int)$tk['is_active'] === 1
                                        ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                        <?= (int)$tk['is_active'] === 1 ? 'Revoke' : 'Enable' ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>