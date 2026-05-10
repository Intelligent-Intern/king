<?php

declare(strict_types=1);

require_once __DIR__ . '/call-access-rejoin-kick-membership-helper.php';

$label = 'call-access-membership-stale-invite-rights-contract';

function videochat_membership_stale_invite_assert(bool $condition, string $message, string $label): void
{
    videochat_iam_rejoin_contract_assert($condition, $message, $label);
}

function videochat_membership_stale_invite_create_organization(PDO $pdo, int $tenantId, string $publicId, string $name): int
{
    $now = gmdate('c');
    $statement = $pdo->prepare(
        <<<'SQL'
INSERT INTO organizations(tenant_id, parent_organization_id, public_id, name, status, created_at, updated_at)
VALUES(:tenant_id, NULL, :public_id, :name, 'active', :created_at, :updated_at)
SQL
    );
    $statement->execute([
        ':tenant_id' => $tenantId,
        ':public_id' => $publicId,
        ':name' => $name,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);

    return (int) $pdo->lastInsertId();
}

function videochat_membership_stale_invite_set_organization_membership(
    PDO $pdo,
    int $tenantId,
    int $organizationId,
    int $userId,
    string $role,
    string $status = 'active'
): void {
    $normalizedRole = strtolower(trim($role)) === 'admin' ? 'admin' : 'member';
    $normalizedStatus = strtolower(trim($status)) === 'disabled' ? 'disabled' : 'active';
    $now = gmdate('c');

    $update = $pdo->prepare(
        <<<'SQL'
UPDATE organization_memberships
SET membership_role = :membership_role,
    status = :status,
    updated_at = :updated_at
WHERE tenant_id = :tenant_id
  AND organization_id = :organization_id
  AND user_id = :user_id
SQL
    );
    $update->execute([
        ':membership_role' => $normalizedRole,
        ':status' => $normalizedStatus,
        ':updated_at' => $now,
        ':tenant_id' => $tenantId,
        ':organization_id' => $organizationId,
        ':user_id' => $userId,
    ]);
    if ($update->rowCount() > 0) {
        return;
    }

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO organization_memberships(tenant_id, organization_id, user_id, membership_role, status, created_at, updated_at)
VALUES(:tenant_id, :organization_id, :user_id, :membership_role, :status, :created_at, :updated_at)
SQL
    );
    $insert->execute([
        ':tenant_id' => $tenantId,
        ':organization_id' => $organizationId,
        ':user_id' => $userId,
        ':membership_role' => $normalizedRole,
        ':status' => $normalizedStatus,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
}

function videochat_membership_stale_invite_grant_organization_read(
    PDO $pdo,
    int $tenantId,
    int $organizationId,
    string $organizationPublicId,
    int $createdByUserId
): void {
    $now = gmdate('c');
    $statement = $pdo->prepare(
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
    );
    $statement->execute([
        ':tenant_id' => $tenantId,
        ':resource_id' => $organizationPublicId,
        ':organization_id' => $organizationId,
        ':created_by_user_id' => $createdByUserId,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
}

function videochat_membership_stale_invite_org_read_ok(
    PDO $pdo,
    int $tenantId,
    int $userId,
    string $organizationPublicId
): bool {
    return (bool) (
        videochat_tenancy_user_has_resource_permission(
            $pdo,
            $tenantId,
            $userId,
            'organization',
            $organizationPublicId,
            'read'
        )['ok'] ?? false
    );
}

function videochat_membership_stale_invite_personal_link(
    PDO $pdo,
    string $callId,
    int $ownerUserId,
    string $ownerRole,
    int $participantUserId,
    int $tenantId,
    string $label
): string {
    $access = videochat_create_call_access_link_for_user($pdo, $callId, $ownerUserId, $ownerRole, [
        'link_kind' => 'personal',
        'participant_user_id' => $participantUserId,
    ], $tenantId);
    videochat_membership_stale_invite_assert((bool) ($access['ok'] ?? false), 'personal call access link should be created', $label);
    $accessId = (string) (($access['access_link'] ?? [])['id'] ?? '');
    videochat_membership_stale_invite_assert($accessId !== '', 'personal access link id should be present', $label);

    return $accessId;
}

function videochat_membership_stale_invite_issue_call_session(
    PDO $pdo,
    string $accessId,
    string $sessionId,
    string $callId,
    string $roomId,
    int $userId,
    string $label
): array {
    $session = videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        static fn (): string => $sessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => $label]
    );
    videochat_membership_stale_invite_assert((bool) ($session['ok'] ?? false), 'call-scoped personal session should issue', $label);
    videochat_membership_stale_invite_assert((int) (($session['user'] ?? [])['id'] ?? 0) === $userId, 'call-scoped session should stay bound to invited user', $label);
    $binding = videochat_fetch_call_access_session_binding($pdo, $sessionId);
    videochat_membership_stale_invite_assert(is_array($binding), 'call-scoped session binding should exist', $label);
    videochat_membership_stale_invite_assert((string) ($binding['call_id'] ?? '') === $callId, 'call-scoped session should bind the invited call', $label);
    videochat_membership_stale_invite_assert((string) ($binding['room_id'] ?? '') === $roomId, 'call-scoped session should bind the invited room', $label);
    videochat_membership_stale_invite_assert((int) ($binding['user_id'] ?? 0) === $userId, 'call-scoped session should bind the invited user id', $label);
    videochat_membership_stale_invite_assert((string) ($binding['link_kind'] ?? '') === 'personal', 'call-scoped session should be personal-link scoped', $label);

    $auth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . rawurlencode($sessionId) . '&room=' . rawurlencode($roomId) . '&call_id=' . rawurlencode($callId),
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        ],
        'websocket'
    );
    videochat_membership_stale_invite_assert((bool) ($auth['ok'] ?? false), 'call-scoped session should authenticate', $label);

    return $auth;
}

