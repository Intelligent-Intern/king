<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../http/module_calls.php';

function videochat_call_update_endpoint_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-update-endpoint-contract] FAIL: {$message}\n");
    exit(1);
}

/**
 * @return array<string, mixed>
 */
function videochat_call_update_endpoint_decode(array $response): array
{
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    if (!is_array($payload)) {
        return [];
    }

    return $payload;
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-call-update-endpoint-' . bin2hex(random_bytes(6)) . '.sqlite';
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
    videochat_call_update_endpoint_assert($adminUserId > 0, 'expected seeded admin user');

    $standardUserId = (int) $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE lower(users.email) = lower('user@intelligent-intern.com')
LIMIT 1
SQL
    )->fetchColumn();
    videochat_call_update_endpoint_assert($standardUserId > 0, 'expected seeded standard user');

    $moderatorRoleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'moderator' LIMIT 1")->fetchColumn();
    videochat_call_update_endpoint_assert($moderatorRoleId > 0, 'expected moderator role');

    $moderatorPassword = password_hash('moderator123', PASSWORD_DEFAULT);
    videochat_call_update_endpoint_assert(is_string($moderatorPassword) && $moderatorPassword !== '', 'moderator password hash failed');
    $insertModerator = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dark', :updated_at)
SQL
    );
    $insertModerator->execute([
        ':email' => 'moderator-update-endpoint@intelligent-intern.com',
        ':display_name' => 'Moderator Update Endpoint',
        ':password_hash' => $moderatorPassword,
        ':role_id' => $moderatorRoleId,
        ':updated_at' => gmdate('c'),
    ]);
    $moderatorUserId = (int) $pdo->lastInsertId();
    videochat_call_update_endpoint_assert($moderatorUserId > 0, 'expected inserted moderator user');

    $created = videochat_create_call($pdo, $adminUserId, [
        'room_id' => 'lobby',
        'title' => 'Before Endpoint Update',
        'starts_at' => '2026-06-10T09:00:00Z',
        'ends_at' => '2026-06-10T10:00:00Z',
        'internal_participant_user_ids' => [$standardUserId],
        'external_participants' => [
            ['email' => 'first-guest@example.com', 'display_name' => 'First Guest'],
        ],
    ]);
    videochat_call_update_endpoint_assert((bool) ($created['ok'] ?? false), 'setup create call should succeed');
    $callId = (string) (($created['call'] ?? [])['id'] ?? '');
    videochat_call_update_endpoint_assert($callId !== '', 'setup call id should be non-empty');

    $adminSessionId = 'sess_call_update_endpoint_admin';
    $userSessionId = 'sess_call_update_endpoint_user';
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
        ':user_agent' => 'call-update-endpoint-contract-admin',
    ]);
    $insertSession->execute([
        ':id' => $userSessionId,
        ':user_id' => $standardUserId,
        ':issued_at' => gmdate('c', time() - 60),
        ':expires_at' => gmdate('c', time() + 3600),
        ':user_agent' => 'call-update-endpoint-contract-user',
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
            'method' => 'PATCH',
            'uri' => '/api/calls/' . $callId,
            'headers' => ['Authorization' => 'Bearer ' . $adminSessionId],
        ],
        'rest'
    );
    videochat_call_update_endpoint_assert((bool) ($adminAuth['ok'] ?? false), 'expected valid admin auth context');

    $userAuth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'PATCH',
            'uri' => '/api/calls/' . $callId,
            'headers' => ['Authorization' => 'Bearer ' . $userSessionId],
        ],
        'rest'
    );
    videochat_call_update_endpoint_assert((bool) ($userAuth['ok'] ?? false), 'expected valid user auth context');

    $adminRequestTemplate = [
        'uri' => '/api/calls/' . $callId,
        'headers' => ['Authorization' => 'Bearer ' . $adminSessionId],
        'remote_address' => '127.0.0.1',
    ];

    $invalidJson = videochat_handle_call_routes(
        '/api/calls/' . $callId,
        'PATCH',
        [...$adminRequestTemplate, 'method' => 'PATCH', 'body' => 'not-json'],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_update_endpoint_assert(is_array($invalidJson), 'invalid-json update response must be an array');
    videochat_call_update_endpoint_assert((int) ($invalidJson['status'] ?? 0) === 400, 'invalid-json update status should be 400');
    $invalidJsonPayload = videochat_call_update_endpoint_decode($invalidJson);
    videochat_call_update_endpoint_assert(
        (string) (($invalidJsonPayload['error'] ?? [])['code'] ?? '') === 'calls_update_invalid_request_body',
        'invalid-json update error code mismatch'
    );

    $emptyPayload = videochat_handle_call_routes(
        '/api/calls/' . $callId,
        'PATCH',
        [...$adminRequestTemplate, 'method' => 'PATCH', 'body' => json_encode([], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_update_endpoint_assert(is_array($emptyPayload), 'empty-payload update response must be an array');
    videochat_call_update_endpoint_assert((int) ($emptyPayload['status'] ?? 0) === 422, 'empty-payload update status should be 422');
    $emptyPayloadBody = videochat_call_update_endpoint_decode($emptyPayload);
    videochat_call_update_endpoint_assert(
        (string) (((($emptyPayloadBody['error'] ?? [])['details'] ?? [])['fields'] ?? [])['payload'] ?? '') === 'at_least_one_supported_field_required',
        'empty-payload update field mismatch'
    );

    $resendRequested = videochat_handle_call_routes(
        '/api/calls/' . $callId,
        'PATCH',
        [
            ...$adminRequestTemplate,
            'method' => 'PATCH',
            'body' => json_encode([
                'resend_invites' => true,
                'title' => 'Attempted Resend',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_update_endpoint_assert(is_array($resendRequested), 'resend-requested update response must be an array');
    videochat_call_update_endpoint_assert((int) ($resendRequested['status'] ?? 0) === 422, 'resend-requested update status should be 422');
    $resendRequestedBody = videochat_call_update_endpoint_decode($resendRequested);
    videochat_call_update_endpoint_assert(
        (string) (((($resendRequestedBody['error'] ?? [])['details'] ?? [])['fields'] ?? [])['resend_invites'] ?? '') === 'global_invite_resend_not_supported_use_explicit_action',
        'resend-requested update field mismatch'
    );

    $forbiddenUpdate = videochat_handle_call_routes(
        '/api/calls/' . $callId,
        'PATCH',
        [
            'method' => 'PATCH',
            'uri' => '/api/calls/' . $callId,
            'headers' => ['Authorization' => 'Bearer ' . $userSessionId],
            'remote_address' => '127.0.0.1',
            'body' => json_encode([
                'title' => 'User Should Not Edit',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $userAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_update_endpoint_assert(is_array($forbiddenUpdate), 'forbidden update response must be an array');
    videochat_call_update_endpoint_assert((int) ($forbiddenUpdate['status'] ?? 0) === 403, 'forbidden update status should be 403');
    $forbiddenUpdateBody = videochat_call_update_endpoint_decode($forbiddenUpdate);
    videochat_call_update_endpoint_assert(
        (string) (($forbiddenUpdateBody['error'] ?? [])['code'] ?? '') === 'calls_forbidden',
        'forbidden update error code mismatch'
    );

    $missingCallUpdate = videochat_handle_call_routes(
        '/api/calls/call_missing_endpoint_contract',
        'PATCH',
        [
            ...$adminRequestTemplate,
            'method' => 'PATCH',
            'uri' => '/api/calls/call_missing_endpoint_contract',
            'body' => json_encode([
                'title' => 'Missing',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_update_endpoint_assert(is_array($missingCallUpdate), 'missing-call update response must be an array');
    videochat_call_update_endpoint_assert((int) ($missingCallUpdate['status'] ?? 0) === 404, 'missing-call update status should be 404');
    $missingCallBody = videochat_call_update_endpoint_decode($missingCallUpdate);
    videochat_call_update_endpoint_assert(
        (string) (($missingCallBody['error'] ?? [])['code'] ?? '') === 'calls_not_found',
        'missing-call update error code mismatch'
    );

    $validUpdate = videochat_handle_call_routes(
        '/api/calls/' . $callId,
        'PATCH',
        [
            ...$adminRequestTemplate,
            'method' => 'PATCH',
            'body' => json_encode([
                'title' => 'After Endpoint Update',
                'starts_at' => '2026-06-10T11:00:00Z',
                'ends_at' => '2026-06-10T12:00:00Z',
                'internal_participant_user_ids' => [$moderatorUserId],
                'external_participants' => [
                    ['email' => 'second-guest@example.com', 'display_name' => 'Second Guest'],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_update_endpoint_assert(is_array($validUpdate), 'valid update response must be an array');
    videochat_call_update_endpoint_assert((int) ($validUpdate['status'] ?? 0) === 200, 'valid update status should be 200');
    $validUpdateBody = videochat_call_update_endpoint_decode($validUpdate);
    videochat_call_update_endpoint_assert((string) ($validUpdateBody['status'] ?? '') === 'ok', 'valid update payload status mismatch');
    videochat_call_update_endpoint_assert(
        (string) ((($validUpdateBody['result'] ?? [])['state'] ?? '')) === 'updated',
        'valid update result state mismatch'
    );

    $updatedCall = (($validUpdateBody['result'] ?? [])['call'] ?? null);
    videochat_call_update_endpoint_assert(is_array($updatedCall), 'valid update should return call envelope');
    videochat_call_update_endpoint_assert((string) ($updatedCall['title'] ?? '') === 'After Endpoint Update', 'valid update title mismatch');
    videochat_call_update_endpoint_assert((string) ($updatedCall['starts_at'] ?? '') === '2026-06-10T11:00:00+00:00', 'valid update starts_at mismatch');
    videochat_call_update_endpoint_assert((string) ($updatedCall['ends_at'] ?? '') === '2026-06-10T12:00:00+00:00', 'valid update ends_at mismatch');
    videochat_call_update_endpoint_assert(
        (int) (((($updatedCall['participants'] ?? [])['totals'] ?? [])['total'] ?? 0)) === 3,
        'valid update participant total mismatch'
    );
    videochat_call_update_endpoint_assert(
        (int) (((($updatedCall['participants'] ?? [])['totals'] ?? [])['internal'] ?? 0)) === 2,
        'valid update internal participant total mismatch'
    );
    videochat_call_update_endpoint_assert(
        (int) (((($updatedCall['participants'] ?? [])['totals'] ?? [])['external'] ?? 0)) === 1,
        'valid update external participant total mismatch'
    );
    videochat_call_update_endpoint_assert(
        ((($validUpdateBody['result'] ?? [])['invite_dispatch']['global_resend_triggered'] ?? null) === false),
        'valid update must not trigger global invite resend'
    );
    videochat_call_update_endpoint_assert(
        ((($validUpdateBody['result'] ?? [])['invite_dispatch']['explicit_action_required'] ?? null) === true),
        'valid update should require explicit invite action'
    );

    $participantRows = $pdo->prepare(
        <<<'SQL'
SELECT email, source
FROM call_participants
WHERE call_id = :call_id
ORDER BY
    CASE source WHEN 'internal' THEN 0 ELSE 1 END ASC,
    email ASC
SQL
    );
    $participantRows->execute([':call_id' => $callId]);
    $participants = $participantRows->fetchAll();
    videochat_call_update_endpoint_assert(is_array($participants) && count($participants) === 3, 'persisted participant rows count mismatch after valid update');
    videochat_call_update_endpoint_assert((string) ($participants[0]['email'] ?? '') === 'admin@intelligent-intern.com', 'owner participant should remain after valid update');
    videochat_call_update_endpoint_assert((string) ($participants[1]['email'] ?? '') === 'moderator-update-endpoint@intelligent-intern.com', 'new internal participant missing after valid update');
    videochat_call_update_endpoint_assert((string) ($participants[2]['email'] ?? '') === 'second-guest@example.com', 'replacement external participant missing after valid update');

    $callRowBeforeInvalid = $pdo->prepare('SELECT title, updated_at FROM calls WHERE id = :id LIMIT 1');
    $callRowBeforeInvalid->execute([':id' => $callId]);
    $beforeInvalid = $callRowBeforeInvalid->fetch();
    videochat_call_update_endpoint_assert(is_array($beforeInvalid), 'expected call row before invalid update');
    $titleBeforeInvalid = (string) ($beforeInvalid['title'] ?? '');

    $duplicateExternalUpdate = videochat_handle_call_routes(
        '/api/calls/' . $callId,
        'PATCH',
        [
            ...$adminRequestTemplate,
            'method' => 'PATCH',
            'body' => json_encode([
                'title' => 'Should Not Persist',
                'external_participants' => [
                    ['email' => 'moderator-update-endpoint@intelligent-intern.com', 'display_name' => 'Duplicate Internal'],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_update_endpoint_assert(is_array($duplicateExternalUpdate), 'duplicate-external update response must be an array');
    videochat_call_update_endpoint_assert((int) ($duplicateExternalUpdate['status'] ?? 0) === 422, 'duplicate-external update status should be 422');
    $duplicateExternalBody = videochat_call_update_endpoint_decode($duplicateExternalUpdate);
    videochat_call_update_endpoint_assert(
        (string) (((($duplicateExternalBody['error'] ?? [])['details'] ?? [])['fields'] ?? [])['external_participants.0.email'] ?? '') === 'duplicates_internal_participant',
        'duplicate-external update field mismatch'
    );

    $callRowAfterInvalid = $pdo->prepare('SELECT title FROM calls WHERE id = :id LIMIT 1');
    $callRowAfterInvalid->execute([':id' => $callId]);
    $afterInvalid = $callRowAfterInvalid->fetch();
    videochat_call_update_endpoint_assert(is_array($afterInvalid), 'expected call row after invalid update');
    videochat_call_update_endpoint_assert(
        (string) ($afterInvalid['title'] ?? '') === $titleBeforeInvalid,
        'invalid duplicate-external update must not persist partial call title mutation'
    );

    $participantRowsAfterInvalid = $pdo->prepare('SELECT COUNT(*) FROM call_participants WHERE call_id = :call_id');
    $participantRowsAfterInvalid->execute([':call_id' => $callId]);
    videochat_call_update_endpoint_assert(
        (int) $participantRowsAfterInvalid->fetchColumn() === 3,
        'invalid duplicate-external update must not mutate participant row count'
    );

    $setCancelled = $pdo->prepare(
        'UPDATE calls SET status = :status, cancelled_at = :cancelled_at, cancel_reason = :cancel_reason WHERE id = :id'
    );
    $setCancelled->execute([
        ':status' => 'cancelled',
        ':cancelled_at' => gmdate('c'),
        ':cancel_reason' => 'cancelled',
        ':id' => $callId,
    ]);
    $immutableCancelled = videochat_handle_call_routes(
        '/api/calls/' . $callId,
        'PATCH',
        [
            ...$adminRequestTemplate,
            'method' => 'PATCH',
            'body' => json_encode([
                'title' => 'Should Not Update Cancelled',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_update_endpoint_assert(is_array($immutableCancelled), 'immutable-cancelled update response must be an array');
    videochat_call_update_endpoint_assert((int) ($immutableCancelled['status'] ?? 0) === 422, 'immutable-cancelled update status should be 422');
    $immutableCancelledBody = videochat_call_update_endpoint_decode($immutableCancelled);
    videochat_call_update_endpoint_assert(
        (string) (((($immutableCancelledBody['error'] ?? [])['details'] ?? [])['fields'] ?? [])['status'] ?? '') === 'immutable_for_edit',
        'immutable-cancelled update field mismatch'
    );

    $methodNotAllowed = videochat_handle_call_routes(
        '/api/calls/' . $callId,
        'GET',
        [...$adminRequestTemplate, 'method' => 'GET', 'body' => ''],
        $adminAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_update_endpoint_assert(is_array($methodNotAllowed), 'method-not-allowed update response must be an array');
    videochat_call_update_endpoint_assert((int) ($methodNotAllowed['status'] ?? 0) === 405, 'method-not-allowed update status should be 405');

    $invalidContext = videochat_handle_call_routes(
        '/api/calls/' . $callId,
        'PATCH',
        [...$adminRequestTemplate, 'method' => 'PATCH', 'body' => json_encode(['title' => 'nope'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
        ['user' => ['id' => 0, 'role' => 'admin']],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_update_endpoint_assert(is_array($invalidContext), 'invalid-context update response must be an array');
    videochat_call_update_endpoint_assert((int) ($invalidContext['status'] ?? 0) === 401, 'invalid-context update status should be 401');

    @unlink($databasePath);
    fwrite(STDOUT, "[call-update-endpoint-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[call-update-endpoint-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
