<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../domain/realtime/realtime_presence.php';
require_once __DIR__ . '/../http/module_calls.php';
require_once __DIR__ . '/../http/module_realtime.php';

function videochat_registered_invitee_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-registered-logged-in-invitee-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_registered_invitee_decode(array $response): array
{
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($payload) ? $payload : [];
}

function videochat_registered_invitee_assert_no_leak(array $response, array $needles, string $label): void
{
    $body = (string) ($response['body'] ?? '');
    foreach ($needles as $needle) {
        $text = is_string($needle) ? trim($needle) : '';
        if ($text === '') {
            continue;
        }
        videochat_registered_invitee_assert(!str_contains($body, $text), "{$label} leaked {$text}");
    }
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-registered-logged-in-invitee-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-registered-logged-in-invitee-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $hostUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $inviteeUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();
    videochat_registered_invitee_assert($hostUserId > 0, 'expected seeded host user');
    videochat_registered_invitee_assert($inviteeUserId > 0, 'expected seeded registered invitee');

    $inviteeBefore = $pdo->query("SELECT email, display_name, password_hash, status FROM users WHERE id = {$inviteeUserId} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    videochat_registered_invitee_assert(is_array($inviteeBefore), 'expected registered invitee profile');

    $insertSession = $pdo->prepare(
        <<<'SQL'
INSERT INTO sessions(id, user_id, issued_at, expires_at, revoked_at, client_ip, user_agent)
VALUES(:id, :user_id, :issued_at, :expires_at, NULL, '127.0.0.1', 'registered-logged-in-invitee-contract')
SQL
    );
    $now = time();
    $existingInviteeSessionId = 'sess_registered_invitee_existing';
    $insertSession->execute([
        ':id' => $existingInviteeSessionId,
        ':user_id' => $inviteeUserId,
        ':issued_at' => gmdate('c', $now - 60),
        ':expires_at' => gmdate('c', $now + 3600),
    ]);

    $createCall = videochat_create_call($pdo, $hostUserId, [
        'title' => 'Registered Logged In Invitee Proof Call',
        'access_mode' => 'invite_only',
        'starts_at' => '2026-09-01T09:00:00Z',
        'ends_at' => '2026-09-01T10:00:00Z',
        'internal_participant_user_ids' => [$inviteeUserId],
        'external_participants' => [],
    ]);
    videochat_registered_invitee_assert((bool) ($createCall['ok'] ?? false), 'host should create invite-only call');
    $call = is_array($createCall['call'] ?? null) ? $createCall['call'] : [];
    $callId = (string) ($call['id'] ?? '');
    videochat_registered_invitee_assert($callId !== '', 'created call id should be present');

    $personalAccess = videochat_create_call_access_link_for_user($pdo, $callId, $hostUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $inviteeUserId,
    ]);
    videochat_registered_invitee_assert((bool) ($personalAccess['ok'] ?? false), 'host should create personalized invite link');
    $accessId = (string) (($personalAccess['access_link'] ?? [])['id'] ?? '');
    videochat_registered_invitee_assert($accessId !== '', 'personalized access id should be present');

    $jsonResponse = static function (int $status, array $payload): array {
        return [
            'status' => $status,
            'headers' => ['content-type' => 'application/json; charset=utf-8'],
            'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    };
    $errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
        $error = ['code' => $code, 'message' => $message];
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

    $privacyNeedles = [
        'guest+',
        'Temporary Link Guest',
        'temporary_call_account',
        'sess_temp_takeover_should_not_bind',
    ];

    $joinResponse = videochat_handle_call_routes(
        '/api/call-access/' . $accessId . '/join',
        'GET',
        [
            'method' => 'GET',
            'uri' => '/api/call-access/' . $accessId . '/join',
            'headers' => ['Authorization' => 'Bearer ' . $existingInviteeSessionId],
        ],
        [],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_registered_invitee_assert((int) ($joinResponse['status'] ?? 0) === 200, 'logged-in invitee join open should resolve');
    videochat_registered_invitee_assert_no_leak($joinResponse, $privacyNeedles, 'logged-in invitee join response');
    $joinPayload = videochat_registered_invitee_decode($joinResponse);
    videochat_registered_invitee_assert((int) ((($joinPayload['result'] ?? [])['target_user'] ?? [])['id'] ?? 0) === $inviteeUserId, 'join response should keep registered invitee as target');
    videochat_registered_invitee_assert((string) ((($joinPayload['result'] ?? [])['link_kind'] ?? '')) === 'personal', 'join response should remain a personalized link');
    videochat_registered_invitee_assert((string) (((($joinPayload['result'] ?? [])['call'] ?? [])['id'] ?? '')) === $callId, 'join response call id should match invitation');

    $guestCountBefore = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE lower(email) LIKE 'guest+%@videochat.local'")->fetchColumn();
    $temporaryAuditBefore = (int) $pdo->query("SELECT COUNT(*) FROM videochat_audit_events WHERE event_type = 'temporary_account_created'")->fetchColumn();

    $issuedCallAccessSessionId = 'sess_registered_invitee_call_access';
    $sessionResponse = videochat_handle_call_routes(
        '/api/call-access/' . $accessId . '/session',
        'POST',
        [
            'method' => 'POST',
            'uri' => '/api/call-access/' . $accessId . '/session',
            'headers' => [
                'Authorization' => 'Bearer ' . $existingInviteeSessionId,
                'Content-Type' => 'application/json',
                'User-Agent' => 'registered-logged-in-invitee-contract',
            ],
            'remote_address' => '127.0.0.1',
            'body' => json_encode([
                'verified_user_id' => $inviteeUserId,
                'verified_session_id' => $existingInviteeSessionId,
                'guest_name' => 'Temporary Link Guest',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
        [],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase,
        static fn (): string => $issuedCallAccessSessionId
    );
    videochat_registered_invitee_assert((int) ($sessionResponse['status'] ?? 0) === 200, 'logged-in invitee session should issue');
    videochat_registered_invitee_assert_no_leak($sessionResponse, $privacyNeedles, 'logged-in invitee session response');
    $sessionPayload = videochat_registered_invitee_decode($sessionResponse);
    $sessionUser = is_array(($sessionPayload['result'] ?? [])['user'] ?? null) ? ($sessionPayload['result'] ?? [])['user'] : [];
    videochat_registered_invitee_assert((int) ($sessionUser['id'] ?? 0) === $inviteeUserId, 'issued call-access session should remain the registered invitee');
    videochat_registered_invitee_assert((string) ($sessionUser['account_type'] ?? '') === 'account', 'issued session should use a registered account');
    videochat_registered_invitee_assert((bool) ($sessionUser['is_guest'] ?? true) === false, 'issued session should not use a temporary guest');

    $guestCountAfter = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE lower(email) LIKE 'guest+%@videochat.local'")->fetchColumn();
    videochat_registered_invitee_assert($guestCountAfter === $guestCountBefore, 'logged-in personalized invite must not create a temporary guest account');
    $temporaryAuditAfter = (int) $pdo->query("SELECT COUNT(*) FROM videochat_audit_events WHERE event_type = 'temporary_account_created'")->fetchColumn();
    videochat_registered_invitee_assert($temporaryAuditAfter === $temporaryAuditBefore, 'logged-in personalized invite must not audit temporary account creation');
    $ignoredGuestNameRows = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE display_name = 'Temporary Link Guest'")->fetchColumn();
    videochat_registered_invitee_assert($ignoredGuestNameRows === 0, 'logged-in personalized invite must ignore guest_name identity takeover input');

    $existingSessionRows = (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE id = '{$existingInviteeSessionId}' AND user_id = {$inviteeUserId} AND revoked_at IS NULL")->fetchColumn();
    videochat_registered_invitee_assert($existingSessionRows === 1, 'existing registered invitee session should remain active');
    $issuedSessionRows = (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE id = '{$issuedCallAccessSessionId}' AND user_id = {$inviteeUserId} AND revoked_at IS NULL")->fetchColumn();
    videochat_registered_invitee_assert($issuedSessionRows === 1, 'issued call-access session should bind the same registered account');

    $binding = videochat_fetch_call_access_session_binding($pdo, $issuedCallAccessSessionId);
    videochat_registered_invitee_assert(is_array($binding), 'call-access session binding should exist');
    videochat_registered_invitee_assert((string) ($binding['access_id'] ?? '') === $accessId, 'binding access id should match personalized invite');
    videochat_registered_invitee_assert((string) ($binding['call_id'] ?? '') === $callId, 'binding call id should match invited call');
    videochat_registered_invitee_assert((int) ($binding['user_id'] ?? 0) === $inviteeUserId, 'binding should use registered invitee user id');
    videochat_registered_invitee_assert((string) ($binding['link_kind'] ?? '') === 'personal', 'binding should remain personal');

    $inviteeAfter = $pdo->query("SELECT email, display_name, password_hash, status FROM users WHERE id = {$inviteeUserId} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    videochat_registered_invitee_assert(is_array($inviteeAfter), 'registered invitee should still exist after join');
    foreach (['email', 'display_name', 'password_hash', 'status'] as $field) {
        videochat_registered_invitee_assert((string) ($inviteeAfter[$field] ?? '') === (string) ($inviteeBefore[$field] ?? ''), "logged-in invite must not overwrite invitee {$field}");
    }

    $accountComparedRows = (int) $pdo->query("SELECT COUNT(*) FROM videochat_audit_events WHERE event_type = 'call_access_account_compared'")->fetchColumn();
    videochat_registered_invitee_assert($accountComparedRows >= 1, 'logged-in personalized invite should audit account comparison');
    $strongMismatchRows = (int) $pdo->query("SELECT COUNT(*) FROM videochat_audit_events WHERE event_type = 'call_access_strong_mismatch_denied'")->fetchColumn();
    videochat_registered_invitee_assert($strongMismatchRows === 0, 'matching registered invitee should not trigger strong mismatch denial');

    $auth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . $issuedCallAccessSessionId . '&room=' . $callId . '&call_id=' . $callId,
            'headers' => ['Authorization' => 'Bearer ' . $issuedCallAccessSessionId],
        ],
        'websocket'
    );
    videochat_registered_invitee_assert((bool) ($auth['ok'] ?? false), 'issued registered invitee session should authenticate for websocket');
    videochat_registered_invitee_assert((int) (($auth['user'] ?? [])['id'] ?? 0) === $inviteeUserId, 'websocket auth should keep registered invitee identity');

    $pendingResolution = videochat_realtime_resolve_connection_rooms($auth, $callId, $openDatabase, $callId);
    videochat_registered_invitee_assert((string) ($pendingResolution['initial_room_id'] ?? '') === videochat_realtime_waiting_room_id(), 'registered invitee should start in waiting room until host admission');
    videochat_registered_invitee_assert((string) ($pendingResolution['requested_room_id'] ?? '') === $callId, 'registered invitee should keep invited room as requested room');
    videochat_registered_invitee_assert((string) ($pendingResolution['pending_room_id'] ?? '') === $callId, 'registered invitee should queue for invited room admission');

    $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET invite_state = 'allowed'
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
SQL
    )->execute([
        ':call_id' => $callId,
        ':user_id' => $inviteeUserId,
    ]);

    $allowedResolution = videochat_realtime_resolve_connection_rooms($auth, $callId, $openDatabase, $callId);
    videochat_registered_invitee_assert((string) ($allowedResolution['initial_room_id'] ?? '') === $callId, 'host-admitted registered invitee should enter the call room');
    videochat_registered_invitee_assert((string) ($allowedResolution['pending_room_id'] ?? '') === '', 'host-admitted registered invitee should no longer be pending');

    @unlink($databasePath);
    fwrite(STDOUT, "[call-access-registered-logged-in-invitee-contract] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-registered-logged-in-invitee-contract] ERROR: ' . $error->getMessage() . "\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
} finally {
    if (isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
