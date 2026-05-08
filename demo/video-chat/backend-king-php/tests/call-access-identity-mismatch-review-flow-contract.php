<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../http/module_calls.php';

function videochat_identity_mismatch_flow_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-identity-mismatch-review-flow-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_identity_mismatch_flow_role_id(PDO $pdo, string $role): int
{
    $query = $pdo->prepare('SELECT id FROM roles WHERE slug = :slug LIMIT 1');
    $query->execute([':slug' => $role]);
    return (int) $query->fetchColumn();
}

function videochat_identity_mismatch_flow_create_user(PDO $pdo, int $roleId, int $tenantId, string $email, string $displayName): int
{
    $hash = password_hash('call-access-identity-mismatch-review-flow', PASSWORD_DEFAULT);
    videochat_identity_mismatch_flow_assert(is_string($hash) && $hash !== '', 'password hash failed');
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dark', :updated_at)
SQL
    );
    $insert->execute([
        ':email' => strtolower(trim($email)),
        ':display_name' => $displayName,
        ':password_hash' => $hash,
        ':role_id' => $roleId,
        ':updated_at' => gmdate('c'),
    ]);
    $userId = (int) $pdo->lastInsertId();
    videochat_identity_mismatch_flow_assert($userId > 0, 'created user id should be positive');
    videochat_tenant_attach_user($pdo, $userId, $tenantId, $roleId === videochat_identity_mismatch_flow_role_id($pdo, 'admin') ? 'owner' : 'member');
    return $userId;
}

function videochat_identity_mismatch_flow_insert_session(PDO $pdo, string $sessionId, int $userId, int $tenantId): void
{
    $tenantColumn = videochat_tenant_table_has_column($pdo, 'sessions', 'active_tenant_id') ? ', active_tenant_id' : '';
    $tenantValue = $tenantColumn !== '' ? ', :active_tenant_id' : '';
    $insert = $pdo->prepare(
        <<<SQL
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent{$tenantColumn})
VALUES(:id, :user_id, :issued_at, :expires_at, NULL, '127.0.0.1', 'identity-mismatch-flow-contract'{$tenantValue})
SQL
    );
    $params = [
        ':id' => $sessionId,
        ':user_id' => $userId,
        ':issued_at' => gmdate('c', time() - 30),
        ':expires_at' => gmdate('c', time() + 3600),
    ];
    if ($tenantColumn !== '') {
        $params[':active_tenant_id'] = $tenantId;
    }
    $insert->execute($params);
}

/**
 * @return array<string, mixed>
 */
