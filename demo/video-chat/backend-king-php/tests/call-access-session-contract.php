<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../domain/realtime/realtime_presence.php';
require_once __DIR__ . '/../http/module_calls.php';
require_once __DIR__ . '/../http/module_realtime.php';

function videochat_call_access_session_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-session-contract] FAIL: {$message}\n");
    exit(1);
}

/**
 * @return array<string, mixed>
 */
function videochat_call_access_session_decode(array $response): array
{
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($payload) ? $payload : [];
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-session-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-access-session-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $standardUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();
    videochat_call_access_session_assert($adminUserId > 0, 'expected seeded admin user');
    videochat_call_access_session_assert($standardUserId > 0, 'expected seeded standard user');

    $createPrimary = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Call Access Session Primary',
        'starts_at' => '2026-09-01T09:00:00Z',
        'ends_at' => '2026-09-01T10:00:00Z',
        'internal_participant_user_ids' => [$standardUserId],
        'external_participants' => [],
    ]);
    videochat_call_access_session_assert((bool) ($createPrimary['ok'] ?? false), 'primary call should be created');
    $primaryCall = is_array($createPrimary['call'] ?? null) ? $createPrimary['call'] : [];
    $primaryCallId = (string) ($primaryCall['id'] ?? '');
    videochat_call_access_session_assert($primaryCallId !== '', 'primary call id should be present');
    videochat_call_access_session_assert((string) ($primaryCall['room_id'] ?? '') === $primaryCallId, 'primary call should use dedicated room');

    $createSecondary = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Call Access Session Secondary',
        'starts_at' => '2026-09-02T09:00:00Z',
        'ends_at' => '2026-09-02T10:00:00Z',
        'internal_participant_user_ids' => [$standardUserId],
        'external_participants' => [],
    ]);
    videochat_call_access_session_assert((bool) ($createSecondary['ok'] ?? false), 'secondary call should be created');
    $secondaryCallId = (string) (($createSecondary['call'] ?? [])['id'] ?? '');
    videochat_call_access_session_assert($secondaryCallId !== '', 'secondary call id should be present');

    $personalAccess = videochat_create_call_access_link_for_user($pdo, $primaryCallId, $adminUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $standardUserId,
    ]);
    videochat_call_access_session_assert((bool) ($personalAccess['ok'] ?? false), 'personal access link should be created');
    $personalAccessId = (string) (($personalAccess['access_link'] ?? [])['id'] ?? '');
    videochat_call_access_session_assert($personalAccessId !== '', 'personal access id should be present');

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

    $joinResponse = videochat_handle_call_routes(
        '/api/call-access/' . $personalAccessId . '/join',
        'GET',
        ['method' => 'GET', 'uri' => '/api/call-access/' . $personalAccessId . '/join', 'headers' => []],
        [],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_access_session_assert(is_array($joinResponse), 'personal join response should be an array');
    videochat_call_access_session_assert((int) ($joinResponse['status'] ?? 0) === 200, 'personal join status should be 200');
    $joinPayload = videochat_call_access_session_decode($joinResponse);
    videochat_call_access_session_assert((string) ((($joinPayload['result'] ?? [])['call'] ?? [])['id'] ?? '') === $primaryCallId, 'join call id should match access call');
    videochat_call_access_session_assert((string) ((($joinPayload['result'] ?? [])['call'] ?? [])['room_id'] ?? '') === $primaryCallId, 'join room id should match dedicated call room');
    videochat_call_access_session_assert((int) ((($joinPayload['result'] ?? [])['target_user'] ?? [])['id'] ?? 0) === $standardUserId, 'join target user should match personal link');

    $personalSessionId = 'sess_call_access_personal_bound';
    $sessionIssuer = static function () use ($personalSessionId): string {
        return $personalSessionId;
    };
    $sessionResponse = videochat_handle_call_routes(
        '/api/call-access/' . $personalAccessId . '/session',
        'POST',
        [
            'method' => 'POST',
            'uri' => '/api/call-access/' . $personalAccessId . '/session',
            'headers' => ['User-Agent' => 'call-access-session-contract'],
            'remote_address' => '127.0.0.1',
            'body' => '{}',
        ],
        [],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase,
        $sessionIssuer
    );
    videochat_call_access_session_assert(is_array($sessionResponse), 'personal session response should be an array');
    videochat_call_access_session_assert((int) ($sessionResponse['status'] ?? 0) === 200, 'personal session status should be 200');
    $sessionPayload = videochat_call_access_session_decode($sessionResponse);
    videochat_call_access_session_assert((string) (((($sessionPayload['result'] ?? [])['session'] ?? [])['token'] ?? '')) === $personalSessionId, 'personal session token mismatch');
    videochat_call_access_session_assert((int) (((($sessionPayload['result'] ?? [])['user'] ?? [])['id'] ?? 0)) === $standardUserId, 'personal session user mismatch');
    videochat_call_access_session_assert((string) (((($sessionPayload['result'] ?? [])['call'] ?? [])['id'] ?? '')) === $primaryCallId, 'personal session call mismatch');

    $binding = videochat_fetch_call_access_session_binding($pdo, $personalSessionId);
    videochat_call_access_session_assert(is_array($binding), 'personal session binding should be persisted');
    videochat_call_access_session_assert((string) ($binding['access_id'] ?? '') === $personalAccessId, 'personal binding access id mismatch');
    videochat_call_access_session_assert((string) ($binding['call_id'] ?? '') === $primaryCallId, 'personal binding call id mismatch');
    videochat_call_access_session_assert((string) ($binding['room_id'] ?? '') === $primaryCallId, 'personal binding room id mismatch');
    videochat_call_access_session_assert((int) ($binding['user_id'] ?? 0) === $standardUserId, 'personal binding user id mismatch');
    videochat_call_access_session_assert((string) ($binding['link_kind'] ?? '') === 'personal', 'personal binding link kind mismatch');

    $personalAuth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . $personalSessionId . '&room=' . $primaryCallId . '&call_id=' . $primaryCallId,
            'headers' => ['Authorization' => 'Bearer ' . $personalSessionId],
        ],
        'websocket'
    );
    videochat_call_access_session_assert((bool) ($personalAuth['ok'] ?? false), 'personal access session should authenticate for websocket');

    $pendingResolution = videochat_realtime_resolve_connection_rooms($personalAuth, $primaryCallId, $openDatabase, $primaryCallId);
    videochat_call_access_session_assert((string) ($pendingResolution['initial_room_id'] ?? '') === videochat_realtime_waiting_room_id(), 'invited personal session should start in waiting room');
    videochat_call_access_session_assert((string) ($pendingResolution['requested_room_id'] ?? '') === $primaryCallId, 'invited personal session should keep bound requested room');
    videochat_call_access_session_assert((string) ($pendingResolution['pending_room_id'] ?? '') === $primaryCallId, 'invited personal session should wait for bound room admission');

    $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET invite_state = 'allowed'
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
SQL
    )->execute([
        ':call_id' => $primaryCallId,
        ':user_id' => $standardUserId,
    ]);

    $allowedResolution = videochat_realtime_resolve_connection_rooms($personalAuth, $primaryCallId, $openDatabase, $primaryCallId);
    videochat_call_access_session_assert((string) ($allowedResolution['initial_room_id'] ?? '') === $primaryCallId, 'allowed personal session should enter bound room');
    videochat_call_access_session_assert((string) ($allowedResolution['pending_room_id'] ?? '') === '', 'allowed personal session should not have pending room');

    $defaultResolution = videochat_realtime_resolve_connection_rooms($personalAuth, '', $openDatabase, '');
    videochat_call_access_session_assert((string) ($defaultResolution['initial_room_id'] ?? '') === $primaryCallId, 'access-bound session without query room should resolve to bound room');
    videochat_call_access_session_assert((string) ($defaultResolution['requested_room_id'] ?? '') === $primaryCallId, 'access-bound session without query should expose bound requested room');

    $mismatchResolution = videochat_realtime_resolve_connection_rooms($personalAuth, $secondaryCallId, $openDatabase, $secondaryCallId);
    videochat_call_access_session_assert((string) ($mismatchResolution['initial_room_id'] ?? '') === videochat_realtime_waiting_room_id(), 'access-bound session mismatch should not enter secondary room');
    videochat_call_access_session_assert((string) ($mismatchResolution['requested_room_id'] ?? '') === '', 'access-bound session mismatch should not keep secondary requested room');
    videochat_call_access_session_assert((string) ($mismatchResolution['pending_room_id'] ?? '') === '', 'access-bound session mismatch should not queue secondary room admission');
    videochat_call_access_session_assert((string) ($mismatchResolution['access_session_binding'] ?? '') === 'mismatch', 'access-bound mismatch should be explicit');

    $createOpen = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Call Access Session Open Guest',
        'access_mode' => 'free_for_all',
        'starts_at' => '2026-09-03T09:00:00Z',
        'ends_at' => '2026-09-03T10:00:00Z',
        'internal_participant_user_ids' => [],
        'external_participants' => [],
    ]);
    videochat_call_access_session_assert((bool) ($createOpen['ok'] ?? false), 'open call should be created');
    $openCallId = (string) (($createOpen['call'] ?? [])['id'] ?? '');
    videochat_call_access_session_assert($openCallId !== '', 'open call id should be present');

    $ownerLandingUpdate = videochat_update_user_settings($pdo, $adminUserId, [
        'post_logout_landing_url' => '/call-goodbye?source=open-access-contract',
    ]);
    videochat_call_access_session_assert((bool) ($ownerLandingUpdate['ok'] ?? false), 'owner logout landing should update');

    $openAccess = videochat_create_call_access_link_for_user($pdo, $openCallId, $adminUserId, 'admin', [
        'link_kind' => 'open',
    ]);
    videochat_call_access_session_assert((bool) ($openAccess['ok'] ?? false), 'open access link should be created');
    $openAccessId = (string) (($openAccess['access_link'] ?? [])['id'] ?? '');
    videochat_call_access_session_assert($openAccessId !== '', 'open access id should be present');

    $missingGuestResponse = videochat_handle_call_routes(
        '/api/call-access/' . $openAccessId . '/session',
        'POST',
        [
            'method' => 'POST',
            'uri' => '/api/call-access/' . $openAccessId . '/session',
            'headers' => [],
            'remote_address' => '127.0.0.1',
            'body' => '{}',
        ],
        [],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase,
        static fn (): string => 'sess_call_access_missing_guest'
    );
    videochat_call_access_session_assert(is_array($missingGuestResponse), 'missing guest response should be an array');
    videochat_call_access_session_assert((int) ($missingGuestResponse['status'] ?? 0) === 422, 'open access session without guest name should be rejected');
    $missingGuestPayload = videochat_call_access_session_decode($missingGuestResponse);
    videochat_call_access_session_assert((string) (($missingGuestPayload['error'] ?? [])['code'] ?? '') === 'call_access_validation_failed', 'missing guest error code mismatch');

    $openSessionId = 'sess_call_access_open_guest_bound';
    $openSessionResponse = videochat_handle_call_routes(
        '/api/call-access/' . $openAccessId . '/session',
        'POST',
        [
            'method' => 'POST',
            'uri' => '/api/call-access/' . $openAccessId . '/session',
            'headers' => ['User-Agent' => 'call-access-session-contract-open'],
            'remote_address' => '127.0.0.1',
            'body' => json_encode(['guest_name' => 'Guest Contract'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        [],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase,
        static fn (): string => $openSessionId
    );
    videochat_call_access_session_assert(is_array($openSessionResponse), 'open guest session response should be an array');
    videochat_call_access_session_assert((int) ($openSessionResponse['status'] ?? 0) === 200, 'open guest session status should be 200');
    $openSessionPayload = videochat_call_access_session_decode($openSessionResponse);
    $guestUserId = (int) (((($openSessionPayload['result'] ?? [])['user'] ?? [])['id'] ?? 0));
    videochat_call_access_session_assert($guestUserId > 0, 'open guest session should create a user');
    videochat_call_access_session_assert((bool) (((($openSessionPayload['result'] ?? [])['user'] ?? [])['is_guest'] ?? false)) === true, 'open guest session user should be marked as guest');
    videochat_call_access_session_assert(
        (string) (((($openSessionPayload['result'] ?? [])['user'] ?? [])['post_logout_landing_url'] ?? '')) === '/call-goodbye?source=open-access-contract',
        'open guest should inherit owner logout landing url for session'
    );

    $openBinding = videochat_fetch_call_access_session_binding($pdo, $openSessionId);
    videochat_call_access_session_assert(is_array($openBinding), 'open guest session binding should be persisted');
    videochat_call_access_session_assert((string) ($openBinding['access_id'] ?? '') === $openAccessId, 'open binding access id mismatch');
    videochat_call_access_session_assert((string) ($openBinding['call_id'] ?? '') === $openCallId, 'open binding call id mismatch');
    videochat_call_access_session_assert((string) ($openBinding['room_id'] ?? '') === $openCallId, 'open binding room id mismatch');
    videochat_call_access_session_assert((int) ($openBinding['user_id'] ?? 0) === $guestUserId, 'open binding user id mismatch');
    videochat_call_access_session_assert((string) ($openBinding['link_kind'] ?? '') === 'open', 'open binding link kind mismatch');

    $guestParticipant = $pdo->prepare(
        <<<'SQL'
SELECT invite_state, source, call_role
FROM call_participants
WHERE call_id = :call_id
  AND user_id = :user_id
LIMIT 1
SQL
    );
    $guestParticipant->execute([
        ':call_id' => $openCallId,
        ':user_id' => $guestUserId,
    ]);
    $guestParticipantRow = $guestParticipant->fetch();
    videochat_call_access_session_assert(is_array($guestParticipantRow), 'open guest should be inserted as call participant');
    videochat_call_access_session_assert((string) ($guestParticipantRow['source'] ?? '') === 'internal', 'open guest participant source mismatch');
    videochat_call_access_session_assert((string) ($guestParticipantRow['invite_state'] ?? '') === 'allowed', 'open guest participant invite state mismatch');

    $openAuth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . $openSessionId . '&room=' . $openCallId . '&call_id=' . $openCallId,
            'headers' => ['Authorization' => 'Bearer ' . $openSessionId],
        ],
        'websocket'
    );
    videochat_call_access_session_assert((bool) ($openAuth['ok'] ?? false), 'open guest access session should authenticate for websocket');
    videochat_call_access_session_assert(
        (string) (($openAuth['user'] ?? [])['post_logout_landing_url'] ?? '') === '/call-goodbye?source=open-access-contract',
        'authenticated open guest should keep owner logout landing url'
    );
    $openPendingResolution = videochat_realtime_resolve_connection_rooms($openAuth, $openCallId, $openDatabase, $openCallId);
    videochat_call_access_session_assert((string) ($openPendingResolution['initial_room_id'] ?? '') === $openCallId, 'open guest should enter the FFA room directly');
    videochat_call_access_session_assert((string) ($openPendingResolution['requested_room_id'] ?? '') === $openCallId, 'open guest should keep the bound requested room');
    videochat_call_access_session_assert((string) ($openPendingResolution['pending_room_id'] ?? '') === '', 'open guest should not wait for admission');

    @unlink($databasePath);
    fwrite(STDOUT, "[call-access-session-contract] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-session-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
