<?php

declare(strict_types=1);

require_once __DIR__ . '/call-access-invitation-invalidation-helper.php';

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

/**
 * @param array<int, string> $sessionIds
 */
function videochat_iam_invalidation_assert_sessions_invalidated(PDO $pdo, array $sessionIds, string $label, string $context): void
{
    foreach ($sessionIds as $sessionId) {
        $validation = videochat_validate_session_token($pdo, $sessionId);
        videochat_iam_invitation_invalidation_assert(!(bool) ($validation['ok'] ?? true), "{$context}: stale session {$sessionId} must fail", $label);
        videochat_iam_invitation_invalidation_assert((string) ($validation['reason'] ?? '') === 'call_access_link_invalidated', "{$context}: stale session {$sessionId} reason mismatch", $label);
        videochat_iam_invitation_invalidation_assert(videochat_fetch_call_access_session_binding($pdo, $sessionId) === null, "{$context}: stale binding {$sessionId} must not resolve", $label);

        $auth = videochat_authenticate_request(
            $pdo,
            [
                'method' => 'GET',
                'uri' => '/ws?session=' . rawurlencode($sessionId),
                'headers' => ['Authorization' => 'Bearer ' . $sessionId],
            ],
            'websocket'
        );
        videochat_iam_invitation_invalidation_assert(!(bool) ($auth['ok'] ?? true), "{$context}: websocket auth {$sessionId} must fail", $label);
        videochat_iam_invitation_invalidation_assert((string) ($auth['reason'] ?? '') === 'call_access_link_invalidated', "{$context}: websocket auth {$sessionId} reason mismatch", $label);
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
