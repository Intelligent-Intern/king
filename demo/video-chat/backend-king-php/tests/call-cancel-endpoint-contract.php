<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_directory.php';
require_once __DIR__ . '/../http/module_calls.php';

function videochat_call_cancel_endpoint_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-cancel-endpoint-contract] FAIL: {$message}\n");
    exit(1);
}

/**
 * @return array<string, mixed>
 */
function videochat_call_cancel_endpoint_decode(array $response): array
{
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    if (!is_array($payload)) {
        return [];
    }

    return $payload;
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-call-cancel-endpoint-' . bin2hex(random_bytes(6)) . '.sqlite';
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
    videochat_call_cancel_endpoint_assert($adminUserId > 0, 'expected seeded admin user');

    $standardUserId = (int) $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE lower(users.email) = lower('user@intelligent-intern.com')
LIMIT 1
SQL
    )->fetchColumn();
    videochat_call_cancel_endpoint_assert($standardUserId > 0, 'expected seeded standard user');

    $moderatorRoleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'moderator' LIMIT 1")->fetchColumn();
    videochat_call_cancel_endpoint_assert($moderatorRoleId > 0, 'expected moderator role');
    $moderatorPassword = password_hash('moderator123', PASSWORD_DEFAULT);
    videochat_call_cancel_endpoint_assert(is_string($moderatorPassword) && $moderatorPassword !== '', 'moderator password hash failed');
    $insertModerator = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dark', :updated_at)
