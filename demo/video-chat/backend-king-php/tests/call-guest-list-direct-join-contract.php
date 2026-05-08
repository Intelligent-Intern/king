<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../support/auth_rbac.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../domain/calls/call_management.php';

function videochat_call_guest_list_direct_join_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-guest-list-direct-join-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_call_guest_list_direct_join_create_user(PDO $pdo, PDOStatement $insertUser, int $roleId, string $email, string $displayName): int
{
    $passwordHash = password_hash('call-guest-list-direct-join-contract', PASSWORD_DEFAULT);
    videochat_call_guest_list_direct_join_assert(is_string($passwordHash) && $passwordHash !== '', 'password hash should be generated');
    $insertUser->execute([
        ':email' => $email,
        ':display_name' => $displayName,
        ':password_hash' => $passwordHash,
        ':role_id' => $roleId,
        ':updated_at' => gmdate('c'),
    ]);

    $userId = (int) $pdo->lastInsertId();
    videochat_call_guest_list_direct_join_assert($userId > 0, 'created user id should be positive');
    return $userId;
}

function videochat_call_guest_list_direct_join_fetch_user(PDO $pdo, int $userId): array
{
    $query = $pdo->prepare('SELECT id, email, display_name FROM users WHERE id = :id LIMIT 1');
    $query->execute([':id' => $userId]);
    $user = $query->fetch(PDO::FETCH_ASSOC);
    videochat_call_guest_list_direct_join_assert(is_array($user), 'expected user row: ' . $userId);
    return $user;
}

function videochat_call_guest_list_direct_join_create_personal_link(
    PDO $pdo,
    string $callId,
    int $tenantId,
    int $createdByUserId,
    int $participantUserId,
    string $participantEmail
): string {
    $accessId = videochat_generate_call_access_uuid();
    $tenantColumn = videochat_tenant_table_has_column($pdo, 'call_access_links', 'tenant_id') ? ', tenant_id' : '';
    $tenantValue = $tenantColumn !== '' ? ', :tenant_id' : '';
    $insert = $pdo->prepare(
        <<<SQL
INSERT INTO call_access_links(
    id,
    call_id,
    participant_user_id,
    participant_email,
    invite_code_id,
    created_by_user_id,
    created_at,
    expires_at,
    last_used_at,
    consumed_at{$tenantColumn}
) VALUES(
    :id,
    :call_id,
    :participant_user_id,
    :participant_email,
    NULL,
    :created_by_user_id,
    :created_at,
    :expires_at,
    NULL,
    NULL{$tenantValue}
)
SQL
    );
    $params = [
        ':id' => $accessId,
        ':call_id' => $callId,
        ':participant_user_id' => $participantUserId,
        ':participant_email' => $participantEmail,
        ':created_by_user_id' => $createdByUserId,
        ':created_at' => gmdate('c'),
        ':expires_at' => gmdate('c', time() + 3600),
    ];
    if ($tenantColumn !== '') {
        $params[':tenant_id'] = $tenantId;
    }
    $insert->execute($params);

    return $accessId;
}

function videochat_call_guest_list_direct_join_attach_tenant(PDOStatement $attachTenant, int $tenantId, int $userId): void
{
    $attachTenant->execute([
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
        ':updated_at' => gmdate('c'),
    ]);
}

function videochat_call_guest_list_direct_join_attach_organization(PDOStatement $attachOrganization, int $tenantId, int $organizationId, int $userId, string $role): void
{
    $attachOrganization->execute([
        ':tenant_id' => $tenantId,
        ':organization_id' => $organizationId,
        ':user_id' => $userId,
        ':membership_role' => $role,
        ':updated_at' => gmdate('c'),
    ]);
}

function videochat_call_guest_list_direct_join_entry_count(PDO $pdo, string $callId, int $userId): int
{
    $query = $pdo->prepare(
        <<<'SQL'
SELECT COUNT(*)
FROM call_participants
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
SQL
    );
    $query->execute([
        ':call_id' => $callId,
        ':user_id' => $userId,
    ]);

    return (int) $query->fetchColumn();
}

/**
 * @param array<int, array<string, mixed>> $events
 * @return array<string, int>
 */
function videochat_call_guest_list_direct_join_event_type_counts(array $events): array
{
    $counts = [];
    foreach ($events as $event) {
        $type = (string) ($event['event_type'] ?? '');
        if ($type === '') {
            continue;
        }
        $counts[$type] = ($counts[$type] ?? 0) + 1;
    }

    return $counts;
}

/**
 * @param array<string, mixed>|null $event
 */
