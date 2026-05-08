<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';

function videochat_owner_transfer_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-owner-transfer-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_owner_transfer_contract_source(string $relativePath): string
{
    $path = dirname(__DIR__) . '/' . ltrim($relativePath, '/');
    $source = is_file($path) ? file_get_contents($path) : false;
    videochat_owner_transfer_contract_assert(is_string($source), "source file missing: {$relativePath}");

    return $source;
}

function videochat_owner_transfer_contract_static_assertions(): void
{
    $managementSource = videochat_owner_transfer_contract_source('domain/calls/call_management.php');
    $querySource = videochat_owner_transfer_contract_source('domain/calls/call_management_query.php');
    $transferSource = videochat_owner_transfer_contract_source('domain/calls/call_management_owner_transfer.php');

    videochat_owner_transfer_contract_assert(
        str_contains($managementSource, "require_once __DIR__ . '/call_management_owner_transfer.php';"),
        'call management must load focused owner-transfer helper'
    );
    videochat_owner_transfer_contract_assert(
        !str_contains($querySource, 'function videochat_update_call_participant_role('),
        'call query module must not own participant role mutation'
    );
    videochat_owner_transfer_contract_assert(
        str_contains($transferSource, 'function videochat_call_owner_transfer_target_boundary_check'),
        'owner transfer must have an explicit target boundary check'
    );
    videochat_owner_transfer_contract_assert(
        str_contains($transferSource, 'forbidden_tenant_boundary'),
        'owner transfer must reject cross-tenant targets'
    );
    videochat_owner_transfer_contract_assert(
        str_contains($transferSource, 'forbidden_organization_boundary'),
        'owner transfer must reject cross-organization targets'
    );
    videochat_owner_transfer_contract_assert(
        preg_match("/user_id <> :target_user_id[\\s\\S]*call_role = 'owner'/", $transferSource) === 1,
        'owner transfer must demote every other current owner row'
    );
    videochat_owner_transfer_contract_assert(
        str_contains($transferSource, 'videochat_call_owner_transfer_current_owner_count')
        && str_contains($transferSource, "owner_transfer_invariant_failed"),
        'owner transfer must enforce exactly one owner inside the transaction'
    );
    videochat_owner_transfer_contract_assert(
        str_contains($transferSource, '$resultTenantId = $isSystemAdmin'),
        'system-admin transfer must refetch through the real call tenant'
    );
}

function videochat_owner_transfer_contract_role_id(PDO $pdo, string $slug): int
{
    $query = $pdo->prepare('SELECT id FROM roles WHERE slug = :slug LIMIT 1');
    $query->execute([':slug' => $slug]);

    return (int) $query->fetchColumn();
}

function videochat_owner_transfer_contract_create_user(PDO $pdo, string $email, string $displayName): int
{
    $roleId = videochat_owner_transfer_contract_role_id($pdo, 'user');
    videochat_owner_transfer_contract_assert($roleId > 0, 'expected seeded user role');
    $passwordHash = password_hash('owner-transfer-contract', PASSWORD_DEFAULT);
    videochat_owner_transfer_contract_assert(is_string($passwordHash) && $passwordHash !== '', 'password hash failed');

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, date_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dmy_dot', 'dark', :updated_at)
SQL
    );
    $insert->execute([
        ':email' => strtolower($email),
        ':display_name' => $displayName,
        ':password_hash' => $passwordHash,
        ':role_id' => $roleId,
        ':updated_at' => gmdate('c'),
    ]);

    $userId = (int) $pdo->lastInsertId();
    videochat_owner_transfer_contract_assert($userId > 0, 'created user should have id');
    return $userId;
}

