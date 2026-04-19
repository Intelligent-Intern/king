<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_directory.php';
require_once __DIR__ . '/../http/module_calls.php';

function videochat_call_create_endpoint_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-create-endpoint-contract] FAIL: {$message}\n");
    exit(1);
}

/**
 * @return array<string, mixed>
 */
function videochat_call_create_endpoint_decode(array $response): array
{
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    if (!is_array($payload)) {
        return [];
    }

    return $payload;
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-call-create-endpoint-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $adminUserId = (int) $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE lower(users.email) = lower('admin@intelligent-intern.com')
LIMIT 1
SQL
    )->fetchColumn();
    videochat_call_create_endpoint_assert($adminUserId > 0, 'expected seeded admin user');

    $standardUserId = (int) $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE lower(users.email) = lower('user@intelligent-intern.com')
LIMIT 1
SQL
    )->fetchColumn();
    videochat_call_create_endpoint_assert($standardUserId > 0, 'expected seeded standard user');

    $standardRoleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'user' LIMIT 1")->fetchColumn();
    videochat_call_create_endpoint_assert($standardRoleId > 0, 'expected user role');

    $extraUserPassword = password_hash('participant123', PASSWORD_DEFAULT);
    videochat_call_create_endpoint_assert(is_string($extraUserPassword) && $extraUserPassword !== '', 'extra user password hash failed');
    $insertExtraUser = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dark', :updated_at)
