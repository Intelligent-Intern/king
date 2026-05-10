<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/audit/audit_events.php';
require_once __DIR__ . '/../domain/calls/call_management.php';

function videochat_call_owner_transfer_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-owner-transfer-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_call_owner_transfer_role_id(PDO $pdo, string $role): int
{
    $query = $pdo->prepare('SELECT id FROM roles WHERE slug = :slug LIMIT 1');
    $query->execute([':slug' => $role]);
    return (int) $query->fetchColumn();
}

function videochat_call_owner_transfer_create_user(PDO $pdo, string $email, string $name, string $role = 'user'): int
{
    $roleId = videochat_call_owner_transfer_role_id($pdo, $role);
    videochat_call_owner_transfer_assert($roleId > 0, "expected {$role} role");

    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, date_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dmy_dot', 'dark', :updated_at)
SQL
    );
    $insert->execute([
        ':email' => strtolower($email),
        ':display_name' => $name,
        ':password_hash' => password_hash('owner-transfer-contract', PASSWORD_DEFAULT),
        ':role_id' => $roleId,
        ':updated_at' => gmdate('c'),
    ]);

    return (int) $pdo->lastInsertId();
}

function videochat_call_owner_transfer_create_tenant(PDO $pdo, string $slug, string $label): int
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
        ':label' => $label,
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);

    return (int) $pdo->lastInsertId();
}

function videochat_call_owner_transfer_attach_user(PDO $pdo, int $tenantId, int $userId, string $role, bool $default): void
{
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO tenant_memberships(tenant_id, user_id, membership_role, permissions_json, status, default_membership, created_at, updated_at)
VALUES(:tenant_id, :user_id, :membership_role, '{}', 'active', :default_membership, :created_at, :updated_at)
SQL
    );
    $insert->execute([
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
        ':membership_role' => $role,
        ':default_membership' => $default ? 1 : 0,
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);
}

function videochat_call_owner_transfer_contract_owner_count(PDO $pdo, string $callId): int
{
    $query = $pdo->prepare(
        "SELECT COUNT(*) FROM call_participants WHERE call_id = :call_id AND source = 'internal' AND call_role = 'owner'"
    );
    $query->execute([':call_id' => $callId]);
    return (int) $query->fetchColumn();
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-owner-transfer-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-owner-transfer-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $tenantAId = videochat_call_owner_transfer_create_tenant($pdo, 'owner-transfer-a', 'Owner Transfer A');
    $tenantBId = videochat_call_owner_transfer_create_tenant($pdo, 'owner-transfer-b', 'Owner Transfer B');
    $tenantAAdminId = videochat_call_owner_transfer_create_user($pdo, 'owner-transfer-a-admin@example.test', 'Tenant A Admin');
    $tenantAUserId = videochat_call_owner_transfer_create_user($pdo, 'owner-transfer-a-user@example.test', 'Tenant A User');
    $tenantBOwnerId = videochat_call_owner_transfer_create_user($pdo, 'owner-transfer-b-owner@example.test', 'Tenant B Owner', 'admin');
    $tenantBNextOwnerId = videochat_call_owner_transfer_create_user($pdo, 'owner-transfer-b-next@example.test', 'Tenant B Next Owner');

    videochat_call_owner_transfer_attach_user($pdo, $tenantAId, $tenantAAdminId, 'admin', true);
    videochat_call_owner_transfer_attach_user($pdo, $tenantAId, $tenantAUserId, 'member', true);
    videochat_call_owner_transfer_attach_user($pdo, $tenantBId, $tenantBOwnerId, 'owner', true);
    videochat_call_owner_transfer_attach_user($pdo, $tenantBId, $tenantBNextOwnerId, 'member', true);

    $createCall = videochat_create_call($pdo, $tenantBOwnerId, [
        'title' => 'Owner Transfer Audit Contract',
        'starts_at' => '2026-10-11T09:00:00Z',
        'ends_at' => '2026-10-11T10:00:00Z',
        'internal_participant_user_ids' => [$tenantBNextOwnerId],
        'external_participants' => [],
    ], $tenantBId);
    videochat_call_owner_transfer_assert((bool) ($createCall['ok'] ?? false), 'tenant B call should be created');
    $callId = (string) (($createCall['call'] ?? [])['id'] ?? '');
    videochat_call_owner_transfer_assert($callId !== '', 'tenant B call id should be present');

    $crossOrgTransfer = videochat_update_call_participant_role(
        $pdo,
        $callId,
        $tenantBNextOwnerId,
        'owner',
        $tenantAAdminId,
        'user',
        $tenantAId
    );
    videochat_call_owner_transfer_assert(!(bool) ($crossOrgTransfer['ok'] ?? true), 'cross-org transfer should fail');
    videochat_call_owner_transfer_assert(
        videochat_audit_fetch_events($pdo, ['event_type' => 'call_owner_transferred', 'limit' => 10]) === [],
        'failed cross-org transfer must not write audit rows'
    );

    $ownerTransfer = videochat_update_call_participant_role(
        $pdo,
        $callId,
        $tenantBNextOwnerId,
        'owner',
        $tenantBOwnerId,
        'admin',
        $tenantBId
    );
    videochat_call_owner_transfer_assert((bool) ($ownerTransfer['ok'] ?? false), 'current owner should transfer ownership');
    videochat_call_owner_transfer_assert((string) ($ownerTransfer['reason'] ?? '') === 'updated', 'owner transfer reason mismatch');
    videochat_call_owner_transfer_assert(videochat_call_owner_transfer_contract_owner_count($pdo, $callId) === 1, 'transfer should leave one owner participant row');

    $events = videochat_audit_fetch_events($pdo, [
        'tenant_id' => $tenantBId,
        'call_id' => $callId,
        'event_type' => 'call_owner_transferred',
        'limit' => 10,
    ]);
    videochat_call_owner_transfer_assert(count($events) === 1, 'successful transfer should write exactly one audit row');
    $event = $events[0];
    $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
    videochat_call_owner_transfer_assert((int) ($event['actor_user_id'] ?? 0) === $tenantBOwnerId, 'audit actor should be previous owner');
    videochat_call_owner_transfer_assert((int) ($event['target_user_id'] ?? 0) === $tenantBNextOwnerId, 'audit target should be new owner');
    videochat_call_owner_transfer_assert((int) ($payload['previous_owner_user_id'] ?? 0) === $tenantBOwnerId, 'audit payload should include previous owner id');
    videochat_call_owner_transfer_assert((int) ($payload['new_owner_user_id'] ?? 0) === $tenantBNextOwnerId, 'audit payload should include new owner id');
    videochat_call_owner_transfer_assert((bool) ($payload['old_owner_admin_preserved'] ?? false), 'old owner admin marker should be preserved');
    videochat_call_owner_transfer_assert((bool) ($payload['one_owner_invariant_preserved'] ?? false), 'one-owner invariant marker should be true');

    $encodedEvent = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    videochat_call_owner_transfer_assert(is_string($encodedEvent), 'audit event should encode');
    foreach (['session_id', 'token', 'sdp', 'candidate'] as $forbiddenText) {
        videochat_call_owner_transfer_assert(!str_contains($encodedEvent, $forbiddenText), 'owner-transfer audit payload should stay sanitized');
    }

    @unlink($databasePath);
    fwrite(STDOUT, "[call-owner-transfer-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[call-owner-transfer-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