function videochat_membership_stale_invite_assert_call_scoped_participant(
    PDO $pdo,
    string $callId,
    string $roomId,
    int $userId,
    int $ownerUserId,
    int $tenantId,
    callable $openDatabase,
    array $auth,
    string $label
): void {
    $decision = videochat_decide_call_access_for_user($pdo, $callId, $userId, 'user', $tenantId);
    videochat_membership_stale_invite_assert((bool) ($decision['allowed'] ?? false), 'explicit invite should keep call access', $label);
    videochat_membership_stale_invite_assert((string) ($decision['source'] ?? '') === 'internal_participant', 'explicit invite should be the access source', $label);
    videochat_membership_stale_invite_assert((string) ($decision['scope'] ?? '') === 'call', 'explicit invite should be call-scoped only', $label);
    videochat_membership_stale_invite_assert((string) ($decision['effective_call_role'] ?? '') === 'participant', 'call-scoped invite should not elevate effective role', $label);
    videochat_membership_stale_invite_assert(!(bool) ($decision['can_moderate'] ?? true), 'call-scoped invite should not grant moderation', $label);
    videochat_membership_stale_invite_assert(!videochat_user_is_organization_admin_for_call($pdo, $callId, $userId, $tenantId), 'current organization-admin status should be false', $label);
    videochat_membership_stale_invite_assert(!videochat_can_administer_call($pdo, $callId, 'admin', $userId, $ownerUserId, $tenantId), 'forged stale admin role should not restore call administration', $label);

    $pendingResolution = videochat_realtime_resolve_connection_rooms($auth, $roomId, $openDatabase, $callId);
    videochat_membership_stale_invite_assert((string) ($pendingResolution['initial_room_id'] ?? '') === videochat_realtime_waiting_room_id(), 'non-admin call-scoped invite should wait for host approval before admission', $label);
    videochat_membership_stale_invite_assert((string) ($pendingResolution['pending_room_id'] ?? '') === $roomId, 'waiting-room decision should remain bound to invited room', $label);

    videochat_iam_rejoin_contract_set_invite_state($pdo, $callId, $userId, 'allowed');
    $allowedResolution = videochat_realtime_resolve_connection_rooms($auth, $roomId, $openDatabase, $callId);
    videochat_membership_stale_invite_assert((string) ($allowedResolution['initial_room_id'] ?? '') === $roomId, 'admitted call-scoped invite should enter only the invited room', $label);
    videochat_membership_stale_invite_assert((string) ($allowedResolution['pending_room_id'] ?? '') === '', 'admitted call-scoped invite should leave waiting-room state', $label);
}

