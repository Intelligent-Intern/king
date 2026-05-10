<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../http/module_calls.php';
require_once __DIR__ . '/../http/module_realtime.php';

$label = 'call-access-invalidation-contract';

function videochat_iam_invalidation_issue_personal_session(PDO $pdo, array $fixture, string $suffix, string $label): string
{
    $sessionId = videochat_iam_invitation_invalidation_session_id($fixture, $suffix);
    $issued = videochat_issue_session_for_call_access(
        $pdo,
        (string) ($fixture['access_id'] ?? ''),
        static fn (): string => $sessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => "{$label}/{$suffix}"]
    );
    videochat_iam_invitation_invalidation_assert((bool) ($issued['ok'] ?? false), "{$suffix}: personalized link should issue a call-access session", $label);
    videochat_iam_invitation_invalidation_assert((string) (($issued['session'] ?? [])['id'] ?? '') === $sessionId, "{$suffix}: issued session id mismatch", $label);

    return $sessionId;
}

function videochat_iam_invalidation_assert_session_room(
    PDO $pdo,
    array $fixture,
    string $sessionId,
    string $expectedInitialRoomId,
    string $label,
    string $context
): void {
    $callId = (string) ($fixture['call_id'] ?? '');
    $auth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . rawurlencode($sessionId) . '&room=' . rawurlencode($callId) . '&call_id=' . rawurlencode($callId),
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        ],
        'websocket'
    );
    videochat_iam_invitation_invalidation_assert((bool) ($auth['ok'] ?? false), "{$context}: session should authenticate before invalidation", $label);
    $resolution = videochat_realtime_resolve_connection_rooms(
        $auth,
        $callId,
        static fn (): PDO => $pdo,
        $callId
    );
    videochat_iam_invitation_invalidation_assert((string) ($resolution['initial_room_id'] ?? '') === $expectedInitialRoomId, "{$context}: initial room mismatch", $label);
    if ($expectedInitialRoomId === videochat_realtime_waiting_room_id()) {
        videochat_iam_invitation_invalidation_assert((string) ($resolution['pending_room_id'] ?? '') === $callId, "{$context}: lobby pending room should stay call-bound", $label);
    } else {
        videochat_iam_invitation_invalidation_assert((string) ($resolution['pending_room_id'] ?? '') === '', "{$context}: admitted session should not keep a pending room", $label);
    }
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
}

function videochat_iam_invalidation_mark_participant_joined(PDO $pdo, array $fixture): void
{
    $update = $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET joined_at = :joined_at,
    left_at = NULL
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
SQL
    );
    $update->execute([
        ':joined_at' => gmdate('c'),
        ':call_id' => (string) ($fixture['call_id'] ?? ''),
        ':user_id' => (int) ($fixture['invited_user_id'] ?? 0),
    ]);
}