SQL
    );
    $insertModerator->execute([
        ':email' => 'moderator-cancel-endpoint@intelligent-intern.com',
        ':display_name' => 'Moderator Cancel Endpoint',
        ':password_hash' => $moderatorPassword,
        ':role_id' => $moderatorRoleId,
        ':updated_at' => gmdate('c'),
    ]);
    $moderatorUserId = (int) $pdo->lastInsertId();
    videochat_call_cancel_endpoint_assert($moderatorUserId > 0, 'expected inserted moderator user');

    $created = videochat_create_call($pdo, $adminUserId, [
        'room_id' => 'lobby',
        'title' => 'Cancel Endpoint Contract',
        'starts_at' => '2026-06-12T09:00:00Z',
        'ends_at' => '2026-06-12T10:00:00Z',
        'internal_participant_user_ids' => [$standardUserId],
    ]);
    videochat_call_cancel_endpoint_assert((bool) ($created['ok'] ?? false), 'setup create call should succeed');
    $callId = (string) (($created['call'] ?? [])['id'] ?? '');
    videochat_call_cancel_endpoint_assert($callId !== '', 'setup call id should be non-empty');

    $joinedAt = '2026-06-12T09:15:00Z';
    $markJoined = $pdo->prepare(
        'UPDATE call_participants SET joined_at = :joined_at, left_at = NULL WHERE call_id = :call_id AND user_id = :user_id'
    );
    $markJoined->execute([
        ':joined_at' => $joinedAt,
        ':call_id' => $callId,
        ':user_id' => $standardUserId,
    ]);

    $adminSessionId = 'sess_call_cancel_endpoint_admin';
    $userSessionId = 'sess_call_cancel_endpoint_user';
    $moderatorSessionId = 'sess_call_cancel_endpoint_moderator';
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
        ':user_agent' => 'call-cancel-endpoint-contract-admin',
    ]);
    $insertSession->execute([
        ':id' => $userSessionId,
        ':user_id' => $standardUserId,
        ':issued_at' => gmdate('c', time() - 60),
        ':expires_at' => gmdate('c', time() + 3600),
        ':user_agent' => 'call-cancel-endpoint-contract-user',
    ]);
    $insertSession->execute([
        ':id' => $moderatorSessionId,
        ':user_id' => $moderatorUserId,
        ':issued_at' => gmdate('c', time() - 60),
        ':expires_at' => gmdate('c', time() + 3600),
        ':user_agent' => 'call-cancel-endpoint-contract-moderator',
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
            'uri' => '/api/calls/' . $callId . '/cancel',
            'headers' => ['Authorization' => 'Bearer ' . $adminSessionId],
        ],
        'rest'
    );
    videochat_call_cancel_endpoint_assert((bool) ($adminAuth['ok'] ?? false), 'expected valid admin auth context');

    $userAuth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'POST',
            'uri' => '/api/calls/' . $callId . '/cancel',
            'headers' => ['Authorization' => 'Bearer ' . $userSessionId],
        ],
        'rest'
    );
    videochat_call_cancel_endpoint_assert((bool) ($userAuth['ok'] ?? false), 'expected valid user auth context');

    $moderatorAuth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'POST',
            'uri' => '/api/calls/' . $callId . '/cancel',
            'headers' => ['Authorization' => 'Bearer ' . $moderatorSessionId],
        ],
        'rest'
    );
    videochat_call_cancel_endpoint_assert((bool) ($moderatorAuth['ok'] ?? false), 'expected valid moderator auth context');

    $moderatorRequestTemplate = [
        'uri' => '/api/calls/' . $callId . '/cancel',
        'headers' => ['Authorization' => 'Bearer ' . $moderatorSessionId],
        'remote_address' => '127.0.0.1',
    ];

    $invalidJson = videochat_handle_call_routes(
        '/api/calls/' . $callId . '/cancel',
        'POST',
        [...$moderatorRequestTemplate, 'method' => 'POST', 'body' => 'not-json'],
        $moderatorAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_cancel_endpoint_assert(is_array($invalidJson), 'invalid-json cancel response must be an array');
    videochat_call_cancel_endpoint_assert((int) ($invalidJson['status'] ?? 0) === 400, 'invalid-json cancel status should be 400');
    $invalidJsonPayload = videochat_call_cancel_endpoint_decode($invalidJson);
    videochat_call_cancel_endpoint_assert(
        (string) (($invalidJsonPayload['error'] ?? [])['code'] ?? '') === 'calls_cancel_invalid_request_body',
        'invalid-json cancel error code mismatch'
    );

    $invalidPayload = videochat_handle_call_routes(
        '/api/calls/' . $callId . '/cancel',
        'POST',
        [...$moderatorRequestTemplate, 'method' => 'POST', 'body' => json_encode([], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
        $moderatorAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_cancel_endpoint_assert(is_array($invalidPayload), 'invalid-payload cancel response must be an array');
    videochat_call_cancel_endpoint_assert((int) ($invalidPayload['status'] ?? 0) === 422, 'invalid-payload cancel status should be 422');
    $invalidPayloadBody = videochat_call_cancel_endpoint_decode($invalidPayload);
    videochat_call_cancel_endpoint_assert(
        (string) (($invalidPayloadBody['error'] ?? [])['code'] ?? '') === 'calls_cancel_validation_failed',
        'invalid-payload cancel error code mismatch'
    );
    videochat_call_cancel_endpoint_assert(
        (string) (((($invalidPayloadBody['error'] ?? [])['details'] ?? [])['fields'] ?? [])['cancel_reason'] ?? '') === 'required_non_empty_string',
        'invalid-payload cancel_reason field mismatch'
    );
    videochat_call_cancel_endpoint_assert(
        (string) (((($invalidPayloadBody['error'] ?? [])['details'] ?? [])['fields'] ?? [])['cancel_message'] ?? '') === 'required_non_empty_string',
        'invalid-payload cancel_message field mismatch'
    );

    $forbiddenCancel = videochat_handle_call_routes(
        '/api/calls/' . $callId . '/cancel',
        'POST',
        [
            'method' => 'POST',
            'uri' => '/api/calls/' . $callId . '/cancel',
            'headers' => ['Authorization' => 'Bearer ' . $userSessionId],
            'remote_address' => '127.0.0.1',
            'body' => json_encode([
                'cancel_reason' => 'policy',
                'cancel_message' => 'Not allowed',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $userAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_cancel_endpoint_assert(is_array($forbiddenCancel), 'forbidden cancel response must be an array');
    videochat_call_cancel_endpoint_assert((int) ($forbiddenCancel['status'] ?? 0) === 403, 'forbidden cancel status should be 403');
    $forbiddenCancelBody = videochat_call_cancel_endpoint_decode($forbiddenCancel);
    videochat_call_cancel_endpoint_assert(
        (string) (($forbiddenCancelBody['error'] ?? [])['code'] ?? '') === 'calls_forbidden',
        'forbidden cancel error code mismatch'
    );

    $cancelResult = videochat_handle_call_routes(
        '/api/calls/' . $callId . '/cancel',
        'POST',
        [
            ...$moderatorRequestTemplate,
            'method' => 'POST',
            'body' => json_encode([
                'cancel_reason' => 'scheduler_conflict',
                'cancel_message' => 'Call cancelled due to scheduling conflict.',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $moderatorAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_cancel_endpoint_assert(is_array($cancelResult), 'valid cancel response must be an array');
    videochat_call_cancel_endpoint_assert((int) ($cancelResult['status'] ?? 0) === 200, 'valid cancel status should be 200');
    $cancelResultBody = videochat_call_cancel_endpoint_decode($cancelResult);
    videochat_call_cancel_endpoint_assert((string) ($cancelResultBody['status'] ?? '') === 'ok', 'valid cancel payload status mismatch');
    videochat_call_cancel_endpoint_assert(
        (string) ((($cancelResultBody['result'] ?? [])['state'] ?? '')) === 'cancelled',
        'valid cancel result state mismatch'
    );
    $cancelledCall = (($cancelResultBody['result'] ?? [])['call'] ?? null);
    videochat_call_cancel_endpoint_assert(is_array($cancelledCall), 'valid cancel should return call envelope');
    videochat_call_cancel_endpoint_assert((string) ($cancelledCall['status'] ?? '') === 'cancelled', 'cancelled call status mismatch');
    videochat_call_cancel_endpoint_assert((string) ($cancelledCall['cancel_reason'] ?? '') === 'scheduler_conflict', 'cancelled call reason mismatch');
    videochat_call_cancel_endpoint_assert((string) ($cancelledCall['cancel_message'] ?? '') === 'Call cancelled due to scheduling conflict.', 'cancelled call message mismatch');
    videochat_call_cancel_endpoint_assert((($cancelledCall['my_participation'] ?? null) === false), 'cancelled call my_participation should be false');
    $cancelledAt = (string) ($cancelledCall['cancelled_at'] ?? '');
    videochat_call_cancel_endpoint_assert($cancelledAt !== '', 'cancelled call timestamp should be set');

    $callRowQuery = $pdo->prepare('SELECT status, cancel_reason, cancel_message, cancelled_at FROM calls WHERE id = :id LIMIT 1');
    $callRowQuery->execute([':id' => $callId]);
    $callRow = $callRowQuery->fetch();
    videochat_call_cancel_endpoint_assert(is_array($callRow), 'cancelled call row should exist');
    videochat_call_cancel_endpoint_assert((string) ($callRow['status'] ?? '') === 'cancelled', 'cancelled row status mismatch');
    videochat_call_cancel_endpoint_assert((string) ($callRow['cancel_reason'] ?? '') === 'scheduler_conflict', 'cancelled row reason mismatch');
    videochat_call_cancel_endpoint_assert((string) ($callRow['cancel_message'] ?? '') === 'Call cancelled due to scheduling conflict.', 'cancelled row message mismatch');

    $participantRows = $pdo->prepare(
        <<<'SQL'
SELECT user_id, email, invite_state, joined_at, left_at
FROM call_participants
WHERE call_id = :call_id
ORDER BY email ASC
SQL
    );
    $participantRows->execute([':call_id' => $callId]);
    $participants = $participantRows->fetchAll();
    videochat_call_cancel_endpoint_assert(is_array($participants) && count($participants) === 2, 'cancelled participant count mismatch');
    foreach ($participants as $participant) {
        videochat_call_cancel_endpoint_assert(
            (string) ($participant['invite_state'] ?? '') === 'cancelled',
            'cancelled participant invite_state mismatch'
        );
    }
    $userParticipant = null;
    foreach ($participants as $participant) {
        if ((int) ($participant['user_id'] ?? 0) === $standardUserId) {
            $userParticipant = $participant;
            break;
        }
    }
    videochat_call_cancel_endpoint_assert(is_array($userParticipant), 'cancelled user participant row should exist');
    videochat_call_cancel_endpoint_assert((string) ($userParticipant['joined_at'] ?? '') === $joinedAt, 'cancelled user participant joined_at should be preserved');
    videochat_call_cancel_endpoint_assert((string) ($userParticipant['left_at'] ?? '') === $cancelledAt, 'cancelled user participant left_at should equal cancellation timestamp');

    $userListAuth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/api/calls?scope=my&status=all&page=1&page_size=10',
            'headers' => ['Authorization' => 'Bearer ' . $userSessionId],
        ],
        'rest'
    );
    videochat_call_cancel_endpoint_assert((bool) ($userListAuth['ok'] ?? false), 'expected valid user auth for calls list');
    $userListing = videochat_handle_call_routes(
        '/api/calls',
        'GET',
        [
            'method' => 'GET',
            'uri' => '/api/calls?scope=my&status=all&page=1&page_size=10',
            'headers' => ['Authorization' => 'Bearer ' . $userSessionId],
            'remote_address' => '127.0.0.1',
            'body' => '',
        ],
        $userListAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_cancel_endpoint_assert(is_array($userListing), 'user listing response must be an array');
    videochat_call_cancel_endpoint_assert((int) ($userListing['status'] ?? 0) === 200, 'user listing status should be 200');
    $userListingBody = videochat_call_cancel_endpoint_decode($userListing);
    videochat_call_cancel_endpoint_assert(
        (int) ((($userListingBody['pagination'] ?? [])['total'] ?? 0)) === 0,
        'cancelled call should be excluded from active join listing'
    );

    $repeatedCancel = videochat_handle_call_routes(
        '/api/calls/' . $callId . '/cancel',
        'POST',
        [
            ...$moderatorRequestTemplate,
            'method' => 'POST',
            'body' => json_encode([
                'cancel_reason' => 'second_attempt',
                'cancel_message' => 'Second cancel attempt',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $moderatorAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_cancel_endpoint_assert(is_array($repeatedCancel), 'repeated cancel response must be an array');
    videochat_call_cancel_endpoint_assert((int) ($repeatedCancel['status'] ?? 0) === 409, 'repeated cancel status should be 409');
    $repeatedCancelBody = videochat_call_cancel_endpoint_decode($repeatedCancel);
    videochat_call_cancel_endpoint_assert(
        (string) (($repeatedCancelBody['error'] ?? [])['code'] ?? '') === 'calls_cancel_state_conflict',
        'repeated cancel error code mismatch'
    );
    videochat_call_cancel_endpoint_assert(
        (string) (((($repeatedCancelBody['error'] ?? [])['details'] ?? [])['fields'] ?? [])['status'] ?? '') === 'already_cancelled',
        'repeated cancel status field mismatch'
    );

    $endedCall = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Ended Call',
        'starts_at' => '2026-06-13T09:00:00Z',
        'ends_at' => '2026-06-13T10:00:00Z',
    ]);
    videochat_call_cancel_endpoint_assert((bool) ($endedCall['ok'] ?? false), 'ended-call setup should succeed');
    $endedCallId = (string) (($endedCall['call'] ?? [])['id'] ?? '');
    $setEnded = $pdo->prepare('UPDATE calls SET status = :status WHERE id = :id');
    $setEnded->execute([
        ':status' => 'ended',
        ':id' => $endedCallId,
    ]);
    $endedCancel = videochat_handle_call_routes(
        '/api/calls/' . $endedCallId . '/cancel',
        'POST',
        [
            ...$moderatorRequestTemplate,
            'method' => 'POST',
            'uri' => '/api/calls/' . $endedCallId . '/cancel',
            'body' => json_encode([
                'cancel_reason' => 'ended_transition',
                'cancel_message' => 'Should not transition from ended to cancelled',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $moderatorAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_cancel_endpoint_assert(is_array($endedCancel), 'ended-call cancel response must be an array');
    videochat_call_cancel_endpoint_assert((int) ($endedCancel['status'] ?? 0) === 409, 'ended-call cancel status should be 409');
    $endedCancelBody = videochat_call_cancel_endpoint_decode($endedCancel);
    videochat_call_cancel_endpoint_assert(
        (string) (((($endedCancelBody['error'] ?? [])['details'] ?? [])['fields'] ?? [])['status'] ?? '') === 'transition_not_allowed',
        'ended-call transition field mismatch'
    );

    $missingCancel = videochat_handle_call_routes(
        '/api/calls/call_missing_cancel_endpoint/cancel',
        'POST',
        [
            ...$moderatorRequestTemplate,
            'method' => 'POST',
            'uri' => '/api/calls/call_missing_cancel_endpoint/cancel',
            'body' => json_encode([
                'cancel_reason' => 'missing',
                'cancel_message' => 'missing',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $moderatorAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_cancel_endpoint_assert(is_array($missingCancel), 'missing-call cancel response must be an array');
    videochat_call_cancel_endpoint_assert((int) ($missingCancel['status'] ?? 0) === 404, 'missing-call cancel status should be 404');
    $missingCancelBody = videochat_call_cancel_endpoint_decode($missingCancel);
    videochat_call_cancel_endpoint_assert(
        (string) (($missingCancelBody['error'] ?? [])['code'] ?? '') === 'calls_not_found',
        'missing-call cancel error code mismatch'
    );

    $methodNotAllowed = videochat_handle_call_routes(
        '/api/calls/' . $callId . '/cancel',
        'GET',
        [...$moderatorRequestTemplate, 'method' => 'GET', 'body' => ''],
        $moderatorAuth,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_cancel_endpoint_assert(is_array($methodNotAllowed), 'method-not-allowed cancel response must be an array');
    videochat_call_cancel_endpoint_assert((int) ($methodNotAllowed['status'] ?? 0) === 405, 'method-not-allowed cancel status should be 405');

    $invalidContext = videochat_handle_call_routes(
        '/api/calls/' . $callId . '/cancel',
        'POST',
        [...$moderatorRequestTemplate, 'method' => 'POST', 'body' => json_encode(['cancel_reason' => 'x', 'cancel_message' => 'y'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
        ['user' => ['id' => 0, 'role' => 'moderator']],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_cancel_endpoint_assert(is_array($invalidContext), 'invalid-context cancel response must be an array');
    videochat_call_cancel_endpoint_assert((int) ($invalidContext['status'] ?? 0) === 401, 'invalid-context cancel status should be 401');

    @unlink($databasePath);
    fwrite(STDOUT, "[call-cancel-endpoint-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[call-cancel-endpoint-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
