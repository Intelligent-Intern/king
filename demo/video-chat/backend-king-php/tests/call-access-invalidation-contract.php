<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../http/module_calls.php';
require_once __DIR__ . '/../http/module_realtime.php';

function videochat_call_access_invalidation_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-invalidation-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_call_access_invalidation_decode(array $response): array
{
    $payload = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($payload) ? $payload : [];
}

function videochat_call_access_invalidation_call_room(PDO $pdo, string $callId): string
{
    $query = $pdo->prepare('SELECT room_id FROM calls WHERE id = :id LIMIT 1');
    $query->execute([':id' => $callId]);
    $roomId = trim((string) ($query->fetchColumn() ?: ''));

    return $roomId === '' ? $callId : $roomId;
}

function videochat_call_access_invalidation_create_personal_fixture(
    PDO $pdo,
    int $adminUserId,
    int $invitedUserId,
    string $title
): array {
    $createCall = videochat_create_call($pdo, $adminUserId, [
        'title' => $title,
        'starts_at' => '2026-09-05T09:00:00Z',
        'ends_at' => '2026-09-05T10:00:00Z',
        'internal_participant_user_ids' => [$invitedUserId],
        'external_participants' => [],
    ]);
    videochat_call_access_invalidation_assert((bool) ($createCall['ok'] ?? false), "{$title}: call should be created");
    $callId = (string) (($createCall['call'] ?? [])['id'] ?? '');
    videochat_call_access_invalidation_assert($callId !== '', "{$title}: call id should be present");

    $access = videochat_create_call_access_link_for_user($pdo, $callId, $adminUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $invitedUserId,
    ]);
    videochat_call_access_invalidation_assert((bool) ($access['ok'] ?? false), "{$title}: personal access link should be created");
    $accessId = (string) (($access['access_link'] ?? [])['id'] ?? '');
    videochat_call_access_invalidation_assert($accessId !== '', "{$title}: personal access id should be present");

    return [
        'call_id' => $callId,
        'room_id' => videochat_call_access_invalidation_call_room($pdo, $callId),
        'access_id' => $accessId,
        'title' => $title,
    ];
}

function videochat_call_access_invalidation_create_open_fixture(
    PDO $pdo,
    int $adminUserId,
    string $title
): array {
    $createCall = videochat_create_call($pdo, $adminUserId, [
        'title' => $title,
        'access_mode' => 'free_for_all',
        'starts_at' => '2026-09-05T09:00:00Z',
        'ends_at' => '2026-09-05T10:00:00Z',
        'internal_participant_user_ids' => [],
        'external_participants' => [],
    ]);
    videochat_call_access_invalidation_assert((bool) ($createCall['ok'] ?? false), "{$title}: open call should be created");
    $callId = (string) (($createCall['call'] ?? [])['id'] ?? '');
    videochat_call_access_invalidation_assert($callId !== '', "{$title}: open call id should be present");

    $access = videochat_create_call_access_link_for_user($pdo, $callId, $adminUserId, 'admin', [
        'link_kind' => 'open',
    ]);
    videochat_call_access_invalidation_assert((bool) ($access['ok'] ?? false), "{$title}: open access link should be created");
    $accessId = (string) (($access['access_link'] ?? [])['id'] ?? '');
    videochat_call_access_invalidation_assert($accessId !== '', "{$title}: open access id should be present");

    return [
        'call_id' => $callId,
        'room_id' => videochat_call_access_invalidation_call_room($pdo, $callId),
        'access_id' => $accessId,
        'title' => $title,
    ];
}

function videochat_call_access_invalidation_issue_session(
    PDO $pdo,
    string $accessId,
    string $sessionId,
    string $label,
    array $options = []
): int {
    $issued = videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        static fn (): string => $sessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => "call-access-invalidation-contract/{$label}"],
        $options
    );
    videochat_call_access_invalidation_assert((bool) ($issued['ok'] ?? false), "{$label}: session should issue before invalidation");
    videochat_call_access_invalidation_assert((string) (($issued['session'] ?? [])['id'] ?? '') === $sessionId, "{$label}: issued session id mismatch");

    $userId = (int) (($issued['user'] ?? [])['id'] ?? 0);
    videochat_call_access_invalidation_assert($userId > 0, "{$label}: issued user id should be present");

    return $userId;
}

