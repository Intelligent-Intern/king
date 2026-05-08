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