function videochat_iam_invalidation_assert_participant_cancelled_with_left_at(PDO $pdo, array $fixture, string $label): void
{
    $query = $pdo->prepare(
        <<<'SQL'
SELECT invite_state, joined_at, left_at
FROM call_participants
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
LIMIT 1
SQL
    );
    $query->execute([
        ':call_id' => (string) ($fixture['call_id'] ?? ''),
        ':user_id' => (int) ($fixture['invited_user_id'] ?? 0),
    ]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    videochat_iam_invitation_invalidation_assert(is_array($row), 'active participant row should remain inspectable after invalidation', $label);
    videochat_iam_invitation_invalidation_assert((string) ($row['invite_state'] ?? '') === 'cancelled', 'active participant should be cancelled after invalidation', $label);
    videochat_iam_invitation_invalidation_assert(trim((string) ($row['joined_at'] ?? '')) !== '', 'active participant joined_at should be preserved', $label);
    videochat_iam_invitation_invalidation_assert(trim((string) ($row['left_at'] ?? '')) !== '', 'active participant should receive left_at when invalidated from call', $label);
}

function videochat_call_access_invalidation_contract_restart_probe(array $argv, string $label): void
{
    videochat_iam_invitation_invalidation_skip_without_sqlite($label);
    $databasePath = (string) ($argv[2] ?? '');
    $fixturePath = (string) ($argv[3] ?? '');
    videochat_iam_invitation_invalidation_assert($databasePath !== '' && is_file($databasePath), 'restart probe database is missing', $label);
    videochat_iam_invitation_invalidation_assert($fixturePath !== '' && is_file($fixturePath), 'restart probe fixture is missing', $label);

    $fixture = json_decode((string) file_get_contents($fixturePath), true);
    videochat_iam_invitation_invalidation_assert(is_array($fixture), 'restart probe fixture should decode', $label);
    $pdo = videochat_open_sqlite_pdo($databasePath);
    $invalidatedLink = videochat_fetch_call_access_link($pdo, (string) ($fixture['access_id'] ?? ''));
    videochat_iam_invitation_invalidation_assert(is_array($invalidatedLink), 'restart probe should refetch invalidated link from disk', $label);
    videochat_iam_invitation_invalidation_assert(videochat_call_access_link_is_invalidated($pdo, $invalidatedLink), 'restart probe should preserve invalidated classification', $label);
    videochat_iam_invitation_invalidation_assert_state_across_browser_device_sessions(
        $pdo,
        $fixture,
        $label,
        'application-restart-ci'
    );
}

function videochat_call_access_invalidation_contract_assert_restart_survives(
    string $databasePath,
    array $fixture,
    string $label
): void {
    $fixturePath = sys_get_temp_dir() . '/videochat-call-access-invalidation-restart-' . bin2hex(random_bytes(6)) . '.json';
    $encoded = json_encode($fixture, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    videochat_iam_invitation_invalidation_assert(is_string($encoded), 'restart fixture should encode', $label);
    file_put_contents($fixturePath, $encoded);

    $command = escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(__FILE__) . ' --restart-probe '
        . escapeshellarg($databasePath) . ' '
        . escapeshellarg($fixturePath);
    $output = [];
    $exitCode = 1;
    exec($command . ' 2>&1', $output, $exitCode);
    @unlink($fixturePath);

    videochat_iam_invitation_invalidation_assert(
        $exitCode === 0,
        'restart probe failed: ' . implode("\n", $output),
        $label
    );
}

if (($argv[1] ?? '') === '--restart-probe') {
    try {
        videochat_call_access_invalidation_contract_restart_probe($argv, $label);
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, "[{$label}] RESTART ERROR: " . $error->getMessage() . "\n");
        exit(1);
    }
}

try {
    videochat_iam_invitation_invalidation_skip_without_sqlite($label);
    [$databasePath, $pdo] = videochat_iam_invitation_invalidation_bootstrap_database('videochat-call-access-invalidation');

    $beforeUse = videochat_iam_invitation_invalidation_personal_fixture(
        $pdo,
        $label,
        'Call Access Invalidation Secret Title'
    );
    $beforeUseInvalidation = videochat_iam_invitation_invalidation_cancel_personal_invitation($pdo, $beforeUse);
    videochat_iam_invitation_invalidation_assert((bool) ($beforeUseInvalidation['ok'] ?? false), 'cancelled invite should be audit-loggable before use', $label);
    $invalidatedLink = videochat_fetch_call_access_link($pdo, (string) ($beforeUse['access_id'] ?? ''));
    videochat_iam_invitation_invalidation_assert(is_array($invalidatedLink), 'invalidated access link row should remain persisted', $label);
    videochat_iam_invitation_invalidation_assert(videochat_call_access_link_is_invalidated($pdo, $invalidatedLink), 'domain should classify cancelled participant invite as invalidated', $label);
    videochat_iam_invitation_invalidation_assert_audit_logged(
        $pdo,
        $beforeUse,
        $label,
        'participant_invite_cancelled'
    );
    videochat_iam_invitation_invalidation_assert_fresh_link_rejected(
        $pdo,
        $beforeUse,
        $label,
        'not_found',
        404,
        'call_access_not_found'
    );
    videochat_iam_invitation_invalidation_assert_state_across_browser_device_sessions(
        $pdo,
        $beforeUse,
        $label,
        'invalidated-before-use'
    );

    $afterUse = videochat_iam_invitation_invalidation_personal_fixture(
        $pdo,
        $label,
        'Call Access Invalidation Rejoin Secret Title'
    );
    videochat_iam_invitation_invalidation_assert_existing_session_rejected_after_cancel($pdo, $afterUse, $label);
    videochat_iam_invitation_invalidation_assert_fresh_link_rejected(
        $pdo,
        $afterUse,
        $label,
        'not_found',
        404,
        'call_access_not_found'
    );

    $inLobby = videochat_iam_invitation_invalidation_personal_fixture(
        $pdo,
        $label,
        'Call Access Invalidation Lobby Secret Title'
    );
    $lobbySessionA = videochat_iam_invalidation_issue_personal_session($pdo, $inLobby, 'lobby_a', $label);
    $lobbySessionB = videochat_iam_invalidation_issue_personal_session($pdo, $inLobby, 'lobby_b', $label);
    videochat_iam_invalidation_assert_session_room($pdo, $inLobby, $lobbySessionA, videochat_realtime_waiting_room_id(), $label, 'lobby browser A');
    videochat_iam_invalidation_assert_session_room($pdo, $inLobby, $lobbySessionB, videochat_realtime_waiting_room_id(), $label, 'lobby browser B');
    $lobbyInvalidation = videochat_iam_invitation_invalidation_cancel_personal_invitation($pdo, $inLobby, [
        'session_id' => $lobbySessionA,
        'invalidation_reason' => 'participant_invite_cancelled_while_lobby',
    ]);
    videochat_iam_invitation_invalidation_assert((bool) ($lobbyInvalidation['ok'] ?? false), 'lobby invite invalidation should succeed', $label);
    videochat_iam_invitation_invalidation_assert((int) ($lobbyInvalidation['access_session_count'] ?? 0) === 2, 'lobby invalidation should see both browser sessions', $label);
    videochat_iam_invalidation_assert_sessions_invalidated($pdo, [$lobbySessionA, $lobbySessionB], $label, 'lobby invalidation');
    videochat_iam_invitation_invalidation_assert_fresh_link_rejected(
        $pdo,
        $inLobby,
        $label,
        'not_found',
        404,
        'call_access_not_found'
    );

    $inCall = videochat_iam_invitation_invalidation_personal_fixture(
        $pdo,
        $label,
        'Call Access Invalidation Active Call Secret Title'
    );
    videochat_iam_invitation_invalidation_set_invite_state($pdo, $inCall, 'allowed');
    $activeSessionA = videochat_iam_invalidation_issue_personal_session($pdo, $inCall, 'active_a', $label);
    $activeSessionB = videochat_iam_invalidation_issue_personal_session($pdo, $inCall, 'active_b', $label);
    videochat_iam_invalidation_mark_participant_joined($pdo, $inCall);
    videochat_iam_invalidation_assert_session_room($pdo, $inCall, $activeSessionA, (string) ($inCall['call_id'] ?? ''), $label, 'active-call browser A');
    videochat_iam_invalidation_assert_session_room($pdo, $inCall, $activeSessionB, (string) ($inCall['call_id'] ?? ''), $label, 'active-call browser B');
    $activeInvalidation = videochat_iam_invitation_invalidation_cancel_personal_invitation($pdo, $inCall, [
        'session_id' => $activeSessionA,
        'invalidation_reason' => 'participant_invite_cancelled_while_in_call',
    ]);
    videochat_iam_invitation_invalidation_assert((bool) ($activeInvalidation['ok'] ?? false), 'active-call invite invalidation should succeed', $label);
    videochat_iam_invitation_invalidation_assert((int) ($activeInvalidation['access_session_count'] ?? 0) === 2, 'active-call invalidation should see both browser sessions', $label);
    videochat_iam_invalidation_assert_sessions_invalidated($pdo, [$activeSessionA, $activeSessionB], $label, 'active-call invalidation');
    videochat_iam_invalidation_assert_participant_cancelled_with_left_at($pdo, $inCall, $label);
    videochat_iam_invitation_invalidation_assert_fresh_link_rejected(
        $pdo,
        $inCall,
        $label,
        'not_found',
        404,
        'call_access_not_found'
    );

    $restart = videochat_iam_invitation_invalidation_personal_fixture(
        $pdo,
        $label,
        'Call Access Invalidation Restart Secret Title'
    );
    $restartInvalidation = videochat_iam_invitation_invalidation_cancel_personal_invitation($pdo, $restart, [
        'invalidation_reason' => 'participant_invite_cancelled_before_restart',
    ]);
    videochat_iam_invitation_invalidation_assert((bool) ($restartInvalidation['ok'] ?? false), 'restart fixture invite should be invalidated before process restart', $label);
    $restartInvalidatedLink = videochat_fetch_call_access_link($pdo, (string) ($restart['access_id'] ?? ''));
    videochat_iam_invitation_invalidation_assert(is_array($restartInvalidatedLink), 'restart invalidated access link row should remain persisted', $label);
    videochat_iam_invitation_invalidation_assert(videochat_call_access_link_is_invalidated($pdo, $restartInvalidatedLink), 'restart fixture should classify as invalidated before child process', $label);
    videochat_call_access_invalidation_contract_assert_restart_survives($databasePath, $restart, $label);

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
    fwrite(STDOUT, "[{$label}] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[{$label}] ERROR: " . $error->getMessage() . "\n");
    exit(1);
} finally {
    if (isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
