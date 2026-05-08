<?php

declare(strict_types=1);

require_once __DIR__ . '/call-access-rejoin-kick-membership-helper.php';

$label = 'call-access-invited-user-org-removal-contract';

try {
    videochat_iam_rejoin_contract_skip_without_sqlite($label);
    [$databasePath, $pdo] = videochat_iam_rejoin_contract_bootstrap_database('videochat-call-access-invited-user-org-removal');
    $ids = videochat_iam_rejoin_contract_fixture_ids($pdo, $label);
    $tenantId = $ids['tenant_id'];
    $organizationId = $ids['organization_id'];
    $organizationPublicId = $ids['organization_public_id'];
    $adminUserId = $ids['admin_user_id'];
    $defaultUserId = $ids['default_user_id'];
    $openDatabase = static fn (): PDO => $pdo;

    $invitedUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-invited-org-removal@example.test',
        'IAM Invited Org Removal',
        $tenantId,
        $organizationId,
        'member',
        'admin'
    );

    $pdo->prepare(
        <<<'SQL'
INSERT INTO permission_grants(
    tenant_id,
    resource_type,
    resource_id,
    action,
    subject_type,
    organization_id,
    created_by_user_id,
    created_at,
    updated_at
) VALUES(
    :tenant_id,
    'organization',
    :resource_id,
    'read',
    'organization',
    :organization_id,
    :created_by_user_id,
    :created_at,
    :updated_at
)
SQL
    )->execute([
        ':tenant_id' => $tenantId,
        ':resource_id' => $organizationPublicId,
        ':organization_id' => $organizationId,
        ':created_by_user_id' => $adminUserId,
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);
    videochat_iam_rejoin_contract_assert(
        (bool) (videochat_tenancy_user_has_resource_permission($pdo, $tenantId, $invitedUserId, 'organization', $organizationPublicId, 'read')['ok'] ?? false),
        'organization admin should receive organization-scoped grant before removal',
        $label
    );

    $otherCall = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $defaultUserId,
        [],
        $tenantId,
        'IAM Org Removal Other Organization Call'
    );
    $otherCallId = $otherCall['call_id'];
    $otherRoomId = $otherCall['room_id'];

    $invitedCall = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $adminUserId,
        [$invitedUserId],
        $tenantId,
        'IAM Org Removal Personal Invite Call'
    );
    $invitedCallId = $invitedCall['call_id'];
    $invitedRoomId = $invitedCall['room_id'];

    $staleAuthBeforeRemoval = videochat_iam_rejoin_contract_issue_user_session(
        $pdo,
        $invitedUserId,
        $tenantId,
        'sess_iam_invited_org_removal_stale_org_admin',
        $label
    );
    videochat_iam_rejoin_contract_assert(
        videochat_user_is_organization_admin_for_call($pdo, $otherCallId, $invitedUserId, $tenantId),
        'invited user should have organization-admin call rights before removal',
        $label
    );
    videochat_iam_rejoin_contract_assert(
        videochat_can_administer_call($pdo, $otherCallId, 'user', $invitedUserId, $defaultUserId, $tenantId),
        'invited organization admin should administer same-organization call before removal',
        $label
    );
    $otherCallBeforeRemoval = videochat_get_call_for_user($pdo, $otherCallId, $invitedUserId, 'user', $tenantId);
    videochat_iam_rejoin_contract_assert((bool) ($otherCallBeforeRemoval['ok'] ?? false), 'organization admin should see same-organization call before removal', $label);
    $roomBeforeRemoval = videochat_realtime_resolve_connection_rooms($staleAuthBeforeRemoval, $otherRoomId, $openDatabase, $otherCallId);
    videochat_iam_rejoin_contract_assert((string) ($roomBeforeRemoval['initial_room_id'] ?? '') === $otherRoomId, 'organization admin should bypass lobby before removal', $label);

    $access = videochat_create_call_access_link_for_user($pdo, $invitedCallId, $adminUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $invitedUserId,
    ], $tenantId);
    videochat_iam_rejoin_contract_assert((bool) ($access['ok'] ?? false), 'personal access link should be created before organization removal', $label);
    $accessId = (string) (($access['access_link'] ?? [])['id'] ?? '');
    videochat_iam_rejoin_contract_assert($accessId !== '', 'personal access id should be present', $label);

    videochat_iam_rejoin_contract_disable_organization_membership($pdo, $tenantId, $organizationId, $invitedUserId);
    videochat_iam_rejoin_contract_assert(videochat_tenant_user_is_member($pdo, $invitedUserId, $tenantId), 'organization removal alone must not delete tenant membership', $label);
    $tenantAfterRemoval = videochat_tenant_context_for_user($pdo, $invitedUserId, $tenantId);
    videochat_iam_rejoin_contract_assert(is_array($tenantAfterRemoval), 'tenant context should remain resolvable after organization-only removal', $label);
    videochat_iam_rejoin_contract_assert((string) ($tenantAfterRemoval['role'] ?? '') === 'member', 'removed organization role must not mint tenant admin role', $label);
    videochat_iam_rejoin_contract_assert((bool) ((($tenantAfterRemoval['permissions'] ?? [])['tenant_admin'] ?? false)) === false, 'removed organization role must not mint tenant-admin permission', $label);
    videochat_iam_rejoin_contract_assert((bool) ((($tenantAfterRemoval['permissions'] ?? [])['manage_organizations'] ?? false)) === false, 'removed organization role must not mint organization-management permission', $label);
    videochat_iam_rejoin_contract_assert(
        (bool) (videochat_tenancy_user_has_resource_permission($pdo, $tenantId, $invitedUserId, 'organization', $organizationPublicId, 'read')['ok'] ?? false) === false,
        'removed organization member must lose organization-scoped resource grants immediately',
        $label
    );

    $staleAuthAfterRemoval = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/api/auth/session',
            'headers' => ['Authorization' => 'Bearer sess_iam_invited_org_removal_stale_org_admin'],
        ],
        'http'
    );
    videochat_iam_rejoin_contract_assert((bool) ($staleAuthAfterRemoval['ok'] ?? false), 'stale normal tenant session should remain valid after organization-only removal', $label);
    videochat_iam_rejoin_contract_assert((string) (($staleAuthAfterRemoval['tenant'] ?? [])['role'] ?? '') === 'member', 'stale session must re-read least-privilege tenant role after organization removal', $label);
    videochat_iam_rejoin_contract_assert((bool) (((($staleAuthAfterRemoval['tenant'] ?? [])['permissions'] ?? [])['tenant_admin'] ?? false)) === false, 'stale session must not keep tenant admin through old organization role', $label);
    videochat_iam_rejoin_contract_assert(!videochat_user_is_organization_admin_for_call($pdo, $otherCallId, $invitedUserId, $tenantId), 'removed organization member must lose org-admin call rights', $label);
    $otherCallAfterRemoval = videochat_get_call_for_user($pdo, $otherCallId, $invitedUserId, 'user', $tenantId);
    videochat_iam_rejoin_contract_assert(!(bool) ($otherCallAfterRemoval['ok'] ?? true), 'removed organization member must not browse unrelated organization call', $label);
    videochat_iam_rejoin_contract_assert((string) ($otherCallAfterRemoval['reason'] ?? '') === 'forbidden', 'unrelated call denial reason mismatch after organization removal', $label);
    videochat_iam_rejoin_contract_assert(!videochat_can_administer_call($pdo, $otherCallId, 'admin', $invitedUserId, $defaultUserId, $tenantId), 'forged stale admin role must not restore org-admin moderation', $label);
    $otherRoomAfterRemoval = videochat_realtime_resolve_connection_rooms($staleAuthAfterRemoval, $otherRoomId, $openDatabase, $otherCallId);
    videochat_iam_rejoin_contract_assert((string) ($otherRoomAfterRemoval['initial_room_id'] ?? '') === videochat_realtime_waiting_room_id(), 'removed organization member must not direct-enter unrelated call', $label);
    videochat_iam_rejoin_contract_assert((string) ($otherRoomAfterRemoval['pending_room_id'] ?? '') === $otherRoomId, 'unrelated call can only be a host-reviewed lobby request after organization removal', $label);

    $publicResolution = videochat_resolve_call_access_public($pdo, $accessId);
    videochat_iam_rejoin_contract_assert((bool) ($publicResolution['ok'] ?? false), 'personal link should remain resolvable after organization removal', $label);
    videochat_iam_rejoin_contract_assert((int) (($publicResolution['target_user'] ?? [])['id'] ?? 0) === $invitedUserId, 'personal link should stay bound to the invited user', $label);

    $callAccessSessionId = 'sess_iam_invited_org_removal_call_scoped';
    $callAccessSession = videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        static fn (): string => $callAccessSessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => $label]
    );
    videochat_iam_rejoin_contract_assert((bool) ($callAccessSession['ok'] ?? false), 'removed organization member should receive a call-scoped invited session', $label);
    videochat_iam_rejoin_contract_assert((int) (($callAccessSession['user'] ?? [])['id'] ?? 0) === $invitedUserId, 'call-scoped session user should match invited user', $label);
    $binding = videochat_fetch_call_access_session_binding($pdo, $callAccessSessionId);
    videochat_iam_rejoin_contract_assert(is_array($binding), 'call access session binding should exist', $label);
    videochat_iam_rejoin_contract_assert((string) ($binding['call_id'] ?? '') === $invitedCallId, 'call access session must bind only the invited call', $label);
    videochat_iam_rejoin_contract_assert((int) ($binding['user_id'] ?? 0) === $invitedUserId, 'call access session must bind the invited user', $label);
    videochat_iam_rejoin_contract_assert((string) ($binding['link_kind'] ?? '') === 'personal', 'call access session should be personal-link scoped', $label);

    $callScopedAuth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . $callAccessSessionId . '&room=' . $invitedRoomId . '&call_id=' . $invitedCallId,
            'headers' => ['Authorization' => 'Bearer ' . $callAccessSessionId],
        ],
        'websocket'
    );
    videochat_iam_rejoin_contract_assert((bool) ($callScopedAuth['ok'] ?? false), 'call-scoped invited session should authenticate after organization removal', $label);
    videochat_iam_rejoin_contract_assert((string) (($callScopedAuth['tenant'] ?? [])['role'] ?? '') === 'member', 'call-scoped invited session must stay tenant member only', $label);
    videochat_iam_rejoin_contract_assert((bool) (((($callScopedAuth['tenant'] ?? [])['permissions'] ?? [])['tenant_admin'] ?? false)) === false, 'call-scoped invited session must not mint tenant admin', $label);
    videochat_iam_rejoin_contract_assert((bool) (((($callScopedAuth['tenant'] ?? [])['permissions'] ?? [])['manage_organizations'] ?? false)) === false, 'call-scoped invited session must not mint organization management', $label);

    $decisionBeforeAdmission = videochat_decide_call_access_for_user($pdo, $invitedCallId, $invitedUserId, 'user', $tenantId);
    videochat_iam_rejoin_contract_assert((bool) ($decisionBeforeAdmission['allowed'] ?? false), 'explicit invited participant should keep call-scoped access before host admission', $label);
    videochat_iam_rejoin_contract_assert((string) ($decisionBeforeAdmission['source'] ?? '') === 'internal_participant', 'invited user access source should be the call participant row', $label);
    videochat_iam_rejoin_contract_assert((string) ($decisionBeforeAdmission['scope'] ?? '') === 'call', 'invited user access scope should be call only', $label);
    videochat_iam_rejoin_contract_assert((string) ($decisionBeforeAdmission['invite_state'] ?? '') === 'invited', 'pre-admission invited user should keep invited state', $label);
    videochat_iam_rejoin_contract_assert((bool) ($decisionBeforeAdmission['can_moderate'] ?? true) === false, 'pre-admission invited guest must not moderate', $label);

    $pendingResolution = videochat_realtime_resolve_connection_rooms($callScopedAuth, $invitedRoomId, $openDatabase, $invitedCallId);
    videochat_iam_rejoin_contract_assert((string) ($pendingResolution['initial_room_id'] ?? '') === videochat_realtime_waiting_room_id(), 'invited removed org member should wait in lobby before admission', $label);
    videochat_iam_rejoin_contract_assert((string) ($pendingResolution['pending_room_id'] ?? '') === $invitedRoomId, 'lobby decision should remain bound to the invited call room', $label);

    $otherCallWithCallScopedSession = videochat_get_call_for_user($pdo, $otherCallId, $invitedUserId, 'user', $tenantId);
    videochat_iam_rejoin_contract_assert(!(bool) ($otherCallWithCallScopedSession['ok'] ?? true), 'call-scoped invited session must not grant unrelated call browse rights', $label);
    $bindingMismatch = videochat_realtime_resolve_connection_rooms($callScopedAuth, $otherRoomId, $openDatabase, $otherCallId);
    videochat_iam_rejoin_contract_assert((string) ($bindingMismatch['access_session_binding'] ?? '') === 'mismatch', 'call-scoped invited session must reject room/call binding mismatch', $label);

    videochat_iam_rejoin_contract_set_invite_state($pdo, $invitedCallId, $invitedUserId, 'allowed');
    $decisionAfterAdmission = videochat_decide_call_access_for_user($pdo, $invitedCallId, $invitedUserId, 'user', $tenantId);
    videochat_iam_rejoin_contract_assert((string) ($decisionAfterAdmission['invite_state'] ?? '') === 'allowed', 'host admission should move invited guest to allowed state', $label);
    videochat_iam_rejoin_contract_assert((bool) ($decisionAfterAdmission['can_moderate'] ?? true) === false, 'admitted invited guest still must not moderate', $label);
    $allowedResolution = videochat_realtime_resolve_connection_rooms($callScopedAuth, $invitedRoomId, $openDatabase, $invitedCallId);
    videochat_iam_rejoin_contract_assert((string) ($allowedResolution['initial_room_id'] ?? '') === $invitedRoomId, 'admitted call-scoped invited guest should enter only the invited call room', $label);
    videochat_iam_rejoin_contract_assert((string) ($allowedResolution['pending_room_id'] ?? '') === '', 'admitted call-scoped invited guest should not remain in lobby', $label);

    $presenceState = videochat_presence_state_init();
    $connection = videochat_iam_rejoin_contract_connection(
        $pdo,
        $presenceState,
        $invitedRoomId,
        $invitedCallId,
        $invitedUserId,
        'IAM Invited Org Removal',
        'user',
        'invited-org-removal-call-scoped',
        $tenantId,
        true,
        $callAccessSessionId
    );
    videochat_iam_rejoin_contract_assert((string) ($connection['active_call_id'] ?? '') === $invitedCallId, 'presence should bind only the invited call after admission', $label);
    videochat_iam_rejoin_contract_assert((string) ($connection['effective_call_role'] ?? '') === 'participant', 'removed organization member should join as invited participant guest, not moderator', $label);
    videochat_iam_rejoin_contract_assert((bool) ($connection['can_moderate_call'] ?? true) === false, 'removed organization member must not regain moderation after join', $label);
    videochat_iam_rejoin_contract_assert(!videochat_user_is_organization_admin_for_call($pdo, $invitedCallId, $invitedUserId, $tenantId), 'joining through invite must not recreate organization-admin membership', $label);

    $deletedUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-invited-org-removal-deleted@example.test',
        'IAM Invited Org Removal Deleted',
        $tenantId,
        $organizationId,
        'member',
        'admin'
    );
    $deletedCall = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $adminUserId,
        [$deletedUserId],
        $tenantId,
        'IAM Org Removal Deleted Personal Invite Call'
    );
    $deletedAccess = videochat_create_call_access_link_for_user($pdo, $deletedCall['call_id'], $adminUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $deletedUserId,
    ], $tenantId);
    videochat_iam_rejoin_contract_assert((bool) ($deletedAccess['ok'] ?? false), 'deleted-call personal access link should be created before organization removal', $label);
    $deletedAccessId = (string) (($deletedAccess['access_link'] ?? [])['id'] ?? '');
    videochat_iam_rejoin_contract_assert($deletedAccessId !== '', 'deleted-call personal access id should be present', $label);
    videochat_iam_rejoin_contract_disable_organization_membership($pdo, $tenantId, $organizationId, $deletedUserId);
    $deleteResult = videochat_delete_call($pdo, $deletedCall['call_id'], $adminUserId, 'admin', $tenantId);
    videochat_iam_rejoin_contract_assert((bool) ($deleteResult['ok'] ?? false), 'deleted-call fixture should delete after organization removal', $label);
    $deletedDecision = videochat_decide_call_access_for_user($pdo, $deletedCall['call_id'], $deletedUserId, 'user', $tenantId);
    videochat_iam_rejoin_contract_assert(!(bool) ($deletedDecision['allowed'] ?? true), 'removed invited user must not join deleted call', $label);
    videochat_iam_rejoin_contract_assert((string) ($deletedDecision['reason'] ?? '') === 'not_found', 'removed invited deleted-call denial reason mismatch', $label);
    $deletedPublicResolution = videochat_resolve_call_access_public($pdo, $deletedAccessId);
    videochat_iam_rejoin_contract_assert(!(bool) ($deletedPublicResolution['ok'] ?? true), 'removed invited deleted-call link should not resolve', $label);
    videochat_iam_rejoin_contract_assert((string) ($deletedPublicResolution['reason'] ?? '') === 'not_found', 'removed invited deleted-call link reason mismatch', $label);
    $deletedSession = videochat_issue_session_for_call_access(
        $pdo,
        $deletedAccessId,
        static fn (): string => 'sess_iam_removed_deleted_should_not_issue',
        ['client_ip' => '127.0.0.1', 'user_agent' => $label]
    );
    videochat_iam_rejoin_contract_assert(!(bool) ($deletedSession['ok'] ?? true), 'removed invited deleted-call link must not issue a session', $label);
    videochat_iam_rejoin_contract_assert((string) ($deletedSession['reason'] ?? '') === 'not_found', 'removed invited deleted-call session denial reason mismatch', $label);

    $endedUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-invited-org-removal-ended@example.test',
        'IAM Invited Org Removal Ended',
        $tenantId,
        $organizationId,
        'member',
        'admin'
    );
    $endedCall = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $adminUserId,
        [$endedUserId],
        $tenantId,
        'IAM Org Removal Ended Personal Invite Call'
    );
    $endedAccess = videochat_create_call_access_link_for_user($pdo, $endedCall['call_id'], $adminUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $endedUserId,
    ], $tenantId);
    videochat_iam_rejoin_contract_assert((bool) ($endedAccess['ok'] ?? false), 'ended-call personal access link should be created before organization removal', $label);
    $endedAccessId = (string) (($endedAccess['access_link'] ?? [])['id'] ?? '');
    videochat_iam_rejoin_contract_assert($endedAccessId !== '', 'ended-call personal access id should be present', $label);
    videochat_iam_rejoin_contract_disable_organization_membership($pdo, $tenantId, $organizationId, $endedUserId);
    $endResult = videochat_end_call($pdo, $endedCall['call_id'], $adminUserId, 'admin', $tenantId);
    videochat_iam_rejoin_contract_assert((bool) ($endResult['ok'] ?? false), 'ended-call fixture should end after organization removal', $label);
    $endedDecision = videochat_decide_call_access_for_user($pdo, $endedCall['call_id'], $endedUserId, 'user', $tenantId);
    videochat_iam_rejoin_contract_assert(!(bool) ($endedDecision['allowed'] ?? true), 'removed invited user must not join ended call', $label);
    videochat_iam_rejoin_contract_assert((string) ($endedDecision['reason'] ?? '') === 'call_not_joinable_from_status', 'removed invited ended-call denial reason mismatch', $label);
    $endedPublicResolution = videochat_resolve_call_access_public($pdo, $endedAccessId);
    videochat_iam_rejoin_contract_assert(!(bool) ($endedPublicResolution['ok'] ?? true), 'removed invited ended-call link should not resolve', $label);
    videochat_iam_rejoin_contract_assert((string) ($endedPublicResolution['reason'] ?? '') === 'not_found', 'removed invited ended-call link reason mismatch', $label);
    $endedSession = videochat_issue_session_for_call_access(
        $pdo,
        $endedAccessId,
        static fn (): string => 'sess_iam_removed_ended_should_not_issue',
        ['client_ip' => '127.0.0.1', 'user_agent' => $label]
    );
    videochat_iam_rejoin_contract_assert(!(bool) ($endedSession['ok'] ?? true), 'removed invited ended-call link must not issue a session', $label);
    videochat_iam_rejoin_contract_assert((string) ($endedSession['reason'] ?? '') === 'not_found', 'removed invited ended-call session denial reason mismatch', $label);

    $kickedUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-invited-org-removal-kicked@example.test',
        'IAM Invited Org Removal Kicked',
        $tenantId,
        $organizationId,
        'member',
        'admin'
    );
    $kickedCall = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $adminUserId,
        [$kickedUserId],
        $tenantId,
        'IAM Org Removal Kicked Personal Invite Call'
    );
    $kickedCallId = $kickedCall['call_id'];
    $kickedRoomId = $kickedCall['room_id'];
    $kickedAccess = videochat_create_call_access_link_for_user($pdo, $kickedCallId, $adminUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $kickedUserId,
    ], $tenantId);
    videochat_iam_rejoin_contract_assert((bool) ($kickedAccess['ok'] ?? false), 'kicked-call personal access link should be created before organization removal', $label);
    $kickedAccessId = (string) (($kickedAccess['access_link'] ?? [])['id'] ?? '');
    videochat_iam_rejoin_contract_assert($kickedAccessId !== '', 'kicked-call personal access id should be present', $label);
    videochat_iam_rejoin_contract_disable_organization_membership($pdo, $tenantId, $organizationId, $kickedUserId);
    videochat_iam_rejoin_contract_set_invite_state($pdo, $kickedCallId, $kickedUserId, 'allowed');
    $kickedSessionId = 'sess_iam_removed_kicked_call_scoped';
    $kickedSession = videochat_issue_session_for_call_access(
        $pdo,
        $kickedAccessId,
        static fn (): string => $kickedSessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => $label]
    );
    videochat_iam_rejoin_contract_assert((bool) ($kickedSession['ok'] ?? false), 'removed invited kicked fixture should issue a call-scoped session before kick', $label);
    $kickedAuth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . $kickedSessionId . '&room=' . $kickedRoomId . '&call_id=' . $kickedCallId,
            'headers' => ['Authorization' => 'Bearer ' . $kickedSessionId],
        ],
        'websocket'
    );
    videochat_iam_rejoin_contract_assert((bool) ($kickedAuth['ok'] ?? false), 'removed invited kicked fixture should authenticate before kick', $label);
    $kickedInitialResolution = videochat_realtime_resolve_connection_rooms($kickedAuth, $kickedRoomId, $openDatabase, $kickedCallId);
    videochat_iam_rejoin_contract_assert((string) ($kickedInitialResolution['initial_room_id'] ?? '') === $kickedRoomId, 'removed invited user should enter call before kick when allowed', $label);
    $kickedPresence = videochat_presence_state_init();
    $kickedLobby = videochat_lobby_state_init();
    $kickOwnerConnection = videochat_iam_rejoin_contract_connection(
        $pdo,
        $kickedPresence,
        $kickedRoomId,
        $kickedCallId,
        $adminUserId,
        'IAM Org Removal Kick Owner',
        'admin',
        'removed-invited-kick-owner',
        $tenantId
    );
    $kickedConnection = videochat_iam_rejoin_contract_connection(
        $pdo,
        $kickedPresence,
        $kickedRoomId,
        $kickedCallId,
        $kickedUserId,
        'IAM Invited Org Removal Kicked',
        'user',
        'removed-invited-kicked',
        $tenantId,
        true,
        $kickedSessionId
    );
    videochat_iam_rejoin_contract_assert((string) ($kickedConnection['active_call_id'] ?? '') === $kickedCallId, 'removed invited kicked fixture should start inside active call', $label);
    $kickResult = videochat_iam_rejoin_contract_apply_lobby_command(
        $kickedLobby,
        $kickedPresence,
        $kickOwnerConnection,
        $openDatabase,
        'lobby/remove',
        $kickedRoomId,
        $kickedUserId,
        $label
    );
    videochat_iam_rejoin_contract_assert((bool) ($kickResult['ok'] ?? false), 'owner should be able to remove the removed invited active participant', $label);
    videochat_iam_rejoin_contract_assert(videochat_iam_rejoin_contract_participant_invite_state($pdo, $kickedCallId, $kickedUserId) === 'invited', 'kick should reset the removed invited user to non-bypass invite state', $label);
    $kickedRejoinResolution = videochat_realtime_resolve_connection_rooms($kickedAuth, $kickedRoomId, $openDatabase, $kickedCallId);
    videochat_iam_rejoin_contract_assert((string) ($kickedRejoinResolution['initial_room_id'] ?? '') === videochat_realtime_waiting_room_id(), 'removed invited kicked user must not direct-rejoin the call room', $label);
    videochat_iam_rejoin_contract_assert((string) ($kickedRejoinResolution['pending_room_id'] ?? '') === $kickedRoomId, 'removed invited kicked user should require renewed host approval', $label);

    $invalidatedUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-invited-org-removal-invalidated@example.test',
        'IAM Invited Org Removal Invalidated',
        $tenantId,
        $organizationId,
        'member',
        'admin'
    );
    $invalidatedCall = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $adminUserId,
        [$invalidatedUserId],
        $tenantId,
        'IAM Org Removal Invalidated Personal Invite Call'
    );
    $invalidatedAccess = videochat_create_call_access_link_for_user($pdo, $invalidatedCall['call_id'], $adminUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $invalidatedUserId,
    ], $tenantId);
    videochat_iam_rejoin_contract_assert((bool) ($invalidatedAccess['ok'] ?? false), 'invalidated personal access link should be created before organization removal', $label);
    $invalidatedAccessId = (string) (($invalidatedAccess['access_link'] ?? [])['id'] ?? '');
    videochat_iam_rejoin_contract_assert($invalidatedAccessId !== '', 'invalidated personal access id should be present', $label);
    videochat_iam_rejoin_contract_disable_organization_membership($pdo, $tenantId, $organizationId, $invalidatedUserId);
    videochat_iam_rejoin_contract_set_invite_state($pdo, $invalidatedCall['call_id'], $invalidatedUserId, 'allowed');
    $validBeforeInvalidation = videochat_issue_session_for_call_access(
        $pdo,
        $invalidatedAccessId,
        static fn (): string => 'sess_iam_removed_invite_valid_before_invalidation',
        ['client_ip' => '127.0.0.1', 'user_agent' => $label]
    );
    videochat_iam_rejoin_contract_assert((bool) ($validBeforeInvalidation['ok'] ?? false), 'removed invited user should receive a session while invite remains valid', $label);
    $invalidateResult = videochat_invalidate_call_access_invitation($pdo, $invalidatedAccessId, 'cancelled', $adminUserId, [
        'contract' => $label,
        'reason' => 'removed_org_member_rejoin_denied',
    ]);
    videochat_iam_rejoin_contract_assert((bool) ($invalidateResult['ok'] ?? false), 'removed invited personal invite invalidation should succeed', $label);
    $invalidatedValidation = videochat_validate_session_token($pdo, 'sess_iam_removed_invite_valid_before_invalidation');
    videochat_iam_rejoin_contract_assert(!(bool) ($invalidatedValidation['ok'] ?? true), 'removed invited user existing session must fail after invite invalidation', $label);
    videochat_iam_rejoin_contract_assert((string) ($invalidatedValidation['reason'] ?? '') === 'call_access_link_invalidated', 'removed invited invalidated session reason mismatch', $label);
    $invalidatedPublicResolution = videochat_resolve_call_access_public($pdo, $invalidatedAccessId);
    videochat_iam_rejoin_contract_assert(!(bool) ($invalidatedPublicResolution['ok'] ?? true), 'removed invited invalidated link should not resolve', $label);
    videochat_iam_rejoin_contract_assert((string) ($invalidatedPublicResolution['reason'] ?? '') === 'not_found', 'removed invited invalidated link reason mismatch', $label);
    $invalidatedRejoin = videochat_issue_session_for_call_access(
        $pdo,
        $invalidatedAccessId,
        static fn (): string => 'sess_iam_removed_invalidated_should_not_issue',
        ['client_ip' => '127.0.0.1', 'user_agent' => $label]
    );
    videochat_iam_rejoin_contract_assert(!(bool) ($invalidatedRejoin['ok'] ?? true), 'removed invited user must not rejoin after invite invalidation', $label);
    videochat_iam_rejoin_contract_assert((string) ($invalidatedRejoin['reason'] ?? '') === 'not_found', 'removed invited invalidated rejoin reason mismatch', $label);

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
