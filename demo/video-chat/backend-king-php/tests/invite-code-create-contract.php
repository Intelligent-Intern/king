<?php

declare(strict_types=1);

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../call_management.php';
require_once __DIR__ . '/../invite_codes.php';

function videochat_invite_create_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[invite-code-create-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_uuid_v4_like(string $value): bool
{
    return preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
        strtolower($value)
    ) === 1;
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-invite-create-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    $adminUserId = (int) $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE roles.slug = 'admin'
ORDER BY users.id ASC
LIMIT 1
SQL
    )->fetchColumn();
    videochat_invite_create_assert($adminUserId > 0, 'expected seeded admin user');

    $userUserId = (int) $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE roles.slug = 'user'
ORDER BY users.id ASC
LIMIT 1
SQL
    )->fetchColumn();
    videochat_invite_create_assert($userUserId > 0, 'expected seeded user user');

    $createCall = videochat_create_call($pdo, $adminUserId, [
        'room_id' => 'lobby',
        'title' => 'Invite Contract Call',
        'starts_at' => '2026-08-01T09:00:00Z',
        'ends_at' => '2026-08-01T10:00:00Z',
        'internal_participant_user_ids' => [$userUserId],
        'external_participants' => [],
    ]);
    videochat_invite_create_assert((bool) ($createCall['ok'] ?? false), 'call create should succeed for invite contract');
    $callId = (string) (($createCall['call'] ?? [])['id'] ?? '');
    videochat_invite_create_assert($callId !== '', 'call id must be present');

    $fixedNow = 1_781_006_400; // 2026-06-20T00:00:00Z
    $callInvite = videochat_create_invite_code($pdo, $adminUserId, 'admin', [
        'scope' => 'call',
        'call_id' => $callId,
    ], $fixedNow);
    videochat_invite_create_assert((bool) ($callInvite['ok'] ?? false), 'call invite create should succeed');
    $callInviteCode = (array) ($callInvite['invite_code'] ?? []);

    videochat_invite_create_assert(videochat_uuid_v4_like((string) ($callInviteCode['id'] ?? '')), 'invite id should be uuid-v4');
    videochat_invite_create_assert(videochat_uuid_v4_like((string) ($callInviteCode['code'] ?? '')), 'invite code should be uuid-v4');
    videochat_invite_create_assert((string) ($callInviteCode['scope'] ?? '') === 'call', 'call invite scope mismatch');
    videochat_invite_create_assert((string) ($callInviteCode['call_id'] ?? '') === $callId, 'call invite call_id mismatch');
    videochat_invite_create_assert(($callInviteCode['room_id'] ?? null) === null, 'call invite room_id must be null');
    videochat_invite_create_assert((int) ($callInviteCode['issued_by_user_id'] ?? 0) === $adminUserId, 'issued_by_user_id mismatch');
    videochat_invite_create_assert((int) ($callInviteCode['max_redemptions'] ?? 0) === 1, 'max_redemptions mismatch');
    videochat_invite_create_assert((int) ($callInviteCode['redemption_count'] ?? -1) === 0, 'redemption_count mismatch');

    $expectedCallExpiresAt = gmdate('c', $fixedNow + videochat_invite_scope_ttl_seconds('call'));
    videochat_invite_create_assert(
        (string) ($callInviteCode['expires_at'] ?? '') === $expectedCallExpiresAt,
        'call invite expires_at must follow deterministic call-scope ttl'
    );
    videochat_invite_create_assert(
        (int) ($callInviteCode['expires_in_seconds'] ?? 0) === videochat_invite_scope_ttl_seconds('call'),
        'call invite expires_in_seconds mismatch'
    );

    $secondCallInvite = videochat_create_invite_code($pdo, $adminUserId, 'admin', [
        'scope' => 'call',
        'call_id' => $callId,
    ], $fixedNow + 60);
    videochat_invite_create_assert((bool) ($secondCallInvite['ok'] ?? false), 'second call invite should succeed');
    videochat_invite_create_assert(
        (string) (($secondCallInvite['invite_code'] ?? [])['code'] ?? '') !== (string) ($callInviteCode['code'] ?? ''),
        'second invite code must be unique'
    );

    $forbiddenInvite = videochat_create_invite_code($pdo, $userUserId, 'user', [
        'scope' => 'call',
        'call_id' => $callId,
    ], $fixedNow + 120);
    videochat_invite_create_assert((bool) ($forbiddenInvite['ok'] ?? true) === false, 'non-owner user call invite should fail');
    videochat_invite_create_assert((string) ($forbiddenInvite['reason'] ?? '') === 'forbidden', 'non-owner failure should be forbidden');

    $roomInvite = videochat_create_invite_code($pdo, $userUserId, 'user', [
        'scope' => 'room',
        'room_id' => 'lobby',
    ], $fixedNow + 180);
    videochat_invite_create_assert((bool) ($roomInvite['ok'] ?? false), 'room invite create should succeed');
    $roomInviteCode = (array) ($roomInvite['invite_code'] ?? []);
    videochat_invite_create_assert((string) ($roomInviteCode['scope'] ?? '') === 'room', 'room invite scope mismatch');
    videochat_invite_create_assert((string) ($roomInviteCode['room_id'] ?? '') === 'lobby', 'room invite room_id mismatch');
    videochat_invite_create_assert(($roomInviteCode['call_id'] ?? null) === null, 'room invite call_id must be null');

    $expectedRoomExpiresAt = gmdate('c', ($fixedNow + 180) + videochat_invite_scope_ttl_seconds('room'));
    videochat_invite_create_assert(
        (string) ($roomInviteCode['expires_at'] ?? '') === $expectedRoomExpiresAt,
        'room invite expires_at must follow deterministic room-scope ttl'
    );

    $invalidExpiryOverride = videochat_create_invite_code($pdo, $adminUserId, 'admin', [
        'scope' => 'room',
        'room_id' => 'lobby',
        'expires_in_seconds' => 10,
    ], $fixedNow + 240);
    videochat_invite_create_assert((bool) ($invalidExpiryOverride['ok'] ?? true) === false, 'expiry override should be rejected');
    videochat_invite_create_assert(
        (string) (($invalidExpiryOverride['errors'] ?? [])['expires_in_seconds'] ?? '') === 'server_managed_expiry_policy',
        'expiry override error mismatch'
    );

    $missingRoom = videochat_create_invite_code($pdo, $adminUserId, 'admin', [
        'scope' => 'room',
        'room_id' => 'unknown-room',
    ], $fixedNow + 300);
    videochat_invite_create_assert((bool) ($missingRoom['ok'] ?? true) === false, 'missing room should fail');
    videochat_invite_create_assert((string) ($missingRoom['reason'] ?? '') === 'not_found', 'missing room reason mismatch');

    $callInviteDbRowQuery = $pdo->prepare(
        'SELECT scope, room_id, call_id, max_redemptions, redemption_count, expires_at FROM invite_codes WHERE code = :code LIMIT 1'
    );
    $callInviteDbRowQuery->execute([':code' => (string) ($callInviteCode['code'] ?? '')]);
    $callInviteDbRow = $callInviteDbRowQuery->fetch();
    videochat_invite_create_assert(is_array($callInviteDbRow), 'call invite row must exist');
    videochat_invite_create_assert((string) ($callInviteDbRow['scope'] ?? '') === 'call', 'call invite row scope mismatch');
    videochat_invite_create_assert(($callInviteDbRow['room_id'] ?? null) === null, 'call invite row room_id must be null');
    videochat_invite_create_assert((string) ($callInviteDbRow['call_id'] ?? '') === $callId, 'call invite row call_id mismatch');
    videochat_invite_create_assert((int) ($callInviteDbRow['max_redemptions'] ?? 0) === 1, 'call invite row max_redemptions mismatch');
    videochat_invite_create_assert((int) ($callInviteDbRow['redemption_count'] ?? -1) === 0, 'call invite row redemption_count mismatch');
    videochat_invite_create_assert(
        (string) ($callInviteDbRow['expires_at'] ?? '') === $expectedCallExpiresAt,
        'call invite row expires_at mismatch'
    );

    $totalInvites = (int) $pdo->query('SELECT COUNT(*) FROM invite_codes')->fetchColumn();
    videochat_invite_create_assert($totalInvites === 3, 'expected three persisted invites (2 call + 1 room)');

    @unlink($databasePath);
    fwrite(STDOUT, "[invite-code-create-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[invite-code-create-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
