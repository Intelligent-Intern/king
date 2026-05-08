<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/realtime/realtime_call_context.php';

function videochat_org_admin_call_rights_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[org-admin-call-rights-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_org_admin_call_rights_create_user(PDO $pdo, PDOStatement $createUser, int $roleId, string $email, string $displayName): int
{
    $passwordHash = password_hash('org-admin-call-rights', PASSWORD_DEFAULT);
    videochat_org_admin_call_rights_assert(is_string($passwordHash) && $passwordHash !== '', 'password hash failed');
    $createUser->execute([
        ':email' => $email,
        ':display_name' => $displayName,
        ':password_hash' => $passwordHash,
        ':role_id' => $roleId,
        ':updated_at' => gmdate('c'),
    ]);

    return (int) $pdo->lastInsertId();
}

function videochat_org_admin_call_rights_attach_tenant(PDOStatement $attachTenant, int $tenantId, int $userId): void
{
    $attachTenant->execute([
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
        ':updated_at' => gmdate('c'),
    ]);
}

function videochat_org_admin_call_rights_attach_organization(PDOStatement $attachOrganization, int $tenantId, int $organizationId, int $userId, string $role): void
{
    $attachOrganization->execute([
        ':tenant_id' => $tenantId,
        ':organization_id' => $organizationId,
        ':user_id' => $userId,
        ':membership_role' => $role,
        ':updated_at' => gmdate('c'),
    ]);
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-org-admin-call-rights-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $userRoleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'user' LIMIT 1")->fetchColumn();
    videochat_org_admin_call_rights_assert($userRoleId > 0, 'expected user role');

    $unique = bin2hex(random_bytes(5));
    $now = gmdate('c');
    $createTenant = $pdo->prepare(
        <<<'SQL'
INSERT INTO tenants(public_id, slug, label, status, created_at, updated_at)
VALUES(:public_id, :slug, :label, 'active', :created_at, :updated_at)
SQL
    );
    $createTenant->execute([
        ':public_id' => 'tenant-org-admin-calls-' . $unique,
        ':slug' => 'org-admin-calls-' . $unique,
        ':label' => 'Org Admin Calls ' . $unique,
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    $tenantId = (int) $pdo->lastInsertId();
    videochat_org_admin_call_rights_assert($tenantId > 0, 'expected tenant id');

    $createOrganization = $pdo->prepare(
        <<<'SQL'
INSERT INTO organizations(tenant_id, public_id, name, status, created_at, updated_at)
VALUES(:tenant_id, :public_id, :name, 'active', :created_at, :updated_at)
SQL
    );
    $createOrganization->execute([
        ':tenant_id' => $tenantId,
        ':public_id' => 'org-admin-calls-a-' . $unique,
        ':name' => 'Org Admin Calls A',
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    $organizationAId = (int) $pdo->lastInsertId();
    $createOrganization->execute([
        ':tenant_id' => $tenantId,
        ':public_id' => 'org-admin-calls-b-' . $unique,
        ':name' => 'Org Admin Calls B',
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
    $organizationBId = (int) $pdo->lastInsertId();
    videochat_org_admin_call_rights_assert($organizationAId > 0 && $organizationBId > 0, 'expected organization ids');

    $createUser = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dark', :updated_at)
SQL
    );
    $orgAdminUserId = videochat_org_admin_call_rights_create_user($pdo, $createUser, $userRoleId, 'org-admin-' . $unique . '@example.test', 'Org Admin');
    $ownerAUserId = videochat_org_admin_call_rights_create_user($pdo, $createUser, $userRoleId, 'owner-a-' . $unique . '@example.test', 'Owner A');
    $ownerBUserId = videochat_org_admin_call_rights_create_user($pdo, $createUser, $userRoleId, 'owner-b-' . $unique . '@example.test', 'Owner B');
    $participantAUserId = videochat_org_admin_call_rights_create_user($pdo, $createUser, $userRoleId, 'participant-a-' . $unique . '@example.test', 'Participant A');
    $participantBUserId = videochat_org_admin_call_rights_create_user($pdo, $createUser, $userRoleId, 'participant-b-' . $unique . '@example.test', 'Participant B');

    $attachTenant = $pdo->prepare(
        <<<'SQL'
INSERT INTO tenant_memberships(tenant_id, user_id, membership_role, status, permissions_json, default_membership, updated_at)
VALUES(:tenant_id, :user_id, 'member', 'active', '{}', 0, :updated_at)
SQL
    );
    foreach ([$orgAdminUserId, $ownerAUserId, $ownerBUserId, $participantAUserId, $participantBUserId] as $userId) {
        videochat_org_admin_call_rights_attach_tenant($attachTenant, $tenantId, $userId);
    }

    $attachOrganization = $pdo->prepare(
        <<<'SQL'
INSERT INTO organization_memberships(tenant_id, organization_id, user_id, membership_role, status, updated_at)
VALUES(:tenant_id, :organization_id, :user_id, :membership_role, 'active', :updated_at)
SQL
    );
    videochat_org_admin_call_rights_attach_organization($attachOrganization, $tenantId, $organizationAId, $orgAdminUserId, 'admin');
    videochat_org_admin_call_rights_attach_organization($attachOrganization, $tenantId, $organizationAId, $ownerAUserId, 'member');
    videochat_org_admin_call_rights_attach_organization($attachOrganization, $tenantId, $organizationAId, $participantAUserId, 'member');
    videochat_org_admin_call_rights_attach_organization($attachOrganization, $tenantId, $organizationBId, $ownerBUserId, 'member');
    videochat_org_admin_call_rights_attach_organization($attachOrganization, $tenantId, $organizationBId, $participantBUserId, 'member');

    $startsAt = gmdate('c', time() - 300);
    $endsAt = gmdate('c', time() + 3600);
    $ownCreate = videochat_create_call($pdo, $ownerAUserId, [
        'title' => 'Own Organization Call',
        'access_mode' => 'invite_only',
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'internal_participant_user_ids' => [$participantAUserId],
    ], $tenantId);
    videochat_org_admin_call_rights_assert((bool) ($ownCreate['ok'] ?? false), 'own organization call create should succeed');
    $ownCallId = (string) (($ownCreate['call'] ?? [])['id'] ?? '');
    videochat_org_admin_call_rights_assert($ownCallId !== '', 'own organization call id should be non-empty');

    $foreignCreate = videochat_create_call($pdo, $ownerBUserId, [
        'title' => 'Foreign Organization Call',
        'access_mode' => 'invite_only',
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'internal_participant_user_ids' => [$participantBUserId],
    ], $tenantId);
    videochat_org_admin_call_rights_assert((bool) ($foreignCreate['ok'] ?? false), 'foreign organization call create should succeed');
    $foreignCallId = (string) (($foreignCreate['call'] ?? [])['id'] ?? '');
    videochat_org_admin_call_rights_assert($foreignCallId !== '', 'foreign organization call id should be non-empty');

    $participantCount = $pdo->prepare(
        <<<'SQL'
SELECT COUNT(*)
FROM call_participants
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
SQL
    );
    $participantCount->execute([
        ':call_id' => $ownCallId,
        ':user_id' => $orgAdminUserId,
    ]);
    videochat_org_admin_call_rights_assert((int) $participantCount->fetchColumn() === 0, 'org admin should not start on own call guest list');

    videochat_org_admin_call_rights_assert(
        videochat_user_is_organization_admin_for_call($pdo, $ownCallId, $orgAdminUserId, $tenantId),
        'org admin helper should allow own organization call'
    );
    videochat_org_admin_call_rights_assert(
        !videochat_user_is_organization_admin_for_call($pdo, $foreignCallId, $orgAdminUserId, $tenantId),
        'org admin helper should reject foreign organization call'
    );
    $manipulatedForeignCall = [
        'id' => $foreignCallId,
        'tenant_id' => $tenantId,
        'owner_user_id' => $ownerBUserId,
        'organization_id' => $organizationAId,
    ];
    videochat_org_admin_call_rights_assert(
        !videochat_user_is_organization_admin_for_call($pdo, $manipulatedForeignCall, $orgAdminUserId, $tenantId),
        'org admin helper must ignore manipulated organization id'
    );

    $ownFetch = videochat_get_call_for_user($pdo, $ownCallId, $orgAdminUserId, 'user', $tenantId);
    videochat_org_admin_call_rights_assert((bool) ($ownFetch['ok'] ?? false), 'org admin should fetch own organization call');
    videochat_org_admin_call_rights_assert((string) (($ownFetch['call'] ?? [])['id'] ?? '') === $ownCallId, 'own fetch call id mismatch');

    $foreignFetch = videochat_get_call_for_user($pdo, $foreignCallId, $orgAdminUserId, 'user', $tenantId);
    videochat_org_admin_call_rights_assert($foreignFetch['ok'] === false, 'org admin should not fetch foreign organization call');
    videochat_org_admin_call_rights_assert($foreignFetch['reason'] === 'forbidden', 'foreign fetch reason mismatch');

    $ownUpdate = videochat_update_call($pdo, $ownCallId, $orgAdminUserId, 'user', [
        'title' => 'Own Organization Call Managed',
    ], $tenantId);
    videochat_org_admin_call_rights_assert((bool) ($ownUpdate['ok'] ?? false), 'org admin should update own organization call');
    videochat_org_admin_call_rights_assert((string) (($ownUpdate['call'] ?? [])['title'] ?? '') === 'Own Organization Call Managed', 'own update title mismatch');

    $foreignUpdate = videochat_update_call($pdo, $foreignCallId, $orgAdminUserId, 'user', [
        'title' => 'Foreign Organization Call Managed',
    ], $tenantId);
    videochat_org_admin_call_rights_assert($foreignUpdate['ok'] === false, 'org admin should not update foreign organization call');
    videochat_org_admin_call_rights_assert($foreignUpdate['reason'] === 'forbidden', 'foreign update reason mismatch');

    $ownRoleUpdate = videochat_update_call_participant_role(
        $pdo,
        $ownCallId,
        $participantAUserId,
        'moderator',
        $orgAdminUserId,
        'user',
        $tenantId
    );
    videochat_org_admin_call_rights_assert((bool) ($ownRoleUpdate['ok'] ?? false), 'org admin should manage own organization call participants');

    $foreignRoleUpdate = videochat_update_call_participant_role(
        $pdo,
        $foreignCallId,
        $participantBUserId,
        'moderator',
        $orgAdminUserId,
        'user',
        $tenantId
    );
    videochat_org_admin_call_rights_assert($foreignRoleUpdate['ok'] === false, 'org admin should not manage foreign organization call participants');
    videochat_org_admin_call_rights_assert($foreignRoleUpdate['reason'] === 'forbidden', 'foreign participant role reason mismatch');

    $ownRealtimeContext = videochat_realtime_call_role_context_for_room_user($pdo, $ownCallId, $orgAdminUserId, $ownCallId, 'user', $tenantId);
    videochat_org_admin_call_rights_assert((string) ($ownRealtimeContext['call_id'] ?? '') === $ownCallId, 'own realtime context call id mismatch');
    videochat_org_admin_call_rights_assert((string) ($ownRealtimeContext['invite_state'] ?? '') === 'allowed', 'own realtime context should allow join');
    videochat_org_admin_call_rights_assert((string) ($ownRealtimeContext['effective_call_role'] ?? '') === 'moderator', 'own realtime context should elevate org admin to moderator');
    videochat_org_admin_call_rights_assert((bool) ($ownRealtimeContext['can_moderate'] ?? false), 'own realtime context should allow lobby moderation');
    videochat_org_admin_call_rights_assert(!(bool) ($ownRealtimeContext['can_manage_owner'] ?? false), 'org admin should not receive owner-transfer rights');

    $foreignRealtimeContext = videochat_realtime_call_role_context_for_room_user($pdo, $foreignCallId, $orgAdminUserId, $foreignCallId, 'user', $tenantId);
    videochat_org_admin_call_rights_assert((string) ($foreignRealtimeContext['call_id'] ?? '') === '', 'foreign realtime context should not bind call');
    videochat_org_admin_call_rights_assert(!(bool) ($foreignRealtimeContext['can_moderate'] ?? false), 'foreign realtime context should not allow moderation');

    $participantCount->execute([
        ':call_id' => $ownCallId,
        ':user_id' => $orgAdminUserId,
    ]);
    videochat_org_admin_call_rights_assert((int) $participantCount->fetchColumn() === 0, 'org admin access should not require guest-list insertion');

    @unlink($databasePath);
    fwrite(STDOUT, "[org-admin-call-rights-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[org-admin-call-rights-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