function videochat_call_access_invalidation_assert_session_room(
    PDO $pdo,
    string $callId,
    string $roomId,
    string $sessionId,
    string $expectedInitialRoomId,
    string $label
): void {
    $auth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . rawurlencode($sessionId) . '&room=' . rawurlencode($roomId) . '&call_id=' . rawurlencode($callId),
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        ],
        'websocket'
    );
    videochat_call_access_invalidation_assert((bool) ($auth['ok'] ?? false), "{$label}: session should authenticate before invalidation");

    $resolution = videochat_realtime_resolve_connection_rooms(
        $auth,
        $roomId,
        static fn (): PDO => $pdo,
        $callId
    );
    videochat_call_access_invalidation_assert((string) ($resolution['initial_room_id'] ?? '') === $expectedInitialRoomId, "{$label}: initial room mismatch");
    if ($expectedInitialRoomId === videochat_realtime_waiting_room_id()) {
        videochat_call_access_invalidation_assert((string) ($resolution['pending_room_id'] ?? '') === $roomId, "{$label}: pending room should stay call-bound");
    } else {
        videochat_call_access_invalidation_assert((string) ($resolution['pending_room_id'] ?? '') === '', "{$label}: active room must not retain pending state");
    }
}

function videochat_call_access_invalidation_assert_session_rejected(
    PDO $pdo,
    string $sessionId,
    string $expectedReason,
    string $label
): void {
    $validation = videochat_validate_session_token($pdo, $sessionId);
    videochat_call_access_invalidation_assert(!(bool) ($validation['ok'] ?? true), "{$label}: session must fail after invalidation");
    videochat_call_access_invalidation_assert((string) ($validation['reason'] ?? '') === $expectedReason, "{$label}: session reason mismatch");
    videochat_call_access_invalidation_assert(videochat_fetch_call_access_session_binding($pdo, $sessionId) === null, "{$label}: stale binding must not resolve");

    $auth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . rawurlencode($sessionId),
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        ],
        'websocket'
    );
    videochat_call_access_invalidation_assert(!(bool) ($auth['ok'] ?? true), "{$label}: websocket auth must fail after invalidation");
    videochat_call_access_invalidation_assert((string) ($auth['reason'] ?? '') === $expectedReason, "{$label}: websocket auth reason mismatch");
}

function videochat_call_access_invalidation_set_participant_state(
    PDO $pdo,
    string $callId,
    int $userId,
    string $state,
    bool $joined
): void {
    $sql = $joined
        ? <<<'SQL'
UPDATE call_participants
SET invite_state = :invite_state,
    joined_at = :now,
    left_at = NULL
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
SQL
        : <<<'SQL'
UPDATE call_participants
SET invite_state = :invite_state,
    joined_at = NULL,
    left_at = NULL
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
SQL
    ;
    $statement = $pdo->prepare($sql);
    $params = [
        ':invite_state' => $state,
        ':call_id' => $callId,
        ':user_id' => $userId,
    ];
    if ($joined) {
        $params[':now'] = gmdate('c');
    }
    $statement->execute($params);
    videochat_call_access_invalidation_assert($statement->rowCount() > 0, "participant {$userId} state should update");
}

function videochat_call_access_invalidation_cancel_participant(PDO $pdo, string $callId, int $userId): void
{
    $statement = $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET invite_state = 'cancelled',
    left_at = CASE
        WHEN joined_at IS NOT NULL AND joined_at <> '' AND (left_at IS NULL OR left_at = '') THEN :left_at
        ELSE left_at
    END
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
SQL
    );
    $statement->execute([
        ':left_at' => gmdate('c'),
        ':call_id' => $callId,
        ':user_id' => $userId,
    ]);
    videochat_call_access_invalidation_assert($statement->rowCount() > 0, 'participant cancellation should update a row');
}

