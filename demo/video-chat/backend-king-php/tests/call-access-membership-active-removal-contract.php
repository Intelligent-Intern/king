<?php

declare(strict_types=1);

require_once __DIR__ . '/call-access-rejoin-kick-membership-helper.php';

$label = 'call-access-membership-active-removal-contract';

try {
    videochat_iam_rejoin_contract_skip_without_sqlite($label);
    [$databasePath, $pdo] = videochat_iam_rejoin_contract_bootstrap_database('videochat-call-access-membership-active-removal');
    $ids = videochat_iam_rejoin_contract_fixture_ids($pdo, $label);
    $tenantId = $ids['tenant_id'];
    $organizationId = $ids['organization_id'];
    $organizationPublicId = $ids['organization_public_id'];
    $adminUserId = $ids['admin_user_id'];
    $defaultUserId = $ids['default_user_id'];
    $openDatabase = static fn (): PDO => $pdo;

    $guestListUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-guest-list-removal@example.test',
        'IAM Guest List Removal',
        $tenantId,
        $organizationId
    );
    $guestListCall = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $adminUserId,
        [$guestListUserId],
        $tenantId,
        'IAM Guest List Removal Contract'
    );
    $guestListCallId = $guestListCall['call_id'];
    $directBeforeRemoval = videochat_user_can_direct_join_call($pdo, $guestListCallId, $guestListUserId, 'user', $tenantId);
    videochat_iam_rejoin_contract_assert((bool) ($directBeforeRemoval['ok'] ?? false), 'guest-listed user should direct-join before removal', $label);
    videochat_iam_rejoin_contract_assert((string) ($directBeforeRemoval['reason'] ?? '') === 'guest_list', 'guest-list direct join reason mismatch before removal', $label);

    $access = videochat_create_call_access_link_for_user($pdo, $guestListCallId, $adminUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $guestListUserId,
    ], $tenantId);
    videochat_iam_rejoin_contract_assert((bool) ($access['ok'] ?? false), 'personal access link should be created before guest-list removal', $label);
    $accessId = (string) (($access['access_link'] ?? [])['id'] ?? '');
    videochat_iam_rejoin_contract_assert($accessId !== '', 'personal access id should be present', $label);

    videochat_iam_rejoin_contract_set_invite_state($pdo, $guestListCallId, $guestListUserId, 'cancelled');
    $directAfterRemoval = videochat_user_can_direct_join_call($pdo, $guestListCallId, $guestListUserId, 'user', $tenantId);
    videochat_iam_rejoin_contract_assert(!(bool) ($directAfterRemoval['ok'] ?? true), 'guest-list removal should deny direct join', $label);
    videochat_iam_rejoin_contract_assert((string) ($directAfterRemoval['reason'] ?? '') === 'guest_list_entry_inactive', 'guest-list removal denial reason mismatch', $label);
    videochat_iam_rejoin_contract_assert((string) ((($directAfterRemoval['guest_list_entry'] ?? [])['invite_state'] ?? '')) === 'cancelled', 'guest-list denial should expose inactive invite state only through contract result', $label);

    $resolveRemovedLink = videochat_resolve_call_access_public($pdo, $accessId);
    videochat_iam_rejoin_contract_assert(!(bool) ($resolveRemovedLink['ok'] ?? true), 'removed guest-list personal link should not resolve', $label);
    videochat_iam_rejoin_contract_assert((string) ($resolveRemovedLink['reason'] ?? '') === 'not_found', 'removed guest-list link should be hidden as not_found', $label);
    $sessionFromRemovedLink = videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        static fn (): string => 'sess_removed_guest_list_should_not_issue',
        ['client_ip' => '127.0.0.1', 'user_agent' => $label]
    );
    videochat_iam_rejoin_contract_assert(!(bool) ($sessionFromRemovedLink['ok'] ?? true), 'removed guest-list link must not issue a call session', $label);

    $orgAdminUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-stale-org-admin@example.test',
        'IAM Stale Org Admin',
        $tenantId,
        $organizationId,
        'member',
        'admin'
    );
    $orgCall = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $defaultUserId,
        [],
        $tenantId,
        'IAM Org Membership Active Removal'
    );
    $orgCallId = $orgCall['call_id'];
    $orgRoomId = $orgCall['room_id'];
    $orgAdminAuth = videochat_iam_rejoin_contract_issue_user_session(
        $pdo,
        $orgAdminUserId,
        $tenantId,
        'sess_iam_stale_org_admin',
        $label
    );
    $orgCallBeforeRemoval = videochat_get_call_for_user($pdo, $orgCallId, $orgAdminUserId, 'user', $tenantId);
    videochat_iam_rejoin_contract_assert((bool) ($orgCallBeforeRemoval['ok'] ?? false), 'organization admin should access same-org call before removal', $label);
    videochat_iam_rejoin_contract_assert(
        videochat_can_administer_call($pdo, $orgCallId, 'user', $orgAdminUserId, $defaultUserId, $tenantId),
        'organization admin should administer same-org call before removal',
        $label
    );

    $orgResolutionBeforeRemoval = videochat_realtime_resolve_connection_rooms($orgAdminAuth, $orgRoomId, $openDatabase, $orgCallId);
    videochat_iam_rejoin_contract_assert((string) ($orgResolutionBeforeRemoval['initial_room_id'] ?? '') === $orgRoomId, 'organization admin should bypass lobby before removal', $label);
    $presenceState = videochat_presence_state_init();
    $orgAdminConnection = videochat_iam_rejoin_contract_connection(
        $pdo,
        $presenceState,
        $orgRoomId,
        $orgCallId,
        $orgAdminUserId,
        'IAM Stale Org Admin',
        'user',
        'org-admin-before-removal',
        $tenantId,
        false,
        'sess_iam_stale_org_admin'
    );
    videochat_iam_rejoin_contract_assert((string) ($orgAdminConnection['active_call_id'] ?? '') === $orgCallId, 'organization admin connection should bind call before removal', $label);
    videochat_iam_rejoin_contract_assert((string) ($orgAdminConnection['effective_call_role'] ?? '') === 'moderator', 'organization admin should resolve moderator-equivalent role before removal', $label);
    videochat_iam_rejoin_contract_assert((bool) ($orgAdminConnection['can_moderate_call'] ?? false), 'organization admin should moderate before removal', $label);

    videochat_iam_rejoin_contract_disable_organization_membership($pdo, $tenantId, $organizationId, $orgAdminUserId);
    $orgAuthAfterRemoval = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/api/auth/session',
            'headers' => ['Authorization' => 'Bearer sess_iam_stale_org_admin'],
        ],
        'http'
    );
    videochat_iam_rejoin_contract_assert((bool) ($orgAuthAfterRemoval['ok'] ?? false), 'organization removal alone should leave tenant session valid', $label);
    videochat_iam_rejoin_contract_assert(!videochat_user_is_organization_admin_for_call($pdo, $orgCallId, $orgAdminUserId, $tenantId), 'removed organization member must lose org-admin call rights', $label);
    $orgCallAfterRemoval = videochat_get_call_for_user($pdo, $orgCallId, $orgAdminUserId, 'user', $tenantId);
    videochat_iam_rejoin_contract_assert(!(bool) ($orgCallAfterRemoval['ok'] ?? true), 'removed organization member must not access hidden invite-only call', $label);
    videochat_iam_rejoin_contract_assert((string) ($orgCallAfterRemoval['reason'] ?? '') === 'forbidden', 'removed organization member call access reason mismatch', $label);
    $forgedAdminAccess = videochat_get_call_for_user($pdo, $orgCallId, $orgAdminUserId, 'admin', $tenantId);
    videochat_iam_rejoin_contract_assert(!(bool) ($forgedAdminAccess['ok'] ?? true), 'stale forged admin role must not restore call access', $label);

    $staleConnection = $orgAdminConnection;
    $staleConnection['call_role'] = 'moderator';
    $staleConnection['effective_call_role'] = 'moderator';
    $staleConnection['can_moderate_call'] = true;
    $revalidatedConnection = videochat_realtime_connection_with_call_context($staleConnection, $openDatabase);
    videochat_iam_rejoin_contract_assert((string) ($revalidatedConnection['active_call_id'] ?? '') === '', 'stale org role connection must lose active call binding after removal', $label);
    videochat_iam_rejoin_contract_assert((bool) ($revalidatedConnection['can_moderate_call'] ?? true) === false, 'stale org role connection must lose moderation after removal', $label);

    $orgResolutionAfterRemoval = videochat_realtime_resolve_connection_rooms($orgAuthAfterRemoval, $orgRoomId, $openDatabase, $orgCallId);
    videochat_iam_rejoin_contract_assert((bool) ($orgResolutionAfterRemoval['ok'] ?? false), 'post-removal room resolution should remain an explicit lobby decision', $label);
    videochat_iam_rejoin_contract_assert((string) ($orgResolutionAfterRemoval['initial_room_id'] ?? '') === videochat_realtime_waiting_room_id(), 'removed organization member should no longer enter active call directly', $label);
    videochat_iam_rejoin_contract_assert((string) ($orgResolutionAfterRemoval['pending_room_id'] ?? '') === $orgRoomId, 'removed organization member should be held for host admission', $label);

    $callScopedOrgAdminUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-call-scoped-org-admin-removal@example.test',
        'IAM Call Scoped Org Admin Removal',
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
        (bool) (videochat_tenancy_user_has_resource_permission($pdo, $tenantId, $callScopedOrgAdminUserId, 'organization', $organizationPublicId, 'read')['ok'] ?? false),
        'call-scoped org admin should have organization resource rights before removal',
        $label
    );

    $callScopedCall = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $defaultUserId,
        [$callScopedOrgAdminUserId],
        $tenantId,
        'IAM Call Scoped Org Admin Active Removal'
    );
    $callScopedCallId = $callScopedCall['call_id'];
    $callScopedRoomId = $callScopedCall['room_id'];
    $callScopedAccess = videochat_create_call_access_link_for_user($pdo, $callScopedCallId, $defaultUserId, 'user', [
        'link_kind' => 'personal',
        'participant_user_id' => $callScopedOrgAdminUserId,
    ], $tenantId);
    videochat_iam_rejoin_contract_assert((bool) ($callScopedAccess['ok'] ?? false), 'call-scoped org admin personal access link should be created', $label);
    $callScopedAccessId = (string) (($callScopedAccess['access_link'] ?? [])['id'] ?? '');
    videochat_iam_rejoin_contract_assert($callScopedAccessId !== '', 'call-scoped org admin personal access id should be present', $label);
    videochat_iam_rejoin_contract_set_invite_state($pdo, $callScopedCallId, $callScopedOrgAdminUserId, 'allowed');

    $callScopedSessionId = 'sess_iam_call_scoped_org_admin_removal';
    $callScopedSession = videochat_issue_session_for_call_access(
        $pdo,
        $callScopedAccessId,
        static fn (): string => $callScopedSessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => $label]
    );
    videochat_iam_rejoin_contract_assert((bool) ($callScopedSession['ok'] ?? false), 'call-scoped org admin session should issue before removal', $label);
    videochat_iam_rejoin_contract_assert(is_array(videochat_fetch_call_access_session_binding($pdo, $callScopedSessionId)), 'call-scoped org admin session binding should exist', $label);
    $callScopedAuth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . $callScopedSessionId . '&room=' . $callScopedRoomId . '&call_id=' . $callScopedCallId,
            'headers' => ['Authorization' => 'Bearer ' . $callScopedSessionId],
        ],
        'websocket'
    );
    videochat_iam_rejoin_contract_assert((bool) ($callScopedAuth['ok'] ?? false), 'call-scoped org admin session should authenticate before removal', $label);
    $callScopedBeforeRemoval = videochat_realtime_resolve_connection_rooms($callScopedAuth, $callScopedRoomId, $openDatabase, $callScopedCallId);
    videochat_iam_rejoin_contract_assert((string) ($callScopedBeforeRemoval['initial_room_id'] ?? '') === $callScopedRoomId, 'call-scoped org admin should enter invited call before removal', $label);

    $callScopedPresence = videochat_presence_state_init();
    $callScopedConnection = videochat_iam_rejoin_contract_connection(
        $pdo,
        $callScopedPresence,
        $callScopedRoomId,
        $callScopedCallId,
        $callScopedOrgAdminUserId,
        'IAM Call Scoped Org Admin Removal',
        'user',
        'call-scoped-org-admin-before-removal',
        $tenantId,
        true,
        $callScopedSessionId
    );
    videochat_iam_rejoin_contract_assert((string) ($callScopedConnection['active_call_id'] ?? '') === $callScopedCallId, 'call-scoped org admin should start inside the invited call', $label);
    videochat_iam_rejoin_contract_assert((bool) ($callScopedConnection['can_moderate_call'] ?? false), 'organization admin should moderate before active removal', $label);

    videochat_iam_rejoin_contract_disable_organization_membership($pdo, $tenantId, $organizationId, $callScopedOrgAdminUserId);
    videochat_iam_rejoin_contract_assert(
        (bool) (videochat_tenancy_user_has_resource_permission($pdo, $tenantId, $callScopedOrgAdminUserId, 'organization', $organizationPublicId, 'read')['ok'] ?? false) === false,
        'active removed org admin must lose organization resource rights immediately',
        $label
    );
    videochat_iam_rejoin_contract_assert(!videochat_user_is_organization_admin_for_call($pdo, $callScopedCallId, $callScopedOrgAdminUserId, $tenantId), 'active removed org admin must lose organization-admin call rights immediately', $label);
    videochat_iam_rejoin_contract_assert(!videochat_can_administer_call($pdo, $callScopedCallId, 'admin', $callScopedOrgAdminUserId, $defaultUserId, $tenantId), 'stale admin role must not restore active org-admin controls after removal', $label);

    $callScopedRevalidated = videochat_realtime_connection_with_call_context($callScopedConnection, $openDatabase);
    videochat_iam_rejoin_contract_assert((string) ($callScopedRevalidated['active_call_id'] ?? '') === $callScopedCallId, 'active removed user should remain connected when explicit call-scoped access exists', $label);
    videochat_iam_rejoin_contract_assert((string) ($callScopedRevalidated['effective_call_role'] ?? '') === 'participant', 'active removed org admin should downgrade to participant in the call', $label);
    videochat_iam_rejoin_contract_assert(!(bool) ($callScopedRevalidated['can_moderate_call'] ?? true), 'active removed org admin must lose realtime moderator controls immediately', $label);
    videochat_iam_rejoin_contract_assert(
        videochat_realtime_connection_can_bypass_admission_for_room($callScopedRevalidated, $callScopedRoomId, $openDatabase),
        'active removed user should keep room admission only through allowed call-scoped access',
        $label
    );
    videochat_iam_rejoin_contract_assert(
        !videochat_realtime_is_user_moderator_for_room($openDatabase, $callScopedOrgAdminUserId, 'user', $callScopedRoomId, $callScopedCallId, $tenantId),
        'active removed org admin must not moderate from current backend state',
        $label
    );
    $callScopedSnapshot = videochat_realtime_room_snapshot_payload($callScopedPresence, $callScopedRevalidated, $openDatabase, 'organization_removed_call_scoped_revalidation');
    $callScopedViewer = is_array($callScopedSnapshot['viewer'] ?? null) ? $callScopedSnapshot['viewer'] : [];
    videochat_iam_rejoin_contract_assert((string) ($callScopedViewer['call_id'] ?? '') === $callScopedCallId, 'snapshot should keep the call-scoped viewer in the active call', $label);
    videochat_iam_rejoin_contract_assert((string) ($callScopedViewer['effective_call_role'] ?? '') === 'participant', 'snapshot should expose downgraded participant role after org removal', $label);
    videochat_iam_rejoin_contract_assert(!(bool) ($callScopedViewer['can_moderate'] ?? true), 'snapshot should remove org-admin controls after org removal', $label);

    $removedMemberUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-tenant-removed-active-call@example.test',
        'IAM Tenant Removed Active Call',
        $tenantId,
        $organizationId
    );
    $memberCall = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $adminUserId,
        [$removedMemberUserId],
        $tenantId,
        'IAM Tenant Membership Active Removal'
    );
    $memberCallId = $memberCall['call_id'];
    $memberRoomId = $memberCall['room_id'];
    videochat_iam_rejoin_contract_set_invite_state($pdo, $memberCallId, $removedMemberUserId, 'allowed');
    $memberAuth = videochat_iam_rejoin_contract_issue_user_session(
        $pdo,
        $removedMemberUserId,
        $tenantId,
        'sess_iam_tenant_removed_active_call',
        $label
    );
    $memberPresenceState = videochat_presence_state_init();
    $memberConnection = videochat_iam_rejoin_contract_connection(
        $pdo,
        $memberPresenceState,
        $memberRoomId,
        $memberCallId,
        $removedMemberUserId,
        'IAM Tenant Removed Active Call',
        'user',
        'tenant-member-before-removal',
        $tenantId,
        true,
        'sess_iam_tenant_removed_active_call'
    );
    videochat_iam_rejoin_contract_assert((string) ($memberConnection['active_call_id'] ?? '') === $memberCallId, 'tenant member should be active in call before removal', $label);
    videochat_iam_rejoin_contract_assert((bool) ($memberAuth['ok'] ?? false), 'tenant member auth should be valid before removal', $label);

    videochat_iam_rejoin_contract_disable_tenant_membership($pdo, $tenantId, $removedMemberUserId);
    $livenessAfterTenantRemoval = videochat_realtime_validate_session_liveness(
        static fn (array $request, string $transport): array => videochat_authenticate_request($pdo, $request, $transport),
        'sess_iam_tenant_removed_active_call',
        '/ws'
    );
    videochat_iam_rejoin_contract_assert(!(bool) ($livenessAfterTenantRemoval['ok'] ?? true), 'tenant membership removal should fail active websocket liveness', $label);
    videochat_iam_rejoin_contract_assert((string) ($livenessAfterTenantRemoval['reason'] ?? '') === 'tenant_membership_inactive', 'tenant removal liveness reason mismatch', $label);
    $livenessPolicy = videochat_realtime_session_liveness_failure_policy((string) ($livenessAfterTenantRemoval['reason'] ?? ''), 1, 0, 5000);
    videochat_iam_rejoin_contract_assert((bool) ($livenessPolicy['close'] ?? false), 'tenant removal liveness failure should close the websocket', $label);
    videochat_iam_rejoin_contract_assert((int) (($livenessPolicy['close_descriptor'] ?? [])['close_code'] ?? 0) === 1008, 'tenant removal close code should be policy violation', $label);

    $pdo->prepare('DELETE FROM sessions WHERE id = :session_id')->execute([':session_id' => 'sess_iam_tenant_removed_active_call']);
    $cachedAuthAfterTenantRemoval = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/api/auth/session',
            'headers' => ['Authorization' => 'Bearer sess_iam_tenant_removed_active_call'],
        ],
        'http'
    );
    videochat_iam_rejoin_contract_assert(!(bool) ($cachedAuthAfterTenantRemoval['ok'] ?? true), 'locally cached stale tenant token must not survive membership removal', $label);
    videochat_iam_rejoin_contract_assert((string) ($cachedAuthAfterTenantRemoval['reason'] ?? '') === 'tenant_membership_inactive', 'cached stale tenant token denial reason mismatch', $label);

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
