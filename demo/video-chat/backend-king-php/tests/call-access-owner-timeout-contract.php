<?php

declare(strict_types=1);

require_once __DIR__ . '/call-access-king-participants-helper.php';

$label = 'call-access-owner-timeout-contract';

function videochat_iam_owner_timeout_call_status(PDO $pdo, string $callId): string
{
    $statement = $pdo->prepare('SELECT status FROM calls WHERE id = :call_id LIMIT 1');
    $statement->execute([':call_id' => $callId]);
    return strtolower(trim((string) ($statement->fetchColumn() ?: '')));
}

function videochat_iam_owner_timeout_left_at(PDO $pdo, string $callId, int $userId): string
{
    $statement = $pdo->prepare('SELECT left_at FROM call_participants WHERE call_id = :call_id AND user_id = :user_id LIMIT 1');
    $statement->execute([':call_id' => $callId, ':user_id' => $userId]);
    return trim((string) ($statement->fetchColumn() ?: ''));
}

function videochat_iam_owner_timeout_count(PDO $pdo, string $sql, array $params = []): int
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return max(0, (int) ($statement->fetchColumn() ?: 0));
}

function videochat_iam_owner_timeout_user_status(PDO $pdo, int $userId): string
{
    $statement = $pdo->prepare('SELECT status FROM users WHERE id = :user_id LIMIT 1');
    $statement->execute([':user_id' => $userId]);
    return strtolower(trim((string) ($statement->fetchColumn() ?: '')));
}

function videochat_iam_owner_timeout_invite_state(PDO $pdo, string $callId, int $userId): string
{
    $statement = $pdo->prepare('SELECT invite_state FROM call_participants WHERE call_id = :call_id AND user_id = :user_id LIMIT 1');
    $statement->execute([':call_id' => $callId, ':user_id' => $userId]);
    return strtolower(trim((string) ($statement->fetchColumn() ?: '')));
}

function videochat_iam_owner_timeout_lobby_waiting_count(PDO $pdo, string $callId): int
{
    return videochat_iam_owner_timeout_count(
        $pdo,
        <<<'SQL'
SELECT COUNT(*)
FROM call_participants
WHERE call_id = :call_id
  AND source = 'internal'
  AND coalesce(call_role, 'participant') <> 'owner'
  AND invite_state IN ('pending', 'allowed')
  AND (joined_at IS NULL OR joined_at = '')
SQL,
        [':call_id' => $callId]
    );
}

function videochat_iam_owner_timeout_link_count(PDO $pdo, string $callId): int
{
    return videochat_iam_owner_timeout_count(
        $pdo,
        'SELECT COUNT(*) FROM call_access_links WHERE call_id = :call_id',
        [':call_id' => $callId]
    );
}

function videochat_iam_owner_timeout_session_revoked(PDO $pdo, string $sessionId): bool
{
    return videochat_iam_owner_timeout_count(
        $pdo,
        'SELECT COUNT(*) FROM sessions WHERE id = :session_id AND revoked_at IS NOT NULL AND revoked_at <> \'\'',
        [':session_id' => $sessionId]
    ) === 1;
}

function videochat_iam_owner_timeout_session_exists(PDO $pdo, string $sessionId): bool
{
    return videochat_iam_owner_timeout_count(
        $pdo,
        'SELECT COUNT(*) FROM sessions WHERE id = :session_id',
        [':session_id' => $sessionId]
    ) === 1;
}

function videochat_iam_owner_timeout_assert_auth_denied(PDO $pdo, string $sessionId, string $callId, string $label): void
{
    $auth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . rawurlencode($sessionId) . '&call_id=' . rawurlencode($callId),
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        ],
        'websocket'
    );
    videochat_iam_rejoin_contract_assert(!(bool) ($auth['ok'] ?? true), 'stale call-access session must not authenticate after owner-timeout end: ' . $sessionId, $label);
}

function videochat_iam_owner_timeout_prepare_call(
    PDO $pdo,
    int $ownerUserId,
    array $participantUserIds,
    int $tenantId,
    string $title,
    string $label
): array {
    $call = videochat_iam_rejoin_contract_create_active_call($pdo, $ownerUserId, $participantUserIds, $tenantId, $title);
    foreach ($participantUserIds as $participantUserId) {
        videochat_iam_rejoin_contract_set_invite_state($pdo, $call['call_id'], (int) $participantUserId, 'allowed');
    }

    return $call;
}

