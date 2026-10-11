<?php
/**
 * SIMLedger - read-only driver API for external systems (Leave Management,
 * payroll, HR...).
 *
 *   GET /api/drivers.php              -> every active driver (full roster)
 *   GET /api/drivers.php?badge=NNNN   -> one driver by badge (sync buttons)
 *
 * Each driver carries two optional numbers: `company_sim` (the SIM currently
 * assigned to them - a company asset) and `mobile` (their primary personal
 * contact - driver owned). Consumers decide the priority; the LMS sync
 * prefers company_sim and falls back to the personal mobile.
 *
 * Auth: Authorization: Bearer <token>  (or X-API-Key: <token>) - minted in
 * Admin -> API Tokens. Unknown/revoked tokens get 401. No session login, no
 * CSRF, no 2FA, no geo-guard: the token IS the boundary. Read-only SELECTs;
 * every call is written to audit_logs with the token name as the actor.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';      // $pdo
require_once __DIR__ . '/../includes/functions.php';   // settingGet (bootstrap)
require_once __DIR__ . '/../includes/permissions.php'; // permissionsBootstrap()
require_once __DIR__ . '/../includes/audit.php';       // logAudit()
require_once __DIR__ . '/../includes/api-auth.php';    // apiAuthenticate()/apiJson()

permissionsBootstrap();   // idempotent: guarantees api_tokens exists
$token = apiAuthenticate();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    apiJson(405, ['ok' => false, 'error' => 'method_not_allowed',
        'message' => 'This endpoint accepts GET only.']);
}

$badge = trim((string)($_GET['badge'] ?? ''));
if ($badge !== '' && strlen($badge) > 30) {
    apiJson(400, ['ok' => false, 'error' => 'bad_request',
        'message' => 'badge must be at most 30 characters.']);
}

// Active roster only: soft-deactivated drivers and non-active employment
// statuses are outside the integration's contract.
$sql = 'SELECT d.badge_number, d.name, d.status, t.name AS terminal,
               d.updated_at,
               (SELECT s.mobile_number FROM driver_sim_assignments a
                  JOIN company_sims s ON s.id = a.sim_id
                 WHERE a.driver_id = d.id AND a.returned_at IS NULL
                 ORDER BY a.id DESC LIMIT 1) AS company_sim,
               (SELECT c.phone_number FROM driver_contact_numbers c
                 WHERE c.driver_id = d.id AND c.is_active = 1
                 ORDER BY c.is_primary DESC, c.id LIMIT 1) AS mobile
          FROM drivers d
          JOIN terminals t ON t.id = d.terminal_id
         WHERE d.is_active = 1 AND d.status = \'active\'';
$params = [];
if ($badge !== '') {
    $sql .= ' AND d.badge_number = ?';
    $params[] = $badge;
}
$sql .= ' ORDER BY d.badge_number';

$stmt = $GLOBALS['pdo']->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$shape = static function (array $r): array {
    return [
        'badge_number' => (string)$r['badge_number'],
        'name'         => (string)$r['name'],
        'status'       => (string)$r['status'],
        'terminal'     => (string)$r['terminal'],
        'company_sim'  => $r['company_sim'] !== null ? (string)$r['company_sim'] : null,
        'mobile'       => $r['mobile'] !== null ? (string)$r['mobile'] : null,
        'updated_at'   => (string)$r['updated_at'],
    ];
};

$actor = mb_substr('api:' . $token['name'], 0, 50);

if ($badge !== '') {
    if ($rows === []) {
        logAudit('API_READ', 'driver', null, null,
            ['token' => $token['name'], 'badge' => $badge, 'found' => false],
            null, $actor);
        apiJson(404, ['ok' => false, 'error' => 'driver_not_found',
            'message' => 'No active driver with that badge number.']);
    }

    logAudit('API_READ', 'driver', null, null,
        ['token' => $token['name'], 'badge' => $badge, 'found' => true],
        null, $actor);
    apiJson(200, ['ok' => true, 'driver' => $shape($rows[0])]);
}

$drivers = array_map($shape, $rows);
logAudit('API_READ', 'drivers', null, null,
    ['token' => $token['name'], 'count' => count($drivers)], null, $actor);
apiJson(200, ['ok' => true, 'count' => count($drivers), 'drivers' => $drivers]);