function videochat_identity_mismatch_flow_decode(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<int, string> $needles
 */
function videochat_identity_mismatch_flow_assert_no_needles(array $response, array $needles, string $label): void
{
    $body = strtolower((string) ($response['body'] ?? ''));
    foreach ($needles as $needle) {
        $text = strtolower(trim($needle));
        if ($text === '') {
            continue;
        }
        videochat_identity_mismatch_flow_assert(!str_contains($body, $text), "{$label} leaked {$needle}");
    }
}

/**
 * @return array<string, mixed>
 */
function videochat_identity_mismatch_flow_create_scenario(
    PDO $pdo,
    int $tenantId,
    int $adminRoleId,
    int $userRoleId,
    string $secret,
    string $targetName,
    string $currentName
): array {
    $hostName = 'Private Host ' . $secret;
    $hostUserId = videochat_identity_mismatch_flow_create_user($pdo, $adminRoleId, $tenantId, 'host-' . $secret . '@example.test', $hostName);
    $targetUserId = videochat_identity_mismatch_flow_create_user($pdo, $userRoleId, $tenantId, 'target-' . $secret . '@example.test', $targetName);
    $currentUserId = videochat_identity_mismatch_flow_create_user($pdo, $userRoleId, $tenantId, 'current-' . $secret . '@example.test', $currentName);
    $sessionId = 'sess_identity_' . $secret;
    videochat_identity_mismatch_flow_insert_session($pdo, $sessionId, $currentUserId, $tenantId);

    $createCall = videochat_create_call($pdo, $hostUserId, [
        'title' => 'Identity Mismatch Call ' . $secret,
        'starts_at' => '2026-11-01T09:00:00Z',
        'ends_at' => '2026-11-01T10:00:00Z',
        'internal_participant_user_ids' => [$targetUserId],
    ], $tenantId);
    videochat_identity_mismatch_flow_assert((bool) ($createCall['ok'] ?? false), 'scenario call should be created');
    $callId = (string) (($createCall['call'] ?? [])['id'] ?? '');
    $access = videochat_create_call_access_link_for_user($pdo, $callId, $hostUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $targetUserId,
    ], $tenantId);
    videochat_identity_mismatch_flow_assert((bool) ($access['ok'] ?? false), 'scenario personalized link should be created');

    return [
        'access_id' => (string) (($access['access_link'] ?? [])['id'] ?? ''),
        'call_id' => $callId,
        'host_name' => $hostName,
        'host_email' => 'host-' . $secret . '@example.test',
        'target_user_id' => $targetUserId,
        'target_name' => $targetName,
        'target_email' => 'target-' . $secret . '@example.test',
        'current_user_id' => $currentUserId,
        'current_name' => $currentName,
        'current_session_id' => $sessionId,
    ];
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-identity-mismatch-review-flow-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-access-identity-mismatch-' . bin2hex(random_bytes(6)) . '.sqlite';
    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);
    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $adminRoleId = videochat_identity_mismatch_flow_role_id($pdo, 'admin');
    $userRoleId = videochat_identity_mismatch_flow_role_id($pdo, 'user');
    videochat_identity_mismatch_flow_assert($tenantId > 0 && $adminRoleId > 0 && $userRoleId > 0, 'tenant and roles should exist');

    $jsonResponse = static fn (int $status, array $payload): array => [
        'status' => $status,
        'headers' => ['content-type' => 'application/json; charset=utf-8'],
        'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
    $errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }
        return $jsonResponse($status, ['status' => 'error', 'error' => $error, 'time' => gmdate('c')]);
    };
    $decodeJsonBody = static function (array $request): array {
        $decoded = json_decode((string) ($request['body'] ?? ''), true);
        return is_array($decoded) ? [$decoded, null] : [null, 'invalid_json'];
    };
    $openDatabase = static fn (): PDO => videochat_open_sqlite_pdo($databasePath);
    $callAccessRoute = static function (array $scenario, string $suffix, string $method, string $sessionId, array $body = [], string $issuedSessionId = '') use ($jsonResponse, $errorResponse, $decodeJsonBody, $openDatabase): array {
        $accessId = (string) $scenario['access_id'];
        $path = '/api/call-access/' . $accessId . $suffix;
        return videochat_handle_call_routes($path, $method, [
            'method' => $method,
            'uri' => $path,
            'headers' => [
                'Authorization' => 'Bearer ' . $sessionId,
                'Content-Type' => 'application/json',
                'User-Agent' => 'identity-mismatch-flow-contract',
            ],
            'remote_address' => '127.0.0.1',
            'body' => $body === [] ? '' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ], [], $jsonResponse, $errorResponse, $decodeJsonBody, $openDatabase, static fn (): string => $issuedSessionId !== '' ? $issuedSessionId : ('sess_issued_' . bin2hex(random_bytes(6))));
    };

    $target = ['display_name' => 'Mia Example'];
    videochat_identity_mismatch_flow_assert(videochat_call_access_identity_mismatch($target, ['display_name' => '  mia   example  '])['state'] === 'no_mismatch', 'trimmed matching names should be no mismatch');
    videochat_identity_mismatch_flow_assert(videochat_call_access_identity_mismatch(['display_name' => 'Mia Anne Example'], ['display_name' => 'Mia Example'])['state'] === 'light_mismatch', 'middle-name drift should be light mismatch');
    videochat_identity_mismatch_flow_assert(videochat_call_access_identity_mismatch($target, ['display_name' => 'Nora Example'])['first_name_differs'] === true, 'first name mismatch should be strong');
    videochat_identity_mismatch_flow_assert(videochat_call_access_identity_mismatch($target, ['display_name' => 'Mia Other'])['last_name_differs'] === true, 'last name mismatch should be strong');
    videochat_identity_mismatch_flow_assert(videochat_call_access_identity_mismatch($target, ['display_name' => 'Nora Other'])['strong'] === true, 'full name mismatch should be strong');

    $noMismatch = videochat_identity_mismatch_flow_create_scenario($pdo, $tenantId, $adminRoleId, $userRoleId, 'no' . bin2hex(random_bytes(3)), 'Mia Example', 'Mia Example');
    $noJoin = $callAccessRoute($noMismatch, '/join', 'GET', (string) $noMismatch['current_session_id']);
    videochat_identity_mismatch_flow_assert((int) ($noJoin['status'] ?? 0) === 200, 'no mismatch join preview should resolve');
    videochat_identity_mismatch_flow_assert_no_needles($noJoin, [(string) $noMismatch['target_email']], 'no mismatch join preview');
    $noSession = $callAccessRoute($noMismatch, '/session', 'POST', (string) $noMismatch['current_session_id'], [
        'verified_user_id' => $noMismatch['current_user_id'],
        'verified_session_id' => $noMismatch['current_session_id'],
    ], 'sess_no_mismatch_issued');
    $noPayload = videochat_identity_mismatch_flow_decode($noSession);
    videochat_identity_mismatch_flow_assert((int) ($noSession['status'] ?? 0) === 200, 'no mismatch session should issue');
    videochat_identity_mismatch_flow_assert((int) (((($noPayload['result'] ?? [])['user'] ?? [])['id'] ?? 0)) === (int) $noMismatch['current_user_id'], 'no mismatch session must bind current account');

    $light = videochat_identity_mismatch_flow_create_scenario($pdo, $tenantId, $adminRoleId, $userRoleId, 'light' . bin2hex(random_bytes(3)), 'Mia Anne Example', 'Mia Example');
    $lightJoin = $callAccessRoute($light, '/join', 'GET', (string) $light['current_session_id']);
    $lightPayload = videochat_identity_mismatch_flow_decode($lightJoin);
    videochat_identity_mismatch_flow_assert((int) ($lightJoin['status'] ?? 0) === 200, 'light mismatch join preview should resolve');
    videochat_identity_mismatch_flow_assert((string) (((($lightPayload['result'] ?? [])['identity_mismatch'] ?? [])['state'] ?? '')) === 'light_mismatch', 'light mismatch should be exposed only as non-strong state');
    videochat_identity_mismatch_flow_assert_no_needles($lightJoin, [(string) $light['target_email']], 'light mismatch join preview');

    $strong = videochat_identity_mismatch_flow_create_scenario($pdo, $tenantId, $adminRoleId, $userRoleId, 'strong' . bin2hex(random_bytes(3)), 'Mia Example', 'Nora Other');
    $secretNeedles = [(string) $strong['target_name'], (string) $strong['target_email'], (string) $strong['host_name'], (string) $strong['host_email'], (string) $strong['call_id']];
    $strongJoin = $callAccessRoute($strong, '/join', 'GET', (string) $strong['current_session_id']);
    $strongPayload = videochat_identity_mismatch_flow_decode($strongJoin);
    videochat_identity_mismatch_flow_assert((int) ($strongJoin['status'] ?? 0) === 403, 'strong mismatch join preview should require warning flow');
    videochat_identity_mismatch_flow_assert((string) (((($strongPayload['error'] ?? [])['details'] ?? [])['fields'] ?? [])['host_name'] ?? '') === 'not_verified', 'strong mismatch should require host-name verification');
    videochat_identity_mismatch_flow_assert_no_needles($strongJoin, $secretNeedles, 'strong mismatch join response');

    $wrongHost = $callAccessRoute($strong, '/session', 'POST', (string) $strong['current_session_id'], [
        'verified_user_id' => $strong['current_user_id'],
        'verified_session_id' => $strong['current_session_id'],
        'host_name' => 'Definitely Wrong Host',
    ], 'sess_wrong_host_should_not_issue');
    $wrongPayload = videochat_identity_mismatch_flow_decode($wrongHost);
    videochat_identity_mismatch_flow_assert((int) ($wrongHost['status'] ?? 0) === 403, 'wrong host should not issue session');
    videochat_identity_mismatch_flow_assert((string) (((($wrongPayload['error'] ?? [])['details'] ?? [])['fields'] ?? [])['host_name'] ?? '') === 'wrong_host_name', 'wrong host should return safe field error');
    videochat_identity_mismatch_flow_assert_no_needles($wrongHost, $secretNeedles, 'wrong host response');
    videochat_identity_mismatch_flow_assert((int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE id = 'sess_wrong_host_should_not_issue'")->fetchColumn() === 0, 'wrong host must not persist a session');

    $correct = videochat_identity_mismatch_flow_create_scenario($pdo, $tenantId, $adminRoleId, $userRoleId, 'correct' . bin2hex(random_bytes(3)), 'Mia Example', 'Nora Other');
    $correctSession = $callAccessRoute($correct, '/session', 'POST', (string) $correct['current_session_id'], [
        'verified_user_id' => $correct['current_user_id'],
        'verified_session_id' => $correct['current_session_id'],
        'host_name' => $correct['host_name'],
    ], 'sess_correct_host_issued');
    $correctPayload = videochat_identity_mismatch_flow_decode($correctSession);
    videochat_identity_mismatch_flow_assert((int) ($correctSession['status'] ?? 0) === 200, 'correct host should issue session for current account');
    videochat_identity_mismatch_flow_assert((int) (((($correctPayload['result'] ?? [])['user'] ?? [])['id'] ?? 0)) === (int) $correct['current_user_id'], 'correct host must bind current account');
    videochat_identity_mismatch_flow_assert((int) (((($correctPayload['result'] ?? [])['access_link'] ?? [])['participant_user_id'] ?? 0)) === (int) $correct['current_user_id'], 'correct host response must sanitize access target to current account');
    videochat_identity_mismatch_flow_assert_no_needles($correctSession, [(string) $correct['target_email'], (string) $correct['target_name']], 'correct host response');

    $attempts = $pdo->query("SELECT outcome FROM call_access_host_verification_attempts ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN) ?: [];
    videochat_identity_mismatch_flow_assert(in_array('wrong_host_name', $attempts, true), 'wrong host attempt should be logged');
    videochat_identity_mismatch_flow_assert(in_array('correct_host_name', $attempts, true), 'correct host attempt should be logged');
    $auditTypes = array_map(static fn (array $event): string => (string) ($event['event_type'] ?? ''), videochat_audit_fetch_events($pdo, ['limit' => 100]));
    videochat_identity_mismatch_flow_assert(in_array('call_access_strong_mismatch_denied', $auditTypes, true), 'strong mismatch denial should be audit-logged');
    videochat_identity_mismatch_flow_assert(in_array('call_access_host_name_rejected', $auditTypes, true), 'failed host verification should be audit-logged');
    videochat_identity_mismatch_flow_assert(in_array('call_access_host_name_verified', $auditTypes, true), 'successful host verification should be audit-logged');

    @unlink($databasePath);
    fwrite(STDOUT, "[call-access-identity-mismatch-review-flow-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-identity-mismatch-review-flow-contract] ERROR: ' . $error->getMessage() . "\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
} finally {
    if (isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