function videochat_iam_owner_timeout_connect_room(
    PDO $pdo,
    array &$presenceState,
    string $roomId,
    string $callId,
    int $ownerUserId,
    array $participantUserIds,
    int $tenantId,
    int $startMs,
    string $suffix
): array {
    $ownerConnection = videochat_iam_king_participant_client(
        $pdo,
        $presenceState,
        $roomId,
        $callId,
        $ownerUserId,
        'IAM Owner',
        'admin',
        'owner',
        $tenantId,
        $startMs,
        'owner-' . $suffix
    );
    $participantConnections = [];
    foreach (array_values($participantUserIds) as $index => $participantUserId) {
        $participantConnections[] = videochat_iam_king_participant_client(
            $pdo,
            $presenceState,
            $roomId,
            $callId,
            (int) $participantUserId,
            'IAM Owner Timeout Participant ' . ($index + 1),
            'user',
            'participant',
            $tenantId,
            $startMs + 1000 + ($index * 1000),
            'participant-' . $suffix . '-' . ($index + 1)
        );
    }

    return [$ownerConnection, $participantConnections];
}

function videochat_iam_owner_timeout_seed_active_call(
    PDO $pdo,
    int $tenantId,
    int $ownerUserId,
    int $participantUserId,
    string $title
): array {
    return videochat_iam_owner_timeout_prepare_call(
        $pdo,
        $ownerUserId,
        [$participantUserId],
        $tenantId,
        $title,
        'call-access-owner-timeout-contract'
    );
}

function videochat_iam_owner_timeout_owner_absence_from_snapshot(
    PDO $pdo,
    array $presenceState,
    array $viewerConnection,
    int $nowMs,
    string $reason
): array {
    $snapshot = videochat_iam_king_participant_snapshot($pdo, $presenceState, $viewerConnection, $nowMs, $reason);
    return (array) (($snapshot['call_lifecycle'] ?? [])['owner_absence'] ?? []);
}

function videochat_iam_owner_timeout_create_temp_guest(PDO $pdo, string $name, int $tenantId, string $label): array
{
    $guest = videochat_create_guest_user_for_call_access($pdo, $name, $tenantId);
    videochat_iam_rejoin_contract_assert((bool) ($guest['ok'] ?? false), 'temporary guest should be created: ' . $name, $label);
    $user = is_array($guest['user'] ?? null) ? $guest['user'] : [];
    videochat_iam_rejoin_contract_assert((int) ($user['id'] ?? 0) > 0, 'temporary guest id should be present: ' . $name, $label);
    return $user;
}

function videochat_iam_owner_timeout_create_personal_link(PDO $pdo, string $callId, int $ownerUserId, int $userId, int $tenantId, string $label): string
{
    $link = videochat_create_call_access_link_for_user(
        $pdo,
        $callId,
        $ownerUserId,
        'admin',
        ['link_kind' => 'personal', 'participant_user_id' => $userId],
        $tenantId
    );
    videochat_iam_rejoin_contract_assert((bool) ($link['ok'] ?? false), 'personalized owner-timeout link should be created', $label);
    $accessId = (string) (($link['access_link'] ?? [])['id'] ?? '');
    videochat_iam_rejoin_contract_assert($accessId !== '', 'personalized owner-timeout link id should be present', $label);
    return $accessId;
}

function videochat_iam_owner_timeout_issue_access_session(PDO $pdo, string $accessId, string $sessionId, string $label): array
{
    $issued = videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        static fn (): string => $sessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => $label]
    );
    videochat_iam_rejoin_contract_assert((bool) ($issued['ok'] ?? false), 'call-access session should issue before owner timeout', $label);

    $auth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . rawurlencode($sessionId),
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        ],
        'websocket'
    );
    videochat_iam_rejoin_contract_assert((bool) ($auth['ok'] ?? false), 'call-access session should authenticate before owner timeout', $label);
    return $issued;
}

function videochat_iam_owner_timeout_drop_owner_context(
    PDO $pdo,
    array &$presenceState,
    array $ownerConnection,
    int $leftAtMs
): array {
    $connectionId = (string) ($ownerConnection['connection_id'] ?? '');
    $droppedConnection = videochat_presence_remove_connection($presenceState, $connectionId);
    $effectiveConnection = is_array($droppedConnection) ? $droppedConnection : $ownerConnection;
    videochat_realtime_remove_call_presence(static fn (): PDO => $pdo, $effectiveConnection);
    videochat_iam_king_participant_set_times(
        $pdo,
        videochat_realtime_connection_call_id($effectiveConnection),
        (int) ($effectiveConnection['user_id'] ?? 0),
        null,
        $leftAtMs
    );
    return $effectiveConnection;
}