function videochat_call_guest_list_direct_join_assert_audit_event(
    ?array $event,
    string $eventType,
    string $action,
    int $tenantId,
    string $callId,
    int $actorUserId,
    int $targetUserId
): void {
    videochat_call_guest_list_direct_join_assert(is_array($event), "{$eventType} audit event should be returned");
    videochat_call_guest_list_direct_join_assert((string) ($event['event_type'] ?? '') === $eventType, "{$eventType} audit event type mismatch");
    videochat_call_guest_list_direct_join_assert((int) ($event['tenant_id'] ?? 0) === $tenantId, "{$eventType} audit tenant mismatch");
    videochat_call_guest_list_direct_join_assert((int) ($event['actor_user_id'] ?? 0) === $actorUserId, "{$eventType} audit actor mismatch");
    videochat_call_guest_list_direct_join_assert((int) ($event['target_user_id'] ?? 0) === $targetUserId, "{$eventType} audit target mismatch");
    videochat_call_guest_list_direct_join_assert((string) ($event['call_id'] ?? '') === $callId, "{$eventType} audit call mismatch");
    videochat_call_guest_list_direct_join_assert((string) ($event['resource_type'] ?? '') === 'call_guest_list_entry', "{$eventType} audit resource type mismatch");
    videochat_call_guest_list_direct_join_assert((string) ($event['resource_id'] ?? '') === (string) $targetUserId, "{$eventType} audit resource id mismatch");
    videochat_call_guest_list_direct_join_assert(
        (string) ($event['resource_fingerprint'] ?? '') === videochat_audit_fingerprint($callId . ':' . $targetUserId),
        "{$eventType} audit resource fingerprint mismatch"
    );
    $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
    videochat_call_guest_list_direct_join_assert((string) ($payload['audit_scope'] ?? '') === 'iam_guest_list', "{$eventType} audit scope mismatch");
    videochat_call_guest_list_direct_join_assert((string) ($payload['action'] ?? '') === $action, "{$eventType} audit action mismatch");
    videochat_call_guest_list_direct_join_assert((bool) ($payload['call_scoped'] ?? false) === true, "{$eventType} audit should mark call scope");
    videochat_call_guest_list_direct_join_assert((bool) ($payload['raw_guest_identifiers_logged'] ?? true) === false, "{$eventType} audit must omit raw guest identifiers");
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-guest-list-direct-join-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-guest-list-direct-join-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $guestListUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();
    videochat_call_guest_list_direct_join_assert($adminUserId > 0, 'expected seeded admin user');
    videochat_call_guest_list_direct_join_assert($guestListUserId > 0, 'expected seeded guest-list user');

    $roleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'user' LIMIT 1")->fetchColumn();
    videochat_call_guest_list_direct_join_assert($roleId > 0, 'expected user role');
    $insertUser = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dark', :updated_at)
SQL
    );
    $notOnGuestListUserId = videochat_call_guest_list_direct_join_create_user(
        $pdo,
        $insertUser,
        $roleId,
        'not-on-guest-list@intelligent-intern.com',
        'Not On Guest List'
    );

    $guestListedCall = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Guest List Direct Join',
        'access_mode' => 'invite_only',
        'starts_at' => '2026-10-01T09:00:00Z',
        'ends_at' => '2026-10-01T10:00:00Z',
        'internal_participant_user_ids' => [$guestListUserId],
        'external_participants' => [],
    ]);
    videochat_call_guest_list_direct_join_assert((bool) ($guestListedCall['ok'] ?? false), 'guest-listed call should be created');
    $guestListedCallId = (string) (($guestListedCall['call'] ?? [])['id'] ?? '');
    videochat_call_guest_list_direct_join_assert($guestListedCallId !== '', 'guest-listed call id should be present');

    $unrelatedCall = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Unrelated Guest List Scope',
        'access_mode' => 'invite_only',
        'starts_at' => '2026-10-02T09:00:00Z',
        'ends_at' => '2026-10-02T10:00:00Z',
        'internal_participant_user_ids' => [],
        'external_participants' => [],
    ]);
    videochat_call_guest_list_direct_join_assert((bool) ($unrelatedCall['ok'] ?? false), 'unrelated call should be created');
    $unrelatedCallId = (string) (($unrelatedCall['call'] ?? [])['id'] ?? '');
    videochat_call_guest_list_direct_join_assert($unrelatedCallId !== '', 'unrelated call id should be present');

    $guestListedDecision = videochat_user_can_direct_join_call($pdo, $guestListedCallId, $guestListUserId, 'user');
    videochat_call_guest_list_direct_join_assert((bool) ($guestListedDecision['ok'] ?? false), 'user on guest list should be allowed to direct join');
    videochat_call_guest_list_direct_join_assert((string) ($guestListedDecision['reason'] ?? '') === 'guest_list', 'guest-list direct join reason mismatch');
    videochat_call_guest_list_direct_join_assert((string) ($guestListedDecision['call_id'] ?? '') === $guestListedCallId, 'guest-list direct join call id mismatch');
    videochat_call_guest_list_direct_join_assert((int) ((($guestListedDecision['guest_list_entry'] ?? [])['user_id'] ?? 0)) === $guestListUserId, 'guest-list entry user mismatch');

    $notGuestListedDecision = videochat_user_can_direct_join_call($pdo, $guestListedCallId, $notOnGuestListUserId, 'user');
    videochat_call_guest_list_direct_join_assert(!(bool) ($notGuestListedDecision['ok'] ?? true), 'user not on guest list should not direct join');
    videochat_call_guest_list_direct_join_assert((string) ($notGuestListedDecision['reason'] ?? '') === 'not_on_guest_list', 'non-guest-list denial reason mismatch');
    videochat_call_guest_list_direct_join_assert(($notGuestListedDecision['guest_list_entry'] ?? null) === null, 'non-guest-list denial must not fabricate an entry');

    $scopedDecision = videochat_user_can_direct_join_call($pdo, $unrelatedCallId, $guestListUserId, 'user');
    videochat_call_guest_list_direct_join_assert(!(bool) ($scopedDecision['ok'] ?? true), 'guest list from one call must not grant direct join to another call');
    videochat_call_guest_list_direct_join_assert((string) ($scopedDecision['reason'] ?? '') === 'not_on_guest_list', 'scoped denial reason mismatch');
    videochat_call_guest_list_direct_join_assert((string) ($scopedDecision['call_id'] ?? '') === $unrelatedCallId, 'scoped denial call id mismatch');

    $systemAdminDecision = videochat_user_can_direct_join_call($pdo, $guestListedCallId, $adminUserId, 'admin');
    videochat_call_guest_list_direct_join_assert((bool) ($systemAdminDecision['ok'] ?? false), 'system admin should direct join without guest-list entry');
    videochat_call_guest_list_direct_join_assert((string) ($systemAdminDecision['reason'] ?? '') === 'system_admin', 'system-admin direct join reason mismatch');
    videochat_call_guest_list_direct_join_assert(($systemAdminDecision['guest_list_entry'] ?? null) === null, 'system-admin direct join must not fabricate a guest-list entry');

    $forgedSystemRoleDecision = videochat_user_can_direct_join_call($pdo, $guestListedCallId, $notOnGuestListUserId, 'admin');
    videochat_call_guest_list_direct_join_assert(!(bool) ($forgedSystemRoleDecision['ok'] ?? true), 'regular user must not direct join by forging admin role input');
    videochat_call_guest_list_direct_join_assert((string) ($forgedSystemRoleDecision['reason'] ?? '') === 'not_on_guest_list', 'forged role denial reason mismatch');

    $unique = bin2hex(random_bytes(5));
    $now = gmdate('c');
    $createTenant = $pdo->prepare(
        <<<'SQL'
INSERT INTO tenants(public_id, slug, label, status, created_at, updated_at)
VALUES(:public_id, :slug, :label, 'active', :created_at, :updated_at)
SQL
    );
    $createTenant->execute([
        ':public_id' => 'tenant-direct-join-' . $unique,
        ':slug' => 'direct-join-' . $unique,
        ':label' => 'Direct Join ' . $unique,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    $tenantId = (int) $pdo->lastInsertId();
    videochat_call_guest_list_direct_join_assert($tenantId > 0, 'expected direct-join tenant id');

    $createOrganization = $pdo->prepare(
        <<<'SQL'
INSERT INTO organizations(tenant_id, public_id, name, status, created_at, updated_at)
VALUES(:tenant_id, :public_id, :name, 'active', :created_at, :updated_at)
SQL
    );
    $createOrganization->execute([
        ':tenant_id' => $tenantId,
        ':public_id' => 'direct-join-a-' . $unique,
        ':name' => 'Direct Join A',
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    $organizationAId = (int) $pdo->lastInsertId();
    $createOrganization->execute([
        ':tenant_id' => $tenantId,
        ':public_id' => 'direct-join-b-' . $unique,
        ':name' => 'Direct Join B',
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    $organizationBId = (int) $pdo->lastInsertId();
    videochat_call_guest_list_direct_join_assert($organizationAId > 0 && $organizationBId > 0, 'expected organization ids');

    $orgAdminUserId = videochat_call_guest_list_direct_join_create_user($pdo, $insertUser, $roleId, 'direct-join-org-admin-' . $unique . '@example.test', 'Direct Join Org Admin');
    $ownerAUserId = videochat_call_guest_list_direct_join_create_user($pdo, $insertUser, $roleId, 'direct-join-owner-a-' . $unique . '@example.test', 'Direct Join Owner A');
    $ownerBUserId = videochat_call_guest_list_direct_join_create_user($pdo, $insertUser, $roleId, 'direct-join-owner-b-' . $unique . '@example.test', 'Direct Join Owner B');
    $participantAUserId = videochat_call_guest_list_direct_join_create_user($pdo, $insertUser, $roleId, 'direct-join-participant-a-' . $unique . '@example.test', 'Direct Join Participant A');
    $participantBUserId = videochat_call_guest_list_direct_join_create_user($pdo, $insertUser, $roleId, 'direct-join-participant-b-' . $unique . '@example.test', 'Direct Join Participant B');

    $attachTenant = $pdo->prepare(
        <<<'SQL'
INSERT INTO tenant_memberships(tenant_id, user_id, membership_role, status, permissions_json, default_membership, updated_at)
VALUES(:tenant_id, :user_id, 'member', 'active', '{}', 0, :updated_at)
SQL
    );
    foreach ([$orgAdminUserId, $ownerAUserId, $ownerBUserId, $participantAUserId, $participantBUserId] as $tenantUserId) {
        videochat_call_guest_list_direct_join_attach_tenant($attachTenant, $tenantId, $tenantUserId);
    }

    $attachOrganization = $pdo->prepare(
        <<<'SQL'
INSERT INTO organization_memberships(tenant_id, organization_id, user_id, membership_role, status, updated_at)
VALUES(:tenant_id, :organization_id, :user_id, :membership_role, 'active', :updated_at)
SQL
    );
    videochat_call_guest_list_direct_join_attach_organization($attachOrganization, $tenantId, $organizationAId, $orgAdminUserId, 'admin');
    videochat_call_guest_list_direct_join_attach_organization($attachOrganization, $tenantId, $organizationAId, $ownerAUserId, 'member');
    videochat_call_guest_list_direct_join_attach_organization($attachOrganization, $tenantId, $organizationAId, $participantAUserId, 'member');
    videochat_call_guest_list_direct_join_attach_organization($attachOrganization, $tenantId, $organizationBId, $ownerBUserId, 'member');
    videochat_call_guest_list_direct_join_attach_organization($attachOrganization, $tenantId, $organizationBId, $participantBUserId, 'member');

    $ownOrgCall = videochat_create_call($pdo, $ownerAUserId, [
        'title' => 'Direct Join Own Organization',
        'access_mode' => 'invite_only',
        'starts_at' => gmdate('c', time() - 300),
        'ends_at' => gmdate('c', time() + 3600),
        'internal_participant_user_ids' => [$participantAUserId],
    ], $tenantId);
    videochat_call_guest_list_direct_join_assert((bool) ($ownOrgCall['ok'] ?? false), 'own organization direct-join call should be created');
    $ownOrgCallId = (string) (($ownOrgCall['call'] ?? [])['id'] ?? '');
    videochat_call_guest_list_direct_join_assert($ownOrgCallId !== '', 'own organization call id should be present');

    $foreignOrgCall = videochat_create_call($pdo, $ownerBUserId, [
        'title' => 'Direct Join Foreign Organization',
        'access_mode' => 'invite_only',
        'starts_at' => gmdate('c', time() - 300),
        'ends_at' => gmdate('c', time() + 3600),
        'internal_participant_user_ids' => [$participantBUserId],
    ], $tenantId);
    videochat_call_guest_list_direct_join_assert((bool) ($foreignOrgCall['ok'] ?? false), 'foreign organization direct-join call should be created');
    $foreignOrgCallId = (string) (($foreignOrgCall['call'] ?? [])['id'] ?? '');
    videochat_call_guest_list_direct_join_assert($foreignOrgCallId !== '', 'foreign organization call id should be present');

    $ownerDirectJoin = videochat_user_can_direct_join_call($pdo, $ownOrgCallId, $ownerAUserId, 'user', $tenantId);
    videochat_call_guest_list_direct_join_assert((bool) ($ownerDirectJoin['ok'] ?? false), 'normal owner should direct join own call');
    videochat_call_guest_list_direct_join_assert((string) ($ownerDirectJoin['reason'] ?? '') === 'owner', 'normal owner direct join reason mismatch');

    $orgAdminOwnDirectJoin = videochat_user_can_direct_join_call($pdo, $ownOrgCallId, $orgAdminUserId, 'user', $tenantId);
    videochat_call_guest_list_direct_join_assert((bool) ($orgAdminOwnDirectJoin['ok'] ?? false), 'org admin should direct join own organization call without guest-list entry');
    videochat_call_guest_list_direct_join_assert((string) ($orgAdminOwnDirectJoin['reason'] ?? '') === 'organization_admin', 'org admin own-organization direct join reason mismatch');
    videochat_call_guest_list_direct_join_assert(($orgAdminOwnDirectJoin['guest_list_entry'] ?? null) === null, 'org admin direct join must not require guest-list entry');

    $orgAdminForeignDirectJoin = videochat_user_can_direct_join_call($pdo, $foreignOrgCallId, $orgAdminUserId, 'user', $tenantId);
    videochat_call_guest_list_direct_join_assert(!(bool) ($orgAdminForeignDirectJoin['ok'] ?? true), 'org admin should not direct join foreign organization call through own-org role');
    videochat_call_guest_list_direct_join_assert((string) ($orgAdminForeignDirectJoin['reason'] ?? '') === 'not_on_guest_list', 'org admin foreign direct join denial reason mismatch');

    $managementRegisteredUserId = videochat_call_guest_list_direct_join_create_user(
        $pdo,
        $insertUser,
        $roleId,
        'direct-join-management-registered-' . $unique . '@example.test',
        'Direct Join Management Registered'
    );
    videochat_call_guest_list_direct_join_attach_tenant($attachTenant, $tenantId, $managementRegisteredUserId);
    videochat_call_guest_list_direct_join_attach_organization($attachOrganization, $tenantId, $organizationAId, $managementRegisteredUserId, 'member');

    $registeredBeforeAdd = videochat_user_can_direct_join_call($pdo, $ownOrgCallId, $managementRegisteredUserId, 'user', $tenantId);
    videochat_call_guest_list_direct_join_assert(!(bool) ($registeredBeforeAdd['ok'] ?? true), 'registered user should not direct join before guest-list add');

    $registeredAdd = videochat_add_call_guest_list_entry($pdo, $ownOrgCallId, $managementRegisteredUserId, $ownerAUserId, 'user', $tenantId);
    videochat_call_guest_list_direct_join_assert((bool) ($registeredAdd['ok'] ?? false), 'owner should add registered user to guest list');
    videochat_call_guest_list_direct_join_assert((string) ($registeredAdd['reason'] ?? '') === 'added', 'registered add reason mismatch');
    videochat_call_guest_list_direct_join_assert(videochat_call_guest_list_direct_join_entry_count($pdo, $ownOrgCallId, $managementRegisteredUserId) === 1, 'registered add should create exactly one guest-list entry');
    videochat_call_guest_list_direct_join_assert_audit_event($registeredAdd['audit_event'] ?? null, 'guest_list_entry_added', 'added', $tenantId, $ownOrgCallId, $ownerAUserId, $managementRegisteredUserId);

    $registeredAfterAdd = videochat_user_can_direct_join_call($pdo, $ownOrgCallId, $managementRegisteredUserId, 'user', $tenantId);
    videochat_call_guest_list_direct_join_assert((bool) ($registeredAfterAdd['ok'] ?? false), 'registered user should direct join after guest-list add');
    videochat_call_guest_list_direct_join_assert((string) ($registeredAfterAdd['reason'] ?? '') === 'guest_list', 'registered add direct join reason mismatch');

    $registeredDuplicate = videochat_add_call_guest_list_entry($pdo, $ownOrgCallId, $managementRegisteredUserId, $ownerAUserId, 'user', $tenantId);
    videochat_call_guest_list_direct_join_assert((bool) ($registeredDuplicate['ok'] ?? false), 'duplicate registered add should merge');
    videochat_call_guest_list_direct_join_assert((string) ($registeredDuplicate['reason'] ?? '') === 'merged', 'duplicate registered add reason mismatch');
    videochat_call_guest_list_direct_join_assert(videochat_call_guest_list_direct_join_entry_count($pdo, $ownOrgCallId, $managementRegisteredUserId) === 1, 'duplicate registered add must not create extra entries');
    videochat_call_guest_list_direct_join_assert_audit_event($registeredDuplicate['audit_event'] ?? null, 'guest_list_entry_merged', 'merged', $tenantId, $ownOrgCallId, $ownerAUserId, $managementRegisteredUserId);

    $registeredScoped = videochat_user_can_direct_join_call($pdo, $foreignOrgCallId, $managementRegisteredUserId, 'user', $tenantId);
    videochat_call_guest_list_direct_join_assert(!(bool) ($registeredScoped['ok'] ?? true), 'registered guest list must remain call-scoped');
    videochat_call_guest_list_direct_join_assert((string) ($registeredScoped['reason'] ?? '') === 'not_on_guest_list', 'registered call-scope denial reason mismatch');

    $registeredRemove = videochat_remove_call_guest_list_entry($pdo, $ownOrgCallId, $managementRegisteredUserId, $ownerAUserId, 'user', $tenantId);
    videochat_call_guest_list_direct_join_assert((bool) ($registeredRemove['ok'] ?? false), 'owner should remove registered guest-list entry');
    videochat_call_guest_list_direct_join_assert((string) ($registeredRemove['reason'] ?? '') === 'removed', 'registered remove reason mismatch');
    videochat_call_guest_list_direct_join_assert_audit_event($registeredRemove['audit_event'] ?? null, 'guest_list_entry_removed', 'removed', $tenantId, $ownOrgCallId, $ownerAUserId, $managementRegisteredUserId);

    $registeredAfterRemove = videochat_user_can_direct_join_call($pdo, $ownOrgCallId, $managementRegisteredUserId, 'user', $tenantId);
    videochat_call_guest_list_direct_join_assert(!(bool) ($registeredAfterRemove['ok'] ?? true), 'registered user should not direct join after guest-list removal');
    videochat_call_guest_list_direct_join_assert((string) ($registeredAfterRemove['reason'] ?? '') === 'guest_list_entry_inactive', 'registered removal direct join reason mismatch');

    $registeredRestore = videochat_add_call_guest_list_entry($pdo, $ownOrgCallId, $managementRegisteredUserId, $ownerAUserId, 'user', $tenantId);
    videochat_call_guest_list_direct_join_assert((bool) ($registeredRestore['ok'] ?? false), 'owner should restore removed registered guest-list entry');
    videochat_call_guest_list_direct_join_assert((string) ($registeredRestore['reason'] ?? '') === 'restored', 'registered restore reason mismatch');
    videochat_call_guest_list_direct_join_assert(videochat_call_guest_list_direct_join_entry_count($pdo, $ownOrgCallId, $managementRegisteredUserId) === 1, 'registered restore must still keep one entry');
    videochat_call_guest_list_direct_join_assert_audit_event($registeredRestore['audit_event'] ?? null, 'guest_list_entry_restored', 'restored', $tenantId, $ownOrgCallId, $ownerAUserId, $managementRegisteredUserId);

    $temporaryCreate = videochat_create_guest_user_for_call_access($pdo, 'Direct Join Temporary Guest', $tenantId, false);
    videochat_call_guest_list_direct_join_assert((bool) ($temporaryCreate['ok'] ?? false), 'temporary guest account should be created through call-access helper');
    $temporaryGuest = is_array($temporaryCreate['user'] ?? null) ? $temporaryCreate['user'] : [];
    $temporaryGuestId = (int) ($temporaryGuest['id'] ?? 0);
    videochat_call_guest_list_direct_join_assert($temporaryGuestId > 0, 'temporary guest user id should be positive');
    videochat_call_guest_list_direct_join_assert((string) ($temporaryGuest['account_type'] ?? '') === 'guest', 'temporary guest account type mismatch');
    videochat_call_guest_list_direct_join_assert((bool) ($temporaryGuest['is_guest'] ?? false) === true, 'temporary guest marker mismatch');
    videochat_call_guest_list_direct_join_assert(!videochat_tenant_user_is_member($pdo, $temporaryGuestId, $tenantId), 'temporary guest should not require tenant membership');

    $temporaryBeforeAdd = videochat_user_can_direct_join_call($pdo, $ownOrgCallId, $temporaryGuestId, 'user', $tenantId);
    videochat_call_guest_list_direct_join_assert(!(bool) ($temporaryBeforeAdd['ok'] ?? true), 'temporary guest should not direct join before guest-list add');

    $temporaryAdd = videochat_add_call_guest_list_entry($pdo, $ownOrgCallId, $temporaryGuestId, $ownerAUserId, 'user', $tenantId);
    videochat_call_guest_list_direct_join_assert((bool) ($temporaryAdd['ok'] ?? false), 'owner should add temporary account to guest list');
    videochat_call_guest_list_direct_join_assert((string) ($temporaryAdd['reason'] ?? '') === 'added', 'temporary add reason mismatch');
    videochat_call_guest_list_direct_join_assert(videochat_call_guest_list_direct_join_entry_count($pdo, $ownOrgCallId, $temporaryGuestId) === 1, 'temporary add should create exactly one guest-list entry');
    videochat_call_guest_list_direct_join_assert_audit_event($temporaryAdd['audit_event'] ?? null, 'guest_list_entry_added', 'added', $tenantId, $ownOrgCallId, $ownerAUserId, $temporaryGuestId);

    $temporaryAfterAdd = videochat_user_can_direct_join_call($pdo, $ownOrgCallId, $temporaryGuestId, 'user', $tenantId);
    videochat_call_guest_list_direct_join_assert((bool) ($temporaryAfterAdd['ok'] ?? false), 'temporary guest should direct join after guest-list add');
    videochat_call_guest_list_direct_join_assert((string) ($temporaryAfterAdd['reason'] ?? '') === 'guest_list', 'temporary add direct join reason mismatch');

    $temporaryDuplicate = videochat_add_call_guest_list_entry($pdo, $ownOrgCallId, $temporaryGuestId, $ownerAUserId, 'user', $tenantId);
    videochat_call_guest_list_direct_join_assert((bool) ($temporaryDuplicate['ok'] ?? false), 'duplicate temporary add should merge');
    videochat_call_guest_list_direct_join_assert((string) ($temporaryDuplicate['reason'] ?? '') === 'merged', 'duplicate temporary add reason mismatch');
    videochat_call_guest_list_direct_join_assert(videochat_call_guest_list_direct_join_entry_count($pdo, $ownOrgCallId, $temporaryGuestId) === 1, 'duplicate temporary add must not create extra entries');
    videochat_call_guest_list_direct_join_assert_audit_event($temporaryDuplicate['audit_event'] ?? null, 'guest_list_entry_merged', 'merged', $tenantId, $ownOrgCallId, $ownerAUserId, $temporaryGuestId);

    $temporaryGuest = videochat_call_guest_list_direct_join_fetch_user($pdo, $temporaryGuestId);
    $temporaryAccessId = videochat_call_guest_list_direct_join_create_personal_link(
        $pdo,
        $ownOrgCallId,
        $tenantId,
        $ownerAUserId,
        $temporaryGuestId,
        (string) ($temporaryGuest['email'] ?? '')
    );
    $temporaryLinkResolution = videochat_resolve_call_access_public($pdo, $temporaryAccessId);
    videochat_call_guest_list_direct_join_assert((bool) ($temporaryLinkResolution['ok'] ?? false), 'temporary personalized guest-list link should resolve');
    videochat_call_guest_list_direct_join_assert((int) (($temporaryLinkResolution['target_user'] ?? [])['id'] ?? 0) === $temporaryGuestId, 'temporary personalized link should resolve to bound temporary account');
    videochat_call_guest_list_direct_join_assert((string) (($temporaryLinkResolution['call'] ?? [])['id'] ?? '') === $ownOrgCallId, 'temporary personalized link should resolve to bound call');

    $manipulatedBodySession = videochat_issue_session_for_call_access(
        $pdo,
        $temporaryAccessId,
        static fn (): string => 'sess_direct_join_temp_manipulated_body',
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-guest-list-direct-join-contract'],
        [
            'participant_user_id' => $participantBUserId,
            'target_user_id' => $participantBUserId,
            'user_id' => $participantBUserId,
            'call_id' => $foreignOrgCallId,
            'room_id' => 'forged-room',
            'role' => 'admin',
            'guest_name' => 'Forged Identity',
        ]
    );
    videochat_call_guest_list_direct_join_assert((bool) ($manipulatedBodySession['ok'] ?? false), 'unknown body authority fields must not block valid temporary link session');
    videochat_call_guest_list_direct_join_assert((int) (($manipulatedBodySession['user'] ?? [])['id'] ?? 0) === $temporaryGuestId, 'body fields must not change the temporary link identity');
    videochat_call_guest_list_direct_join_assert((string) (($manipulatedBodySession['call'] ?? [])['id'] ?? '') === $ownOrgCallId, 'body fields must not change the link call');
    videochat_call_guest_list_direct_join_assert((int) (($manipulatedBodySession['user'] ?? [])['id'] ?? 0) !== $participantBUserId, 'temporary link must not assume another participant identity');
    videochat_call_guest_list_direct_join_assert((bool) (($manipulatedBodySession['call'] ?? [])['my_participation'] ?? false), 'temporary guest-list link should retain direct participant visibility');

    $wrongAuthenticatedSession = videochat_issue_session_for_call_access(
        $pdo,
        $temporaryAccessId,
        static fn (): string => 'sess_direct_join_temp_wrong_auth',
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-guest-list-direct-join-contract'],
        [
            'authenticated_user_id' => $participantBUserId,
            'authenticated_session_id' => 'sess_participant_b_wrong_auth',
        ]
    );
    videochat_call_guest_list_direct_join_assert(!(bool) ($wrongAuthenticatedSession['ok'] ?? true), 'authenticated different user must not take over temporary personalized link');
    videochat_call_guest_list_direct_join_assert((string) ($wrongAuthenticatedSession['reason'] ?? '') === 'forbidden', 'wrong authenticated user denial reason mismatch');
    $wrongAuthSessionRows = $pdo->query("SELECT COUNT(*) FROM sessions WHERE id = 'sess_direct_join_temp_wrong_auth'");
    videochat_call_guest_list_direct_join_assert($wrongAuthSessionRows !== false && (int) $wrongAuthSessionRows->fetchColumn() === 0, 'wrong authenticated denial must not persist a session');

    $mutatedAccessId = substr($temporaryAccessId, 0, -1) . (substr($temporaryAccessId, -1) === 'a' ? 'b' : 'a');
    $mutatedSession = videochat_issue_session_for_call_access(
        $pdo,
        $mutatedAccessId,
        static fn (): string => 'sess_direct_join_temp_mutated_link',
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-guest-list-direct-join-contract']
    );
    videochat_call_guest_list_direct_join_assert(!(bool) ($mutatedSession['ok'] ?? true), 'mutated temporary personalized link should be rejected');
    videochat_call_guest_list_direct_join_assert((string) ($mutatedSession['reason'] ?? '') === 'not_found', 'mutated temporary personalized link reason mismatch');

    $pdo->prepare('UPDATE call_participants SET left_at = :left_at WHERE call_id = :call_id AND user_id = :user_id')->execute([
        ':left_at' => gmdate('c'),
        ':call_id' => $ownOrgCallId,
        ':user_id' => $temporaryGuestId,
    ]);
    $leftBinding = videochat_validate_call_access_session_binding($pdo, 'sess_direct_join_temp_manipulated_body', $temporaryGuestId);
    videochat_call_guest_list_direct_join_assert((bool) ($leftBinding['ok'] ?? false), 'temporary guest-list session should remain bound after leaving');
    videochat_call_guest_list_direct_join_assert((string) ($leftBinding['reason'] ?? '') === 'ok', 'temporary guest-list leave binding reason mismatch');
    $recognizedReopen = videochat_issue_session_for_call_access(
        $pdo,
        $temporaryAccessId,
        static fn (): string => 'sess_direct_join_temp_reopen_after_leave',
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-guest-list-direct-join-contract']
    );
    videochat_call_guest_list_direct_join_assert((bool) ($recognizedReopen['ok'] ?? false), 'temporary guest-list link should reopen after leaving');
    videochat_call_guest_list_direct_join_assert((int) (($recognizedReopen['user'] ?? [])['id'] ?? 0) === $temporaryGuestId, 'reopened temporary link should recognize the same temporary account');

    $plainNonGuestDecision = videochat_user_can_direct_join_call($pdo, $ownOrgCallId, $participantBUserId, 'user', $tenantId);
    videochat_call_guest_list_direct_join_assert(!(bool) ($plainNonGuestDecision['ok'] ?? true), 'non-guest-list user should not direct join');

    $events = videochat_audit_fetch_events($pdo, ['tenant_id' => $tenantId, 'call_id' => $ownOrgCallId, 'limit' => 100]);
    $eventTypes = videochat_call_guest_list_direct_join_event_type_counts($events);
    videochat_call_guest_list_direct_join_assert(($eventTypes['guest_list_entry_added'] ?? 0) >= 2, 'guest-list add audit events should exist');
    videochat_call_guest_list_direct_join_assert(($eventTypes['guest_list_entry_merged'] ?? 0) >= 2, 'guest-list duplicate merge audit events should exist');
    videochat_call_guest_list_direct_join_assert(($eventTypes['guest_list_entry_removed'] ?? 0) >= 1, 'guest-list remove audit event should exist');
    videochat_call_guest_list_direct_join_assert(($eventTypes['guest_list_entry_restored'] ?? 0) >= 1, 'guest-list restore audit event should exist');
    $encodedEvents = json_encode($events, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    videochat_call_guest_list_direct_join_assert(is_string($encodedEvents), 'guest-list audit events should encode');
    foreach ([
        'direct-join-management-registered-' . $unique . '@example.test',
        'Direct Join Management Registered',
        (string) ($temporaryGuest['email'] ?? ''),
        'Direct Join Temporary Guest',
    ] as $forbiddenAuditText) {
        if ($forbiddenAuditText === '') {
            continue;
        }
        videochat_call_guest_list_direct_join_assert(
            !str_contains($encodedEvents, $forbiddenAuditText),
            'guest-list audit must not leak raw guest identifier: ' . $forbiddenAuditText
        );
    }

    @unlink($databasePath);
    fwrite(STDOUT, "[call-guest-list-direct-join-contract] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[call-guest-list-direct-join-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
