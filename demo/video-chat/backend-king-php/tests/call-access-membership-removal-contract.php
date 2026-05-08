<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../domain/realtime/realtime_presence.php';
require_once __DIR__ . '/../http/module_realtime.php';

function videochat_call_access_membership_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-membership-removal-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-membership-removal-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-access-membership-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $adminUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $invitedUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();
    videochat_call_access_membership_assert($tenantId > 0, 'expected default tenant');
    videochat_call_access_membership_assert($adminUserId > 0, 'expected seeded admin user');
    videochat_call_access_membership_assert($invitedUserId > 0, 'expected seeded invited user');
    videochat_call_access_membership_assert(
        videochat_tenant_user_is_member($pdo, $invitedUserId, $tenantId),
        'seeded invited user should start as an active tenant member'
    );

    $createCall = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Call Access Membership Removal',
        'starts_at' => '2026-09-04T09:00:00Z',
        'ends_at' => '2026-09-04T10:00:00Z',
        'internal_participant_user_ids' => [$invitedUserId],
        'external_participants' => [],
    ], $tenantId);
    videochat_call_access_membership_assert((bool) ($createCall['ok'] ?? false), 'call should be created');
    $callId = (string) (($createCall['call'] ?? [])['id'] ?? '');
    videochat_call_access_membership_assert($callId !== '', 'call id should be present');

    $access = videochat_create_call_access_link_for_user($pdo, $callId, $adminUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $invitedUserId,
    ], $tenantId);
    videochat_call_access_membership_assert((bool) ($access['ok'] ?? false), 'personal access link should be created');
    $accessId = (string) (($access['access_link'] ?? [])['id'] ?? '');
    videochat_call_access_membership_assert($accessId !== '', 'personal access id should be present');

    $removedAt = gmdate('c');
    $pdo->prepare('UPDATE group_memberships SET status = \'disabled\', updated_at = :updated_at WHERE tenant_id = :tenant_id AND user_id = :user_id')->execute([
        ':updated_at' => $removedAt,
        ':tenant_id' => $tenantId,
        ':user_id' => $invitedUserId,
    ]);
    $pdo->prepare('UPDATE organization_memberships SET status = \'disabled\', updated_at = :updated_at WHERE tenant_id = :tenant_id AND user_id = :user_id')->execute([
        ':updated_at' => $removedAt,
        ':tenant_id' => $tenantId,
        ':user_id' => $invitedUserId,
    ]);
    $pdo->prepare('UPDATE tenant_memberships SET status = \'disabled\', updated_at = :updated_at WHERE tenant_id = :tenant_id AND user_id = :user_id')->execute([
        ':updated_at' => $removedAt,
        ':tenant_id' => $tenantId,
        ':user_id' => $invitedUserId,
    ]);

    videochat_call_access_membership_assert(
        !videochat_tenant_user_is_member($pdo, $invitedUserId, $tenantId),
        'membership removal: removed invited user must immediately lose tenant membership'
    );
    videochat_call_access_membership_assert(
        videochat_tenant_context_for_user($pdo, $invitedUserId, $tenantId) === null,
        'removed invited user must not retain tenant context through membership lookup'
    );
    videochat_call_access_membership_assert(
        videochat_fetch_active_user_for_call_access($pdo, $invitedUserId, null, $tenantId) === null,
        'default call-access user lookup must still require active tenant membership'
    );

    $publicResolution = videochat_resolve_call_access_public($pdo, $accessId);
    videochat_call_access_membership_assert((bool) ($publicResolution['ok'] ?? false), 'personal link should remain resolvable after membership removal');
    videochat_call_access_membership_assert(
        (int) (($publicResolution['target_user'] ?? [])['id'] ?? 0) === $invitedUserId,
        'call-scoped personal link should still resolve its invited user'
    );

    $sessionId = 'sess_call_access_removed_member';
    $session = videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        static fn (): string => $sessionId,
        ['client_ip' => '127.0.0.1', 'user_agent' => 'call-access-membership-removal-contract']
    );
    videochat_call_access_membership_assert((bool) ($session['ok'] ?? false), 'removed invited user should receive a call-scoped session');
    videochat_call_access_membership_assert((int) (($session['user'] ?? [])['id'] ?? 0) === $invitedUserId, 'session user should match removed invited user');

    $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET invite_state = 'allowed'
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
SQL
    )->execute([
        ':call_id' => $callId,
        ':user_id' => $invitedUserId,
    ]);

    $auth = videochat_authenticate_request(
        $pdo,
        [
            'method' => 'GET',
            'uri' => '/ws?session=' . $sessionId . '&room=' . $callId . '&call_id=' . $callId,
            'headers' => ['Authorization' => 'Bearer ' . $sessionId],
        ],
        'websocket'
    );
    $fallbackTenant = videochat_tenant_context_for_call_access_session($pdo, $invitedUserId, $sessionId);
    videochat_call_access_membership_assert(is_array($fallbackTenant), 'call-scoped fallback tenant context should be resolvable');
    videochat_call_access_membership_assert((int) ($fallbackTenant['membership_id'] ?? -1) === 0, 'call-scoped fallback must not invent membership id');
    videochat_call_access_membership_assert((bool) ($auth['ok'] ?? false), 'call-scoped session should authenticate after membership removal');
    videochat_call_access_membership_assert((int) (($auth['tenant'] ?? [])['id'] ?? 0) === $tenantId, 'call-scoped session should retain call tenant context');
    videochat_call_access_membership_assert((string) (($auth['tenant'] ?? [])['role'] ?? '') === 'member', 'call-scoped fallback should be least-privilege member role');
    videochat_call_access_membership_assert(
        (bool) (((($auth['tenant'] ?? [])['permissions'] ?? [])['tenant_admin'] ?? false)) === false,
        'call-scoped fallback must not restore tenant admin rights'
    );
    videochat_call_access_membership_assert(
        !videochat_tenant_user_is_member($pdo, $invitedUserId, $tenantId),
        'call-scoped authentication must not recreate tenant membership'
    );

    $openDatabase = static fn (): PDO => videochat_open_sqlite_pdo($databasePath);
    $roomResolution = videochat_realtime_resolve_connection_rooms($auth, $callId, $openDatabase, $callId);
    videochat_call_access_membership_assert(
        (string) ($roomResolution['initial_room_id'] ?? '') === $callId,
        'admitted call-scoped invited user should enter the bound call room'
    );
    videochat_call_access_membership_assert(
        (string) ($roomResolution['pending_room_id'] ?? '') === '',
        'admitted call-scoped invited user should not remain in lobby'
    );

    @unlink($databasePath);
    fwrite(STDOUT, "[call-access-membership-removal-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-membership-removal-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
