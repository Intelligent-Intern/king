<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_directory.php';
require_once __DIR__ . '/../http/module_calls.php';

function videochat_calls_list_endpoint_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[calls-list-endpoint-contract] FAIL: {$message}\n");
    exit(1);
}

/**
 * @return array<string, mixed>
 */
function videochat_calls_list_endpoint_decode(array $response): array
{
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    if (!is_array($payload)) {
        return [];
    }

    return $payload;
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-calls-list-endpoint-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $adminIdQuery = $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE lower(users.email) = lower('admin@intelligent-intern.com')
LIMIT 1
SQL
    );
    $adminUserId = (int) $adminIdQuery->fetchColumn();
    videochat_calls_list_endpoint_assert($adminUserId > 0, 'expected seeded admin user');

    $userIdQuery = $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE lower(users.email) = lower('user@intelligent-intern.com')
LIMIT 1
SQL
    );
    $standardUserId = (int) $userIdQuery->fetchColumn();
    videochat_calls_list_endpoint_assert($standardUserId > 0, 'expected seeded standard user');

    $insertCall = $pdo->prepare(
        <<<'SQL'
INSERT INTO calls(
    id, room_id, title, owner_user_id, status, starts_at, ends_at, cancelled_at, cancel_reason, cancel_message, created_at, updated_at
) VALUES(
    :id, :room_id, :title, :owner_user_id, :status, :starts_at, :ends_at, :cancelled_at, :cancel_reason, :cancel_message, :created_at, :updated_at
)
SQL
    );

    $calls = [
        [
            'id' => 'call-endpoint-001',
            'title' => 'Alpha Architecture',
            'owner_user_id' => $adminUserId,
            'status' => 'scheduled',
            'starts_at' => '2026-06-01T09:00:00Z',
            'ends_at' => '2026-06-01T09:30:00Z',
            'cancelled_at' => null,
            'cancel_reason' => null,
            'cancel_message' => null,
        ],
        [
            'id' => 'call-endpoint-002',
            'title' => 'User Launch',
            'owner_user_id' => $standardUserId,
            'status' => 'active',
            'starts_at' => '2026-06-02T09:00:00Z',
            'ends_at' => '2026-06-02T09:30:00Z',
            'cancelled_at' => null,
            'cancel_reason' => null,
            'cancel_message' => null,
        ],
        [
            'id' => 'call-endpoint-003',
            'title' => 'Alpha Retrospective',
            'owner_user_id' => $adminUserId,
            'status' => 'ended',
            'starts_at' => '2026-06-03T09:00:00Z',
            'ends_at' => '2026-06-03T09:30:00Z',
            'cancelled_at' => null,
            'cancel_reason' => null,
            'cancel_message' => null,
        ],
        [
            'id' => 'call-endpoint-004',
            'title' => 'Cancelled Drill',
            'owner_user_id' => $adminUserId,
            'status' => 'cancelled',
            'starts_at' => '2026-06-04T09:00:00Z',
            'ends_at' => '2026-06-04T09:30:00Z',
            'cancelled_at' => '2026-06-03T12:00:00Z',
            'cancel_reason' => 'cancelled by owner',
            'cancel_message' => 'Cancelled for endpoint contract verification.',
        ],
    ];

    foreach ($calls as $call) {
        $insertCall->execute([
            ':id' => $call['id'],
            ':room_id' => 'lobby',
            ':title' => $call['title'],
            ':owner_user_id' => $call['owner_user_id'],
            ':status' => $call['status'],
            ':starts_at' => $call['starts_at'],
            ':ends_at' => $call['ends_at'],
            ':cancelled_at' => $call['cancelled_at'],
            ':cancel_reason' => $call['cancel_reason'],
            ':cancel_message' => $call['cancel_message'],
            ':created_at' => $call['starts_at'],
            ':updated_at' => $call['starts_at'],
        ]);
    }

    $insertParticipant = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_participants(call_id, user_id, email, display_name, source, invite_state, joined_at, left_at)
