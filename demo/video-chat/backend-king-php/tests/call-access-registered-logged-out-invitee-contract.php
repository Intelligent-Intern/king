<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../domain/realtime/realtime_call_context.php';

function videochat_registered_logged_out_invitee_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-access-registered-logged-out-invitee-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_registered_logged_out_invitee_guest_count(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM users WHERE lower(email) LIKE 'guest+%@videochat.local'")->fetchColumn();
}

function videochat_registered_logged_out_invitee_audit_count(PDO $pdo, string $eventType): int
{
    $query = $pdo->prepare('SELECT COUNT(*) FROM videochat_audit_events WHERE event_type = :event_type');
    $query->execute([':event_type' => $eventType]);
    return (int) $query->fetchColumn();
}

function videochat_registered_logged_out_invitee_session_user_id(PDO $pdo, string $sessionId): int
{
    $query = $pdo->prepare('SELECT user_id FROM sessions WHERE id = :id LIMIT 1');
    $query->execute([':id' => $sessionId]);
    $userId = $query->fetchColumn();
    return is_numeric($userId) ? (int) $userId : 0;
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-access-registered-logged-out-invitee-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-registered-logged-out-invitee-' . bin2hex(random_bytes(6)) . '.sqlite';
    @unlink($databasePath);
    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $tenantId = (int) $pdo->query("SELECT id FROM tenants WHERE slug = 'default' LIMIT 1")->fetchColumn();
    $hostUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('admin@intelligent-intern.com') LIMIT 1")->fetchColumn();
    $inviteeUserId = (int) $pdo->query("SELECT id FROM users WHERE lower(email) = lower('user@intelligent-intern.com') LIMIT 1")->fetchColumn();
    videochat_registered_logged_out_invitee_assert($tenantId > 0, 'expected default tenant');
    videochat_registered_logged_out_invitee_assert($hostUserId > 0, 'expected seeded host');
    videochat_registered_logged_out_invitee_assert($inviteeUserId > 0, 'expected seeded registered invitee');

    $inviteeBefore = $pdo->query("SELECT email, display_name, password_hash, status FROM users WHERE id = {$inviteeUserId} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    videochat_registered_logged_out_invitee_assert(is_array($inviteeBefore), 'registered invitee profile should exist');

    $createCall = videochat_create_call($pdo, $hostUserId, [
        'title' => 'Registered Logged Out Invitee Proof',
        'starts_at' => '2026-09-10T09:00:00Z',
        'ends_at' => '2026-09-10T10:00:00Z',
        'internal_participant_user_ids' => [$inviteeUserId],
        'external_participants' => [],
    ], $tenantId);
    videochat_registered_logged_out_invitee_assert((bool) ($createCall['ok'] ?? false), 'host should create invite-only call');
    $call = is_array($createCall['call'] ?? null) ? $createCall['call'] : [];
    $callId = (string) ($call['id'] ?? '');
    $roomId = (string) ($call['room_id'] ?? '');
    videochat_registered_logged_out_invitee_assert($callId !== '' && $roomId !== '', 'created call should expose call and room ids');

    $access = videochat_create_call_access_link_for_user($pdo, $callId, $hostUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $inviteeUserId,
    ], $tenantId);
    videochat_registered_logged_out_invitee_assert((bool) ($access['ok'] ?? false), 'host should create personalized registered-user link');
    $accessLink = is_array($access['access_link'] ?? null) ? $access['access_link'] : [];
    $accessId = (string) ($accessLink['id'] ?? '');
    videochat_registered_logged_out_invitee_assert($accessId !== '', 'registered invitee access id should exist');
    videochat_registered_logged_out_invitee_assert((int) ($accessLink['participant_user_id'] ?? 0) === $inviteeUserId, 'personalized link must be server-bound to registered user id');
    videochat_registered_logged_out_invitee_assert((string) ($accessLink['participant_email'] ?? '') === strtolower((string) ($inviteeBefore['email'] ?? '')), 'personalized link should keep registered invitee contact email');

    $guestCountBefore = videochat_registered_logged_out_invitee_guest_count($pdo);
    $guestAuditBefore = videochat_registered_logged_out_invitee_audit_count($pdo, 'temporary_account_created');

    $publicResolve = videochat_resolve_call_access_public($pdo, $accessId);
    videochat_registered_logged_out_invitee_assert((bool) ($publicResolve['ok'] ?? false), 'logged-out registered invitee link should resolve');
    videochat_registered_logged_out_invitee_assert((int) (($publicResolve['target_user'] ?? [])['id'] ?? 0) === $inviteeUserId, 'public resolve should recognize the registered target user');
    videochat_registered_logged_out_invitee_assert((string) (($publicResolve['target_user'] ?? [])['account_type'] ?? '') === 'account', 'public resolve target should be a registered account');
    videochat_registered_logged_out_invitee_assert((bool) (($publicResolve['target_user'] ?? [])['is_guest'] ?? true) === false, 'public resolve target must not be a guest account');

    $issued = videochat_issue_session_for_call_access(
        $pdo,
        $accessId,
        static fn (): string => 'sess_registered_logged_out_invitee',
        ['client_ip' => '127.0.0.1', 'user_agent' => 'registered-logged-out-invitee-contract']
    );
    videochat_registered_logged_out_invitee_assert((bool) ($issued['ok'] ?? false), 'logged-out registered invitee should receive a call-access session');
    videochat_registered_logged_out_invitee_assert((int) (($issued['user'] ?? [])['id'] ?? 0) === $inviteeUserId, 'issued session should bind the registered invitee');
    videochat_registered_logged_out_invitee_assert((string) (($issued['user'] ?? [])['account_type'] ?? '') === 'account', 'issued user should remain a registered account');
    videochat_registered_logged_out_invitee_assert((bool) (($issued['user'] ?? [])['is_guest'] ?? true) === false, 'issued user must not be marked as guest');
    videochat_registered_logged_out_invitee_assert(videochat_registered_logged_out_invitee_session_user_id($pdo, 'sess_registered_logged_out_invitee') === $inviteeUserId, 'stored session should point to registered invitee');
    videochat_registered_logged_out_invitee_assert(videochat_registered_logged_out_invitee_guest_count($pdo) === $guestCountBefore, 'registered logged-out invite must not create a temporary guest account');
    videochat_registered_logged_out_invitee_assert(videochat_registered_logged_out_invitee_audit_count($pdo, 'temporary_account_created') === $guestAuditBefore, 'registered logged-out invite must not audit temporary-account creation');

    $binding = videochat_validate_call_access_session_binding($pdo, 'sess_registered_logged_out_invitee', $inviteeUserId);
    videochat_registered_logged_out_invitee_assert((bool) ($binding['ok'] ?? false), 'registered invitee call-access binding should validate');
    videochat_registered_logged_out_invitee_assert((string) (($binding['binding'] ?? [])['call_id'] ?? '') === $callId, 'call-access binding should stay call-bound');
    videochat_registered_logged_out_invitee_assert((string) (($binding['binding'] ?? [])['room_id'] ?? '') === $roomId, 'call-access binding should stay room-bound');
    videochat_registered_logged_out_invitee_assert((string) (($binding['binding'] ?? [])['link_kind'] ?? '') === 'personal', 'call-access binding should stay personal');

    $decision = videochat_decide_call_access_for_user($pdo, $callId, $inviteeUserId, 'user', $tenantId);
    videochat_registered_logged_out_invitee_assert((bool) ($decision['allowed'] ?? false), 'registered invitee should be allowed for invited call');
    videochat_registered_logged_out_invitee_assert((string) ($decision['source'] ?? '') === 'internal_participant', 'registered invitee access should come from call participant invitation');
    videochat_registered_logged_out_invitee_assert((string) ($decision['scope'] ?? '') === 'call', 'registered invitee access should be call-scoped');
    videochat_registered_logged_out_invitee_assert((string) ($decision['invite_state'] ?? '') === 'invited', 'registered invitee should start in invited state');
    videochat_registered_logged_out_invitee_assert(!(bool) ($decision['can_administer'] ?? true), 'registered invitee should not gain host/admin rights');

    $auth = [
        'ok' => true,
        'token' => 'sess_registered_logged_out_invitee',
        'session' => ['id' => 'sess_registered_logged_out_invitee'],
        'tenant' => ['id' => $tenantId],
        'user' => [
            ...((array) ($issued['user'] ?? [])),
            'id' => $inviteeUserId,
            'role' => 'user',
        ],
    ];
    $roomResolution = videochat_realtime_resolve_connection_rooms($auth, $roomId, static fn (): PDO => $pdo, $callId);
    videochat_registered_logged_out_invitee_assert((bool) ($roomResolution['ok'] ?? false), 'registered invitee realtime room resolution should succeed');
    videochat_registered_logged_out_invitee_assert((string) ($roomResolution['initial_room_id'] ?? '') === videochat_realtime_waiting_room_id(), 'registered invitee should wait in lobby while invite state is invited');
    videochat_registered_logged_out_invitee_assert((string) ($roomResolution['pending_room_id'] ?? '') === $roomId, 'registered invitee lobby route should stay bound to the invited call room');

    $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET invite_state = 'allowed'
WHERE call_id = :call_id
  AND user_id = :user_id
SQL
    )->execute([
        ':call_id' => $callId,
        ':user_id' => $inviteeUserId,
    ]);
    $admittedResolution = videochat_realtime_resolve_connection_rooms($auth, $roomId, static fn (): PDO => $pdo, $callId);
    videochat_registered_logged_out_invitee_assert((bool) ($admittedResolution['ok'] ?? false), 'admitted registered invitee room resolution should succeed');
    videochat_registered_logged_out_invitee_assert((string) ($admittedResolution['initial_room_id'] ?? '') === $roomId, 'admitted registered invitee should enter the call room directly');
    videochat_registered_logged_out_invitee_assert((string) ($admittedResolution['pending_room_id'] ?? '') === '', 'admitted registered invitee should no longer wait in lobby');

    $inviteeAfter = $pdo->query("SELECT email, display_name, password_hash, status FROM users WHERE id = {$inviteeUserId} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    videochat_registered_logged_out_invitee_assert(is_array($inviteeAfter), 'registered invitee profile should still exist after link open');
    foreach (['email', 'display_name', 'password_hash', 'status'] as $field) {
        videochat_registered_logged_out_invitee_assert((string) ($inviteeAfter[$field] ?? '') === (string) ($inviteeBefore[$field] ?? ''), "registered invitee {$field} should not be mutated by link open");
    }

    @unlink($databasePath);
    fwrite(STDOUT, "[call-access-registered-logged-out-invitee-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[call-access-registered-logged-out-invitee-contract] ERROR: ' . $error->getMessage() . "\n");
    fwrite(STDERR, $error->getTraceAsString() . "\n");
    exit(1);
} finally {
    if (isset($databasePath) && is_string($databasePath) && is_file($databasePath)) {
        @unlink($databasePath);
    }
}