function videochat_iam_owner_timeout_assert_abrupt_absence_mode(
    PDO $pdo,
    int $tenantId,
    int $ownerUserId,
    int $participantUserId,
    string $mode,
    int $offsetMs,
    string $label
): void {
    $call = videochat_iam_owner_timeout_seed_active_call(
        $pdo,
        $tenantId,
        $ownerUserId,
        $participantUserId,
        'IAM Owner Absence ' . str_replace('_', ' ', $mode)
    );
    $callId = (string) $call['call_id'];
    $roomId = (string) $call['room_id'];
    $presenceState = videochat_presence_state_init();
    $startMs = 1_779_000_000_000 + $offsetMs;
    $ownerConnection = videochat_iam_king_participant_client(
        $pdo,
        $presenceState,
        $roomId,
        $callId,
        $ownerUserId,
        'IAM Owner',
        'admin',
        'owner',
        $tenantId,
        $startMs,
        'owner-' . $mode
    );
    $participantConnection = videochat_iam_king_participant_client(
        $pdo,
        $presenceState,
        $roomId,
        $callId,
        $participantUserId,
        'IAM Owner Timeout Participant',
        'user',
        'participant',
        $tenantId,
        $startMs + 1000,
        'participant-' . $mode
    );

    videochat_iam_king_participant_touch($pdo, $ownerConnection, $startMs + 2000);
    videochat_iam_king_participant_touch($pdo, $participantConnection, $startMs + 2000);

    if ($mode === 'owner_network_disconnected') {
        $networkAbsentSinceMs = $startMs + 2000 + videochat_realtime_presence_db_ttl_ms();
        $monitorMs = $networkAbsentSinceMs + 1000;
        videochat_iam_king_participant_touch($pdo, $participantConnection, $monitorMs);
        $monitorSnapshot = videochat_iam_king_participant_snapshot($pdo, $presenceState, $participantConnection, $monitorMs, $mode);
        $ownerAbsence = (array) (($monitorSnapshot['call_lifecycle'] ?? [])['owner_absence'] ?? []);
        videochat_iam_rejoin_contract_assert((string) ($ownerAbsence['status'] ?? '') === 'monitoring', 'owner network loss should start owner-absence monitoring', $label);
        videochat_iam_rejoin_contract_assert((int) ($ownerAbsence['absent_since_ms'] ?? 0) === $networkAbsentSinceMs, 'owner network loss should persist absence from stale heartbeat cutoff', $label);
        videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_left_at($pdo, $callId, $ownerUserId) === videochat_iam_king_participant_iso($networkAbsentSinceMs), 'owner network loss should persist owner left_at from stale heartbeat cutoff', $label);
    } else {
        $leftAtMs = $startMs + 60_000;
        videochat_iam_owner_timeout_drop_owner_context($pdo, $presenceState, $ownerConnection, $leftAtMs);
        videochat_iam_king_participant_touch($pdo, $participantConnection, $leftAtMs + 1000);
        $monitorSnapshot = videochat_iam_king_participant_snapshot($pdo, $presenceState, $participantConnection, $leftAtMs + 1000, $mode);
        $ownerAbsence = (array) (($monitorSnapshot['call_lifecycle'] ?? [])['owner_absence'] ?? []);
        videochat_iam_rejoin_contract_assert((string) ($ownerAbsence['status'] ?? '') === 'monitoring', "{$mode} should start owner-absence monitoring without explicit call end", $label);
        videochat_iam_rejoin_contract_assert((int) ($ownerAbsence['absent_since_ms'] ?? 0) === $leftAtMs, "{$mode} should use the server-side owner departure time", $label);
    }

    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_call_status($pdo, $callId) === 'active', "{$mode} should not explicitly end the call", $label);
}