function videochat_owner_transfer_contract_create_tenant(PDO $pdo, string $slug): int
{
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO tenants(public_id, slug, label, status, created_at, updated_at)
VALUES(:public_id, :slug, :label, 'active', :created_at, :updated_at)
SQL
    );
    $insert->execute([
        ':public_id' => videochat_generate_call_id(),
        ':slug' => $slug,
        ':label' => ucwords(str_replace('-', ' ', $slug)),
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);

    $tenantId = (int) $pdo->lastInsertId();
    videochat_owner_transfer_contract_assert($tenantId > 0, 'created tenant should have id');
    return $tenantId;
}

function videochat_owner_transfer_contract_create_organization(PDO $pdo, int $tenantId, string $name): int
{
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO organizations(tenant_id, parent_organization_id, public_id, name, status, created_at, updated_at)
VALUES(:tenant_id, NULL, :public_id, :name, 'active', :created_at, :updated_at)
SQL
    );
    $insert->execute([
        ':tenant_id' => $tenantId,
        ':public_id' => videochat_generate_call_id(),
        ':name' => $name,
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);

    $organizationId = (int) $pdo->lastInsertId();
    videochat_owner_transfer_contract_assert($organizationId > 0, 'created organization should have id');
    return $organizationId;
}

function videochat_owner_transfer_contract_attach_tenant(PDO $pdo, int $tenantId, int $userId, string $role = 'member'): void
{
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO tenant_memberships(tenant_id, user_id, membership_role, status, permissions_json, default_membership, created_at, updated_at)
VALUES(:tenant_id, :user_id, :membership_role, 'active', '{}', 0, :created_at, :updated_at)
SQL
    );
    $insert->execute([
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
        ':membership_role' => $role,
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);
}

function videochat_owner_transfer_contract_attach_organization(PDO $pdo, int $tenantId, int $organizationId, int $userId, string $role = 'member'): void
{
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO organization_memberships(tenant_id, organization_id, user_id, membership_role, status, created_at, updated_at)
VALUES(:tenant_id, :organization_id, :user_id, :membership_role, 'active', :created_at, :updated_at)
SQL
    );
    $insert->execute([
        ':tenant_id' => $tenantId,
        ':organization_id' => $organizationId,
        ':user_id' => $userId,
        ':membership_role' => $role,
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);
}

function videochat_owner_transfer_contract_create_call(PDO $pdo, int $tenantId, int $ownerUserId, array $participantUserIds, string $title): array
{
    $created = videochat_create_call($pdo, $ownerUserId, [
        'title' => $title,
        'starts_at' => '2026-10-12T09:00:00Z',
        'ends_at' => '2026-10-12T10:00:00Z',
        'internal_participant_user_ids' => $participantUserIds,
        'external_participants' => [],
    ], $tenantId);
    videochat_owner_transfer_contract_assert((bool) ($created['ok'] ?? false), "{$title} should be created");

    $call = (array) ($created['call'] ?? []);
    videochat_owner_transfer_contract_assert((string) ($call['id'] ?? '') !== '', "{$title} should expose call id");
    videochat_owner_transfer_contract_assert((string) ($call['room_id'] ?? '') !== '', "{$title} should expose room id");

    return $call;
}

function videochat_owner_transfer_contract_insert_internal_participant(PDO $pdo, string $callId, int $userId): void
{
    $identity = videochat_active_user_identity($pdo, $userId);
    videochat_owner_transfer_contract_assert(is_array($identity), 'stale participant user should exist');
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_participants(call_id, user_id, email, display_name, source, call_role, invite_state, joined_at, left_at)
VALUES(:call_id, :user_id, :email, :display_name, 'internal', 'participant', 'invited', NULL, NULL)
SQL
    );
    $insert->execute([
        ':call_id' => $callId,
        ':user_id' => $userId,
        ':email' => (string) ($identity['email'] ?? ''),
        ':display_name' => (string) ($identity['display_name'] ?? ''),
    ]);
}

