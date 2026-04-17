<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/invite_codes.php';

function videochat_invite_redeem_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[invite-code-redeem-contract] FAIL: {$message}\n");
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
    $databasePath = sys_get_temp_dir() . '/videochat-invite-redeem-' . bin2hex(random_bytes(6)) . '.sqlite';
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
    videochat_invite_redeem_assert($adminUserId > 0, 'expected seeded admin user');

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
    videochat_invite_redeem_assert($userUserId > 0, 'expected seeded user user');

    $createdCall = videochat_create_call($pdo, $adminUserId, [
        'room_id' => 'lobby',
        'title' => 'Redeem Contract Call',
        'starts_at' => '2026-08-01T09:00:00Z',
        'ends_at' => '2026-08-01T10:00:00Z',
        'internal_participant_user_ids' => [$userUserId],
        'external_participants' => [],
    ]);
    videochat_invite_redeem_assert((bool) ($createdCall['ok'] ?? false), 'call create should succeed for redeem contract');
    $callId = (string) (($createdCall['call'] ?? [])['id'] ?? '');
    videochat_invite_redeem_assert($callId !== '', 'call id must be present');

    $fixedNow = 1_781_006_400; // 2026-06-20T00:00:00Z
    $callInvite = videochat_create_invite_code($pdo, $adminUserId, 'admin', [
        'scope' => 'call',
        'call_id' => $callId,
    ], $fixedNow);
    videochat_invite_redeem_assert((bool) ($callInvite['ok'] ?? false), 'call invite create should succeed');
    $callInviteCode = (string) (($callInvite['invite_code'] ?? [])['code'] ?? '');
    videochat_invite_redeem_assert(videochat_uuid_v4_like($callInviteCode), 'call invite code must be uuid-v4');

    $redeemCallNow = $fixedNow + 120;
    $redeemCallResult = videochat_redeem_invite_code($pdo, $userUserId, 'user', [
        'code' => strtoupper($callInviteCode),
    ], $redeemCallNow);
    videochat_invite_redeem_assert((bool) ($redeemCallResult['ok'] ?? false), 'call invite redeem should succeed');
    videochat_invite_redeem_assert((string) ($redeemCallResult['reason'] ?? '') === 'redeemed', 'call invite redeem reason mismatch');

    $callRedemption = (array) ($redeemCallResult['redemption'] ?? []);
    $callInvitePayload = (array) ($callRedemption['invite_code'] ?? []);
    $callJoinContext = (array) ($callRedemption['join_context'] ?? []);
    $callJoinCall = (array) ($callJoinContext['call'] ?? []);
    $callJoinRequestUser = (array) ($callJoinContext['request_user'] ?? []);

    videochat_invite_redeem_assert((string) ($callInvitePayload['code'] ?? '') === strtolower($callInviteCode), 'redeemed call invite code mismatch');
    videochat_invite_redeem_assert((string) ($callInvitePayload['scope'] ?? '') === 'call', 'redeemed call scope mismatch');
    videochat_invite_redeem_assert((int) ($callInvitePayload['redemption_count'] ?? -1) === 1, 'call redemption_count should be 1');
    videochat_invite_redeem_assert((int) ($callInvitePayload['remaining_redemptions'] ?? -1) === 0, 'call remaining_redemptions should be 0');
    videochat_invite_redeem_assert((int) ($callInvitePayload['redeemed_by_user_id'] ?? 0) === $userUserId, 'call redeemed_by_user_id mismatch');
    videochat_invite_redeem_assert(
        (string) ($callRedemption['redeemed_at'] ?? '') === gmdate('c', $redeemCallNow),
        'call redeemed_at mismatch'
    );
    videochat_invite_redeem_assert((string) ($callJoinContext['scope'] ?? '') === 'call', 'call join context scope mismatch');
    videochat_invite_redeem_assert((string) ($callJoinCall['id'] ?? '') === $callId, 'call join context call id mismatch');
    videochat_invite_redeem_assert((int) ($callJoinRequestUser['user_id'] ?? 0) === $userUserId, 'call join request_user id mismatch');
    videochat_invite_redeem_assert((string) ($callJoinRequestUser['role'] ?? '') === 'user', 'call join request_user role mismatch');

    $callInviteRow = $pdo->prepare(
        'SELECT redemption_count, redeemed_by_user_id FROM invite_codes WHERE lower(code) = :code LIMIT 1'
    );
    $callInviteRow->execute([':code' => strtolower($callInviteCode)]);
    $callInviteRowData = $callInviteRow->fetch();
    videochat_invite_redeem_assert(is_array($callInviteRowData), 'redeemed call invite row must exist');
    videochat_invite_redeem_assert((int) ($callInviteRowData['redemption_count'] ?? -1) === 1, 'redeemed call invite row redemption_count mismatch');
    videochat_invite_redeem_assert((int) ($callInviteRowData['redeemed_by_user_id'] ?? 0) === $userUserId, 'redeemed call invite row redeemed_by mismatch');

    $redeemCallAgain = videochat_redeem_invite_code($pdo, $adminUserId, 'admin', [
        'code' => $callInviteCode,
    ], $redeemCallNow + 10);
    videochat_invite_redeem_assert((bool) ($redeemCallAgain['ok'] ?? true) === false, 'already redeemed call invite should fail');
    videochat_invite_redeem_assert((string) ($redeemCallAgain['reason'] ?? '') === 'exhausted', 'already redeemed reason mismatch');

    $roomInvite = videochat_create_invite_code($pdo, $adminUserId, 'admin', [
        'scope' => 'room',
        'room_id' => 'lobby',
    ], $fixedNow + 200);
    videochat_invite_redeem_assert((bool) ($roomInvite['ok'] ?? false), 'room invite create should succeed');
    $roomInviteCode = (string) (($roomInvite['invite_code'] ?? [])['code'] ?? '');

    $redeemRoomResult = videochat_redeem_invite_code($pdo, $adminUserId, 'admin', [
        'code' => $roomInviteCode,
    ], $fixedNow + 220);
    videochat_invite_redeem_assert((bool) ($redeemRoomResult['ok'] ?? false), 'room invite redeem should succeed');
    $roomJoinContext = (array) (($redeemRoomResult['redemption'] ?? [])['join_context'] ?? []);
    $roomJoinRoom = (array) ($roomJoinContext['room'] ?? []);
    videochat_invite_redeem_assert((string) ($roomJoinContext['scope'] ?? '') === 'room', 'room join context scope mismatch');
    videochat_invite_redeem_assert((string) ($roomJoinRoom['id'] ?? '') === 'lobby', 'room join context room mismatch');
    videochat_invite_redeem_assert(($roomJoinContext['call'] ?? null) === null, 'room join context call must be null');

    $expiredInvite = videochat_create_invite_code($pdo, $adminUserId, 'admin', [
        'scope' => 'room',
        'room_id' => 'lobby',
    ], $fixedNow + 300);
    videochat_invite_redeem_assert((bool) ($expiredInvite['ok'] ?? false), 'expired room invite setup should succeed');
    $expiredInviteCode = (string) (($expiredInvite['invite_code'] ?? [])['code'] ?? '');

    $redeemExpired = videochat_redeem_invite_code($pdo, $userUserId, 'user', [
        'code' => $expiredInviteCode,
    ], ($fixedNow + 300) + videochat_invite_scope_ttl_seconds('room') + 1);
    videochat_invite_redeem_assert((bool) ($redeemExpired['ok'] ?? true) === false, 'expired invite redeem should fail');
    videochat_invite_redeem_assert((string) ($redeemExpired['reason'] ?? '') === 'expired', 'expired invite reason mismatch');

    $cancelledCall = videochat_create_call($pdo, $adminUserId, [
        'room_id' => 'lobby',
        'title' => 'Cancelled Invite Contract Call',
        'starts_at' => '2026-08-02T09:00:00Z',
        'ends_at' => '2026-08-02T10:00:00Z',
    ]);
    videochat_invite_redeem_assert((bool) ($cancelledCall['ok'] ?? false), 'cancelled call setup should succeed');
    $cancelledCallId = (string) (($cancelledCall['call'] ?? [])['id'] ?? '');

    $cancelledCallInvite = videochat_create_invite_code($pdo, $adminUserId, 'admin', [
        'scope' => 'call',
        'call_id' => $cancelledCallId,
    ], $fixedNow + 400);
    videochat_invite_redeem_assert((bool) ($cancelledCallInvite['ok'] ?? false), 'cancelled call invite setup should succeed');
    $cancelledCallInviteCode = (string) (($cancelledCallInvite['invite_code'] ?? [])['code'] ?? '');

    $markCancelledCall = $pdo->prepare('UPDATE calls SET status = :status WHERE id = :id');
    $markCancelledCall->execute([
        ':status' => 'cancelled',
        ':id' => $cancelledCallId,
    ]);

    $redeemCancelledCall = videochat_redeem_invite_code($pdo, $userUserId, 'user', [
        'code' => $cancelledCallInviteCode,
    ], $fixedNow + 420);
    videochat_invite_redeem_assert((bool) ($redeemCancelledCall['ok'] ?? true) === false, 'cancelled call invite redeem should fail');
    videochat_invite_redeem_assert((string) ($redeemCancelledCall['reason'] ?? '') === 'conflict', 'cancelled call invite reason mismatch');
    videochat_invite_redeem_assert(
        (string) (($redeemCancelledCall['errors'] ?? [])['call_id'] ?? '') === 'call_not_joinable_from_status',
        'cancelled call invite field mismatch'
    );

    $invalidCodeResult = videochat_redeem_invite_code($pdo, $adminUserId, 'admin', [
        'code' => 'not-a-uuid',
    ], $fixedNow + 500);
    videochat_invite_redeem_assert((bool) ($invalidCodeResult['ok'] ?? true) === false, 'invalid code redeem should fail');
    videochat_invite_redeem_assert((string) ($invalidCodeResult['reason'] ?? '') === 'validation_failed', 'invalid code reason mismatch');
    videochat_invite_redeem_assert(
        (string) (($invalidCodeResult['errors'] ?? [])['code'] ?? '') === 'invalid_code_format',
        'invalid code validation field mismatch'
    );

    $unknownCodeResult = videochat_redeem_invite_code($pdo, $adminUserId, 'admin', [
        'code' => videochat_generate_uuid_v4(),
    ], $fixedNow + 510);
    videochat_invite_redeem_assert((bool) ($unknownCodeResult['ok'] ?? true) === false, 'unknown code redeem should fail');
    videochat_invite_redeem_assert((string) ($unknownCodeResult['reason'] ?? '') === 'not_found', 'unknown code reason mismatch');

    @unlink($databasePath);
    fwrite(STDOUT, "[invite-code-redeem-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[invite-code-redeem-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
