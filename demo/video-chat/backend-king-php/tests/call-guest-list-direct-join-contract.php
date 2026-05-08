<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/calls/call_management.php';

function videochat_call_guest_list_direct_join_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-guest-list-direct-join-contract] FAIL: {$message}\n");
    exit(1);
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

    @unlink($databasePath);
    fwrite(STDOUT, "[call-guest-list-direct-join-contract] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[call-guest-list-direct-join-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