SQL
    );
    $insertExtraUser->execute([
        ':email' => 'participant-create-endpoint@intelligent-intern.com',
        ':display_name' => 'Participant Create Endpoint',
        ':password_hash' => $extraUserPassword,
        ':role_id' => $standardRoleId,
        ':updated_at' => gmdate('c'),
    ]);
    $extraUserId = (int) $pdo->lastInsertId();
    videochat_call_create_endpoint_assert($extraUserId > 0, 'expected inserted extra user');

    $adminSessionId = 'sess_call_create_endpoint_admin';
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
        ':user_agent' => 'call-create-endpoint-contract-admin',
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
            'method' => 'POST',
            'uri' => '/api/calls',
            'headers' => ['Authorization' => 'Bearer ' . $adminSessionId],
        ],
        'rest'
    );
    videochat_call_create_endpoint_assert((bool) ($adminAuth['ok'] ?? false), 'expected valid admin auth context');

    $requestTemplate = [
        'uri' => '/api/calls',
        'headers' => ['Authorization' => 'Bearer ' . $adminSessionId],
        'remote_address' => '127.0.0.1',
    ];

    $invalidJson = videochat_handle_call_routes(
        '/api/calls',
        'POST',
        [...$requestTemplate, 'method' => 'POST', 'body' => 'not-json'],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_create_endpoint_assert(is_array($invalidJson), 'invalid-json create response must be an array');
    videochat_call_create_endpoint_assert((int) ($invalidJson['status'] ?? 0) === 400, 'invalid-json create status should be 400');
    $invalidJsonPayload = videochat_call_create_endpoint_decode($invalidJson);
    videochat_call_create_endpoint_assert(
        (string) (($invalidJsonPayload['error'] ?? [])['code'] ?? '') === 'calls_create_invalid_request_body',
        'invalid-json create error code mismatch'
    );

    $invalidPayload = videochat_handle_call_routes(
        '/api/calls',
        'POST',
        [
            ...$requestTemplate,
            'method' => 'POST',
            'body' => json_encode([
                'title' => '',
                'starts_at' => 'not-a-date',
                'ends_at' => '2026-06-01T10:00:00Z',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_create_endpoint_assert(is_array($invalidPayload), 'invalid-payload create response must be an array');
    videochat_call_create_endpoint_assert((int) ($invalidPayload['status'] ?? 0) === 422, 'invalid-payload create status should be 422');
    $invalidPayloadBody = videochat_call_create_endpoint_decode($invalidPayload);
    videochat_call_create_endpoint_assert(
        (string) (($invalidPayloadBody['error'] ?? [])['code'] ?? '') === 'calls_create_validation_failed',
        'invalid-payload create error code mismatch'
    );
    videochat_call_create_endpoint_assert(
        (string) (((($invalidPayloadBody['error'] ?? [])['details'] ?? [])['fields'] ?? [])['title'] ?? '') === 'required_title',
        'invalid-payload create title field mismatch'
    );

    $unknownInternal = videochat_handle_call_routes(
        '/api/calls',
        'POST',
        [
            ...$requestTemplate,
            'method' => 'POST',
            'body' => json_encode([
                'title' => 'Invalid Internal User',
                'starts_at' => '2026-06-01T10:00:00Z',
                'ends_at' => '2026-06-01T11:00:00Z',
                'internal_participant_user_ids' => [$standardUserId, 999999],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_create_endpoint_assert(is_array($unknownInternal), 'unknown-internal create response must be an array');
    videochat_call_create_endpoint_assert((int) ($unknownInternal['status'] ?? 0) === 422, 'unknown-internal create status should be 422');
    $unknownInternalBody = videochat_call_create_endpoint_decode($unknownInternal);
    videochat_call_create_endpoint_assert(
        (string) (((($unknownInternalBody['error'] ?? [])['details'] ?? [])['fields'] ?? [])['internal_participant_user_ids'] ?? '') === 'contains_unknown_or_inactive_user',
        'unknown-internal create field mismatch'
    );

    $validCreate = videochat_handle_call_routes(
        '/api/calls',
        'POST',
        [
            ...$requestTemplate,
            'method' => 'POST',
            'body' => json_encode([
                'room_id' => 'lobby',
                'title' => 'Weekly Product Sync Endpoint',
                'access_mode' => 'free_for_all',
                'starts_at' => '2026-06-02T09:00:00Z',
                'ends_at' => '2026-06-02T10:00:00Z',
                'internal_participant_user_ids' => [$standardUserId, $extraUserId],
                'external_participants' => [
                    ['email' => 'guest-a@example.com', 'display_name' => 'Guest A'],
                    ['email' => 'guest-b@example.com', 'display_name' => 'Guest B'],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_create_endpoint_assert(is_array($validCreate), 'valid create response must be an array');
    videochat_call_create_endpoint_assert((int) ($validCreate['status'] ?? 0) === 201, 'valid create status should be 201');
    $validCreateBody = videochat_call_create_endpoint_decode($validCreate);
    videochat_call_create_endpoint_assert((string) ($validCreateBody['status'] ?? '') === 'ok', 'valid create payload status mismatch');
    videochat_call_create_endpoint_assert(
        (string) ((($validCreateBody['result'] ?? [])['state'] ?? '')) === 'created',
        'valid create result state mismatch'
    );

    $createdCall = (($validCreateBody['result'] ?? [])['call'] ?? null);
    videochat_call_create_endpoint_assert(is_array($createdCall), 'valid create should return call envelope');
    $callId = (string) ($createdCall['id'] ?? '');
    videochat_call_create_endpoint_assert($callId !== '', 'created call id should be non-empty');
    videochat_call_create_endpoint_assert((string) ($createdCall['room_id'] ?? '') === $callId, 'created call must use a dedicated room id');
    videochat_call_create_endpoint_assert((string) ($createdCall['status'] ?? '') === 'scheduled', 'created call status mismatch');
    videochat_call_create_endpoint_assert((string) ($createdCall['title'] ?? '') === 'Weekly Product Sync Endpoint', 'created call title mismatch');
    videochat_call_create_endpoint_assert(
        (int) ((($createdCall['owner'] ?? [])['user_id'] ?? 0)) === $adminUserId,
        'created call owner mismatch'
    );
    videochat_call_create_endpoint_assert(
        (int) (((($createdCall['participants'] ?? [])['totals'] ?? [])['total'] ?? 0)) === 5,
        'created call participant total mismatch'
    );
    videochat_call_create_endpoint_assert(
        (int) (((($createdCall['participants'] ?? [])['totals'] ?? [])['internal'] ?? 0)) === 3,
        'created call internal participant total mismatch'
    );
    videochat_call_create_endpoint_assert(
        (int) (((($createdCall['participants'] ?? [])['totals'] ?? [])['external'] ?? 0)) === 2,
        'created call external participant total mismatch'
    );
    videochat_call_create_endpoint_assert(
        (string) ($createdCall['access_mode'] ?? '') === 'free_for_all',
        'created call access_mode mismatch'
    );

    $roomRowQuery = $pdo->prepare('SELECT id, name, visibility, status, created_by_user_id FROM rooms WHERE id = :id LIMIT 1');
    $roomRowQuery->execute([':id' => $callId]);
    $roomRow = $roomRowQuery->fetch();
    videochat_call_create_endpoint_assert(is_array($roomRow), 'created call room must exist in database');
    videochat_call_create_endpoint_assert((string) ($roomRow['id'] ?? '') === $callId, 'created call room id mismatch');
    videochat_call_create_endpoint_assert((string) ($roomRow['name'] ?? '') === 'Weekly Product Sync Endpoint', 'created call room name mismatch');
    videochat_call_create_endpoint_assert((string) ($roomRow['visibility'] ?? '') === 'private', 'created call room visibility mismatch');
    videochat_call_create_endpoint_assert((string) ($roomRow['status'] ?? '') === 'active', 'created call room status mismatch');
    videochat_call_create_endpoint_assert((int) ($roomRow['created_by_user_id'] ?? 0) === $adminUserId, 'created call room owner mismatch');

    $callRowQuery = $pdo->prepare('SELECT id, room_id, owner_user_id, status, title, access_mode FROM calls WHERE id = :id LIMIT 1');
    $callRowQuery->execute([':id' => $callId]);
    $callRow = $callRowQuery->fetch();
    videochat_call_create_endpoint_assert(is_array($callRow), 'created call row must exist in database');
    videochat_call_create_endpoint_assert((string) ($callRow['room_id'] ?? '') === $callId, 'persisted call room_id must use dedicated room');
    videochat_call_create_endpoint_assert((int) ($callRow['owner_user_id'] ?? 0) === $adminUserId, 'persisted owner mismatch');
    videochat_call_create_endpoint_assert((string) ($callRow['status'] ?? '') === 'scheduled', 'persisted status mismatch');
    videochat_call_create_endpoint_assert((string) ($callRow['title'] ?? '') === 'Weekly Product Sync Endpoint', 'persisted title mismatch');
    videochat_call_create_endpoint_assert((string) ($callRow['access_mode'] ?? '') === 'free_for_all', 'persisted access_mode mismatch');

    $participantCountQuery = $pdo->prepare('SELECT COUNT(*) FROM call_participants WHERE call_id = :call_id');
    $participantCountQuery->execute([':call_id' => $callId]);
    $participantCount = (int) $participantCountQuery->fetchColumn();
    videochat_call_create_endpoint_assert($participantCount === 5, 'persisted participant row count mismatch');

    $callsCountBeforeDuplicate = (int) $pdo->query('SELECT COUNT(*) FROM calls')->fetchColumn();
    $duplicateExternal = videochat_handle_call_routes(
        '/api/calls',
        'POST',
        [
            ...$requestTemplate,
            'method' => 'POST',
            'body' => json_encode([
                'title' => 'Duplicate External',
                'starts_at' => '2026-06-03T09:00:00Z',
                'ends_at' => '2026-06-03T10:00:00Z',
                'internal_participant_user_ids' => [$standardUserId],
                'external_participants' => [
                    ['email' => 'user@intelligent-intern.com', 'display_name' => 'Duplicate User Email'],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_create_endpoint_assert(is_array($duplicateExternal), 'duplicate-external create response must be an array');
    videochat_call_create_endpoint_assert((int) ($duplicateExternal['status'] ?? 0) === 422, 'duplicate-external create status should be 422');
    $duplicateExternalBody = videochat_call_create_endpoint_decode($duplicateExternal);
    videochat_call_create_endpoint_assert(
        (string) (((($duplicateExternalBody['error'] ?? [])['details'] ?? [])['fields'] ?? [])['external_participants.0.email'] ?? '') === 'duplicates_internal_participant',
        'duplicate-external create field mismatch'
    );
    $callsCountAfterDuplicate = (int) $pdo->query('SELECT COUNT(*) FROM calls')->fetchColumn();
    videochat_call_create_endpoint_assert(
        $callsCountAfterDuplicate === $callsCountBeforeDuplicate,
        'failed duplicate-external create must not persist a partial call row'
    );

    $listAfterCreate = videochat_handle_call_routes(
        '/api/calls',
        'GET',
        [
            ...$requestTemplate,
            'method' => 'GET',
            'uri' => '/api/calls?scope=my&status=scheduled&query=weekly%20product%20sync%20endpoint&page=1&page_size=10',
            'body' => '',
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_create_endpoint_assert(is_array($listAfterCreate), 'list-after-create response must be an array');
    videochat_call_create_endpoint_assert((int) ($listAfterCreate['status'] ?? 0) === 200, 'list-after-create status should be 200');
    $listAfterCreateBody = videochat_call_create_endpoint_decode($listAfterCreate);
    videochat_call_create_endpoint_assert(
        (int) ((($listAfterCreateBody['pagination'] ?? [])['total'] ?? 0)) === 1,
        'list-after-create total mismatch'
    );
    $listRows = is_array($listAfterCreateBody['calls'] ?? null) ? $listAfterCreateBody['calls'] : [];
    videochat_call_create_endpoint_assert(count($listRows) === 1, 'list-after-create row count mismatch');
    videochat_call_create_endpoint_assert((string) ($listRows[0]['id'] ?? '') === $callId, 'list-after-create row id mismatch');

    $invalidUserContext = videochat_handle_call_routes(
        '/api/calls',
        'POST',
        [...$requestTemplate, 'method' => 'POST', 'body' => '{}'],
        ['user' => ['id' => 0, 'role' => 'admin']],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_create_endpoint_assert(is_array($invalidUserContext), 'invalid-user-context create response must be an array');
    videochat_call_create_endpoint_assert((int) ($invalidUserContext['status'] ?? 0) === 401, 'invalid-user-context create status should be 401');

    $methodNotAllowed = videochat_handle_call_routes(
        '/api/calls',
        'PUT',
        [...$requestTemplate, 'method' => 'PUT', 'body' => ''],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_create_endpoint_assert(is_array($methodNotAllowed), 'method-not-allowed create response must be an array');
    videochat_call_create_endpoint_assert((int) ($methodNotAllowed['status'] ?? 0) === 405, 'method-not-allowed create status should be 405');
    $methodNotAllowedBody = videochat_call_create_endpoint_decode($methodNotAllowed);
    videochat_call_create_endpoint_assert(
        (string) (($methodNotAllowedBody['error'] ?? [])['code'] ?? '') === 'method_not_allowed',
        'method-not-allowed create error code mismatch'
    );

    @unlink($databasePath);
    fwrite(STDOUT, "[call-create-endpoint-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[call-create-endpoint-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