try {
    videochat_iam_rejoin_contract_skip_without_sqlite($label);
    [$databasePath, $pdo] = videochat_iam_rejoin_contract_bootstrap_database('videochat-call-access-membership-stale-invite-rights');
    $ids = videochat_iam_rejoin_contract_fixture_ids($pdo, $label);
    $tenantId = $ids['tenant_id'];
    $alphaOrganizationId = $ids['organization_id'];
    $alphaOrganizationPublicId = $ids['organization_public_id'];
    $adminUserId = $ids['admin_user_id'];
    $defaultUserId = $ids['default_user_id'];
    $openDatabase = static fn (): PDO => $pdo;
    $betaOrganizationId = videochat_membership_stale_invite_create_organization(
        $pdo,
        $tenantId,
        'organization-beta-stale-invite-contract',
        'IAM Beta Stale Invite Contract'
    );
    videochat_membership_stale_invite_assert($betaOrganizationId > 0, 'second organization should be created', $label);
    videochat_membership_stale_invite_grant_organization_read($pdo, $tenantId, $alphaOrganizationId, $alphaOrganizationPublicId, $adminUserId);

    $movedMemberUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-moved-org-member@example.test',
        'IAM Moved Org Member',
        $tenantId,
        $alphaOrganizationId,
        'member',
        'member'
    );
    videochat_membership_stale_invite_assert(
        videochat_membership_stale_invite_org_read_ok($pdo, $tenantId, $movedMemberUserId, $alphaOrganizationPublicId),
        'alpha organization member should receive organization-scoped grant before move',
        $label
    );
    $movedInvitedCall = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $adminUserId,
        [$movedMemberUserId],
        $tenantId,
        'IAM Moved Member Invited Call'
    );
    $movedOtherCall = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $adminUserId,
        [],
        $tenantId,
        'IAM Moved Member Other Alpha Call'
    );
    $movedAccessId = videochat_membership_stale_invite_personal_link(
        $pdo,
        $movedInvitedCall['call_id'],
        $adminUserId,
        'admin',
        $movedMemberUserId,
        $tenantId,
        $label
    );
    videochat_iam_rejoin_contract_disable_organization_membership($pdo, $tenantId, $alphaOrganizationId, $movedMemberUserId);
    videochat_membership_stale_invite_set_organization_membership($pdo, $tenantId, $betaOrganizationId, $movedMemberUserId, 'member');
    videochat_membership_stale_invite_assert(videochat_tenant_user_is_member($pdo, $movedMemberUserId, $tenantId), 'moved organization member should keep tenant membership', $label);
    videochat_membership_stale_invite_assert(
        !videochat_membership_stale_invite_org_read_ok($pdo, $tenantId, $movedMemberUserId, $alphaOrganizationPublicId),
        'moved organization member should lose old-organization resource grants',
        $label
    );
    $movedOtherDecision = videochat_decide_call_access_for_user($pdo, $movedOtherCall['call_id'], $movedMemberUserId, 'user', $tenantId);
    videochat_membership_stale_invite_assert(!(bool) ($movedOtherDecision['allowed'] ?? true), 'moved member should not use old organization membership for unrelated alpha call', $label);
    $movedAuth = videochat_membership_stale_invite_issue_call_session(
        $pdo,
        $movedAccessId,
        'sess_iam_moved_member_call_scoped',
        $movedInvitedCall['call_id'],
        $movedInvitedCall['room_id'],
        $movedMemberUserId,
        $label
    );
    videochat_membership_stale_invite_assert_call_scoped_participant(
        $pdo,
        $movedInvitedCall['call_id'],
        $movedInvitedCall['room_id'],
        $movedMemberUserId,
        $adminUserId,
        $tenantId,
        $openDatabase,
        $movedAuth,
        $label
    );

    $downgradedAdminUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-downgraded-org-admin@example.test',
        'IAM Downgraded Org Admin',
        $tenantId,
        $alphaOrganizationId,
        'member',
        'admin'
    );
    $downgradedInvitedCall = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $defaultUserId,
        [$downgradedAdminUserId],
        $tenantId,
        'IAM Downgraded Admin Invited Call'
    );
    $downgradedOtherCall = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $defaultUserId,
        [],
        $tenantId,
        'IAM Downgraded Admin Other Alpha Call'
    );
    videochat_membership_stale_invite_assert(videochat_user_is_organization_admin_for_call($pdo, $downgradedOtherCall['call_id'], $downgradedAdminUserId, $tenantId), 'org admin should have same-organization call rights before downgrade', $label);
    $downgradedAccessId = videochat_membership_stale_invite_personal_link(
        $pdo,
        $downgradedInvitedCall['call_id'],
        $defaultUserId,
        'user',
        $downgradedAdminUserId,
        $tenantId,
        $label
    );
    videochat_membership_stale_invite_set_organization_membership($pdo, $tenantId, $alphaOrganizationId, $downgradedAdminUserId, 'member');
    videochat_membership_stale_invite_assert(!videochat_user_is_organization_admin_for_call($pdo, $downgradedOtherCall['call_id'], $downgradedAdminUserId, $tenantId), 'downgraded admin should lose org-admin rights for unrelated calls', $label);
    $downgradedAuth = videochat_membership_stale_invite_issue_call_session(
        $pdo,
        $downgradedAccessId,
        'sess_iam_downgraded_admin_call_scoped',
        $downgradedInvitedCall['call_id'],
        $downgradedInvitedCall['room_id'],
        $downgradedAdminUserId,
        $label
    );
    videochat_membership_stale_invite_assert_call_scoped_participant(
        $pdo,
        $downgradedInvitedCall['call_id'],
        $downgradedInvitedCall['room_id'],
        $downgradedAdminUserId,
        $defaultUserId,
        $tenantId,
        $openDatabase,
        $downgradedAuth,
        $label
    );

    $promotedUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-promoted-org-admin@example.test',
        'IAM Promoted Org Admin',
        $tenantId,
        $alphaOrganizationId,
        'member',
        'member'
    );
    $promotedInvitedCall = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $defaultUserId,
        [$promotedUserId],
        $tenantId,
        'IAM Promoted User Invited Call'
    );
    $promotedOtherCall = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $defaultUserId,
        [],
        $tenantId,
        'IAM Promoted User Other Alpha Call'
    );
    $promotedAccessId = videochat_membership_stale_invite_personal_link(
        $pdo,
        $promotedInvitedCall['call_id'],
        $defaultUserId,
        'user',
        $promotedUserId,
        $tenantId,
        $label
    );
    videochat_membership_stale_invite_set_organization_membership($pdo, $tenantId, $alphaOrganizationId, $promotedUserId, 'admin');
    videochat_membership_stale_invite_assert(videochat_user_is_organization_admin_for_call($pdo, $promotedOtherCall['call_id'], $promotedUserId, $tenantId), 'promoted member should gain current org-admin rights while still in the organization', $label);
    videochat_membership_stale_invite_assert(videochat_can_administer_call($pdo, $promotedOtherCall['call_id'], 'user', $promotedUserId, $defaultUserId, $tenantId), 'promoted member should administer same-organization calls from current membership', $label);
    $promotedAuth = videochat_membership_stale_invite_issue_call_session(
        $pdo,
        $promotedAccessId,
        'sess_iam_promoted_user_current_admin',
        $promotedInvitedCall['call_id'],
        $promotedInvitedCall['room_id'],
        $promotedUserId,
        $label
    );
    $promotedDecision = videochat_decide_call_access_for_user($pdo, $promotedInvitedCall['call_id'], $promotedUserId, 'user', $tenantId);
    videochat_membership_stale_invite_assert((string) ($promotedDecision['source'] ?? '') === 'organization_admin', 'promoted user should receive current org-admin call source', $label);
    videochat_membership_stale_invite_assert((string) ($promotedDecision['scope'] ?? '') === 'organization', 'promoted user should receive organization-scoped rights while still a member', $label);
    videochat_membership_stale_invite_assert((string) ($promotedDecision['effective_call_role'] ?? '') === 'moderator', 'promoted user should receive moderator-equivalent role', $label);
    videochat_membership_stale_invite_assert((bool) ($promotedDecision['can_moderate'] ?? false), 'promoted user should receive current moderation rights', $label);
    $promotedResolution = videochat_realtime_resolve_connection_rooms($promotedAuth, $promotedInvitedCall['room_id'], $openDatabase, $promotedInvitedCall['call_id']);
    videochat_membership_stale_invite_assert((string) ($promotedResolution['initial_room_id'] ?? '') === $promotedInvitedCall['room_id'], 'promoted org admin should direct-enter from current organization rights', $label);

    $removedAdminUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-removed-stale-admin@example.test',
        'IAM Removed Stale Admin',
        $tenantId,
        $alphaOrganizationId,
        'member',
        'admin'
    );
    $removedAdminCall = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $defaultUserId,
        [$removedAdminUserId],
        $tenantId,
        'IAM Removed Admin Stale Invite Call'
    );
    $removedAdminAccessId = videochat_membership_stale_invite_personal_link(
        $pdo,
        $removedAdminCall['call_id'],
        $defaultUserId,
        'user',
        $removedAdminUserId,
        $tenantId,
        $label
    );
    videochat_iam_rejoin_contract_disable_organization_membership($pdo, $tenantId, $alphaOrganizationId, $removedAdminUserId);
    $removedAdminAuth = videochat_membership_stale_invite_issue_call_session(
        $pdo,
        $removedAdminAccessId,
        'sess_iam_removed_stale_admin_call_scoped',
        $removedAdminCall['call_id'],
        $removedAdminCall['room_id'],
        $removedAdminUserId,
        $label
    );
    videochat_membership_stale_invite_assert_call_scoped_participant(
        $pdo,
        $removedAdminCall['call_id'],
        $removedAdminCall['room_id'],
        $removedAdminUserId,
        $defaultUserId,
        $tenantId,
        $openDatabase,
        $removedAdminAuth,
        $label
    );

    $lobbyUserId = videochat_iam_rejoin_contract_seed_user(
        $pdo,
        'iam-removed-lobby-member@example.test',
        'IAM Removed Lobby Member',
        $tenantId,
        $alphaOrganizationId,
        'member',
        'member'
    );
    videochat_membership_stale_invite_assert(
        videochat_membership_stale_invite_org_read_ok($pdo, $tenantId, $lobbyUserId, $alphaOrganizationPublicId),
        'lobby member should have organization resource rights before removal',
        $label
    );
    $lobbyCall = videochat_iam_rejoin_contract_create_active_call(
        $pdo,
        $adminUserId,
        [$lobbyUserId],
        $tenantId,
        'IAM Removed Lobby Member Call'
    );
    $lobbyAccessId = videochat_membership_stale_invite_personal_link(
        $pdo,
        $lobbyCall['call_id'],
        $adminUserId,
        'admin',
        $lobbyUserId,
        $tenantId,
        $label
    );
    $lobbyAuth = videochat_membership_stale_invite_issue_call_session(
        $pdo,
        $lobbyAccessId,
        'sess_iam_removed_lobby_member_call_scoped',
        $lobbyCall['call_id'],
        $lobbyCall['room_id'],
        $lobbyUserId,
        $label
    );
    $lobbyResolution = videochat_realtime_resolve_connection_rooms($lobbyAuth, $lobbyCall['room_id'], $openDatabase, $lobbyCall['call_id']);
    videochat_membership_stale_invite_assert((string) ($lobbyResolution['initial_room_id'] ?? '') === videochat_realtime_waiting_room_id(), 'lobby member should start in waiting room before admission', $label);
    $lobbyPresence = videochat_presence_state_init();
    $lobbyState = videochat_lobby_state_init();
    $waitingConnection = videochat_iam_rejoin_contract_waiting_connection(
        $pdo,
        $lobbyPresence,
        $lobbyCall['room_id'],
        $lobbyCall['call_id'],
        $lobbyUserId,
        'IAM Removed Lobby Member',
        'removed-lobby-member',
        $tenantId,
        'sess_iam_removed_lobby_member_call_scoped'
    );
    videochat_realtime_mark_call_participant_pending_for_queue($openDatabase, $waitingConnection);
    $queueResult = videochat_lobby_queue_connection_for_room($lobbyState, $lobbyPresence, $waitingConnection, $lobbyCall['room_id']);
    videochat_membership_stale_invite_assert((bool) ($queueResult['ok'] ?? false), 'lobby member should be queued before organization removal', $label);

    videochat_iam_rejoin_contract_disable_organization_membership($pdo, $tenantId, $alphaOrganizationId, $lobbyUserId);
    videochat_membership_stale_invite_assert(
        !videochat_membership_stale_invite_org_read_ok($pdo, $tenantId, $lobbyUserId, $alphaOrganizationPublicId),
        'removed lobby user should lose organization resource rights immediately',
        $label
    );
    $lobbyResolutionAfterRemoval = videochat_realtime_resolve_connection_rooms($lobbyAuth, $lobbyCall['room_id'], $openDatabase, $lobbyCall['call_id']);
    videochat_membership_stale_invite_assert((string) ($lobbyResolutionAfterRemoval['initial_room_id'] ?? '') === videochat_realtime_waiting_room_id(), 'removed lobby user should stay in waiting room without org-based bypass', $label);
    videochat_membership_stale_invite_assert((string) ($lobbyResolutionAfterRemoval['pending_room_id'] ?? '') === $lobbyCall['room_id'], 'removed lobby user should keep call-scoped pending room binding', $label);
    $waitingRevalidated = videochat_realtime_connection_with_call_context($waitingConnection, $openDatabase);
    videochat_membership_stale_invite_assert((string) ($waitingRevalidated['active_call_id'] ?? '') === $lobbyCall['call_id'], 'removed lobby user should keep call-scoped pending call context', $label);
    videochat_membership_stale_invite_assert(!(bool) ($waitingRevalidated['can_moderate_call'] ?? true), 'removed lobby user should not keep org-based moderation rights', $label);
    videochat_realtime_sync_lobby_room_from_database($lobbyState, $openDatabase, $lobbyCall['room_id'], $lobbyCall['call_id'], null, $tenantId);
    videochat_membership_stale_invite_assert(
        isset($lobbyState['rooms'][$lobbyCall['room_id']]['queued_by_user'][$lobbyUserId]),
        'removed lobby user should remain queued through call-scoped invitation',
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
