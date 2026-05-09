<?php

declare(strict_types=1);

require_once __DIR__ . '/call-access-rejoin-kick-membership-helper.php';

$label = 'call-access-authorized-rejoin-contract';

/**
 * @return array{call_id: string, room_id: string}
 */
function videochat_authorized_rejoin_contract_create_allowed_call(
    PDO $pdo,
    int $ownerUserId,
    int $participantUserId,
    int $tenantId,
    string $title
): array {
    $call = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $ownerUserId,
        [$participantUserId],
        $tenantId,
        $title
    );
    videochat_iam_rejoin_contract_set_invite_state($pdo, $call['call_id'], $participantUserId, 'allowed');
    return $call;
}

function videochat_authorized_rejoin_contract_direct_join_reason(
    PDO $pdo,
    string $callId,
    int $userId,
    string $authRole,
    int $tenantId,
    string $expectedReason,
    string $caseKey,
    string $label
): void {
    $decision = videochat_user_can_direct_join_call($pdo, $callId, $userId, $authRole, $tenantId);
    videochat_iam_rejoin_contract_assert((bool) ($decision['ok'] ?? false), "{$caseKey}: direct join decision should allow the authorized user", $label);
    videochat_iam_rejoin_contract_assert((string) ($decision['reason'] ?? '') === $expectedReason, "{$caseKey}: direct join reason mismatch", $label);
    videochat_iam_rejoin_contract_assert((string) ($decision['call_id'] ?? '') === $callId, "{$caseKey}: direct join call id mismatch", $label);
}

function videochat_authorized_rejoin_contract_assert_room_resolution(
    array $auth,
    string $roomId,
    callable $openDatabase,
    string $callId,
    string $caseKey,
    string $label
): void {
    $resolution = videochat_realtime_resolve_connection_rooms($auth, $roomId, $openDatabase, $callId);
    videochat_iam_rejoin_contract_assert((bool) ($resolution['ok'] ?? false), "{$caseKey}: websocket room resolution should succeed", $label);
    videochat_iam_rejoin_contract_assert((string) ($resolution['initial_room_id'] ?? '') === $roomId, "{$caseKey}: authorized rejoin should enter the call room directly", $label);
    videochat_iam_rejoin_contract_assert((string) ($resolution['pending_room_id'] ?? '') === '', "{$caseKey}: authorized rejoin must not return to lobby", $label);
}

function videochat_authorized_rejoin_contract_assert_join_leave_rejoin(
    PDO $pdo,
    array $presenceState,
    string $roomId,
    string $callId,
    int $tenantId,
    int $userId,
    string $displayName,
    string $authRole,
    string $sessionId,
    string $expectedEffectiveRole,
    bool $expectedModeration,
    string $caseKey,
    string $label
): void {
    $openDatabase = static fn (): PDO => $pdo;
    $auth = videochat_iam_rejoin_contract_issue_user_session($pdo, $userId, $tenantId, $sessionId, $label);

    videochat_authorized_rejoin_contract_assert_room_resolution($auth, $roomId, $openDatabase, $callId, "{$caseKey}: initial join", $label);

    $connection = videochat_iam_rejoin_contract_connection(
        $pdo,
        $presenceState,
        $roomId,
        $callId,
        $userId,
        $displayName,
        $authRole,
        $caseKey . '-before-leave',
        $tenantId,
        true,
        $sessionId
    );
    videochat_iam_rejoin_contract_assert((string) ($connection['active_call_id'] ?? '') === $callId, "{$caseKey}: joined connection should bind the call", $label);
    videochat_iam_rejoin_contract_assert((string) ($connection['effective_call_role'] ?? '') === $expectedEffectiveRole, "{$caseKey}: effective call role mismatch before leave", $label);
    videochat_iam_rejoin_contract_assert((bool) ($connection['can_moderate_call'] ?? false) === $expectedModeration, "{$caseKey}: moderation capability mismatch before leave", $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_rejoin_contract_participant_left_at($pdo, $callId, $userId) === '', "{$caseKey}: joined participant should not have left_at", $label);

    $sender = static fn (mixed $socket, array $payload): bool => true;
    videochat_presence_remove_connection($presenceState, (string) ($connection['connection_id'] ?? ''), $sender);
    videochat_realtime_remove_call_presence($openDatabase, $connection);
    videochat_realtime_mark_call_participant_left($openDatabase, $connection, $presenceState);
    videochat_iam_rejoin_contract_assert(videochat_iam_rejoin_contract_participant_left_at($pdo, $callId, $userId) !== '', "{$caseKey}: leave should persist left_at", $label);

    videochat_authorized_rejoin_contract_assert_room_resolution($auth, $roomId, $openDatabase, $callId, "{$caseKey}: rejoin after leave", $label);

    $rejoinConnection = videochat_iam_rejoin_contract_connection(
        $pdo,
        $presenceState,
        $roomId,
        $callId,
        $userId,
        $displayName,
        $authRole,
        $caseKey . '-after-rejoin',
        $tenantId,
        true,
        $sessionId
    );
    videochat_iam_rejoin_contract_assert((string) ($rejoinConnection['active_call_id'] ?? '') === $callId, "{$caseKey}: rejoined connection should bind the call", $label);
    videochat_iam_rejoin_contract_assert((string) ($rejoinConnection['effective_call_role'] ?? '') === $expectedEffectiveRole, "{$caseKey}: effective call role mismatch after rejoin", $label);
    videochat_iam_rejoin_contract_assert((bool) ($rejoinConnection['can_moderate_call'] ?? false) === $expectedModeration, "{$caseKey}: moderation capability mismatch after rejoin", $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_rejoin_contract_participant_left_at($pdo, $callId, $userId) === '', "{$caseKey}: rejoin should clear stale left_at", $label);
}