VALUES(:call_id, :user_id, :email, :display_name, :source, :invite_state, :joined_at, :left_at)
SQL
    );

    $insertParticipant->execute([
        ':call_id' => 'call-endpoint-001',
        ':user_id' => $standardUserId,
        ':email' => 'user@intelligent-intern.com',
        ':display_name' => 'Call User',
        ':source' => 'internal',
        ':invite_state' => 'accepted',
        ':joined_at' => null,
        ':left_at' => null,
    ]);
    $insertParticipant->execute([
        ':call_id' => 'call-endpoint-003',
        ':user_id' => $standardUserId,
        ':email' => 'user@intelligent-intern.com',
        ':display_name' => 'Call User',
        ':source' => 'internal',
        ':invite_state' => 'accepted',
        ':joined_at' => null,
        ':left_at' => null,
    ]);
    $insertParticipant->execute([
        ':call_id' => 'call-endpoint-004',
        ':user_id' => $standardUserId,
        ':email' => 'user@intelligent-intern.com',
        ':display_name' => 'Call User',
        ':source' => 'internal',
        ':invite_state' => 'accepted',
        ':joined_at' => null,
        ':left_at' => null,
    ]);

    $adminSessionId = 'sess_calls_list_admin_contract';
    $userSessionId = 'sess_calls_list_user_contract';
    $insertSession = $pdo->prepare(
        <<<'SQL'
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent)
VALUES(:id, :user_id, :issued_at, :expires_at, NULL, '127.0.0.1', :user_agent)
SQL
    );
    $insertSession->execute([
        ':id' => $adminSessionId,
        ':user_id' => $adminUserId,
        ':issued_at' => gmdate('c', time() - 60),
        ':expires_at' => gmdate('c', time() + 3600),
        ':user_agent' => 'calls-list-endpoint-contract-admin',
    ]);
    $insertSession->execute([
        ':id' => $userSessionId,
        ':user_id' => $standardUserId,
        ':issued_at' => gmdate('c', time() - 60),
        ':expires_at' => gmdate('c', time() + 3600),
        ':user_agent' => 'calls-list-endpoint-contract-user',
    ]);

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
        if (!is_array($decoded)) {
            return [null, 'invalid_json'];
        }

        return [$decoded, null];
    };

    $openDatabase = static function () use ($databasePath): PDO {
        return videochat_open_sqlite_pdo($databasePath);
    };

    $adminAuth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/api/calls?scope=all&page=1&page_size=2',
            'headers' => ['Authorization' => 'Bearer ' . $adminSessionId],
        ],
        'rest'
    );
    videochat_calls_list_endpoint_assert((bool) ($adminAuth['ok'] ?? false), 'expected valid admin auth context');

    $adminGet = videochat_handle_call_routes(
        '/api/calls',
        'GET',
        [
            'method' => 'GET',
            'uri' => '/api/calls?scope=all&page=1&page_size=2',
            'headers' => ['Authorization' => 'Bearer ' . $adminSessionId],
            'body' => '',
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_calls_list_endpoint_assert(is_array($adminGet), 'admin GET /api/calls response must be an array');
    videochat_calls_list_endpoint_assert((int) ($adminGet['status'] ?? 0) === 200, 'admin GET /api/calls status should be 200');
    $adminGetPayload = videochat_calls_list_endpoint_decode($adminGet);
    videochat_calls_list_endpoint_assert((string) ($adminGetPayload['status'] ?? '') === 'ok', 'admin calls-list payload status mismatch');
    videochat_calls_list_endpoint_assert(
        (string) ((($adminGetPayload['filters'] ?? [])['requested_scope'] ?? '')) === 'all',
        'admin calls-list requested_scope mismatch'
    );
    videochat_calls_list_endpoint_assert(
        (string) ((($adminGetPayload['filters'] ?? [])['effective_scope'] ?? '')) === 'all',
        'admin calls-list effective_scope should remain all'
    );
    videochat_calls_list_endpoint_assert(
        (int) ((($adminGetPayload['pagination'] ?? [])['total'] ?? 0)) === 4,
        'admin calls-list total mismatch'
    );
    videochat_calls_list_endpoint_assert(
        (int) ((($adminGetPayload['pagination'] ?? [])['page_count'] ?? 0)) === 2,
        'admin calls-list page_count mismatch'
    );
    videochat_calls_list_endpoint_assert(
        (int) ((($adminGetPayload['pagination'] ?? [])['returned'] ?? 0)) === 2,
        'admin calls-list returned mismatch'
    );
    $adminRows = is_array($adminGetPayload['calls'] ?? null) ? $adminGetPayload['calls'] : [];
    videochat_calls_list_endpoint_assert(count($adminRows) === 2, 'admin calls-list row count mismatch');
    videochat_calls_list_endpoint_assert((string) ($adminRows[0]['id'] ?? '') === 'call-endpoint-001', 'admin calls-list first row order mismatch');
    videochat_calls_list_endpoint_assert((string) ($adminRows[1]['id'] ?? '') === 'call-endpoint-002', 'admin calls-list second row order mismatch');
    videochat_calls_list_endpoint_assert(
        (string) ((($adminGetPayload['sort'] ?? [])['primary'] ?? '')) === 'starts_at_asc',
        'admin calls-list sort primary mismatch'
    );
    videochat_calls_list_endpoint_assert(
        (string) ((($adminGetPayload['sort'] ?? [])['secondary'] ?? '')) === 'created_at_asc',
        'admin calls-list sort secondary mismatch'
    );
    videochat_calls_list_endpoint_assert(
        (string) ((($adminGetPayload['sort'] ?? [])['tie_breaker'] ?? '')) === 'id_asc',
        'admin calls-list sort tie_breaker mismatch'
    );

    $adminSearch = videochat_handle_call_routes(
        '/api/calls',
        'GET',
        [
            'method' => 'GET',
            'uri' => '/api/calls?scope=all&query=alpha&page=1&page_size=10',
            'headers' => ['Authorization' => 'Bearer ' . $adminSessionId],
            'body' => '',
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_calls_list_endpoint_assert(is_array($adminSearch), 'admin search response must be an array');
    videochat_calls_list_endpoint_assert((int) ($adminSearch['status'] ?? 0) === 200, 'admin search status should be 200');
    $adminSearchPayload = videochat_calls_list_endpoint_decode($adminSearch);
    videochat_calls_list_endpoint_assert(
        (int) ((($adminSearchPayload['pagination'] ?? [])['total'] ?? 0)) === 2,
        'admin search total mismatch'
    );

    $invalidFilterResponse = videochat_handle_call_routes(
        '/api/calls',
        'GET',
        [
            'method' => 'GET',
            'uri' => '/api/calls?scope=tenant&status=broken&page=0&page_size=999',
            'headers' => ['Authorization' => 'Bearer ' . $adminSessionId],
            'body' => '',
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_calls_list_endpoint_assert(is_array($invalidFilterResponse), 'invalid-filter response must be an array');
    videochat_calls_list_endpoint_assert((int) ($invalidFilterResponse['status'] ?? 0) === 422, 'invalid-filter status should be 422');
    $invalidFilterPayload = videochat_calls_list_endpoint_decode($invalidFilterResponse);
    videochat_calls_list_endpoint_assert(
        (string) (($invalidFilterPayload['error'] ?? [])['code'] ?? '') === 'calls_list_validation_failed',
        'invalid-filter error code mismatch'
    );
    $invalidFields = (($invalidFilterPayload['error'] ?? [])['details'] ?? [])['fields'] ?? [];
    videochat_calls_list_endpoint_assert(
        (string) ($invalidFields['scope'] ?? '') === 'must_be_my_or_all',
        'invalid-filter scope field mismatch'
    );
    videochat_calls_list_endpoint_assert(
        (string) ($invalidFields['status'] ?? '') === 'must_be_all_or_valid_call_status',
        'invalid-filter status field mismatch'
    );
    videochat_calls_list_endpoint_assert(
        (string) ($invalidFields['page'] ?? '') === 'must_be_integer_greater_than_zero',
        'invalid-filter page field mismatch'
    );
    videochat_calls_list_endpoint_assert(
        (string) ($invalidFields['page_size'] ?? '') === 'must_be_integer_between_1_and_100',
        'invalid-filter page_size field mismatch'
    );

    $userAuth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/api/calls?scope=all&page=1&page_size=10',
            'headers' => ['Authorization' => 'Bearer ' . $userSessionId],
        ],
        'rest'
    );
    videochat_calls_list_endpoint_assert((bool) ($userAuth['ok'] ?? false), 'expected valid user auth context');

    $userScopeAll = videochat_handle_call_routes(
        '/api/calls',
        'GET',
        [
            'method' => 'GET',
            'uri' => '/api/calls?scope=all&page=1&page_size=10',
            'headers' => ['Authorization' => 'Bearer ' . $userSessionId],
            'body' => '',
        ],
        $userAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_calls_list_endpoint_assert(is_array($userScopeAll), 'user scope-all response must be an array');
    videochat_calls_list_endpoint_assert((int) ($userScopeAll['status'] ?? 0) === 200, 'user scope-all status should be 200');
    $userScopeAllPayload = videochat_calls_list_endpoint_decode($userScopeAll);
    videochat_calls_list_endpoint_assert(
        (string) ((($userScopeAllPayload['filters'] ?? [])['requested_scope'] ?? '')) === 'all',
        'user scope-all requested_scope mismatch'
    );
    videochat_calls_list_endpoint_assert(
        (string) ((($userScopeAllPayload['filters'] ?? [])['effective_scope'] ?? '')) === 'my',
        'user scope-all effective_scope should downgrade to my'
    );
    videochat_calls_list_endpoint_assert(
        (int) ((($userScopeAllPayload['pagination'] ?? [])['total'] ?? 0)) === 3,
        'user scope-all/my total mismatch'
    );
    $userRows = is_array($userScopeAllPayload['calls'] ?? null) ? $userScopeAllPayload['calls'] : [];
    videochat_calls_list_endpoint_assert(count($userRows) === 3, 'user scope-all/my row count mismatch');
    videochat_calls_list_endpoint_assert((string) ($userRows[0]['id'] ?? '') === 'call-endpoint-001', 'user calls-list row 1 mismatch');
    videochat_calls_list_endpoint_assert((string) ($userRows[1]['id'] ?? '') === 'call-endpoint-002', 'user calls-list row 2 mismatch');
    videochat_calls_list_endpoint_assert((string) ($userRows[2]['id'] ?? '') === 'call-endpoint-003', 'user calls-list row 3 mismatch');
    $userRowIds = array_values(array_map(
        static fn (array $row): string => (string) ($row['id'] ?? ''),
        $userRows
    ));
    videochat_calls_list_endpoint_assert(
        !in_array('call-endpoint-004', $userRowIds, true),
        'cancelled call must be excluded from user my-scope join semantics'
    );

    $invalidUserContext = videochat_handle_call_routes(
        '/api/calls',
        'GET',
        [
            'method' => 'GET',
            'uri' => '/api/calls',
            'headers' => ['Authorization' => 'Bearer ' . $userSessionId],
            'body' => '',
        ],
        ['user' => ['id' => 0, 'role' => 'user']],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_calls_list_endpoint_assert(is_array($invalidUserContext), 'invalid-user-context response must be an array');
    videochat_calls_list_endpoint_assert((int) ($invalidUserContext['status'] ?? 0) === 401, 'invalid-user-context status should be 401');

    $methodNotAllowed = videochat_handle_call_routes(
        '/api/calls',
        'PUT',
        [
            'method' => 'PUT',
            'uri' => '/api/calls',
            'headers' => ['Authorization' => 'Bearer ' . $adminSessionId],
            'body' => '',
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_calls_list_endpoint_assert(is_array($methodNotAllowed), 'method-not-allowed response must be an array');
    videochat_calls_list_endpoint_assert((int) ($methodNotAllowed['status'] ?? 0) === 405, 'method-not-allowed status should be 405');
    $methodNotAllowedPayload = videochat_calls_list_endpoint_decode($methodNotAllowed);
    videochat_calls_list_endpoint_assert(
        (string) (($methodNotAllowedPayload['error'] ?? [])['code'] ?? '') === 'method_not_allowed',
        'method-not-allowed error code mismatch'
    );

    @unlink($databasePath);
    fwrite(STDOUT, "[calls-list-endpoint-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[calls-list-endpoint-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
