<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/realtime/realtime_call_context.php';
require_once __DIR__ . '/../http/module_calls.php';

function videochat_call_creation_owner_rights_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-creation-owner-rights-contract] FAIL: {$message}\n");
    exit(1);
}

/**
 * @return array<string, mixed>
 */
function videochat_call_creation_owner_rights_decode(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function videochat_call_creation_owner_rights_user_id(PDO $pdo, string $roleSlug): int
{
    $query = $pdo->prepare(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE roles.slug = :role_slug
ORDER BY users.id ASC
LIMIT 1
SQL
    );
    $query->execute([':role_slug' => $roleSlug]);

    return (int) $query->fetchColumn();
}

function videochat_call_creation_owner_rights_issue_session(PDO $pdo, int $userId, string $label): string
{
    $sessionId = 'sess_call_creation_owner_rights_' . $label;
    $insertSession = $pdo->prepare(
        <<<'SQL'
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent)
VALUES(:id, :user_id, :issued_at, :expires_at, NULL, '127.0.0.1', :user_agent)
SQL
    );
    $insertSession->execute([
        ':id' => $sessionId,
        ':user_id' => $userId,
        ':issued_at' => gmdate('c', time() - 60),
        ':expires_at' => gmdate('c', time() + 3600),
        ':user_agent' => 'call-creation-owner-rights-contract-' . $label,
    ]);

    return $sessionId;
}

/**
 * @return array<string, mixed>
 */
function videochat_call_creation_owner_rights_auth_context(PDO $pdo, string $sessionId): array
{
    $auth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'POST',
            'uri' => '/api/calls',
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        ],
        'rest'
    );
    videochat_call_creation_owner_rights_assert((bool) ($auth['ok'] ?? false), 'expected authenticated call creator');

    return $auth;
}

/**
 * @return array<string, mixed>|null
 */
