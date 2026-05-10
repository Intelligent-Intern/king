<?php

declare(strict_types=1);

require_once __DIR__ . '/call-access-rejoin-kick-membership-helper.php';

$label = 'call-access-rejoin-refresh-session-safety-contract';

/**
 * @return array<string, mixed>
 */
function videochat_iam_rejoin_refresh_contract_leave_and_rejoin(
    PDO $pdo,
    callable $openDatabase,
    array $auth,
    string $roomId,
    string $callId,
    int $userId,
    string $displayName,
    string $globalRole,
    int $tenantId,
    string $caseName,
    string $expectedEffectiveRole,
    bool $expectedCanModerate,
    string $label
): array {
    $presenceState = videochat_presence_state_init();
    $frames = [];
    $sender = static function (mixed $socket, array $payload) use (&$frames): bool {
        $key = is_scalar($socket) ? (string) $socket : 'unknown';
        $frames[$key] ??= [];
        $frames[$key][] = $payload;
        return true;
    };

    $firstResolution = videochat_realtime_resolve_connection_rooms($auth, $roomId, $openDatabase, $callId);
    videochat_iam_rejoin_contract_assert((bool) ($firstResolution['ok'] ?? false), "{$caseName}: first room resolution should succeed", $label);
    videochat_iam_rejoin_contract_assert((string) ($firstResolution['initial_room_id'] ?? '') === $roomId, "{$caseName}: first join should enter the call room", $label);
    videochat_iam_rejoin_contract_assert((string) ($firstResolution['pending_room_id'] ?? '') === '', "{$caseName}: first join should not wait in lobby", $label);

    $sessionId = videochat_realtime_session_id_from_auth($auth);
    $connection = videochat_iam_rejoin_contract_connection(
        $pdo,
        $presenceState,
        $roomId,
        $callId,
        $userId,
        $displayName,
        $globalRole,
        str_replace('_', '-', $caseName) . '-first',
        $tenantId,
        true,
        $sessionId
    );
    videochat_iam_rejoin_contract_assert((string) ($connection['active_call_id'] ?? '') === $callId, "{$caseName}: joined connection should bind active call", $label);

    videochat_presence_remove_connection($presenceState, (string) ($connection['connection_id'] ?? ''), $sender);
    videochat_realtime_remove_call_presence($openDatabase, $connection);
    videochat_realtime_mark_call_participant_left($openDatabase, $connection, $presenceState);
    videochat_iam_rejoin_contract_assert(videochat_iam_rejoin_contract_participant_left_at($pdo, $callId, $userId) !== '', "{$caseName}: leaving should persist left_at", $label);

    $rejoinResolution = videochat_realtime_resolve_connection_rooms($auth, $roomId, $openDatabase, $callId);
    videochat_iam_rejoin_contract_assert((bool) ($rejoinResolution['ok'] ?? false), "{$caseName}: rejoin room resolution should succeed", $label);
    videochat_iam_rejoin_contract_assert((string) ($rejoinResolution['initial_room_id'] ?? '') === $roomId, "{$caseName}: rejoin should enter the call room", $label);
    videochat_iam_rejoin_contract_assert((string) ($rejoinResolution['pending_room_id'] ?? '') === '', "{$caseName}: rejoin should not wait in lobby", $label);

    $rejoinConnection = videochat_iam_rejoin_contract_connection(
        $pdo,
        $presenceState,
        $roomId,
        $callId,
        $userId,
        $displayName,
        $globalRole,
        str_replace('_', '-', $caseName) . '-rejoin',
        $tenantId,
        true,
        $sessionId
    );
    videochat_iam_rejoin_contract_assert(videochat_iam_rejoin_contract_participant_left_at($pdo, $callId, $userId) === '', "{$caseName}: rejoin should clear stale left_at", $label);
    videochat_iam_rejoin_contract_assert((string) ($rejoinConnection['effective_call_role'] ?? '') === $expectedEffectiveRole, "{$caseName}: rejoin should use current effective role", $label);
    videochat_iam_rejoin_contract_assert((bool) ($rejoinConnection['can_moderate_call'] ?? false) === $expectedCanModerate, "{$caseName}: rejoin should use current moderation rights", $label);

    return [
        'connection' => $rejoinConnection,
        'presence_state' => $presenceState,
        'frames' => $frames,
    ];
}

