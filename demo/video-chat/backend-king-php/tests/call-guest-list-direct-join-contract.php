<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth_rbac.php';
require_once __DIR__ . '/../domain/calls/call_management.php';

function videochat_call_guest_list_direct_join_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-guest-list-direct-join-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_call_guest_list_direct_join_create_tenant(PDO $pdo, string $slug, string $label): int
{
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO tenants(public_id, slug, label, status, created_at, updated_at)
VALUES(:public_id, :slug, :label, 'active', :created_at, :updated_at)
SQL
    );
    $insert->execute([
        ':public_id' => 'tenant-' . bin2hex(random_bytes(8)),
        ':slug' => $slug,
        ':label' => $label,
        ':created_at' => gmdate('c'),
        ':updated_at' => gmdate('c'),
    ]);

    return (int) $pdo->lastInsertId();
}

function videochat_call_guest_list_direct_join_attach_user(PDO $pdo, int $tenantId, int $userId, string $role): void
{
    $insert = $pdo->prepare(
        <<<'SQL'
INSERT INTO tenant_memberships(tenant_id, user_id, membership_role, permissions_json, status, default_membership, created_at, updated_at)
VALUES(:tenant_id, :user_id, :membership_role, '{}', 'active', 0, :created_at, :updated_at)
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

    $tenantAId = videochat_call_guest_list_direct_join_create_tenant($pdo, 'guest-list-direct-a', 'Guest List Direct A');
    $tenantBId = videochat_call_guest_list_direct_join_create_tenant($pdo, 'guest-list-direct-b', 'Guest List Direct B');
    videochat_call_guest_list_direct_join_assert($tenantAId > 0 && $tenantBId > 0 && $tenantAId !== $tenantBId, 'expected isolated tenant ids');
    videochat_call_guest_list_direct_join_attach_user($pdo, $tenantAId, $adminUserId, 'owner');
    videochat_call_guest_list_direct_join_attach_user($pdo, $tenantAId, $guestListUserId, 'member');

    $roleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'user' LIMIT 1")->fetchColumn();
    videochat_call_guest_list_direct_join_assert($roleId > 0, 'expected user role');
    $insertUser = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status)
VALUES(:email, :display_name, NULL, :role_id, 'active')
SQL
    );
    $insertUser->execute([
        ':email' => 'not-on-guest-list@intelligent-intern.com',
        ':display_name' => 'Not On Guest List',
        ':role_id' => $roleId,
    ]);
    $notOnGuestListUserId = (int) $pdo->lastInsertId();
    videochat_call_guest_list_direct_join_assert($notOnGuestListUserId > 0, 'expected non-guest-list user');

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

    $declinedCall = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Declined Guest List Direct Join',
        'access_mode' => 'invite_only',
        'starts_at' => '2026-10-03T09:00:00Z',
        'ends_at' => '2026-10-03T10:00:00Z',
        'internal_participant_user_ids' => [$guestListUserId],
        'external_participants' => [],
    ]);
    videochat_call_guest_list_direct_join_assert((bool) ($declinedCall['ok'] ?? false), 'declined guest-list call should be created');
    $declinedCallId = (string) (($declinedCall['call'] ?? [])['id'] ?? '');
    videochat_call_guest_list_direct_join_assert($declinedCallId !== '', 'declined guest-list call id should be present');
    $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET invite_state = 'declined'
WHERE call_id = :call_id
  AND user_id = :user_id
