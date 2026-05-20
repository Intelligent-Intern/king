<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../http/module_calls_reactivate.php';

function videochat_call_reactivate_endpoint_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-reactivate-endpoint-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_call_reactivate_endpoint_decode(array $response): array
{
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($payload) ? $payload : [];
}

function videochat_call_reactivate_endpoint_status(PDO $pdo, string $callId): string
{
    $query = $pdo->prepare('SELECT status FROM calls WHERE id = :id LIMIT 1');
    $query->execute([':id' => $callId]);
    return strtolower(trim((string) ($query->fetchColumn() ?: '')));
}

function videochat_call_reactivate_endpoint_participant(PDO $pdo, string $callId, int $userId): array
{
    $query = $pdo->prepare(
        <<<'SQL'
SELECT invite_state, joined_at, left_at, call_role
FROM call_participants
WHERE call_id = :call_id
  AND user_id = :user_id
LIMIT 1
SQL
    );
    $query->execute([
        ':call_id' => $callId,
        ':user_id' => $userId,
    ]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

function videochat_call_reactivate_endpoint_audit_count(PDO $pdo, string $callId): int
{
    $query = $pdo->prepare(
        <<<'SQL'
SELECT COUNT(*)
FROM videochat_audit_events
WHERE call_id = :call_id
  AND event_type = 'call_reactivated'
SQL
    );
    $query->execute([':call_id' => $callId]);
    return max(0, (int) ($query->fetchColumn() ?: 0));
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-call-reactivate-endpoint-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $primaryAdminUserId = (int) $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE users.id = 1
  AND roles.slug = 'admin'
  AND users.status = 'active'
LIMIT 1
SQL
    )->fetchColumn();
    videochat_call_reactivate_endpoint_assert($primaryAdminUserId === 1, 'expected seeded primary admin user #1');

    $standardUserId = (int) $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE roles.slug = 'user'
ORDER BY users.id ASC
LIMIT 1
SQL
    )->fetchColumn();
    videochat_call_reactivate_endpoint_assert($standardUserId > 0, 'expected seeded standard user');

    $adminRoleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'admin' LIMIT 1")->fetchColumn();
    videochat_call_reactivate_endpoint_assert($adminRoleId > 0, 'expected admin role');
    $adminPassword = password_hash('secondary-admin-reactivate', PASSWORD_DEFAULT);
    $insertAdmin = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dark', :updated_at)
SQL
    );
    $insertAdmin->execute([
        ':email' => 'secondary-reactivate-admin@intelligent-intern.com',
        ':display_name' => 'Secondary Reactivate Admin',
        ':password_hash' => $adminPassword,
        ':role_id' => $adminRoleId,
        ':updated_at' => gmdate('c'),
    ]);
    $secondaryAdminUserId = (int) $pdo->lastInsertId();
    videochat_call_reactivate_endpoint_assert($secondaryAdminUserId > 1, 'expected secondary admin user beyond id #1');

    $created = videochat_create_call($pdo, $primaryAdminUserId, [
        'room_id' => 'lobby',
        'title' => 'Reactivate Endpoint Cancelled Contract',
        'starts_at' => '2026-06-12T09:00:00Z',
        'ends_at' => '2026-06-12T10:00:00Z',
        'internal_participant_user_ids' => [$standardUserId],
    ]);
    videochat_call_reactivate_endpoint_assert((bool) ($created['ok'] ?? false), 'setup cancelled call should create');
    $cancelledCallId = (string) (($created['call'] ?? [])['id'] ?? '');
    videochat_call_reactivate_endpoint_assert($cancelledCallId !== '', 'setup cancelled call id should be non-empty');

    $joinedAt = '2026-06-12T09:15:00Z';
    $pdo->prepare('UPDATE call_participants SET invite_state = \'allowed\', joined_at = :joined_at, left_at = NULL WHERE call_id = :call_id')->execute([
        ':joined_at' => $joinedAt,
        ':call_id' => $cancelledCallId,
    ]);

    $cancelResult = videochat_cancel_call($pdo, $cancelledCallId, $primaryAdminUserId, 'admin', [
        'cancel_reason' => 'reactivate_contract',
        'cancel_message' => '<p>Reactivate contract cancellation.</p>',
    ]);
    videochat_call_reactivate_endpoint_assert((bool) ($cancelResult['ok'] ?? false), 'setup cancel should succeed');
    videochat_call_reactivate_endpoint_assert(videochat_call_reactivate_endpoint_status($pdo, $cancelledCallId) === 'cancelled', 'setup call should be cancelled');

    $createdEnded = videochat_create_call($pdo, $primaryAdminUserId, [
        'room_id' => 'lobby',
        'title' => 'Reactivate Endpoint Ended Contract',
        'starts_at' => '2026-06-13T09:00:00Z',
        'ends_at' => '2026-06-13T10:00:00Z',
        'internal_participant_user_ids' => [$standardUserId],
    ]);
    videochat_call_reactivate_endpoint_assert((bool) ($createdEnded['ok'] ?? false), 'setup ended call should create');
    $endedCallId = (string) (($createdEnded['call'] ?? [])['id'] ?? '');
    videochat_call_reactivate_endpoint_assert($endedCallId !== '', 'setup ended call id should be non-empty');
    $endResult = videochat_end_call($pdo, $endedCallId, $primaryAdminUserId, 'admin');
    videochat_call_reactivate_endpoint_assert((bool) ($endResult['ok'] ?? false), 'setup end should succeed');
    videochat_call_reactivate_endpoint_assert(videochat_call_reactivate_endpoint_status($pdo, $endedCallId) === 'ended', 'setup call should be ended');

    $insertSession = $pdo->prepare(
        <<<'SQL'
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent)
VALUES(:id, :user_id, :issued_at, :expires_at, NULL, '127.0.0.1', :user_agent)
SQL
    );
    foreach ([
        'sess_call_reactivate_primary_admin' => [$primaryAdminUserId, 'call-reactivate-primary-admin'],
        'sess_call_reactivate_secondary_admin' => [$secondaryAdminUserId, 'call-reactivate-secondary-admin'],
        'sess_call_reactivate_user' => [$standardUserId, 'call-reactivate-user'],
    ] as $sessionId => [$userId, $agent]) {
        $insertSession->execute([
            ':id' => $sessionId,
            ':user_id' => $userId,
            ':issued_at' => gmdate('c', time() - 60),
            ':expires_at' => gmdate('c', time() + 3600),
            ':user_agent' => $agent,
        ]);
    }

    $jsonResponse = static function (int $status, array $payload): array {
        return [
            'status' => $status,
            'headers' => ['content-type' => 'application/json; charset=utf-8'],
            'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    };

    $errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
        $error = [
            'code' => $code,
            'message' => $message,
        ];
        if ($details !== []) {
            $error['details'] = $details;
        }

        return $jsonResponse($status, [
            'status' => 'error',
            'error' => $error,
            'time' => gmdate('c'),
        ]);
    };

    $decodeJsonBody = static function (array $request): array {
        $body = $request['body'] ?? '';
        if (!is_string($body) || trim($body) === '') {
            return [null, 'empty_body'];
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? [$decoded, null] : [null, 'invalid_json'];
    };

    $openDatabase = static function () use ($databasePath): PDO {
        return videochat_open_sqlite_pdo($databasePath);
    };

    $primaryAuth = videochat_authenticate_request($pdo, [
        'method' => 'POST',
        'uri' => '/api/calls/' . rawurlencode($cancelledCallId) . '/reactivate',
        'headers' => ['Authorization' => 'Bearer sess_call_reactivate_primary_admin'],
    ], 'rest');
    videochat_call_reactivate_endpoint_assert((bool) ($primaryAuth['ok'] ?? false), 'expected primary admin auth');

    $secondaryAuth = videochat_authenticate_request($pdo, [
        'method' => 'POST',
        'uri' => '/api/calls/' . rawurlencode($cancelledCallId) . '/reactivate',
        'headers' => ['Authorization' => 'Bearer sess_call_reactivate_secondary_admin'],
    ], 'rest');
    videochat_call_reactivate_endpoint_assert((bool) ($secondaryAuth['ok'] ?? false), 'expected secondary admin auth');

    $userAuth = videochat_authenticate_request($pdo, [
        'method' => 'POST',
        'uri' => '/api/calls/' . rawurlencode($cancelledCallId) . '/reactivate',
        'headers' => ['Authorization' => 'Bearer sess_call_reactivate_user'],
    ], 'rest');
    videochat_call_reactivate_endpoint_assert((bool) ($userAuth['ok'] ?? false), 'expected standard user auth');

    $secondaryDenied = videochat_handle_call_reactivate_routes(
        '/api/calls/' . rawurlencode($cancelledCallId) . '/reactivate',
        'POST',
        ['body' => json_encode(['confirm' => 'reactivate_call'])],
        $secondaryAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_reactivate_endpoint_assert((int) ($secondaryDenied['status'] ?? 0) === 403, 'secondary admin should not reactivate');

    $userDenied = videochat_handle_call_reactivate_routes(
        '/api/calls/' . rawurlencode($cancelledCallId) . '/reactivate',
        'POST',
        ['body' => json_encode(['confirm' => 'reactivate_call'])],
        $userAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_reactivate_endpoint_assert((int) ($userDenied['status'] ?? 0) === 403, 'standard user should not reactivate');

    $missingConfirm = videochat_handle_call_reactivate_routes(
        '/api/calls/' . rawurlencode($cancelledCallId) . '/reactivate',
        'POST',
        ['body' => json_encode(['confirm' => 'wrong'])],
        $primaryAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_reactivate_endpoint_assert((int) ($missingConfirm['status'] ?? 0) === 422, 'reactivate should require explicit confirm');

    $reactivated = videochat_handle_call_reactivate_routes(
        '/api/calls/' . rawurlencode($cancelledCallId) . '/reactivate',
        'POST',
        ['body' => json_encode(['confirm' => 'reactivate_call'])],
        $primaryAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    $reactivatedPayload = videochat_call_reactivate_endpoint_decode($reactivated);
    videochat_call_reactivate_endpoint_assert((int) ($reactivated['status'] ?? 0) === 200, 'primary admin should reactivate cancelled call');
    videochat_call_reactivate_endpoint_assert((string) (($reactivatedPayload['result'] ?? [])['state'] ?? '') === 'reactivated', 'reactivated payload state mismatch');
    videochat_call_reactivate_endpoint_assert(videochat_call_reactivate_endpoint_status($pdo, $cancelledCallId) === 'active', 'cancelled call should become active');

    $ownerRow = videochat_call_reactivate_endpoint_participant($pdo, $cancelledCallId, $primaryAdminUserId);
    $userRow = videochat_call_reactivate_endpoint_participant($pdo, $cancelledCallId, $standardUserId);
    videochat_call_reactivate_endpoint_assert((string) ($ownerRow['invite_state'] ?? '') === 'allowed', 'reactivated owner should be allowed');
    videochat_call_reactivate_endpoint_assert((string) ($ownerRow['left_at'] ?? '') === '', 'reactivated owner left_at should be clear');
    videochat_call_reactivate_endpoint_assert((string) ($userRow['invite_state'] ?? '') === 'invited', 'reactivated participant should be invited');
    videochat_call_reactivate_endpoint_assert((string) ($userRow['left_at'] ?? '') === '', 'reactivated participant left_at should be clear');
    videochat_call_reactivate_endpoint_assert(videochat_call_reactivate_endpoint_audit_count($pdo, $cancelledCallId) === 1, 'reactivation audit should be recorded once');

    $alreadyActive = videochat_handle_call_reactivate_routes(
        '/api/calls/' . rawurlencode($cancelledCallId) . '/reactivate',
        'POST',
        ['body' => json_encode(['confirm' => 'reactivate_call'])],
        $primaryAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    $alreadyActivePayload = videochat_call_reactivate_endpoint_decode($alreadyActive);
    videochat_call_reactivate_endpoint_assert((int) ($alreadyActive['status'] ?? 0) === 200, 'reactivating an active call should be idempotent');
    videochat_call_reactivate_endpoint_assert((string) (($alreadyActivePayload['result'] ?? [])['state'] ?? '') === 'already_active', 'idempotent active state mismatch');
    videochat_call_reactivate_endpoint_assert(videochat_call_reactivate_endpoint_audit_count($pdo, $cancelledCallId) === 1, 'idempotent active reactivation should not add audit noise');

    $endedReactivated = videochat_handle_call_reactivate_routes(
        '/api/calls/' . rawurlencode($endedCallId) . '/reactivate',
        'POST',
        ['body' => json_encode(['confirm' => 'reactivate_call'])],
        $primaryAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_reactivate_endpoint_assert((int) ($endedReactivated['status'] ?? 0) === 200, 'primary admin should reactivate ended call');
    videochat_call_reactivate_endpoint_assert(videochat_call_reactivate_endpoint_status($pdo, $endedCallId) === 'active', 'ended call should become active');
    videochat_call_reactivate_endpoint_assert(videochat_call_reactivate_endpoint_audit_count($pdo, $endedCallId) === 1, 'ended reactivation audit should be recorded');

    @unlink($databasePath);
} catch (Throwable $exception) {
    fwrite(STDERR, '[call-reactivate-endpoint-contract] FAIL: ' . $exception->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "[call-reactivate-endpoint-contract] PASS\n");