try {
    videochat_iam_rejoin_contract_skip_without_sqlite($label);
    [$databasePath, $pdo] = videochat_iam_rejoin_contract_bootstrap_database('videochat-call-access-rejoin-refresh-session-safety');
    $ids = videochat_iam_rejoin_contract_fixture_ids($pdo, $label);
    $tenantId = $ids['tenant_id'];
    $systemAdminUserId = $ids['admin_user_id'];
    $organizationId = $ids['organization_id'];
    $openDatabase = static fn (): PDO => $pdo;

    $normalOwnerUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-rejoin-refresh-owner@example.test',
        'IAM Rejoin Refresh Owner',
        $tenantId,
        $organizationId
    );
    $registeredUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-rejoin-refresh-registered@example.test',
        'IAM Rejoin Refresh Registered',
        $tenantId,
        $organizationId
    );
    $guestListUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-rejoin-refresh-guest-list@example.test',
        'IAM Rejoin Refresh Guest List',
        $tenantId,
        $organizationId
    );
    $organizationAdminUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-rejoin-refresh-org-admin@example.test',
        'IAM Rejoin Refresh Org Admin',
        $tenantId,
        $organizationId,
        'member',
        'admin'
    );
    $newOwnerUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-rejoin-refresh-new-owner@example.test',
        'IAM Rejoin Refresh New Owner',
        $tenantId,
        $organizationId
    );

    $call = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $normalOwnerUserId,
        [$registeredUserId, $guestListUserId, $newOwnerUserId],
        $tenantId,
        'IAM Rejoin Refresh Session Safety'
    );
    $callId = $call['call_id'];
    $roomId = $call['room_id'];
    foreach ([$registeredUserId, $guestListUserId, $newOwnerUserId] as $allowedUserId) {
        videochat_iam_rejoin_contract_set_invite_state($pdo, $callId, $allowedUserId, 'allowed');
    }

    $registeredAuth = videochat_iam_rejoin_contract_issue_user_session($pdo, $registeredUserId, $tenantId, 'sess_iam_rejoin_refresh_registered', $label);
    videochat_iam_rejoin_refresh_contract_leave_and_rejoin(
        $pdo,
        $openDatabase,
        $registeredAuth,
        $roomId,
        $callId,
        $registeredUserId,
        'IAM Rejoin Refresh Registered',
        'user',
        $tenantId,
        'e2e_rejoin_006_registered_guest_can_rejoin',
        'participant',
        false,
        $label
    );

    $systemAdminAuth = videochat_iam_rejoin_contract_issue_user_session($pdo, $systemAdminUserId, $tenantId, 'sess_iam_rejoin_refresh_system_admin', $label);
    videochat_iam_rejoin_refresh_contract_leave_and_rejoin(
        $pdo,
        $openDatabase,
        $systemAdminAuth,
        $roomId,
        $callId,
        $systemAdminUserId,
        'System Admin',
        'admin',
        $tenantId,
        'admin_can_rejoin_after_leaving',
        'owner',
        true,
        $label
    );

    $organizationAdminAuth = videochat_iam_rejoin_contract_issue_user_session($pdo, $organizationAdminUserId, $tenantId, 'sess_iam_rejoin_refresh_org_admin', $label);
    videochat_iam_rejoin_refresh_contract_leave_and_rejoin(
        $pdo,
        $openDatabase,
        $organizationAdminAuth,
        $roomId,
        $callId,
        $organizationAdminUserId,
        'IAM Rejoin Refresh Org Admin',
        'user',
        $tenantId,
        'organization_admin_can_rejoin_after_leaving',
        'moderator',
        true,
        $label
    );

    $guestListAuth = videochat_iam_rejoin_contract_issue_user_session($pdo, $guestListUserId, $tenantId, 'sess_iam_rejoin_refresh_guest_list', $label);
    $guestListRejoin = videochat_iam_rejoin_refresh_contract_leave_and_rejoin(
        $pdo,
        $openDatabase,
        $guestListAuth,
        $roomId,
        $callId,
        $guestListUserId,
        'IAM Rejoin Refresh Guest List',
        'user',
        $tenantId,
        'guest_list_user_can_rejoin_after_leaving',
        'participant',
        false,
        $label
    );
    $guestListConnection = (array) ($guestListRejoin['connection'] ?? []);
    $guestListPresenceState = (array) ($guestListRejoin['presence_state'] ?? []);
    $noopSender = static fn (mixed $socket, array $payload): bool => true;
    videochat_presence_remove_connection($guestListPresenceState, (string) ($guestListConnection['connection_id'] ?? ''), $noopSender);
    videochat_realtime_remove_call_presence($openDatabase, $guestListConnection);
    videochat_realtime_mark_call_participant_left($openDatabase, $guestListConnection, $guestListPresenceState);
    videochat_iam_rejoin_contract_set_invite_state($pdo, $callId, $guestListUserId, 'cancelled');
    $guestListRemovedResolution = videochat_realtime_resolve_connection_rooms($guestListAuth, $roomId, $openDatabase, $callId);
    videochat_iam_rejoin_contract_assert((bool) ($guestListRemovedResolution['ok'] ?? false), 'e2e_rejoin_007: guest-list removal should still resolve explicitly', $label);
    videochat_iam_rejoin_contract_assert((string) ($guestListRemovedResolution['initial_room_id'] ?? '') === videochat_realtime_waiting_room_id(), 'e2e_rejoin_007: removed guest-list user must not directly rejoin', $label);
    videochat_iam_rejoin_contract_assert((string) ($guestListRemovedResolution['pending_room_id'] ?? '') === $roomId, 'e2e_rejoin_007: removed guest-list user should be routed to lobby for the same call', $label);

    $ownerTransfer = videochat_update_call_participant_role($pdo, $callId, $newOwnerUserId, 'owner', $normalOwnerUserId, 'user', $tenantId);
    videochat_iam_rejoin_contract_assert((bool) ($ownerTransfer['ok'] ?? false), 'e2e_rejoin_009: owner transfer should succeed', $label);
    $formerOwnerContext = videochat_call_role_context_for_room_user($pdo, $roomId, $normalOwnerUserId);
    videochat_iam_rejoin_contract_assert((string) ($formerOwnerContext['call_role'] ?? '') === 'participant', 'e2e_rejoin_009: former owner should be demoted before rejoin', $label);
    videochat_iam_rejoin_contract_assert(!(bool) ($formerOwnerContext['can_moderate'] ?? true), 'e2e_rejoin_009: former owner should lose moderation before rejoin', $label);
    $normalOwnerAuth = videochat_iam_rejoin_contract_issue_user_session($pdo, $normalOwnerUserId, $tenantId, 'sess_iam_rejoin_refresh_former_owner', $label);
    videochat_iam_rejoin_refresh_contract_leave_and_rejoin(
        $pdo,
        $openDatabase,
        $normalOwnerAuth,
        $roomId,
        $callId,
        $normalOwnerUserId,
        'IAM Rejoin Refresh Owner',
        'user',
        $tenantId,
        'e2e_rejoin_009_rejoin_after_owner_transfer_uses_new_permissions',
        'participant',
        false,
        $label
    );

    $tempCall = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $normalOwnerUserId,
        [],
        $tenantId,
        'IAM Temporary Guest Rejoin Refresh',
        'free_for_all'
    );
    $tempCallId = $tempCall['call_id'];
    $tempRoomId = $tempCall['room_id'];
    $tempSessionId = 'sess_iam_rejoin_refresh_temp_guest';
    $tempGuestSession = videochat_iam_rejoin_contract_issue_open_guest_session(
        $pdo,
        $tempCallId,
        $normalOwnerUserId,
        $tenantId,
        $tempSessionId,
        'IAM Rejoin Refresh Temporary Guest',
        $label
    );
    $tempGuestUser = (array) ($tempGuestSession['user'] ?? []);
    $tempGuestUserId = (int) ($tempGuestUser['id'] ?? 0);
    videochat_iam_rejoin_contract_assert($tempGuestUserId > 0, 'temporary guest should receive a stable user id', $label);
    videochat_iam_rejoin_contract_set_invite_state($pdo, $tempCallId, $tempGuestUserId, 'allowed');
    videochat_iam_rejoin_refresh_contract_leave_and_rejoin(
        $pdo,
        $openDatabase,
        (array) ($tempGuestSession['auth'] ?? []),
        $tempRoomId,
        $tempCallId,
        $tempGuestUserId,
        'IAM Rejoin Refresh Temporary Guest',
        'user',
        $tenantId,
        'temporary_guest_list_user_can_rejoin_after_leaving',
        'participant',
        false,
        $label
    );

    $tamperedAuth = (array) ($tempGuestSession['auth'] ?? []);
    $tamperedAuth['user'] = array_merge((array) ($tamperedAuth['user'] ?? []), [
        'id' => $registeredUserId,
        'display_name' => 'IAM Rejoin Refresh Registered',
    ]);
    $bindingMismatch = videochat_realtime_resolve_connection_rooms($tamperedAuth, $tempRoomId, $openDatabase, $tempCallId);
    videochat_iam_rejoin_contract_assert((string) ($bindingMismatch['access_session_binding'] ?? '') === 'mismatch', 'account-binding violation should be detected from call-access session binding', $label);
    videochat_iam_rejoin_contract_assert((string) ($bindingMismatch['initial_room_id'] ?? '') === videochat_realtime_waiting_room_id(), 'account-binding violation must not enter the call room', $label);
    videochat_iam_rejoin_contract_assert((string) ($bindingMismatch['pending_room_id'] ?? '') === '', 'account-binding violation must not queue another user into the bound temporary context', $label);

    $duplicateTempSession = videochat_issue_session_for_call_access(
        $pdo,
        (string) (((array) ($tempGuestSession['access_link'] ?? []))['id'] ?? ''),
        static fn (): string => $tempSessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => $label],
        ['guest_name' => 'IAM Rejoin Refresh Duplicate Guest']
    );
    videochat_iam_rejoin_contract_assert(!(bool) ($duplicateTempSession['ok'] ?? true), 'parallel temporary session must not reuse an existing session id', $label);
    videochat_iam_rejoin_contract_assert((string) ($duplicateTempSession['reason'] ?? '') === 'conflict', 'parallel temporary session reuse should fail as a conflict', $label);
    videochat_iam_rejoin_contract_assert((string) (($duplicateTempSession['errors'] ?? [])['session'] ?? '') === 'session_id_not_available', 'parallel temporary session reuse should expose session_id_not_available', $label);

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