function videochat_call_access_invalidation_guest_count(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM users WHERE email LIKE 'guest+%@videochat.local'")->fetchColumn();
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-invalidation-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-access-invalidation-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $invitedUser = $pdo->query("SELECT id, email, display_name FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetch();
    videochat_call_access_invalidation_assert($adminUserId > 0, 'expected seeded admin user');
    videochat_call_access_invalidation_assert(is_array($invitedUser), 'expected seeded invited user');
    $invitedUserId = (int) ($invitedUser['id'] ?? 0);
    $invitedEmail = (string) ($invitedUser['email'] ?? '');
    $invitedDisplayName = (string) ($invitedUser['display_name'] ?? '');
    videochat_call_access_invalidation_assert($invitedUserId > 0, 'expected invited user id');
    videochat_call_access_invalidation_assert($invitedEmail !== '', 'expected invited user email');

    $createCall = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Call Access Invalidation Secret Title',
        'starts_at' => '2026-09-05T09:00:00Z',
        'ends_at' => '2026-09-05T10:00:00Z',
        'internal_participant_user_ids' => [$invitedUserId],
        'external_participants' => [],
    ]);
    videochat_call_access_invalidation_assert((bool) ($createCall['ok'] ?? false), 'call should be created');
    $callId = (string) (($createCall['call'] ?? [])['id'] ?? '');
    videochat_call_access_invalidation_assert($callId !== '', 'call id should be present');

    $access = videochat_create_call_access_link_for_user($pdo, $callId, $adminUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $invitedUserId,
    ]);
    videochat_call_access_invalidation_assert((bool) ($access['ok'] ?? false), 'personal access link should be created');
    $accessId = (string) (($access['access_link'] ?? [])['id'] ?? '');
    videochat_call_access_invalidation_assert($accessId !== '', 'personal access id should be present');

    $initialResolution = videochat_resolve_call_access_public($pdo, $accessId);
    videochat_call_access_invalidation_assert((bool) ($initialResolution['ok'] ?? false), 'personal link should resolve before invalidation');
    videochat_call_access_invalidation_assert((int) (($initialResolution['target_user'] ?? [])['id'] ?? 0) === $invitedUserId, 'pre-invalidation target user mismatch');

    $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET invite_state = 'cancelled'
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
SQL
    )->execute([
        ':call_id' => $callId,
        ':user_id' => $invitedUserId,
    ]);

    $invalidatedLink = videochat_fetch_call_access_link($pdo, $accessId);
    videochat_call_access_invalidation_assert(is_array($invalidatedLink), 'invalidated access link row should remain persisted');
    videochat_call_access_invalidation_assert(videochat_call_access_link_is_invalidated($pdo, $invalidatedLink), 'domain should classify cancelled participant invite as invalidated');

    $invalidatedResolution = videochat_resolve_call_access_public($pdo, $accessId);
    videochat_call_access_invalidation_assert(!(bool) ($invalidatedResolution['ok'] ?? true), 'invalidated link must not resolve');
    videochat_call_access_invalidation_assert((string) ($invalidatedResolution['reason'] ?? '') === 'not_found', 'invalidated link should fail as safe invalid-link state');
    videochat_call_access_invalidation_assert(($invalidatedResolution['access_link'] ?? null) === null, 'invalidated resolution must not expose access link metadata');
    videochat_call_access_invalidation_assert(($invalidatedResolution['call'] ?? null) === null, 'invalidated resolution must not expose call data');
    videochat_call_access_invalidation_assert(($invalidatedResolution['target_user'] ?? null) === null, 'invalidated resolution must not expose target user data');
    videochat_call_access_invalidation_assert((($invalidatedResolution['target_hint'] ?? [])['participant_email'] ?? null) === null, 'invalidated resolution must not expose participant email hint');

    $sessionIssueAttempts = 0;
    $sessionResult = videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        static function () use (&$sessionIssueAttempts): string {
            $sessionIssueAttempts += 1;
            return 'sess_call_access_invalidated_should_not_issue';
        },
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-invalidation-contract']
    );
    videochat_call_access_invalidation_assert(!(bool) ($sessionResult['ok'] ?? true), 'invalidated personalized link must not create a fresh session');
    videochat_call_access_invalidation_assert((string) ($sessionResult['reason'] ?? '') === 'not_found', 'invalidated session attempt should fail as safe invalid-link state');
    videochat_call_access_invalidation_assert($sessionIssueAttempts === 0, 'session id issuer must not run for invalidated link');
    videochat_call_access_invalidation_assert(($sessionResult['session'] ?? null) === null, 'invalidated session attempt must not expose session');
    videochat_call_access_invalidation_assert(($sessionResult['user'] ?? null) === null, 'invalidated session attempt must not expose user');
    videochat_call_access_invalidation_assert(($sessionResult['access_link'] ?? null) === null, 'invalidated session attempt must not expose access link');
    videochat_call_access_invalidation_assert(($sessionResult['call'] ?? null) === null, 'invalidated session attempt must not expose call');

    $sessionCount = (int) $pdo->query("SELECT COUNT(*) FROM sessions WHERE id = 'sess_call_access_invalidated_should_not_issue'")->fetchColumn();
    videochat_call_access_invalidation_assert($sessionCount === 0, 'invalidated link must not persist a fresh session');
    $bindingCount = (int) $pdo->query("SELECT COUNT(*) FROM call_access_sessions WHERE access_id = " . $pdo->quote($accessId))->fetchColumn();
    videochat_call_access_invalidation_assert($bindingCount === 0, 'invalidated link must not persist a call-access session binding');

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
    $openDatabase = static fn (): PDO => videochat_open_sqlite_pdo($databasePath);

    $joinResponse = videochat_handle_call_routes(
        '/api/call-access/' . $accessId . '/join',
        'GET',
        ['method' => 'GET', 'uri' => '/api/call-access/' . $accessId . '/join', 'headers' => []],
        [],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_access_invalidation_assert(is_array($joinResponse), 'invalidated join response should be an array');
    videochat_call_access_invalidation_assert((int) ($joinResponse['status'] ?? 0) === 404, 'invalidated join should return safe not-found status');
    $joinBody = (string) ($joinResponse['body'] ?? '');
    $joinPayload = videochat_call_access_invalidation_decode($joinResponse);
    videochat_call_access_invalidation_assert((string) (($joinPayload['error'] ?? [])['code'] ?? '') === 'call_access_not_found', 'invalidated join error code mismatch');

    $httpSessionIssuerCalls = 0;
    $httpSessionResponse = videochat_handle_call_routes(
        '/api/call-access/' . $accessId . '/session',
        'POST',
        [
            'method' => 'POST',
            'uri' => '/api/call-access/' . $accessId . '/session',
            'headers' => ['User-Agent' => 'call-access-invalidation-contract-http'],
            'remote_address' => '127.0.0.1',
            'body' => '{}',
        ],
        [],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase,
        static function () use (&$httpSessionIssuerCalls): string {
            $httpSessionIssuerCalls += 1;
            return 'sess_call_access_invalidated_http_should_not_issue';
        }
    );
    videochat_call_access_invalidation_assert(is_array($httpSessionResponse), 'invalidated HTTP session response should be an array');
    videochat_call_access_invalidation_assert((int) ($httpSessionResponse['status'] ?? 0) === 404, 'invalidated HTTP session should return safe not-found status');
    videochat_call_access_invalidation_assert($httpSessionIssuerCalls === 0, 'HTTP session issuer must not run for invalidated link');
    $httpSessionBody = (string) ($httpSessionResponse['body'] ?? '');
    $httpSessionPayload = videochat_call_access_invalidation_decode($httpSessionResponse);
    videochat_call_access_invalidation_assert((string) (($httpSessionPayload['error'] ?? [])['code'] ?? '') === 'call_access_not_found', 'invalidated HTTP session error code mismatch');

    foreach ([$joinBody, $httpSessionBody] as $body) {
        videochat_call_access_invalidation_assert(!str_contains($body, $invitedEmail), 'invalidated response must not leak invited email');
        if ($invitedDisplayName !== '') {
            videochat_call_access_invalidation_assert(!str_contains($body, $invitedDisplayName), 'invalidated response must not leak invited display name');
        }
        videochat_call_access_invalidation_assert(!str_contains($body, 'Call Access Invalidation Secret Title'), 'invalidated response must not leak call title');
        videochat_call_access_invalidation_assert(!str_contains($body, $callId), 'invalidated response must not leak call id');
    }

    $lobbyFixture = videochat_call_access_invalidation_create_personal_fixture(
        $pdo,
        $adminUserId,
        $invitedUserId,
        'Call Access Invalidation Lobby Active Secret'
    );
    $lobbySessionA = 'sess_call_access_invalidated_lobby_a';
    $lobbySessionB = 'sess_call_access_invalidated_lobby_b';
    videochat_call_access_invalidation_issue_session($pdo, (string) $lobbyFixture['access_id'], $lobbySessionA, 'personal lobby A');
    videochat_call_access_invalidation_issue_session($pdo, (string) $lobbyFixture['access_id'], $lobbySessionB, 'personal lobby B');
    videochat_call_access_invalidation_assert_session_room($pdo, (string) $lobbyFixture['call_id'], (string) $lobbyFixture['room_id'], $lobbySessionA, videochat_realtime_waiting_room_id(), 'personal lobby A');
    videochat_call_access_invalidation_assert_session_room($pdo, (string) $lobbyFixture['call_id'], (string) $lobbyFixture['room_id'], $lobbySessionB, videochat_realtime_waiting_room_id(), 'personal lobby B');
    videochat_call_access_invalidation_cancel_participant($pdo, (string) $lobbyFixture['call_id'], $invitedUserId);
    videochat_call_access_invalidation_assert_session_rejected($pdo, $lobbySessionA, 'call_access_participant_removed', 'personal lobby A');
    videochat_call_access_invalidation_assert_session_rejected($pdo, $lobbySessionB, 'call_access_participant_removed', 'personal lobby B');
    $lobbyFreshIssuerCalls = 0;
    $lobbyFresh = videochat_issue_session_for_call_access(
        $pdo,
        (string) $lobbyFixture['access_id'],
        static function () use (&$lobbyFreshIssuerCalls): string {
            $lobbyFreshIssuerCalls += 1;
            return 'sess_call_access_invalidated_lobby_fresh_should_not_issue';
        },
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-invalidation-contract/lobby-fresh']
    );
    videochat_call_access_invalidation_assert(!(bool) ($lobbyFresh['ok'] ?? true), 'personal lobby invalidation must reject fresh session');
    videochat_call_access_invalidation_assert((string) ($lobbyFresh['reason'] ?? '') === 'not_found', 'personal lobby fresh reason mismatch');
    videochat_call_access_invalidation_assert($lobbyFreshIssuerCalls === 0, 'personal lobby invalidation must not call fresh issuer');

    $activeFixture = videochat_call_access_invalidation_create_personal_fixture(
        $pdo,
        $adminUserId,
        $invitedUserId,
        'Call Access Invalidation In Call Active Secret'
    );
    videochat_call_access_invalidation_set_participant_state($pdo, (string) $activeFixture['call_id'], $invitedUserId, 'allowed', true);
    $activeSessionA = 'sess_call_access_invalidated_active_a';
    $activeSessionB = 'sess_call_access_invalidated_active_b';
    videochat_call_access_invalidation_issue_session($pdo, (string) $activeFixture['access_id'], $activeSessionA, 'personal active A');
    videochat_call_access_invalidation_issue_session($pdo, (string) $activeFixture['access_id'], $activeSessionB, 'personal active B');
    videochat_call_access_invalidation_assert_session_room($pdo, (string) $activeFixture['call_id'], (string) $activeFixture['room_id'], $activeSessionA, (string) $activeFixture['room_id'], 'personal active A');
    videochat_call_access_invalidation_assert_session_room($pdo, (string) $activeFixture['call_id'], (string) $activeFixture['room_id'], $activeSessionB, (string) $activeFixture['room_id'], 'personal active B');
    videochat_call_access_invalidation_cancel_participant($pdo, (string) $activeFixture['call_id'], $invitedUserId);
    videochat_call_access_invalidation_assert_session_rejected($pdo, $activeSessionA, 'call_access_participant_removed', 'personal active A');
    videochat_call_access_invalidation_assert_session_rejected($pdo, $activeSessionB, 'call_access_participant_removed', 'personal active B');
    $activeParticipant = $pdo->prepare(
        <<<'SQL'
SELECT invite_state, joined_at, left_at
FROM call_participants
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
LIMIT 1
SQL
    );
    $activeParticipant->execute([
        ':call_id' => (string) $activeFixture['call_id'],
        ':user_id' => $invitedUserId,
    ]);
    $activeParticipantRow = $activeParticipant->fetch(PDO::FETCH_ASSOC);
    videochat_call_access_invalidation_assert(is_array($activeParticipantRow), 'active participant should remain inspectable');
    videochat_call_access_invalidation_assert((string) ($activeParticipantRow['invite_state'] ?? '') === 'cancelled', 'active participant should be cancelled');
    videochat_call_access_invalidation_assert(trim((string) ($activeParticipantRow['joined_at'] ?? '')) !== '', 'active participant joined_at should be preserved');
    videochat_call_access_invalidation_assert(trim((string) ($activeParticipantRow['left_at'] ?? '')) !== '', 'active participant should receive left_at');

    $anonymousLobby = videochat_call_access_invalidation_create_open_fixture(
        $pdo,
        $adminUserId,
        'Call Access Disabled Anonymous Lobby Secret'
    );
    $anonymousLobbySessionA = 'sess_call_access_disabled_anon_lobby_a';
    $anonymousLobbySessionB = 'sess_call_access_disabled_anon_lobby_b';
    $anonymousLobbyUserA = videochat_call_access_invalidation_issue_session($pdo, (string) $anonymousLobby['access_id'], $anonymousLobbySessionA, 'anonymous lobby A', ['guest_name' => 'Disabled Anonymous Lobby A']);
    $anonymousLobbyUserB = videochat_call_access_invalidation_issue_session($pdo, (string) $anonymousLobby['access_id'], $anonymousLobbySessionB, 'anonymous lobby B', ['guest_name' => 'Disabled Anonymous Lobby B']);
    videochat_call_access_invalidation_set_participant_state($pdo, (string) $anonymousLobby['call_id'], $anonymousLobbyUserA, 'pending', false);
    videochat_call_access_invalidation_set_participant_state($pdo, (string) $anonymousLobby['call_id'], $anonymousLobbyUserB, 'pending', false);
    videochat_call_access_invalidation_assert_session_room($pdo, (string) $anonymousLobby['call_id'], (string) $anonymousLobby['room_id'], $anonymousLobbySessionA, videochat_realtime_waiting_room_id(), 'anonymous lobby A');
    videochat_call_access_invalidation_assert_session_room($pdo, (string) $anonymousLobby['call_id'], (string) $anonymousLobby['room_id'], $anonymousLobbySessionB, videochat_realtime_waiting_room_id(), 'anonymous lobby B');
    $anonymousLobbyDisable = videochat_disable_anonymous_call_access_link($pdo, (string) $anonymousLobby['access_id'], $adminUserId, [
        'invalidation_reason' => 'contract_anonymous_link_disable_while_lobby',
    ]);
    videochat_call_access_invalidation_assert((bool) ($anonymousLobbyDisable['ok'] ?? false), 'anonymous lobby disable should succeed');
    videochat_call_access_invalidation_assert((int) ($anonymousLobbyDisable['access_session_count'] ?? 0) === 2, 'anonymous lobby disable should count both browser sessions');
    videochat_call_access_invalidation_assert(videochat_call_access_link_is_disabled($anonymousLobbyDisable['access_link'] ?? null), 'anonymous lobby disabled link should expose disabled_at');
    videochat_call_access_invalidation_assert_session_rejected($pdo, $anonymousLobbySessionA, 'call_access_link_invalidated', 'anonymous lobby A');
    videochat_call_access_invalidation_assert_session_rejected($pdo, $anonymousLobbySessionB, 'call_access_link_invalidated', 'anonymous lobby B');
    $anonymousLobbyAuditPayload = (array) ((($anonymousLobbyDisable['audit_event'] ?? [])['payload'] ?? []));
    videochat_call_access_invalidation_assert(($anonymousLobbyAuditPayload['raw_link_identifier_logged'] ?? true) === false, 'anonymous lobby audit must not log raw link id');
    videochat_call_access_invalidation_assert(($anonymousLobbyAuditPayload['raw_credential_identifier_logged'] ?? true) === false, 'anonymous lobby audit must not log raw credentials');
    videochat_call_access_invalidation_assert(($anonymousLobbyAuditPayload['raw_guest_identity_logged'] ?? true) === false, 'anonymous lobby audit must not log raw guest identity');
    $anonymousLobbyGuestCount = videochat_call_access_invalidation_guest_count($pdo);
    $anonymousLobbyFreshIssuerCalls = 0;
    $anonymousLobbyFresh = videochat_issue_session_for_call_access(
        $pdo,
        (string) $anonymousLobby['access_id'],
        static function () use (&$anonymousLobbyFreshIssuerCalls): string {
            $anonymousLobbyFreshIssuerCalls += 1;
            return 'sess_call_access_disabled_anon_lobby_fresh_should_not_issue';
        },
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-invalidation-contract/anonymous-lobby-fresh'],
        ['guest_name' => 'Disabled Anonymous Lobby Fresh']
    );
    videochat_call_access_invalidation_assert(!(bool) ($anonymousLobbyFresh['ok'] ?? true), 'disabled anonymous lobby link must reject fresh session');
    videochat_call_access_invalidation_assert((string) ($anonymousLobbyFresh['reason'] ?? '') === 'not_found', 'disabled anonymous lobby fresh reason mismatch');
    videochat_call_access_invalidation_assert($anonymousLobbyFreshIssuerCalls === 0, 'disabled anonymous lobby link must not call fresh issuer');
    videochat_call_access_invalidation_assert(videochat_call_access_invalidation_guest_count($pdo) === $anonymousLobbyGuestCount, 'disabled anonymous lobby link must not create a replacement guest');

    $anonymousActive = videochat_call_access_invalidation_create_open_fixture(
        $pdo,
        $adminUserId,
        'Call Access Disabled Anonymous In Call Secret'
    );
    $anonymousActiveSessionA = 'sess_call_access_disabled_anon_active_a';
    $anonymousActiveSessionB = 'sess_call_access_disabled_anon_active_b';
    $anonymousActiveUserA = videochat_call_access_invalidation_issue_session($pdo, (string) $anonymousActive['access_id'], $anonymousActiveSessionA, 'anonymous active A', ['guest_name' => 'Disabled Anonymous Active A']);
    $anonymousActiveUserB = videochat_call_access_invalidation_issue_session($pdo, (string) $anonymousActive['access_id'], $anonymousActiveSessionB, 'anonymous active B', ['guest_name' => 'Disabled Anonymous Active B']);
    videochat_call_access_invalidation_set_participant_state($pdo, (string) $anonymousActive['call_id'], $anonymousActiveUserA, 'allowed', true);
    videochat_call_access_invalidation_set_participant_state($pdo, (string) $anonymousActive['call_id'], $anonymousActiveUserB, 'allowed', true);
    videochat_call_access_invalidation_assert_session_room($pdo, (string) $anonymousActive['call_id'], (string) $anonymousActive['room_id'], $anonymousActiveSessionA, (string) $anonymousActive['room_id'], 'anonymous active A');
    videochat_call_access_invalidation_assert_session_room($pdo, (string) $anonymousActive['call_id'], (string) $anonymousActive['room_id'], $anonymousActiveSessionB, (string) $anonymousActive['room_id'], 'anonymous active B');
    $anonymousActiveDisable = videochat_disable_anonymous_call_access_link($pdo, (string) $anonymousActive['access_id'], $adminUserId, [
        'invalidation_reason' => 'contract_anonymous_link_disable_while_in_call',
    ]);
    videochat_call_access_invalidation_assert((bool) ($anonymousActiveDisable['ok'] ?? false), 'anonymous active disable should succeed');
    videochat_call_access_invalidation_assert((int) ($anonymousActiveDisable['access_session_count'] ?? 0) === 2, 'anonymous active disable should count both browser sessions');
    videochat_call_access_invalidation_assert_session_rejected($pdo, $anonymousActiveSessionA, 'call_access_link_invalidated', 'anonymous active A');
    videochat_call_access_invalidation_assert_session_rejected($pdo, $anonymousActiveSessionB, 'call_access_link_invalidated', 'anonymous active B');
    $disabledResolve = videochat_resolve_call_access_public($pdo, (string) $anonymousActive['access_id']);
    videochat_call_access_invalidation_assert(!(bool) ($disabledResolve['ok'] ?? true), 'disabled anonymous active link must not resolve');
    videochat_call_access_invalidation_assert((string) ($disabledResolve['reason'] ?? '') === 'not_found', 'disabled anonymous active resolve reason mismatch');
    videochat_call_access_invalidation_assert(($disabledResolve['access_link'] ?? null) === null, 'disabled anonymous resolve must redact link');
    videochat_call_access_invalidation_assert(($disabledResolve['call'] ?? null) === null, 'disabled anonymous resolve must redact call');
    videochat_call_access_invalidation_assert(($disabledResolve['target_user'] ?? null) === null, 'disabled anonymous resolve must redact user');

    $disabledJoin = videochat_handle_call_routes(
        '/api/call-access/' . (string) $anonymousActive['access_id'] . '/join',
        'GET',
        ['method' => 'GET', 'uri' => '/api/call-access/' . (string) $anonymousActive['access_id'] . '/join', 'headers' => []],
        [],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody,
        $openDatabase
    );
    videochat_call_access_invalidation_assert(is_array($disabledJoin), 'disabled anonymous join response should be an array');
    videochat_call_access_invalidation_assert((int) ($disabledJoin['status'] ?? 0) === 404, 'disabled anonymous join should return safe not-found status');
    videochat_call_access_invalidation_assert((string) ((videochat_call_access_invalidation_decode($disabledJoin)['error'] ?? [])['code'] ?? '') === 'call_access_not_found', 'disabled anonymous join error code mismatch');
    $disabledJoinBody = (string) ($disabledJoin['body'] ?? '');
    foreach ([(string) $anonymousActive['title'], (string) $anonymousActive['call_id'], (string) $anonymousActive['access_id'], $anonymousActiveSessionA, $anonymousActiveSessionB, 'Disabled Anonymous Active A', 'Disabled Anonymous Active B'] as $needle) {
        videochat_call_access_invalidation_assert(!str_contains($disabledJoinBody, $needle), "disabled anonymous join leaked {$needle}");
    }

    @unlink($databasePath);
    fwrite(STDOUT, "[call-access-invalidation-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-invalidation-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