function videochat_call_creation_owner_rights_internal_participant(array $call, int $userId): ?array
{
    $participants = is_array($call['participants'] ?? null) ? $call['participants'] : [];
    $internalParticipants = is_array($participants['internal'] ?? null) ? $participants['internal'] : [];
    foreach ($internalParticipants as $participant) {
        if (is_array($participant) && (int) ($participant['user_id'] ?? 0) === $userId) {
            return $participant;
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $apiAuthContext
 */
function videochat_call_creation_owner_rights_run_actor(
    PDO $pdo,
    callable $openDatabase,
    callable $jsonResponse,
    callable $errorResponse,
    callable $decodeJsonBody,
    array $apiAuthContext,
    int $actorUserId,
    string $actorRole,
    string $label
): void {
    $title = 'Owner Rights ' . ucfirst($label) . ' Contract';
    $createResponse = videochat_handle_call_routes(
        '/api/calls',
        'POST',
        [
            'method' => 'POST',
            'uri' => '/api/calls',
            'headers' => [],
            'remote_address' => '127.0.0.1',
            'body' => json_encode([
                'title' => $title,
                'access_mode' => 'invite_only',
                'starts_at' => '2030-06-01T09:00:00Z',
                'ends_at' => '2030-06-01T10:00:00Z',
                'internal_participant_user_ids' => [],
                'external_participants' => [],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        $apiAuthContext,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_creation_owner_rights_assert(is_array($createResponse), "{$label} create response should be present");
    videochat_call_creation_owner_rights_assert((int) ($createResponse['status'] ?? 0) === 201, "{$label} create should return HTTP 201");

    $createPayload = videochat_call_creation_owner_rights_decode($createResponse);
    $createdCall = (($createPayload['result'] ?? [])['call'] ?? null);
    videochat_call_creation_owner_rights_assert(is_array($createdCall), "{$label} create should return call payload");
    $callId = (string) ($createdCall['id'] ?? '');
    $roomId = (string) ($createdCall['room_id'] ?? '');
    videochat_call_creation_owner_rights_assert($callId !== '' && $roomId === $callId, "{$label} call should use dedicated room id");
    videochat_call_creation_owner_rights_assert((int) (($createdCall['owner'] ?? [])['user_id'] ?? 0) === $actorUserId, "{$label} creator should be response owner");

    $creatorParticipant = videochat_call_creation_owner_rights_internal_participant($createdCall, $actorUserId);
    videochat_call_creation_owner_rights_assert(is_array($creatorParticipant), "{$label} creator should be internal participant");
    videochat_call_creation_owner_rights_assert((string) ($creatorParticipant['call_role'] ?? '') === 'owner', "{$label} creator participant should have owner role");
    videochat_call_creation_owner_rights_assert((string) ($creatorParticipant['invite_state'] ?? '') === 'allowed', "{$label} creator invite state should be allowed");
    videochat_call_creation_owner_rights_assert((bool) ($creatorParticipant['is_owner'] ?? false), "{$label} creator participant should be flagged owner");

    $callRow = $pdo->prepare('SELECT owner_user_id, room_id FROM calls WHERE id = :call_id LIMIT 1');
    $callRow->execute([':call_id' => $callId]);
    $persistedCall = $callRow->fetch();
    videochat_call_creation_owner_rights_assert(is_array($persistedCall), "{$label} call row should persist");
    videochat_call_creation_owner_rights_assert((int) ($persistedCall['owner_user_id'] ?? 0) === $actorUserId, "{$label} persisted owner should be creator");
    videochat_call_creation_owner_rights_assert((string) ($persistedCall['room_id'] ?? '') === $roomId, "{$label} persisted room id mismatch");

    $roomRow = $pdo->prepare('SELECT created_by_user_id FROM rooms WHERE id = :room_id LIMIT 1');
    $roomRow->execute([':room_id' => $roomId]);
    videochat_call_creation_owner_rights_assert((int) $roomRow->fetchColumn() === $actorUserId, "{$label} room creator should be actor");

    $participantRow = $pdo->prepare(
        <<<'SQL'
SELECT call_role, invite_state
FROM call_participants
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
LIMIT 1
SQL
    );
    $participantRow->execute([
        ':call_id' => $callId,
        ':user_id' => $actorUserId,
    ]);
    $persistedParticipant = $participantRow->fetch();
    videochat_call_creation_owner_rights_assert(is_array($persistedParticipant), "{$label} creator participant row should persist");
    videochat_call_creation_owner_rights_assert((string) ($persistedParticipant['call_role'] ?? '') === 'owner', "{$label} persisted creator role should be owner");
    videochat_call_creation_owner_rights_assert((string) ($persistedParticipant['invite_state'] ?? '') === 'allowed', "{$label} persisted creator invite state should be allowed");

    $callFetch = videochat_get_call_for_user($pdo, $callId, $actorUserId, $actorRole);
    videochat_call_creation_owner_rights_assert((bool) ($callFetch['ok'] ?? false), "{$label} creator should fetch own call");
    videochat_call_creation_owner_rights_assert(
        (int) (((($callFetch['call'] ?? [])['owner'] ?? [])['user_id'] ?? 0)) === $actorUserId,
        "{$label} fetched owner should remain creator"
    );

    videochat_call_creation_owner_rights_assert(
        videochat_can_administer_call($pdo, $callId, $actorRole, $actorUserId, $actorUserId),
        "{$label} creator should have call-admin rights"
    );

    $roleContext = videochat_call_role_context_for_room_user($pdo, $roomId, $actorUserId);
    videochat_call_creation_owner_rights_assert((string) ($roleContext['call_id'] ?? '') === $callId, "{$label} role context call id mismatch");
    videochat_call_creation_owner_rights_assert((string) ($roleContext['call_role'] ?? '') === 'owner', "{$label} role context should resolve owner");
    videochat_call_creation_owner_rights_assert((bool) ($roleContext['can_moderate'] ?? false), "{$label} owner should moderate own call");
    videochat_call_creation_owner_rights_assert((bool) ($roleContext['can_manage_owner'] ?? false), "{$label} owner should have owner-management rights in own call");

    $realtimeContext = videochat_realtime_call_role_context_for_room_user($pdo, $roomId, $actorUserId, $callId, $actorRole);
    videochat_call_creation_owner_rights_assert((string) ($realtimeContext['call_id'] ?? '') === $callId, "{$label} realtime context call id mismatch");
    videochat_call_creation_owner_rights_assert((string) ($realtimeContext['call_role'] ?? '') === 'owner', "{$label} realtime context should resolve owner");
    videochat_call_creation_owner_rights_assert((bool) ($realtimeContext['can_moderate'] ?? false), "{$label} realtime context should allow moderation");
    videochat_call_creation_owner_rights_assert((bool) ($realtimeContext['can_manage_owner'] ?? false), "{$label} realtime context should allow owner management");

    $updateResult = videochat_update_call($pdo, $callId, $actorUserId, $actorRole, [
        'title' => $title . ' Updated',
    ]);
    videochat_call_creation_owner_rights_assert((bool) ($updateResult['ok'] ?? false), "{$label} creator should update own call through call-admin path");
    videochat_call_creation_owner_rights_assert((string) (($updateResult['call'] ?? [])['title'] ?? '') === $title . ' Updated', "{$label} update result title mismatch");
    videochat_call_creation_owner_rights_assert((int) (((($updateResult['call'] ?? [])['owner'] ?? [])['user_id'] ?? 0)) === $actorUserId, "{$label} update should preserve owner");
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-call-creation-owner-rights-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $normalUserId = videochat_call_creation_owner_rights_user_id($pdo, 'user');
    $adminUserId = videochat_call_creation_owner_rights_user_id($pdo, 'admin');
    videochat_call_creation_owner_rights_assert($normalUserId > 0, 'expected seeded normal user');
    videochat_call_creation_owner_rights_assert($adminUserId > 0, 'expected seeded admin user');

    $normalSessionId = videochat_call_creation_owner_rights_issue_session($pdo, $normalUserId, 'user');
    $adminSessionId = videochat_call_creation_owner_rights_issue_session($pdo, $adminUserId, 'admin');
    $normalAuth = videochat_call_creation_owner_rights_auth_context($pdo, $normalSessionId);
    $adminAuth = videochat_call_creation_owner_rights_auth_context($pdo, $adminSessionId);

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

    videochat_call_creation_owner_rights_run_actor(
        $pdo,
        $openDatabase,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $normalAuth,
        $normalUserId,
        'user',
        'normal-user'
    );
    videochat_call_creation_owner_rights_run_actor(
        $pdo,
        $openDatabase,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $adminAuth,
        $adminUserId,
        'admin',
        'admin-user'
    );

    @unlink($databasePath);
    fwrite(STDOUT, "[call-creation-owner-rights-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[call-creation-owner-rights-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