try {
    videochat_iam_rejoin_contract_skip_without_sqlite($label);
    [$databasePath, $pdo] = videochat_iam_rejoin_contract_bootstrap_database('videochat-call-access-owner-timeout');
    $ids = videochat_iam_rejoin_contract_fixture_ids($pdo, $label);
    $tenantId = $ids['tenant_id'];
    $ownerUserId = $ids['admin_user_id'];
    $participantUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-owner-timeout-participant@example.test',
        'IAM Owner Timeout Participant',
        $tenantId,
        $ids['organization_id']
    );
    $secondParticipantUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-owner-timeout-second-participant@example.test',
        'IAM Owner Timeout Second Participant',
        $tenantId,
        $ids['organization_id']
    );

    $tabCloseCall = videochat_iam_owner_timeout_prepare_call($pdo, $ownerUserId, [$participantUserId], $tenantId, 'IAM Owner Tab Close Proof', $label);
    $tabClosePresence = videochat_presence_state_init();
    $tabCloseStartMs = 1_778_100_000_000;
    [$tabCloseOwner, $tabCloseParticipants] = videochat_iam_owner_timeout_connect_room(
        $pdo,
        $tabClosePresence,
        $tabCloseCall['room_id'],
        $tabCloseCall['call_id'],
        $ownerUserId,
        [$participantUserId],
        $tenantId,
        $tabCloseStartMs,
        'tab-close'
    );
    $tabCloseLeftMs = $tabCloseStartMs + 60_000;
    videochat_iam_king_participant_leave($pdo, $tabClosePresence, $tabCloseOwner, $tabCloseLeftMs);
    videochat_iam_king_participant_touch($pdo, $tabCloseParticipants[0], $tabCloseLeftMs + 1000);
    $tabCloseAbsence = videochat_iam_owner_timeout_owner_absence_from_snapshot($pdo, $tabClosePresence, $tabCloseParticipants[0], $tabCloseLeftMs + 1000, 'owner_tab_close');
    videochat_iam_rejoin_contract_assert((string) ($tabCloseAbsence['status'] ?? '') === 'monitoring', 'owner tab close should start owner-absence monitoring', $label);
    videochat_iam_rejoin_contract_assert((int) ($tabCloseAbsence['absent_since_ms'] ?? 0) === $tabCloseLeftMs, 'owner tab close should use server leave time as absent_since', $label);

    $staleCall = videochat_iam_owner_timeout_prepare_call($pdo, $ownerUserId, [$participantUserId], $tenantId, 'IAM Owner Crash Rejoin Proof', $label);
    $stalePresence = videochat_presence_state_init();
    $staleStartMs = 1_778_200_000_000;
    [, $staleParticipants] = videochat_iam_owner_timeout_connect_room(
        $pdo,
        $stalePresence,
        $staleCall['room_id'],
        $staleCall['call_id'],
        $ownerUserId,
        [$participantUserId],
        $tenantId,
        $staleStartMs,
        'stale-owner'
    );
    $staleDetectedMs = $staleStartMs + videochat_realtime_presence_db_ttl_ms() + 1000;
    videochat_iam_king_participant_touch($pdo, $staleParticipants[0], $staleDetectedMs);
    $staleAbsence = videochat_iam_owner_timeout_owner_absence_from_snapshot($pdo, $stalePresence, $staleParticipants[0], $staleDetectedMs, 'owner_browser_crash');
    videochat_iam_rejoin_contract_assert((string) ($staleAbsence['status'] ?? '') === 'monitoring', 'owner browser crash should start owner-absence monitoring from stale presence', $label);
    videochat_iam_rejoin_contract_assert((int) ($staleAbsence['absent_since_ms'] ?? 0) === $staleStartMs + videochat_realtime_presence_db_ttl_ms(), 'owner browser crash should be based on server heartbeat TTL', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_left_at($pdo, $staleCall['call_id'], $ownerUserId) !== '', 'owner browser crash should materialize left_at for refresh-stable countdowns', $label);
    $preCountdownOwnerReturnMs = $staleStartMs + videochat_realtime_presence_db_ttl_ms() + 5 * 60 * 1000;
    videochat_iam_king_participant_client(
        $pdo,
        $stalePresence,
        $staleCall['room_id'],
        $staleCall['call_id'],
        $ownerUserId,
        'IAM Owner',
        'admin',
        'owner',
        $tenantId,
        $preCountdownOwnerReturnMs,
        'owner-pre-countdown-return'
    );
    videochat_iam_king_participant_touch($pdo, $staleParticipants[0], $preCountdownOwnerReturnMs);
    $preCountdownReturn = videochat_iam_owner_timeout_owner_absence_from_snapshot($pdo, $stalePresence, $staleParticipants[0], $preCountdownOwnerReturnMs, 'owner_rejoin_before_countdown');
    videochat_iam_rejoin_contract_assert((string) ($preCountdownReturn['status'] ?? '') === 'owner_present', 'owner rejoin before final countdown should cancel owner absence', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_call_status($pdo, $staleCall['call_id']) === 'active', 'owner rejoin before final countdown should keep call active', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_left_at($pdo, $staleCall['call_id'], $ownerUserId) === '', 'owner rejoin before final countdown should clear stale left_at', $label);

    $syncCall = videochat_iam_owner_timeout_prepare_call($pdo, $ownerUserId, [$participantUserId, $secondParticipantUserId], $tenantId, 'IAM Owner Countdown Sync Proof', $label);
    $syncPresence = videochat_presence_state_init();
    $syncStartMs = 1_778_300_000_000;
    [$syncOwner, $syncParticipants] = videochat_iam_owner_timeout_connect_room(
        $pdo,
        $syncPresence,
        $syncCall['room_id'],
        $syncCall['call_id'],
        $ownerUserId,
        [$participantUserId, $secondParticipantUserId],
        $tenantId,
        $syncStartMs,
        'sync'
    );
    $syncOwnerLeftMs = $syncStartMs + 60_000;
    videochat_iam_king_participant_leave($pdo, $syncPresence, $syncOwner, $syncOwnerLeftMs);
    $syncCountdownMs = $syncOwnerLeftMs + VIDEOCHAT_OWNER_ABSENCE_TIMER_MS - VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS;
    videochat_iam_king_participant_touch($pdo, $syncParticipants[0], $syncCountdownMs);
    videochat_iam_king_participant_touch($pdo, $syncParticipants[1], $syncCountdownMs);
    $syncA = videochat_iam_owner_timeout_owner_absence_from_snapshot($pdo, $syncPresence, $syncParticipants[0], $syncCountdownMs, 'owner_network_loss_countdown_a');
    $syncB = videochat_iam_owner_timeout_owner_absence_from_snapshot($pdo, $syncPresence, $syncParticipants[1], $syncCountdownMs, 'owner_network_loss_countdown_b');
    videochat_iam_rejoin_contract_assert((string) ($syncA['status'] ?? '') === 'countdown', 'owner network disconnect should enter countdown for participant A', $label);
    videochat_iam_rejoin_contract_assert((string) ($syncB['status'] ?? '') === 'countdown', 'owner network disconnect should enter countdown for participant B', $label);
    videochat_iam_rejoin_contract_assert((int) ($syncA['ends_at_ms'] ?? 0) === (int) ($syncB['ends_at_ms'] ?? -1), 'owner absence countdown must be synchronized across participants', $label);
    videochat_iam_rejoin_contract_assert((int) ($syncA['countdown_remaining_ms'] ?? 0) === VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS, 'synchronized countdown should start with five minutes remaining', $label);
    $refreshMs = $syncCountdownMs + 30_000;
    $refreshedConnection = videochat_iam_king_participant_leave($pdo, $syncPresence, $syncParticipants[0], $refreshMs - 1000);
    $refreshedConnection = videochat_iam_king_participant_client(
        $pdo,
        $syncPresence,
        $syncCall['room_id'],
        $syncCall['call_id'],
        $participantUserId,
        'IAM Owner Timeout Participant 1',
        'user',
        'participant',
        $tenantId,
        $refreshMs,
        'participant-refresh'
    );
    videochat_iam_king_participant_touch($pdo, $syncParticipants[1], $refreshMs);
    $refreshAbsence = videochat_iam_owner_timeout_owner_absence_from_snapshot($pdo, $syncPresence, $refreshedConnection, $refreshMs, 'participant_refresh_during_countdown');
    videochat_iam_rejoin_contract_assert((string) ($refreshAbsence['status'] ?? '') === 'countdown', 'owner absence countdown should survive participant refresh', $label);
    videochat_iam_rejoin_contract_assert((int) ($refreshAbsence['absent_since_ms'] ?? 0) === $syncOwnerLeftMs, 'participant refresh should preserve owner absent_since', $label);
    videochat_iam_rejoin_contract_assert((int) ($refreshAbsence['countdown_remaining_ms'] ?? 0) === VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS - 30_000, 'participant refresh should keep countdown on server time', $label);

    $call = videochat_iam_owner_timeout_seed_active_call(
        $pdo,
        $tenantId,
        $ownerUserId,
        $participantUserId,
        'IAM Owner Absence Timeout Contract'
    );
    $callId = $call['call_id'];
    $roomId = $call['room_id'];

    $pendingGuest = videochat_iam_owner_timeout_create_temp_guest($pdo, 'IAM Owner Timeout Pending Guest', $tenantId, $label);
    $pendingGuestId = (int) ($pendingGuest['id'] ?? 0);
    $admittedGuest = videochat_iam_owner_timeout_create_temp_guest($pdo, 'IAM Owner Timeout Admitted Guest', $tenantId, $label);
    $admittedGuestId = (int) ($admittedGuest['id'] ?? 0);
    videochat_ensure_internal_call_participant(
        $pdo,
        $callId,
        $pendingGuestId,
        (string) ($pendingGuest['email'] ?? ''),
        (string) ($pendingGuest['display_name'] ?? ''),
        'pending'
    );
    videochat_ensure_internal_call_participant(
        $pdo,
        $callId,
        $admittedGuestId,
        (string) ($admittedGuest['email'] ?? ''),
        (string) ($admittedGuest['display_name'] ?? ''),
        'allowed'
    );
    $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET joined_at = :joined_at,
    left_at = NULL
