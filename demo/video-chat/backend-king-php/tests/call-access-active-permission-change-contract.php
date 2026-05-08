<?php

declare(strict_types=1);

require_once __DIR__ . '/call-access-rejoin-kick-membership-helper.php';
require_once __DIR__ . '/../http/module_call_apps.php';

$label = 'call-access-active-permission-change-contract';

function videochat_iam_active_permission_contract_set_organization_role(
    PDO $pdo,
    int $tenantId,
    int $organizationId,
    int $userId,
    string $role
): void {
    $statement = $pdo->prepare(
        <<<'SQL'
UPDATE organization_memberships
SET membership_role = :membership_role,
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND organization_id = :organization_id
  AND user_id = :user_id
  AND status = 'active'
SQL
    );
    $statement->execute([
        ':membership_role' => strtolower(trim($role)) === 'admin' ? 'admin' : 'member',
        ':updated_at' => gmdate('c'),
        ':tenant_id' => $tenantId,
        ':organization_id' => $organizationId,
        ':user_id' => $userId,
    ]);
}

function videochat_iam_active_permission_contract_decode(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

function videochat_iam_active_permission_contract_error_response(int $status, string $code, string $message, array $details = []): array
{
    return [
        'status' => $status,
        'headers' => ['content-type' => 'application/json; charset=utf-8'],
        'body' => json_encode([
            'status' => 'error',
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
}

function videochat_iam_active_permission_contract_json_response(int $status, array $payload): array
{
    return [
        'status' => $status,
        'headers' => ['content-type' => 'application/json; charset=utf-8'],
        'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
}

try {
    videochat_iam_rejoin_contract_skip_without_sqlite($label);
    [$databasePath, $pdo] = videochat_iam_rejoin_contract_bootstrap_database('videochat-call-access-active-permission-change');
    $ids = videochat_iam_rejoin_contract_fixture_ids($pdo, $label);
    $tenantId = $ids['tenant_id'];
    $organizationId = $ids['organization_id'];
    $adminUserId = $ids['admin_user_id'];
    $defaultUserId = $ids['default_user_id'];
    $openDatabase = static fn (): PDO => $pdo;

    $guestUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-active-guest-removal@example.test',
        'IAM Active Guest Removal',
        $tenantId,
        $organizationId
    );
    $guestCall = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $adminUserId,
        [$guestUserId],
        $tenantId,
        'IAM Active Guest List Removal'
    );
    $guestCallId = $guestCall['call_id'];
    $guestRoomId = $guestCall['room_id'];
    videochat_iam_rejoin_contract_set_invite_state($pdo, $guestCallId, $guestUserId, 'allowed');
    $guestAuth = videochat_iam_rejoin_contract_issue_user_session(
        $pdo,
        $guestUserId,
        $tenantId,
        'sess_iam_active_guest_removal',
        $label
    );
    $guestPresence = videochat_presence_state_init();
    $guestConnection = videochat_iam_rejoin_contract_connection(
        $pdo,
        $guestPresence,
        $guestRoomId,
        $guestCallId,
        $guestUserId,
        'IAM Active Guest Removal',
        'user',
        'active-guest-before-removal',
        $tenantId,
        true,
        'sess_iam_active_guest_removal'
    );
    videochat_iam_rejoin_contract_assert((string) ($guestConnection['active_call_id'] ?? '') === $guestCallId, 'guest should start inside active call', $label);

    videochat_presence_remove_connection($guestPresence, (string) ($guestConnection['connection_id'] ?? ''), static fn (): bool => true);
    videochat_realtime_remove_call_presence($openDatabase, $guestConnection);
    videochat_realtime_mark_call_participant_left($openDatabase, $guestConnection, $guestPresence);
    videochat_iam_rejoin_contract_assert(videochat_iam_rejoin_contract_participant_left_at($pdo, $guestCallId, $guestUserId) !== '', 'guest leave should persist before removal', $label);

    videochat_iam_rejoin_contract_set_invite_state($pdo, $guestCallId, $guestUserId, 'cancelled');
    $guestRejoinResolution = videochat_realtime_resolve_connection_rooms($guestAuth, $guestRoomId, $openDatabase, $guestCallId);
    videochat_iam_rejoin_contract_assert((bool) ($guestRejoinResolution['ok'] ?? false), 'guest removal rejoin resolution should complete', $label);
    videochat_iam_rejoin_contract_assert((string) ($guestRejoinResolution['initial_room_id'] ?? '') === videochat_realtime_waiting_room_id(), 'removed guest should be routed to lobby on rejoin', $label);
    videochat_iam_rejoin_contract_assert((string) ($guestRejoinResolution['pending_room_id'] ?? '') === $guestRoomId, 'removed guest should keep only a pending room request', $label);

    $staleGuestConnection = $guestConnection;
    $staleGuestConnection['room_id'] = videochat_realtime_waiting_room_id();
    $staleGuestConnection['requested_room_id'] = $guestRoomId;
    $staleGuestConnection['pending_room_id'] = $guestRoomId;
    $staleGuestConnection['invite_state'] = 'allowed';
    videochat_iam_rejoin_contract_assert(
        !videochat_realtime_connection_can_bypass_admission_for_room($staleGuestConnection, $guestRoomId, $openDatabase),
        'stale allowed guest connection must not bypass after guest-list removal',
        $label
    );

    $orgAdminUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-active-org-admin-downgrade@example.test',
        'IAM Active Org Admin Downgrade',
        $tenantId,
        $organizationId,
        'member',
        'admin'
    );
    $orgAdminCall = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $defaultUserId,
        [],
        $tenantId,
        'IAM Active Org Admin Downgrade'
    );
    $orgAdminCallId = $orgAdminCall['call_id'];
    $orgAdminRoomId = $orgAdminCall['room_id'];
    $orgAdminAuth = videochat_iam_rejoin_contract_issue_user_session(
        $pdo,
        $orgAdminUserId,
        $tenantId,
        'sess_iam_active_org_admin_downgrade',
        $label
    );
    $orgAdminResolution = videochat_realtime_resolve_connection_rooms($orgAdminAuth, $orgAdminRoomId, $openDatabase, $orgAdminCallId);
    videochat_iam_rejoin_contract_assert((string) ($orgAdminResolution['initial_room_id'] ?? '') === $orgAdminRoomId, 'org admin should bypass lobby before role downgrade', $label);
    $orgAdminPresence = videochat_presence_state_init();
    $orgAdminConnection = videochat_iam_rejoin_contract_connection(
        $pdo,
        $orgAdminPresence,
        $orgAdminRoomId,
        $orgAdminCallId,
        $orgAdminUserId,
        'IAM Active Org Admin Downgrade',
        'user',
        'active-org-admin-before-downgrade',
        $tenantId,
        false,
        'sess_iam_active_org_admin_downgrade'
    );
    videochat_iam_rejoin_contract_assert((string) ($orgAdminConnection['effective_call_role'] ?? '') === 'moderator', 'org admin should resolve moderator rights before downgrade', $label);
    videochat_iam_rejoin_contract_assert((bool) ($orgAdminConnection['can_moderate_call'] ?? false), 'org admin should moderate before downgrade', $label);

    videochat_iam_active_permission_contract_set_organization_role($pdo, $tenantId, $organizationId, $orgAdminUserId, 'member');
    $staleOrgAdminConnection = $orgAdminConnection;
    $staleOrgAdminConnection['call_role'] = 'moderator';
    $staleOrgAdminConnection['effective_call_role'] = 'moderator';
    $staleOrgAdminConnection['can_moderate_call'] = true;
    $revalidatedOrgAdmin = videochat_realtime_connection_with_call_context($staleOrgAdminConnection, $openDatabase);
    videochat_iam_rejoin_contract_assert((string) ($revalidatedOrgAdmin['active_call_id'] ?? '') === '', 'downgraded org admin should lose active call binding without call-scoped access', $label);
    videochat_iam_rejoin_contract_assert(!(bool) ($revalidatedOrgAdmin['can_moderate_call'] ?? true), 'downgraded org admin should lose stale moderation', $label);
    videochat_iam_rejoin_contract_assert(
        !videochat_realtime_is_user_moderator_for_room($openDatabase, $orgAdminUserId, 'user', $orgAdminRoomId, $orgAdminCallId, $tenantId),
        'downgraded org admin should not moderate from current membership state',
        $label
    );
    $orgAdminAfterDowngrade = videochat_realtime_resolve_connection_rooms($orgAdminAuth, $orgAdminRoomId, $openDatabase, $orgAdminCallId);
    videochat_iam_rejoin_contract_assert((string) ($orgAdminAfterDowngrade['initial_room_id'] ?? '') === videochat_realtime_waiting_room_id(), 'downgraded org admin should route to lobby', $label);
    videochat_iam_rejoin_contract_assert((string) ($orgAdminAfterDowngrade['pending_room_id'] ?? '') === $orgAdminRoomId, 'downgraded org admin should keep pending room request', $label);
    videochat_iam_rejoin_contract_assert(
        !videochat_realtime_connection_can_bypass_admission_for_room($staleOrgAdminConnection, $orgAdminRoomId, $openDatabase),
        'downgraded org admin direct join must fail closed against stale moderator connection fields',
        $label
    );

    $reconnectBackfillConnection = $staleOrgAdminConnection;
    $reconnectBackfillConnection['room_id'] = videochat_realtime_waiting_room_id();
    $reconnectBackfillConnection['requested_room_id'] = $orgAdminRoomId;
    $reconnectBackfillConnection['pending_room_id'] = $orgAdminRoomId;
    $reconnectBackfillConnection['requested_call_id'] = $orgAdminCallId;
    $reconnectBackfillConnection['active_call_id'] = $orgAdminCallId;
    $reconnectBackfillConnection = videochat_realtime_connection_with_call_context($reconnectBackfillConnection, $openDatabase);
    videochat_iam_rejoin_contract_assert((string) ($reconnectBackfillConnection['active_call_id'] ?? '') === '', 'reconnect backfill must clear stale org-admin active call binding after downgrade', $label);
    videochat_iam_rejoin_contract_assert((string) ($reconnectBackfillConnection['effective_call_role'] ?? '') === 'participant', 'reconnect backfill must not restore stale moderator role after downgrade', $label);
    videochat_iam_rejoin_contract_assert(!(bool) ($reconnectBackfillConnection['can_moderate_call'] ?? true), 'reconnect backfill must not restore stale moderation flag after downgrade', $label);
    videochat_iam_rejoin_contract_assert(!(bool) ($reconnectBackfillConnection['can_manage_call_owner'] ?? true), 'reconnect backfill must not restore stale owner-management flag after downgrade', $label);

    $reconnectPresence = videochat_presence_state_init();
    $reconnectJoin = videochat_presence_join_room($reconnectPresence, $reconnectBackfillConnection, videochat_realtime_waiting_room_id());
    $reconnectBackfillConnection = (array) ($reconnectJoin['connection'] ?? $reconnectBackfillConnection);
    $reconnectFrames = [];
    $reconnectSender = static function (mixed $socket, array $payload) use (&$reconnectFrames): bool {
        $reconnectFrames[] = $payload;
        return true;
    };
    $reconnectRoomSnapshot = videochat_realtime_send_room_snapshot($reconnectPresence, $reconnectBackfillConnection, $openDatabase, 'permission_change_reconnect_backfill', $reconnectSender);
    $reconnectPayload = is_array($reconnectRoomSnapshot['payload'] ?? null) ? $reconnectRoomSnapshot['payload'] : [];
    $reconnectViewer = is_array($reconnectPayload['viewer'] ?? null) ? $reconnectPayload['viewer'] : [];
    $reconnectCallApps = is_array($reconnectPayload['call_apps'] ?? null) ? $reconnectPayload['call_apps'] : [];
    videochat_iam_rejoin_contract_assert((string) ($reconnectPayload['room_id'] ?? '') === videochat_realtime_waiting_room_id(), 'reconnect snapshot should remain in waiting room after downgrade', $label);
    videochat_iam_rejoin_contract_assert((string) ($reconnectViewer['call_id'] ?? 'stale') === '', 'reconnect snapshot viewer must not expose stale call id after downgrade', $label);
    videochat_iam_rejoin_contract_assert((string) ($reconnectViewer['effective_call_role'] ?? '') === 'participant', 'reconnect snapshot viewer must not expose stale moderator role after downgrade', $label);
    videochat_iam_rejoin_contract_assert(!(bool) ($reconnectViewer['can_moderate'] ?? true), 'reconnect snapshot viewer must not expose stale moderation after downgrade', $label);
    videochat_iam_rejoin_contract_assert((int) ($reconnectCallApps['active_session_count'] ?? 0) === 0, 'reconnect snapshot must not backfill Call Apps without active call access after downgrade', $label);
    videochat_iam_rejoin_contract_assert(((array) ($reconnectCallApps['active_sessions'] ?? [])) === [], 'reconnect snapshot must not leak Call App sessions from stale requested call id', $label);
    videochat_iam_rejoin_contract_assert((string) (($reconnectFrames[0] ?? [])['reason'] ?? '') === 'permission_change_reconnect_backfill', 'reconnect snapshot frame reason mismatch', $label);

    $callAppAvailability = videochat_handle_call_app_routes(
        '/api/calls/' . rawurlencode($orgAdminCallId) . '/call-apps/available',
        'GET',
        [
            'method' => 'GET',
            'uri' => '/api/calls/' . rawurlencode($orgAdminCallId) . '/call-apps/available?query=whiteboard',
            'path' => '/api/calls/' . rawurlencode($orgAdminCallId) . '/call-apps/available',
            'body' => '',
        ],
        $orgAdminAuth,
        'videochat_iam_active_permission_contract_json_response',
        'videochat_iam_active_permission_contract_error_response',
        $openDatabase
    );
    $callAppAvailabilityPayload = videochat_iam_active_permission_contract_decode($callAppAvailability ?? []);
    videochat_iam_rejoin_contract_assert((int) (($callAppAvailability ?? [])['status'] ?? 0) === 403, 'downgraded org admin Call App availability must fail closed', $label);
    videochat_iam_rejoin_contract_assert((string) (($callAppAvailabilityPayload['error'] ?? [])['code'] ?? '') === 'calls_forbidden', 'downgraded org admin Call App denial code mismatch', $label);

    $ownerUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-active-owner-transfer-old@example.test',
        'IAM Active Owner Transfer Old',
        $tenantId,
        $organizationId
    );
    $newOwnerUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-active-owner-transfer-new@example.test',
        'IAM Active Owner Transfer New',
        $tenantId,
        $organizationId
    );
    $managedUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-active-owner-transfer-managed@example.test',
        'IAM Active Owner Transfer Managed',
        $tenantId,
        $organizationId
    );
    $ownerCall = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $ownerUserId,
        [$newOwnerUserId, $managedUserId],
        $tenantId,
        'IAM Active Owner Transfer'
    );
    $ownerCallId = $ownerCall['call_id'];
    $ownerRoomId = $ownerCall['room_id'];
    $ownerPresence = videochat_presence_state_init();
    $oldOwnerConnection = videochat_iam_rejoin_contract_connection(
        $pdo,
        $ownerPresence,
        $ownerRoomId,
        $ownerCallId,
        $ownerUserId,
        'IAM Active Owner Transfer Old',
        'user',
        'active-owner-before-transfer',
        $tenantId,
        true,
        'sess_iam_active_owner_old'
    );
    videochat_iam_rejoin_contract_assert((bool) ($oldOwnerConnection['can_manage_call_owner'] ?? false), 'old owner should manage owner before transfer', $label);

    $ownerTransfer = videochat_update_call_participant_role($pdo, $ownerCallId, $newOwnerUserId, 'owner', $ownerUserId, 'user', $tenantId);
    videochat_iam_rejoin_contract_assert((bool) ($ownerTransfer['ok'] ?? false), 'active owner transfer should succeed', $label);
    $staleOwnerConnection = $oldOwnerConnection;
    $staleOwnerConnection['call_role'] = 'owner';
    $staleOwnerConnection['effective_call_role'] = 'owner';
    $staleOwnerConnection['can_moderate_call'] = true;
    $staleOwnerConnection['can_manage_call_owner'] = true;
    $revalidatedOldOwner = videochat_realtime_connection_with_call_context($staleOwnerConnection, $openDatabase);
    videochat_iam_rejoin_contract_assert((string) ($revalidatedOldOwner['call_role'] ?? '') === 'participant', 'old owner should revalidate to participant after transfer', $label);
    videochat_iam_rejoin_contract_assert(!(bool) ($revalidatedOldOwner['can_moderate_call'] ?? true), 'old owner should lose stale moderation after transfer', $label);
    videochat_iam_rejoin_contract_assert(!(bool) ($revalidatedOldOwner['can_manage_call_owner'] ?? true), 'old owner should lose stale owner management after transfer', $label);
    videochat_iam_rejoin_contract_assert(
        !videochat_realtime_is_user_moderator_for_room($openDatabase, $ownerUserId, 'user', $ownerRoomId, $ownerCallId, $tenantId),
        'old owner should not moderate from stale owner role after transfer',
        $label
    );

    $newOwnerConnection = videochat_iam_rejoin_contract_connection(
        $pdo,
        $ownerPresence,
        $ownerRoomId,
        $ownerCallId,
        $newOwnerUserId,
        'IAM Active Owner Transfer New',
        'user',
        'active-owner-after-transfer',
        $tenantId,
        false,
        'sess_iam_active_owner_new'
    );
    videochat_iam_rejoin_contract_assert((string) ($newOwnerConnection['call_role'] ?? '') === 'owner', 'new owner should resolve owner role after transfer', $label);
    videochat_iam_rejoin_contract_assert((bool) ($newOwnerConnection['can_manage_call_owner'] ?? false), 'new owner should manage owner after transfer', $label);

    $oldOwnerTransferAttempt = videochat_update_call_participant_role($pdo, $ownerCallId, $managedUserId, 'owner', $ownerUserId, 'user', $tenantId);
    videochat_iam_rejoin_contract_assert(!(bool) ($oldOwnerTransferAttempt['ok'] ?? true), 'old owner must not transfer owner again after active transfer', $label);
    videochat_iam_rejoin_contract_assert((string) ($oldOwnerTransferAttempt['reason'] ?? '') === 'forbidden', 'old owner post-transfer denial reason mismatch', $label);

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