SQL
    )->execute([
        ':call_id' => $declinedCallId,
        ':user_id' => $guestListUserId,
    ]);
    $declinedDecision = videochat_user_can_direct_join_call($pdo, $declinedCallId, $guestListUserId, 'user');
    videochat_call_guest_list_direct_join_assert(!(bool) ($declinedDecision['ok'] ?? true), 'declined guest-list entry must not direct join');
    videochat_call_guest_list_direct_join_assert((string) ($declinedDecision['reason'] ?? '') === 'guest_list_entry_inactive', 'declined denial reason mismatch');
    videochat_call_guest_list_direct_join_assert((string) ((($declinedDecision['guest_list_entry'] ?? [])['invite_state'] ?? '')) === 'declined', 'declined denial should expose normalized inactive entry');

    $externalOnlyCall = videochat_create_call($pdo, $adminUserId, [
        'title' => 'External Participant Is Not Guest List',
        'access_mode' => 'invite_only',
        'starts_at' => '2026-10-04T09:00:00Z',
        'ends_at' => '2026-10-04T10:00:00Z',
        'internal_participant_user_ids' => [],
        'external_participants' => [],
    ]);
    videochat_call_guest_list_direct_join_assert((bool) ($externalOnlyCall['ok'] ?? false), 'external-only call should be created');
    $externalOnlyCallId = (string) (($externalOnlyCall['call'] ?? [])['id'] ?? '');
    videochat_call_guest_list_direct_join_assert($externalOnlyCallId !== '', 'external-only call id should be present');
    $pdo->prepare(
        <<<'SQL'
INSERT INTO call_participants(call_id, user_id, email, display_name, source, call_role, invite_state, joined_at, left_at)
VALUES(:call_id, :user_id, :email, :display_name, 'external', 'participant', 'allowed', NULL, NULL)
SQL
    )->execute([
        ':call_id' => $externalOnlyCallId,
        ':user_id' => $guestListUserId,
        ':email' => 'external-only-guest-list@example.test',
        ':display_name' => 'External Only Guest List',
    ]);
    $externalOnlyDecision = videochat_user_can_direct_join_call($pdo, $externalOnlyCallId, $guestListUserId, 'user');
    videochat_call_guest_list_direct_join_assert(!(bool) ($externalOnlyDecision['ok'] ?? true), 'external participant row must not count as internal guest list');
    videochat_call_guest_list_direct_join_assert((string) ($externalOnlyDecision['reason'] ?? '') === 'not_on_guest_list', 'external participant denial reason mismatch');

    $tenantCall = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Tenant Scoped Guest List Direct Join',
        'access_mode' => 'invite_only',
        'starts_at' => '2026-10-05T09:00:00Z',
        'ends_at' => '2026-10-05T10:00:00Z',
        'internal_participant_user_ids' => [$guestListUserId],
        'external_participants' => [],
    ], $tenantAId);
    videochat_call_guest_list_direct_join_assert((bool) ($tenantCall['ok'] ?? false), 'tenant-scoped guest-list call should be created');
    $tenantCallId = (string) (($tenantCall['call'] ?? [])['id'] ?? '');
    videochat_call_guest_list_direct_join_assert($tenantCallId !== '', 'tenant-scoped call id should be present');
    $tenantAllowedDecision = videochat_user_can_direct_join_call($pdo, $tenantCallId, $guestListUserId, 'user', $tenantAId);
    videochat_call_guest_list_direct_join_assert((bool) ($tenantAllowedDecision['ok'] ?? false), 'guest list should allow direct join in the owning tenant');
    videochat_call_guest_list_direct_join_assert((string) ($tenantAllowedDecision['reason'] ?? '') === 'guest_list', 'tenant guest-list allow reason mismatch');
    $tenantDeniedDecision = videochat_user_can_direct_join_call($pdo, $tenantCallId, $guestListUserId, 'user', $tenantBId);
    videochat_call_guest_list_direct_join_assert(!(bool) ($tenantDeniedDecision['ok'] ?? true), 'guest list must not cross tenant call lookup');
    videochat_call_guest_list_direct_join_assert((string) ($tenantDeniedDecision['reason'] ?? '') === 'not_found', 'cross-tenant guest-list denial reason mismatch');
    videochat_call_guest_list_direct_join_assert(($tenantDeniedDecision['guest_list_entry'] ?? null) === null, 'cross-tenant denial must not expose guest-list entry');

    @unlink($databasePath);
    fwrite(STDOUT, "[call-guest-list-direct-join-contract] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[call-guest-list-direct-join-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