try {
    videochat_owner_transfer_contract_static_assertions();

    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-owner-transfer-contract] SKIP persistence: pdo_sqlite unavailable\n");
        fwrite(STDOUT, "[call-owner-transfer-contract] PASS\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-owner-transfer-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $tenantId = videochat_owner_transfer_contract_create_tenant($pdo, 'owner-transfer-' . bin2hex(random_bytes(3)));
    $orgAlphaId = videochat_owner_transfer_contract_create_organization($pdo, $tenantId, 'Owner Transfer Alpha');
    $orgBetaId = videochat_owner_transfer_contract_create_organization($pdo, $tenantId, 'Owner Transfer Beta');

    $normalOwnerId = videochat_owner_transfer_contract_create_user($pdo, 'owner-transfer-normal-owner@example.test', 'Normal Owner');
    $normalNewOwnerId = videochat_owner_transfer_contract_create_user($pdo, 'owner-transfer-normal-new@example.test', 'Normal New Owner');
    $normalThirdId = videochat_owner_transfer_contract_create_user($pdo, 'owner-transfer-normal-third@example.test', 'Normal Third');
    foreach ([$normalOwnerId, $normalNewOwnerId, $normalThirdId] as $userId) {
        videochat_owner_transfer_contract_attach_tenant($pdo, $tenantId, $userId);
        videochat_owner_transfer_contract_attach_organization($pdo, $tenantId, $orgAlphaId, $userId);
    }

    $normalCall = videochat_owner_transfer_contract_create_call(
        $pdo,
        $tenantId,
        $normalOwnerId,
        [$normalNewOwnerId, $normalThirdId],
        'Normal Owner Transfer Contract'
    );
    $normalCallId = (string) ($normalCall['id'] ?? '');
    $normalRoomId = (string) ($normalCall['room_id'] ?? '');
    $pdo->prepare("UPDATE call_participants SET call_role = 'owner' WHERE call_id = :call_id AND user_id = :user_id")->execute([
        ':call_id' => $normalCallId,
        ':user_id' => $normalThirdId,
    ]);
    videochat_owner_transfer_contract_assert(videochat_call_owner_transfer_current_owner_count($pdo, $normalCallId) === 2, 'test setup should create duplicate owners');

    $normalTransfer = videochat_update_call_participant_role($pdo, $normalCallId, $normalNewOwnerId, 'owner', $normalOwnerId, 'user', $tenantId);
    videochat_owner_transfer_contract_assert((bool) ($normalTransfer['ok'] ?? false), 'normal owner should transfer ownership');
    videochat_owner_transfer_contract_assert(videochat_call_owner_transfer_current_owner_count($pdo, $normalCallId) === 1, 'normal transfer should leave exactly one owner');
    videochat_owner_transfer_contract_assert((int) (videochat_fetch_call_for_update($pdo, $normalCallId, $tenantId)['owner_user_id'] ?? 0) === $normalNewOwnerId, 'normal transfer should persist new owner');

    $oldNormalContext = videochat_call_role_context_for_room_user($pdo, $normalRoomId, $normalOwnerId);
    videochat_owner_transfer_contract_assert((string) ($oldNormalContext['call_role'] ?? '') === 'participant', 'old normal owner should be demoted');
    videochat_owner_transfer_contract_assert(!(bool) ($oldNormalContext['can_moderate'] ?? true), 'old normal owner should lose call-admin rights');
    videochat_owner_transfer_contract_assert(!(bool) ($oldNormalContext['can_manage_owner'] ?? true), 'old normal owner should lose owner-management rights');

    $newNormalContext = videochat_call_role_context_for_room_user($pdo, $normalRoomId, $normalNewOwnerId);
    videochat_owner_transfer_contract_assert((string) ($newNormalContext['call_role'] ?? '') === 'owner', 'new owner should receive owner role');
    videochat_owner_transfer_contract_assert((bool) ($newNormalContext['can_moderate'] ?? false), 'new owner should receive call-admin rights');
    videochat_owner_transfer_contract_assert((bool) ($newNormalContext['can_manage_owner'] ?? false), 'new owner should receive owner-management rights');

    $formerOwnerAction = videochat_update_call_participant_role($pdo, $normalCallId, $normalThirdId, 'owner', $normalOwnerId, 'user', $tenantId);
    videochat_owner_transfer_contract_assert(!(bool) ($formerOwnerAction['ok'] ?? true), 'former non-admin owner must not transfer owner again');
    videochat_owner_transfer_contract_assert((string) ($formerOwnerAction['reason'] ?? '') === 'forbidden', 'former owner action should be forbidden');

    $nonexistentTransfer = videochat_update_call_participant_role($pdo, $normalCallId, 987654321, 'owner', $normalNewOwnerId, 'user', $tenantId);
    videochat_owner_transfer_contract_assert(!(bool) ($nonexistentTransfer['ok'] ?? true), 'transfer to nonexistent user must fail');
    videochat_owner_transfer_contract_assert((string) ($nonexistentTransfer['reason'] ?? '') === 'validation_failed', 'nonexistent transfer should be validation failure');
    videochat_owner_transfer_contract_assert((string) (($nonexistentTransfer['errors'] ?? [])['target_user_id'] ?? '') === 'active_user_not_found', 'nonexistent transfer error mismatch');

    $orgAdminOwnerId = videochat_owner_transfer_contract_create_user($pdo, 'owner-transfer-org-admin@example.test', 'Org Admin Owner');
    $orgAdminNewOwnerId = videochat_owner_transfer_contract_create_user($pdo, 'owner-transfer-org-new@example.test', 'Org Admin New Owner');
    $orgAdminManagedId = videochat_owner_transfer_contract_create_user($pdo, 'owner-transfer-org-managed@example.test', 'Org Managed');
    foreach ([$orgAdminOwnerId, $orgAdminNewOwnerId, $orgAdminManagedId] as $userId) {
        videochat_owner_transfer_contract_attach_tenant($pdo, $tenantId, $userId);
    }
    videochat_owner_transfer_contract_attach_organization($pdo, $tenantId, $orgAlphaId, $orgAdminOwnerId, 'admin');
    videochat_owner_transfer_contract_attach_organization($pdo, $tenantId, $orgAlphaId, $orgAdminNewOwnerId);
    videochat_owner_transfer_contract_attach_organization($pdo, $tenantId, $orgAlphaId, $orgAdminManagedId);

    $orgAdminCall = videochat_owner_transfer_contract_create_call(
        $pdo,
        $tenantId,
        $orgAdminOwnerId,
        [$orgAdminNewOwnerId, $orgAdminManagedId],
        'Org Admin Owner Transfer Contract'
    );
    $orgAdminCallId = (string) ($orgAdminCall['id'] ?? '');
    $orgAdminRoomId = (string) ($orgAdminCall['room_id'] ?? '');
    $orgAdminTransfer = videochat_update_call_participant_role($pdo, $orgAdminCallId, $orgAdminNewOwnerId, 'owner', $orgAdminOwnerId, 'user', $tenantId);
    videochat_owner_transfer_contract_assert((bool) ($orgAdminTransfer['ok'] ?? false), 'organization admin owner should transfer ownership');

    $oldOrgAdminContext = videochat_call_role_context_for_room_user($pdo, $orgAdminRoomId, $orgAdminOwnerId);
    videochat_owner_transfer_contract_assert((string) ($oldOrgAdminContext['call_role'] ?? '') === 'participant', 'old org admin owner row should be demoted');
    videochat_owner_transfer_contract_assert((string) ($oldOrgAdminContext['effective_call_role'] ?? '') === 'moderator', 'old org admin should keep effective moderator role');
    videochat_owner_transfer_contract_assert((bool) ($oldOrgAdminContext['can_moderate'] ?? false), 'old org admin should retain call-admin rights');
    videochat_owner_transfer_contract_assert(!(bool) ($oldOrgAdminContext['can_manage_owner'] ?? true), 'old org admin should not retain owner-management rights');

    $orgAdminModeration = videochat_update_call_participant_role($pdo, $orgAdminCallId, $orgAdminManagedId, 'moderator', $orgAdminOwnerId, 'user', $tenantId);
    videochat_owner_transfer_contract_assert((bool) ($orgAdminModeration['ok'] ?? false), 'demoted org admin should still perform call-admin role changes');

    $crossOrgOwnerId = videochat_owner_transfer_contract_create_user($pdo, 'owner-transfer-cross-owner@example.test', 'Cross Org Owner');
    $crossOrgTargetId = videochat_owner_transfer_contract_create_user($pdo, 'owner-transfer-cross-target@example.test', 'Cross Org Target');
    foreach ([$crossOrgOwnerId, $crossOrgTargetId] as $userId) {
        videochat_owner_transfer_contract_attach_tenant($pdo, $tenantId, $userId);
    }
    videochat_owner_transfer_contract_attach_organization($pdo, $tenantId, $orgAlphaId, $crossOrgOwnerId);
    videochat_owner_transfer_contract_attach_organization($pdo, $tenantId, $orgBetaId, $crossOrgTargetId);

    $crossOrgCall = videochat_owner_transfer_contract_create_call(
        $pdo,
        $tenantId,
        $crossOrgOwnerId,
        [$crossOrgTargetId],
        'Cross Org Owner Transfer Contract'
    );
    $crossOrgCallId = (string) ($crossOrgCall['id'] ?? '');
    $crossOrgTransfer = videochat_update_call_participant_role($pdo, $crossOrgCallId, $crossOrgTargetId, 'owner', $crossOrgOwnerId, 'user', $tenantId);
    videochat_owner_transfer_contract_assert(!(bool) ($crossOrgTransfer['ok'] ?? true), 'cross-organization owner transfer must fail');
    videochat_owner_transfer_contract_assert((string) ($crossOrgTransfer['reason'] ?? '') === 'forbidden', 'cross-organization transfer should be forbidden');
    videochat_owner_transfer_contract_assert((string) (($crossOrgTransfer['errors'] ?? [])['target_user_id'] ?? '') === 'forbidden_organization_boundary', 'cross-organization error mismatch');
    videochat_owner_transfer_contract_assert(videochat_call_owner_transfer_current_owner_count($pdo, $crossOrgCallId) === 1, 'failed cross-org transfer must keep one owner');
    videochat_owner_transfer_contract_assert((int) (videochat_fetch_call_for_update($pdo, $crossOrgCallId, $tenantId)['owner_user_id'] ?? 0) === $crossOrgOwnerId, 'failed cross-org transfer must keep old owner');

    $tenantlessTargetId = videochat_owner_transfer_contract_create_user($pdo, 'owner-transfer-tenantless@example.test', 'Tenantless Target');
    $tenantBoundaryCall = videochat_owner_transfer_contract_create_call(
        $pdo,
        $tenantId,
        $crossOrgOwnerId,
        [],
        'Tenant Boundary Owner Transfer Contract'
    );
    $tenantBoundaryCallId = (string) ($tenantBoundaryCall['id'] ?? '');
    videochat_owner_transfer_contract_insert_internal_participant($pdo, $tenantBoundaryCallId, $tenantlessTargetId);
    $tenantBoundaryTransfer = videochat_update_call_participant_role($pdo, $tenantBoundaryCallId, $tenantlessTargetId, 'owner', $crossOrgOwnerId, 'user', $tenantId);
    videochat_owner_transfer_contract_assert(!(bool) ($tenantBoundaryTransfer['ok'] ?? true), 'cross-tenant owner transfer must fail');
    videochat_owner_transfer_contract_assert((string) (($tenantBoundaryTransfer['errors'] ?? [])['target_user_id'] ?? '') === 'forbidden_tenant_boundary', 'cross-tenant error mismatch');

    @unlink($databasePath);
    fwrite(STDOUT, "[call-owner-transfer-contract] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[call-owner-transfer-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