WHERE call_id = :call_id
  AND user_id = :user_id
SQL
    )->execute([
        ':joined_at' => gmdate('c'),
        ':call_id' => $callId,
        ':user_id' => $admittedGuestId,
    ]);
    $personalAccessId = videochat_iam_owner_timeout_create_personal_link($pdo, $callId, $ownerUserId, $admittedGuestId, $tenantId, $label);
    $personalSessionId = 'sess_owner_timeout_personal_guest';
    videochat_iam_owner_timeout_issue_access_session($pdo, $personalAccessId, $personalSessionId, $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_link_count($pdo, $callId) >= 1, 'owner-timeout setup should have a personalized link', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_lobby_waiting_count($pdo, $callId) >= 1, 'owner-timeout setup should have a pending temporary lobby row', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_invite_state($pdo, $callId, $admittedGuestId) === 'allowed', 'owner-timeout setup should have admitted temporary participant state', $label);

    $presenceState = videochat_presence_state_init();
    $startMs = 1_778_000_000_000;
    $ownerConnection = videochat_iam_king_participant_client(
        $pdo,
        $presenceState,
        $roomId,
        $callId,
        $ownerUserId,
        'IAM Owner',
        'admin',
        'owner',
        $tenantId,
        $startMs,
        'owner'
    );
    $participantConnection = videochat_iam_king_participant_client(
        $pdo,
        $presenceState,
        $roomId,
        $callId,
        $participantUserId,
        'IAM Owner Timeout Participant',
        'user',
        'participant',
        $tenantId,
        $startMs + 1000,
        'participant'
    );

    videochat_iam_king_participant_touch($pdo, $ownerConnection, $startMs + 2000);
    videochat_iam_king_participant_touch($pdo, $participantConnection, $startMs + 2000);
    $initialSnapshot = videochat_iam_king_participant_snapshot($pdo, $presenceState, $participantConnection, $startMs + 2000, 'initial');
    videochat_iam_rejoin_contract_assert((int) ($initialSnapshot['participant_count'] ?? 0) === 2, 'simulated King participant clients should enter the call room', $label);
    $initialOwnerAbsence = (array) (($initialSnapshot['call_lifecycle'] ?? [])['owner_absence'] ?? []);
    videochat_iam_rejoin_contract_assert((string) ($initialOwnerAbsence['status'] ?? '') === 'owner_present', 'initial owner-absence state should detect the owner', $label);
    videochat_iam_rejoin_contract_assert((int) ($initialOwnerAbsence['timer_ms'] ?? 0) === 15 * 60 * 1000, 'owner absence timer must be 15 minutes', $label);
    videochat_iam_rejoin_contract_assert((int) ($initialOwnerAbsence['countdown_ms'] ?? 0) === 5 * 60 * 1000, 'owner absence countdown must be 5 minutes', $label);

    $ownerLeftMs = $startMs + 60_000;
    videochat_iam_king_participant_leave($pdo, $presenceState, $ownerConnection, $ownerLeftMs);

    $beforeCountdownMs = $ownerLeftMs + VIDEOCHAT_OWNER_ABSENCE_TIMER_MS - VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS - 1000;
    videochat_iam_king_participant_touch($pdo, $participantConnection, $beforeCountdownMs);
    $beforeCountdownSnapshot = videochat_iam_king_participant_snapshot($pdo, $presenceState, $participantConnection, $beforeCountdownMs, 'owner_absence_monitoring');
    $beforeCountdown = (array) (($beforeCountdownSnapshot['call_lifecycle'] ?? [])['owner_absence'] ?? []);
    videochat_iam_rejoin_contract_assert((string) ($beforeCountdown['status'] ?? '') === 'monitoring', 'owner absence should monitor before the 15-minute threshold', $label);
    videochat_iam_rejoin_contract_assert((bool) ($beforeCountdown['countdown_started'] ?? true) === false, 'countdown must not start before the 15-minute timer', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_call_status($pdo, $callId) === 'active', 'call must stay active before owner absence countdown', $label);

    $ownerReturnBeforeCountdownMs = $ownerLeftMs + VIDEOCHAT_OWNER_ABSENCE_TIMER_MS - VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS - 1000;
    $ownerReturnBeforeCountdownConnection = videochat_iam_king_participant_client(
        $pdo,
        $presenceState,
        $roomId,
        $callId,
        $ownerUserId,
        'IAM Owner',
        'admin',
        'owner',
        $tenantId,
        $ownerReturnBeforeCountdownMs,
        'owner-return-before-countdown'
    );
    videochat_iam_king_participant_touch($pdo, $participantConnection, $ownerReturnBeforeCountdownMs);
    $ownerReturnBeforeCountdownSnapshot = videochat_iam_king_participant_snapshot($pdo, $presenceState, $participantConnection, $ownerReturnBeforeCountdownMs, 'owner_returned_before_countdown');
    $ownerReturnBeforeCountdown = (array) (($ownerReturnBeforeCountdownSnapshot['call_lifecycle'] ?? [])['owner_absence'] ?? []);
    videochat_iam_rejoin_contract_assert((string) ($ownerReturnBeforeCountdown['status'] ?? '') === 'owner_present', 'owner return before final countdown must cancel owner-absence monitoring', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_call_status($pdo, $callId) === 'active', 'owner return before final countdown must keep the call active', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_left_at($pdo, $callId, $ownerUserId) === '', 'owner return before final countdown should clear owner left_at', $label);

    $secondOwnerLeftMs = $ownerReturnBeforeCountdownMs + 60_000;
    videochat_iam_king_participant_leave($pdo, $presenceState, $ownerReturnBeforeCountdownConnection, $secondOwnerLeftMs);

    $countdownStartMs = $secondOwnerLeftMs + VIDEOCHAT_OWNER_ABSENCE_TIMER_MS - VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS;
    videochat_iam_king_participant_touch($pdo, $participantConnection, $countdownStartMs);
    $countdownSnapshot = videochat_iam_king_participant_snapshot($pdo, $presenceState, $participantConnection, $countdownStartMs, 'owner_absence_countdown');
    $countdown = (array) (($countdownSnapshot['call_lifecycle'] ?? [])['owner_absence'] ?? []);
    videochat_iam_rejoin_contract_assert((string) ($countdown['status'] ?? '') === 'countdown', 'owner absence should enter countdown at 15 minutes', $label);
    videochat_iam_rejoin_contract_assert((bool) ($countdown['countdown_started'] ?? false) === true, 'owner absence countdown should be marked started', $label);
    videochat_iam_rejoin_contract_assert((int) ($countdown['countdown_remaining_ms'] ?? 0) === VIDEOCHAT_OWNER_ABSENCE_COUNTDOWN_MS, 'countdown should start with five minutes remaining', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_call_status($pdo, $callId) === 'active', 'call must stay active at countdown start', $label);

    $ownerReturnMs = $countdownStartMs + 60_000;
    $ownerReturnConnection = videochat_iam_king_participant_client(
        $pdo,
        $presenceState,
        $roomId,
        $callId,
        $ownerUserId,
        'IAM Owner',
        'admin',
        'owner',
        $tenantId,
        $ownerReturnMs,
        'owner-return'
    );
    videochat_iam_king_participant_touch($pdo, $participantConnection, $ownerReturnMs);
    $ownerReturnSnapshot = videochat_iam_king_participant_snapshot($pdo, $presenceState, $participantConnection, $ownerReturnMs, 'owner_returned');
    $ownerReturn = (array) (($ownerReturnSnapshot['call_lifecycle'] ?? [])['owner_absence'] ?? []);
    videochat_iam_rejoin_contract_assert((string) ($ownerReturn['status'] ?? '') === 'owner_present', 'owner return must cancel owner-absence countdown', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_call_status($pdo, $callId) === 'active', 'owner return during countdown must keep the call active', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_left_at($pdo, $callId, $ownerUserId) === '', 'owner return should clear the owner left_at marker', $label);

    $thirdOwnerLeftMs = $ownerReturnMs + 60_000;
    videochat_iam_king_participant_leave($pdo, $presenceState, $ownerReturnConnection, $thirdOwnerLeftMs);
    $deadlineMs = $thirdOwnerLeftMs + VIDEOCHAT_OWNER_ABSENCE_TIMER_MS;
    videochat_iam_king_participant_touch($pdo, $participantConnection, $deadlineMs);
    $endedSnapshot = videochat_iam_king_participant_snapshot($pdo, $presenceState, $participantConnection, $deadlineMs, 'owner_absence_deadline');
    $ended = (array) (($endedSnapshot['call_lifecycle'] ?? [])['owner_absence'] ?? []);
    videochat_iam_rejoin_contract_assert((string) ($ended['status'] ?? '') === 'ended', 'owner absence should end after timer plus countdown: ' . json_encode($ended), $label);
    videochat_iam_rejoin_contract_assert((string) ($ended['ended_reason'] ?? '') === 'owner_absent_timeout', 'owner absence end reason mismatch', $label);
    videochat_iam_rejoin_contract_assert((bool) ($ended['transitioned'] ?? false) === true, 'owner absence helper should report the ended transition', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_call_status($pdo, $callId) === 'ended', 'owner absence deadline should persist the call as ended', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_left_at($pdo, $callId, $participantUserId) !== '', 'implicit ending should mark remaining participant left_at', $label);
    $endedLifecycle = (array) ($ended['lifecycle'] ?? []);
    videochat_iam_rejoin_contract_assert((string) ($endedLifecycle['transition'] ?? '') === 'ended', 'owner-timeout end should apply terminal lifecycle', $label);
    videochat_iam_rejoin_contract_assert((int) ($endedLifecycle['invalidated_link_count'] ?? 0) >= 1, 'owner-timeout end should invalidate personalized links', $label);
    videochat_iam_rejoin_contract_assert((int) ($endedLifecycle['revoked_access_session_count'] ?? 0) >= 1, 'owner-timeout end should revoke call-access sessions', $label);
    videochat_iam_rejoin_contract_assert((int) ($endedLifecycle['lobby_cleared_count'] ?? 0) >= 3, 'owner-timeout end should clear lobby/admitted participant state', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_link_count($pdo, $callId) === 0, 'owner-timeout end should delete call access links', $label);
    videochat_iam_rejoin_contract_assert((string) (videochat_resolve_call_access_public($pdo, $personalAccessId)['reason'] ?? '') === 'not_found', 'owner-timeout end should invalidate personalized invite link', $label);
    $latePersonalSession = videochat_issue_session_for_call_access(
        $pdo,
        $personalAccessId,
        static fn (): string => 'sess_owner_timeout_late_personal',
        ['client_ip' => '127.0.0.1', 'user_agent' => $label]
    );
    videochat_iam_rejoin_contract_assert(!(bool) ($latePersonalSession['ok'] ?? true), 'owner-timeout ended personalized link must not issue a new session', $label);
    videochat_iam_rejoin_contract_assert(!videochat_iam_owner_timeout_session_exists($pdo, 'sess_owner_timeout_late_personal'), 'owner-timeout denied late session must not be stored', $label);
    $directJoinAfterEnd = videochat_decide_call_access_for_user($pdo, $callId, $participantUserId, 'user', $tenantId);
    videochat_iam_rejoin_contract_assert(!(bool) ($directJoinAfterEnd['allowed'] ?? true), 'owner-timeout ended state should block fresh direct joins', $label);
    videochat_iam_rejoin_contract_assert((string) ($directJoinAfterEnd['reason'] ?? '') === 'call_not_joinable_from_status', 'owner-timeout fresh join denial reason mismatch', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_session_revoked($pdo, $personalSessionId), 'owner-timeout end should revoke personalized session', $label);
    videochat_iam_owner_timeout_assert_auth_denied($pdo, $personalSessionId, $callId, $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_user_status($pdo, $pendingGuestId) === 'disabled', 'owner-timeout end should disable pending temporary guest', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_user_status($pdo, $admittedGuestId) === 'disabled', 'owner-timeout end should disable admitted temporary guest', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_lobby_waiting_count($pdo, $callId) === 0, 'owner-timeout end should clear lobby rows', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_invite_state($pdo, $callId, $pendingGuestId) === 'cancelled', 'owner-timeout end should cancel pending lobby participant', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_invite_state($pdo, $callId, $admittedGuestId) === 'cancelled', 'owner-timeout end should cancel admitted temporary participant', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_owner_timeout_left_at($pdo, $callId, $admittedGuestId) !== '', 'owner-timeout end should mark admitted temporary participant left', $label);
    videochat_iam_rejoin_contract_assert(
        videochat_iam_owner_timeout_count($pdo, "SELECT COUNT(*) FROM videochat_audit_events WHERE call_id = :call_id AND event_type = 'call_ended'", [':call_id' => $callId]) >= 1,
        'owner-timeout end should preserve call-ended audit log',
        $label
    );

    videochat_iam_owner_timeout_assert_abrupt_absence_mode($pdo, $tenantId, $ownerUserId, $participantUserId, 'owner_browser_crash', 10_000_000, $label);
    videochat_iam_owner_timeout_assert_abrupt_absence_mode($pdo, $tenantId, $ownerUserId, $participantUserId, 'owner_context_killed', 20_000_000, $label);
    videochat_iam_owner_timeout_assert_abrupt_absence_mode($pdo, $tenantId, $ownerUserId, $participantUserId, 'owner_network_disconnected', 30_000_000, $label);

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