try {
    videochat_iam_rejoin_contract_skip_without_sqlite($label);
    [$databasePath, $pdo] = videochat_iam_rejoin_contract_bootstrap_database('videochat-call-access-authorized-rejoin');
    $ids = videochat_iam_rejoin_contract_fixture_ids($pdo, $label);
    $tenantId = $ids['tenant_id'];
    $organizationId = $ids['organization_id'];
    $systemAdminUserId = $ids['admin_user_id'];

    $ownerUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-authorized-rejoin-owner@example.test',
        'IAM Authorized Rejoin Owner',
        $tenantId,
        $organizationId
    );
    $registeredUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-registered-authorized-rejoin@example.test',
        'IAM Registered Authorized Rejoin',
        $tenantId,
        $organizationId
    );
    $guestListUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-guest-list-authorized-rejoin@example.test',
        'IAM Guest List Authorized Rejoin',
        $tenantId,
        $organizationId
    );
    $organizationAdminUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-org-admin-authorized-rejoin@example.test',
        'IAM Org Admin Authorized Rejoin',
        $tenantId,
        $organizationId,
        'member',
        'admin'
    );

    $registeredCall = videochat_authorized_rejoin_contract_create_allowed_call(
        $pdo,
        $ownerUserId,
        $registeredUserId,
        $tenantId,
        'IAM Registered Authorized Rejoin Contract'
    );
    videochat_authorized_rejoin_contract_direct_join_reason(
        $pdo,
        $registeredCall['call_id'],
        $registeredUserId,
        'user',
        $tenantId,
        'guest_list',
        'registered_authorized_user_can_rejoin_after_leaving',
        $label
    );
    videochat_authorized_rejoin_contract_assert_join_leave_rejoin(
        $pdo,
        videochat_presence_state_init(),
        $registeredCall['room_id'],
        $registeredCall['call_id'],
        $tenantId,
        $registeredUserId,
        'IAM Registered Authorized Rejoin',
        'user',
        'sess_iam_registered_authorized_rejoin',
        'participant',
        false,
        'registered_authorized_user_can_rejoin_after_leaving',
        $label
    );

    $adminCall = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $ownerUserId,
        [],
        $tenantId,
        'IAM System Admin Authorized Rejoin Contract'
    );
    videochat_authorized_rejoin_contract_direct_join_reason(
        $pdo,
        $adminCall['call_id'],
        $systemAdminUserId,
        'admin',
        $tenantId,
        'system_admin',
        'admin_can_rejoin_after_leaving',
        $label
    );
    videochat_authorized_rejoin_contract_assert_join_leave_rejoin(
        $pdo,
        videochat_presence_state_init(),
        $adminCall['room_id'],
        $adminCall['call_id'],
        $tenantId,
        $systemAdminUserId,
        'Call System Admin',
        'admin',
        'sess_iam_admin_authorized_rejoin',
        'owner',
        true,
        'admin_can_rejoin_after_leaving',
        $label
    );

    $organizationAdminCall = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $ownerUserId,
        [],
        $tenantId,
        'IAM Organization Admin Authorized Rejoin Contract'
    );
    videochat_authorized_rejoin_contract_direct_join_reason(
        $pdo,
        $organizationAdminCall['call_id'],
        $organizationAdminUserId,
        'user',
        $tenantId,
        'organization_admin',
        'organization_admin_can_rejoin_after_leaving',
        $label
    );
    videochat_authorized_rejoin_contract_assert_join_leave_rejoin(
        $pdo,
        videochat_presence_state_init(),
        $organizationAdminCall['room_id'],
        $organizationAdminCall['call_id'],
        $tenantId,
        $organizationAdminUserId,
        'IAM Org Admin Authorized Rejoin',
        'user',
        'sess_iam_org_admin_authorized_rejoin',
        'moderator',
        true,
        'organization_admin_can_rejoin_after_leaving',
        $label
    );

    $guestListCall = videochat_authorized_rejoin_contract_create_allowed_call(
        $pdo,
        $ownerUserId,
        $guestListUserId,
        $tenantId,
        'IAM Guest List Authorized Rejoin Contract'
    );
    videochat_authorized_rejoin_contract_direct_join_reason(
        $pdo,
        $guestListCall['call_id'],
        $guestListUserId,
        'user',
        $tenantId,
        'guest_list',
        'e2e_rejoin_006_registered_guest_can_rejoin',
        $label
    );
    videochat_authorized_rejoin_contract_assert_join_leave_rejoin(
        $pdo,
        videochat_presence_state_init(),
        $guestListCall['room_id'],
        $guestListCall['call_id'],
        $tenantId,
        $guestListUserId,
        'IAM Guest List Authorized Rejoin',
        'user',
        'sess_iam_guest_list_authorized_rejoin',
        'participant',
        false,
        'e2e_rejoin_006_registered_guest_can_rejoin',
        $label
    );

    @unlink($databasePath);
    fwrite(STDOUT, "[{$label}] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[{$label}] ERROR: " . $error->getMessage() . "\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
} finally {
    if (isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
