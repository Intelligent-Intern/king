<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/auth.php';
require_once __DIR__ . '/../domain/audit/audit_events.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/calls/call_directory.php';
require_once __DIR__ . '/../domain/calls/call_access.php';
require_once __DIR__ . '/../domain/realtime/realtime_call_context.php';

function videochat_call_update_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-update-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_call_update_event_type_count(array $events, string $eventType): int
{
    $count = 0;
    foreach ($events as $event) {
        if (is_array($event) && (string) ($event['event_type'] ?? '') === $eventType) {
            $count++;
        }
    }

    return $count;
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-update-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-update-' . bin2hex(random_bytes(6)) . '.sqlite';
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
    videochat_call_update_assert($adminUserId > 0, 'expected seeded admin user');

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
    videochat_call_update_assert($userUserId > 0, 'expected seeded user user');

    $userRoleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'user' LIMIT 1")->fetchColumn();
    videochat_call_update_assert($userRoleId > 0, 'expected user role');
    $participantPassword = password_hash('participant123', PASSWORD_DEFAULT);
    videochat_call_update_assert(is_string($participantPassword) && $participantPassword !== '', 'participant password hash failed');
    $createParticipant = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dark', :updated_at)
SQL
    );
    $createParticipant->execute([
        ':email' => 'participant-update-call@intelligent-intern.com',
        ':display_name' => 'Participant Update Call',
        ':password_hash' => $participantPassword,
        ':role_id' => $userRoleId,
        ':updated_at' => gmdate('c'),
    ]);
    $moderatorUserId = (int) $pdo->lastInsertId();
    videochat_call_update_assert($moderatorUserId > 0, 'expected inserted participant user');
    $createParticipant->execute([
        ':email' => 'free-for-all-join@intelligent-intern.com',
        ':display_name' => 'Free For All Join',
        ':password_hash' => $participantPassword,
        ':role_id' => $userRoleId,
        ':updated_at' => gmdate('c'),
    ]);
    $freeForAllJoinUserId = (int) $pdo->lastInsertId();
    videochat_call_update_assert($freeForAllJoinUserId > 0, 'expected inserted free for all join user');

    $created = videochat_create_call($pdo, $adminUserId, [
        'room_id' => 'lobby',
        'title' => 'Before Update',
        'starts_at' => '2026-06-10T09:00:00Z',
        'ends_at' => '2026-06-10T10:00:00Z',
        'internal_participant_user_ids' => [$userUserId],
        'external_participants' => [
            ['email' => 'first-guest@example.com', 'display_name' => 'First Guest'],
        ],
    ]);
    videochat_call_update_assert($created['ok'] === true, 'setup create should succeed');
    $callId = (string) (($created['call'] ?? [])['id'] ?? '');
    videochat_call_update_assert($callId !== '', 'setup call id should be non-empty');
    $originalRoomId = (string) (($created['call'] ?? [])['room_id'] ?? '');
    videochat_call_update_assert($originalRoomId !== '', 'setup call room id should be non-empty');

    $roomMutationUpdate = videochat_update_call($pdo, $callId, $adminUserId, 'admin', [
        'room_id' => 'lobby',
        'title' => 'Room Mutation Attempt',
    ]);
    videochat_call_update_assert($roomMutationUpdate['ok'] === false, 'room_id update should fail');
    videochat_call_update_assert($roomMutationUpdate['reason'] === 'validation_failed', 'room_id update reason mismatch');
    videochat_call_update_assert(
        (string) (($roomMutationUpdate['errors'] ?? [])['room_id'] ?? '') === 'immutable_for_call',
        'room_id update immutable error mismatch'
    );
    $roomMutationRowQuery = $pdo->prepare('SELECT room_id, title FROM calls WHERE id = :id LIMIT 1');
    $roomMutationRowQuery->execute([':id' => $callId]);
    $roomMutationRow = $roomMutationRowQuery->fetch();
    videochat_call_update_assert(is_array($roomMutationRow), 'room mutation call row should exist');
    videochat_call_update_assert(
        (string) ($roomMutationRow['room_id'] ?? '') === $originalRoomId,
        'room_id update must not change the call room'
    );
    videochat_call_update_assert(
        (string) ($roomMutationRow['title'] ?? '') === 'Before Update',
        'room_id update must not partially update title'
    );

    $emptyUpdate = videochat_update_call($pdo, $callId, $adminUserId, 'admin', []);
    videochat_call_update_assert($emptyUpdate['ok'] === false, 'empty update payload should fail');
    videochat_call_update_assert($emptyUpdate['reason'] === 'validation_failed', 'empty update reason mismatch');
    videochat_call_update_assert(
        (string) (($emptyUpdate['errors'] ?? [])['payload'] ?? '') === 'at_least_one_supported_field_required',
        'empty update payload error mismatch'
    );

    $resendRequestedUpdate = videochat_update_call($pdo, $callId, $adminUserId, 'admin', [
        'resend_invites' => true,
        'title' => 'Attempted Resend',
    ]);
    videochat_call_update_assert($resendRequestedUpdate['ok'] === false, 'resend_invites request should fail');
    videochat_call_update_assert($resendRequestedUpdate['reason'] === 'validation_failed', 'resend_invites reason mismatch');
    videochat_call_update_assert(
        (string) (($resendRequestedUpdate['errors'] ?? [])['resend_invites'] ?? '') === 'global_invite_resend_not_supported_use_explicit_action',
        'resend_invites validation error mismatch'
    );

    $forbiddenUpdate = videochat_update_call($pdo, $callId, $userUserId, 'user', [
        'title' => 'User Should Not Edit',
    ]);
    videochat_call_update_assert($forbiddenUpdate['ok'] === false, 'non-owner user update should fail');
    videochat_call_update_assert($forbiddenUpdate['reason'] === 'forbidden', 'non-owner user update reason mismatch');

    $participantUpdate = videochat_update_call($pdo, $callId, $moderatorUserId, 'user', [
        'title' => 'Participant Should Not Edit',
    ]);
    videochat_call_update_assert($participantUpdate['ok'] === false, 'non-owner participant update should fail');
    videochat_call_update_assert($participantUpdate['reason'] === 'forbidden', 'non-owner participant update reason mismatch');

    $personalLinkByUser = videochat_create_call_access_link_for_user($pdo, $callId, $adminUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $userUserId,
    ]);
    videochat_call_update_assert((bool) ($personalLinkByUser['ok'] ?? false), 'setup personal access link by user should succeed');

    $personalLinkByEmail = videochat_create_call_access_link_for_user($pdo, $callId, $adminUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_email' => 'second-guest@example.com',
    ]);
    videochat_call_update_assert((bool) ($personalLinkByEmail['ok'] ?? false), 'setup personal access link by email should succeed');

    $ownerUpdate = videochat_update_call($pdo, $callId, $adminUserId, 'admin', [
        'title' => 'After Update',
        'access_mode' => 'free_for_all',
        'starts_at' => '2026-06-10T11:00:00Z',
        'ends_at' => '2026-06-10T12:00:00Z',
        'internal_participant_user_ids' => [$moderatorUserId],
        'external_participants' => [
            ['email' => 'second-guest@example.com', 'display_name' => 'Second Guest'],
        ],
    ]);
    videochat_call_update_assert($ownerUpdate['ok'] === true, 'owner update should succeed');
    videochat_call_update_assert($ownerUpdate['reason'] === 'updated', 'owner update reason mismatch');
    videochat_call_update_assert(
        (string) (($ownerUpdate['call'] ?? [])['title'] ?? '') === 'After Update',
        'owner update title mismatch'
    );
    videochat_call_update_assert(
        (string) (($ownerUpdate['call'] ?? [])['starts_at'] ?? '') === '2026-06-10T11:00:00+00:00',
        'owner update starts_at mismatch'
    );
    videochat_call_update_assert(
        (string) (($ownerUpdate['call'] ?? [])['ends_at'] ?? '') === '2026-06-10T12:00:00+00:00',
        'owner update ends_at mismatch'
    );
    videochat_call_update_assert(
        (string) (($ownerUpdate['call'] ?? [])['access_mode'] ?? '') === 'free_for_all',
        'owner update access_mode mismatch'
    );
    videochat_call_update_assert(
        (int) ((($ownerUpdate['call'] ?? [])['participants']['totals'] ?? [])['total'] ?? 0) === 3,
        'owner update participant total mismatch'
    );
    videochat_call_update_assert(
        (int) ((($ownerUpdate['call'] ?? [])['participants']['totals'] ?? [])['internal'] ?? 0) === 2,
        'owner update internal participant total mismatch'
    );
    videochat_call_update_assert(
        (int) ((($ownerUpdate['call'] ?? [])['participants']['totals'] ?? [])['external'] ?? 0) === 1,
        'owner update external participant total mismatch'
    );
    videochat_call_update_assert(
        ((($ownerUpdate['invite_dispatch'] ?? [])['global_resend_triggered'] ?? null) === false),
        'owner update must not trigger global invite resend'
    );
    videochat_call_update_assert(
        ((($ownerUpdate['invite_dispatch'] ?? [])['explicit_action_required'] ?? null) === true),
        'owner update should require explicit invite action'
    );
    videochat_call_update_assert(
        ((($ownerUpdate['lifecycle'] ?? [])['applied'] ?? null) === true),
        'rescheduled owner update must apply access lifecycle cleanup'
    );
    videochat_call_update_assert(
        (int) ((($ownerUpdate['lifecycle'] ?? [])['invalidated_link_count'] ?? 0)) >= 2,
        'rescheduled owner update must invalidate existing personal access links'
    );

    $guestListAuditEvents = videochat_audit_fetch_events($pdo, ['call_id' => $callId, 'limit' => 50]);
    videochat_call_update_assert(
        videochat_call_update_event_type_count($guestListAuditEvents, 'guest_list_entry_added') === 4,
        'create and replacement update should audit four guest-list additions'
    );
    videochat_call_update_assert(
        videochat_call_update_event_type_count($guestListAuditEvents, 'guest_list_entry_removed') === 2,
        'replacement update should audit removed guest-list entries'
    );
    videochat_call_update_assert(
        videochat_call_update_event_type_count($guestListAuditEvents, 'guest_list_entry_updated') === 0,
        'replacement update should not report unchanged owner row as guest-list update'
    );
    videochat_call_update_assert(
        videochat_guest_list_audit_event_type('merged', [], []) === 'guest_list_entry_merged',
        'guest-list audit must reserve merged for duplicate active add normalization'
    );
    videochat_call_update_assert(
        videochat_guest_list_audit_event_type('restored', [], []) === 'guest_list_entry_restored',
        'guest-list audit must reserve restored for inactive entry reactivation'
    );
    $encodedGuestListAudit = json_encode($guestListAuditEvents, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    videochat_call_update_assert(is_string($encodedGuestListAudit), 'guest-list audit events should encode');
    foreach ([
        'first-guest@example.com',
        'second-guest@example.com',
        'participant-update-call@intelligent-intern.com',
    ] as $rawAuditText) {
        videochat_call_update_assert(
            !str_contains($encodedGuestListAudit, $rawAuditText),
            'guest-list audit must not leak raw participant email: ' . $rawAuditText
        );
    }

    $markSecondGuestPending = $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET invite_state = 'pending'
WHERE call_id = :call_id
  AND lower(email) = lower(:email)
  AND source = 'external'
SQL
    );
    $markSecondGuestPending->execute([
        ':call_id' => $callId,
        ':email' => 'second-guest@example.com',
    ]);
    videochat_call_update_assert($markSecondGuestPending->rowCount() === 1, 'setup external guest metadata update should affect one row');

    $metadataOnlyGuestListUpdate = videochat_update_call($pdo, $callId, $adminUserId, 'admin', [
        'internal_participant_user_ids' => [$moderatorUserId],
        'external_participants' => [
            ['email' => 'second-guest@example.com', 'display_name' => 'Second Guest'],
        ],
    ]);
    videochat_call_update_assert($metadataOnlyGuestListUpdate['ok'] === true, 'metadata-only guest-list update should succeed');
    $updatedGuestListAuditEvents = videochat_audit_fetch_events($pdo, ['call_id' => $callId, 'limit' => 50]);
    videochat_call_update_assert(
        videochat_call_update_event_type_count($updatedGuestListAuditEvents, 'guest_list_entry_updated') === 1,
        'replacement diff should audit non-permission guest-list metadata as updated'
    );
    videochat_call_update_assert(
        videochat_call_update_event_type_count($updatedGuestListAuditEvents, 'guest_list_entry_merged') === 0,
        'replacement diff must not relabel metadata updates as duplicate merges'
    );
    videochat_call_update_assert(
        videochat_call_update_event_type_count($updatedGuestListAuditEvents, 'guest_list_entry_restored') === 0,
        'replacement diff must not relabel metadata updates as inactive-entry restores'
    );

    $guestListAuditEvents = videochat_audit_fetch_events($pdo, ['call_id' => $callId, 'limit' => 50]);
    videochat_call_update_assert(
        videochat_call_update_event_type_count($guestListAuditEvents, 'guest_list_entry_added') === 4,
        'create and replacement update should audit four guest-list additions'
    );
    videochat_call_update_assert(
        videochat_call_update_event_type_count($guestListAuditEvents, 'guest_list_entry_removed') === 2,
        'replacement update should audit removed guest-list entries'
    );
    videochat_call_update_assert(
        videochat_call_update_event_type_count($guestListAuditEvents, 'guest_list_entry_updated') === 0,
        'replacement update should not report unchanged owner row as guest-list update'
    );
    videochat_call_update_assert(
        videochat_guest_list_audit_event_type('merged', [], []) === 'guest_list_entry_merged',
        'guest-list audit must reserve merged for duplicate active add normalization'
    );
    videochat_call_update_assert(
        videochat_guest_list_audit_event_type('restored', [], []) === 'guest_list_entry_restored',
        'guest-list audit must reserve restored for inactive entry reactivation'
    );
    $encodedGuestListAudit = json_encode($guestListAuditEvents, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    videochat_call_update_assert(is_string($encodedGuestListAudit), 'guest-list audit events should encode');
    foreach ([
        'first-guest@example.com',
        'second-guest@example.com',
        'participant-update-call@intelligent-intern.com',
    ] as $rawAuditText) {
        videochat_call_update_assert(
            !str_contains($encodedGuestListAudit, $rawAuditText),
            'guest-list audit must not leak raw participant email: ' . $rawAuditText
        );
    }

    $markSecondGuestPending = $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET invite_state = 'pending'
WHERE call_id = :call_id
  AND lower(email) = lower(:email)
  AND source = 'external'
SQL
    );
    $markSecondGuestPending->execute([
        ':call_id' => $callId,
        ':email' => 'second-guest@example.com',
    ]);
    videochat_call_update_assert($markSecondGuestPending->rowCount() === 1, 'setup external guest metadata update should affect one row');

    $metadataOnlyGuestListUpdate = videochat_update_call($pdo, $callId, $adminUserId, 'admin', [
        'internal_participant_user_ids' => [$moderatorUserId],
        'external_participants' => [
            ['email' => 'second-guest@example.com', 'display_name' => 'Second Guest'],
        ],
    ]);
    videochat_call_update_assert($metadataOnlyGuestListUpdate['ok'] === true, 'metadata-only guest-list update should succeed');
    $updatedGuestListAuditEvents = videochat_audit_fetch_events($pdo, ['call_id' => $callId, 'limit' => 50]);
    videochat_call_update_assert(
        videochat_call_update_event_type_count($updatedGuestListAuditEvents, 'guest_list_entry_updated') === 1,
        'replacement diff should audit non-permission guest-list metadata as updated'
    );
    videochat_call_update_assert(
        videochat_call_update_event_type_count($updatedGuestListAuditEvents, 'guest_list_entry_merged') === 0,
        'replacement diff must not relabel metadata updates as duplicate merges'
    );
    videochat_call_update_assert(
        videochat_call_update_event_type_count($updatedGuestListAuditEvents, 'guest_list_entry_restored') === 0,
        'replacement diff must not relabel metadata updates as inactive-entry restores'
    );

    $userOwnedCall = videochat_create_call($pdo, $userUserId, [
        'title' => 'User Owned Admin Transfer',
        'starts_at' => '2026-06-10T13:00:00Z',
        'ends_at' => '2026-06-10T14:00:00Z',
        'internal_participant_user_ids' => [$moderatorUserId],
    ]);
    videochat_call_update_assert($userOwnedCall['ok'] === true, 'user-owned call setup should succeed');
    $userOwnedCallId = (string) (($userOwnedCall['call'] ?? [])['id'] ?? '');
    videochat_call_update_assert($userOwnedCallId !== '', 'user-owned call id should be non-empty');
    $adminOwnerTransfer = videochat_update_call_participant_role(
        $pdo,
        $userOwnedCallId,
        $moderatorUserId,
        'owner',
        $adminUserId,
        'admin'
    );
    videochat_call_update_assert($adminOwnerTransfer['ok'] === true, 'admin should transfer owner role on any call');
    videochat_call_update_assert($adminOwnerTransfer['reason'] === 'updated', 'admin owner transfer reason mismatch');
    $transferredOwnerUserId = (int) $pdo->query(
        "SELECT owner_user_id FROM calls WHERE id = " . $pdo->quote($userOwnedCallId) . " LIMIT 1"
    )->fetchColumn();
    videochat_call_update_assert($transferredOwnerUserId === $moderatorUserId, 'admin owner transfer should update calls.owner_user_id');
    $previousOwnerRoleQuery = $pdo->prepare('SELECT call_role FROM call_participants WHERE call_id = :call_id AND user_id = :user_id LIMIT 1');
    $previousOwnerRoleQuery->execute([
        ':call_id' => $userOwnedCallId,
        ':user_id' => $userUserId,
    ]);
    videochat_call_update_assert((string) $previousOwnerRoleQuery->fetchColumn() === 'participant', 'admin owner transfer should demote previous owner participant row');

    $callRowQuery = $pdo->prepare('SELECT title, access_mode, starts_at, ends_at FROM calls WHERE id = :id LIMIT 1');
    $callRowQuery->execute([':id' => $callId]);
    $callRow = $callRowQuery->fetch();
    videochat_call_update_assert(is_array($callRow), 'updated call row should exist');
    videochat_call_update_assert((string) ($callRow['title'] ?? '') === 'After Update', 'updated call title persistence mismatch');
    videochat_call_update_assert((string) ($callRow['access_mode'] ?? '') === 'free_for_all', 'updated call access_mode persistence mismatch');
    videochat_call_update_assert((string) ($callRow['starts_at'] ?? '') === '2026-06-10T11:00:00+00:00', 'updated call starts_at persistence mismatch');
    videochat_call_update_assert((string) ($callRow['ends_at'] ?? '') === '2026-06-10T12:00:00+00:00', 'updated call ends_at persistence mismatch');

    $personalLinkCountQuery = $pdo->prepare(
        <<<'SQL'
SELECT COUNT(*)
FROM call_access_links
WHERE call_id = :call_id
  AND (
      participant_user_id IS NOT NULL
      OR (
          participant_email IS NOT NULL
          AND trim(participant_email) <> ''
      )
  )
SQL
    );
    $personalLinkCountQuery->execute([':call_id' => $callId]);
    $personalLinkCount = (int) $personalLinkCountQuery->fetchColumn();
    videochat_call_update_assert($personalLinkCount === 0, 'rescheduling must invalidate existing personal access links');

    $freeForAllLink = videochat_create_call_access_link_for_user($pdo, $callId, $adminUserId, 'admin', []);
    videochat_call_update_assert((bool) ($freeForAllLink['ok'] ?? false), 'default access link for free_for_all should succeed');
    videochat_call_update_assert(
        videochat_call_access_link_kind(is_array($freeForAllLink['access_link'] ?? null) ? $freeForAllLink['access_link'] : null) === 'open',
        'default access link for free_for_all should be open'
    );
    $freeForAllUserCall = videochat_get_call_for_user($pdo, $callId, $freeForAllJoinUserId, 'user');
    videochat_call_update_assert((bool) ($freeForAllUserCall['ok'] ?? false), 'logged-in users should resolve free_for_all calls before participant row creation');
    $freeForAllRealtimeContext = videochat_realtime_call_role_context_for_room_user(
        $pdo,
        $callId,
        $freeForAllJoinUserId,
        $callId,
        'user'
    );
    videochat_call_update_assert(
        (string) ($freeForAllRealtimeContext['invite_state'] ?? '') === 'allowed',
        'logged-in users should bypass admission for free_for_all calls'
    );

    $moderatorRoleUpdate = videochat_update_call_participant_role(
        $pdo,
        $callId,
        $moderatorUserId,
        'moderator',
        $adminUserId,
        'admin'
    );
    videochat_call_update_assert($moderatorRoleUpdate['ok'] === true, 'admin should assign moderator role');
    $guestListPermissionAuditEvents = videochat_audit_fetch_events($pdo, [
        'call_id' => $callId,
        'event_type' => 'guest_list_permission_changed',
        'limit' => 20,
    ]);
    videochat_call_update_assert(count($guestListPermissionAuditEvents) === 1, 'moderator grant should audit one guest-list permission change');
    $permissionPayload = is_array(($guestListPermissionAuditEvents[0] ?? [])['payload'] ?? null)
        ? $guestListPermissionAuditEvents[0]['payload']
        : [];
    videochat_call_update_assert((string) (($permissionPayload['before'] ?? [])['call_role'] ?? '') === 'participant', 'permission audit before role mismatch');
    videochat_call_update_assert((string) (($permissionPayload['after'] ?? [])['call_role'] ?? '') === 'moderator', 'permission audit after role mismatch');

    $moderatorOpenLink = videochat_create_call_access_link_for_user($pdo, $callId, $moderatorUserId, 'user', [
        'link_kind' => 'open',
    ]);
    videochat_call_update_assert((bool) ($moderatorOpenLink['ok'] ?? false), 'call moderator should create open invite link');

    $moderatorCallUpdate = videochat_update_call($pdo, $callId, $moderatorUserId, 'user', [
        'title' => 'Moderator Updated',
    ]);
    videochat_call_update_assert($moderatorCallUpdate['ok'] === true, 'call moderator should update call settings');
    videochat_call_update_assert(
        (string) (($moderatorCallUpdate['call'] ?? [])['title'] ?? '') === 'Moderator Updated',
        'call moderator update title mismatch'
    );

    $moderatorOwnerTransfer = videochat_update_call_participant_role(
        $pdo,
        $callId,
        $moderatorUserId,
        'owner',
        $moderatorUserId,
        'user'
    );
    videochat_call_update_assert($moderatorOwnerTransfer['ok'] === false, 'call moderator must not transfer ownership');
    videochat_call_update_assert($moderatorOwnerTransfer['reason'] === 'forbidden', 'call moderator owner-transfer reason mismatch');

    $freeForAllPersonalLink = videochat_create_call_access_link_for_user($pdo, $callId, $adminUserId, 'admin', [
        'link_kind' => 'personal',
        'participant_user_id' => $moderatorUserId,
    ]);
    videochat_call_update_assert((bool) ($freeForAllPersonalLink['ok'] ?? false), 'personal link request in free_for_all mode should be coerced to open');
    videochat_call_update_assert(
        videochat_call_access_link_kind(is_array($freeForAllPersonalLink['access_link'] ?? null) ? $freeForAllPersonalLink['access_link'] : null) === 'open',
        'personal link request in free_for_all mode should return an open link'
    );

    $participantRows = $pdo->prepare(
        <<<'SQL'
SELECT email, source
FROM call_participants
WHERE call_id = :call_id
ORDER BY
    CASE source
        WHEN 'internal' THEN 0
        ELSE 1
    END ASC,
    email ASC
SQL
    );
    $participantRows->execute([':call_id' => $callId]);
    $participants = $participantRows->fetchAll();
    videochat_call_update_assert(is_array($participants) && count($participants) === 3, 'updated participant rows count mismatch');
    videochat_call_update_assert(
        (string) ($participants[0]['email'] ?? '') === 'admin@intelligent-intern.com',
        'updated participants should retain owner'
    );
    videochat_call_update_assert(
        (string) ($participants[1]['email'] ?? '') === 'participant-update-call@intelligent-intern.com',
        'updated participants should include new internal participant'
    );
    videochat_call_update_assert(
        (string) ($participants[2]['email'] ?? '') === 'second-guest@example.com',
        'updated participants should include replacement external participant'
    );

    $cancelCall = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Immutable Cancelled',
        'starts_at' => '2026-06-11T09:00:00Z',
        'ends_at' => '2026-06-11T10:00:00Z',
    ]);
    videochat_call_update_assert($cancelCall['ok'] === true, 'cancel setup call create should succeed');
    $cancelCallId = (string) (($cancelCall['call'] ?? [])['id'] ?? '');
    $setCancelled = $pdo->prepare(
        'UPDATE calls SET status = :status, cancelled_at = :cancelled_at, cancel_reason = :cancel_reason WHERE id = :id'
    );
    $setCancelled->execute([
        ':status' => 'cancelled',
        ':cancelled_at' => gmdate('c'),
        ':cancel_reason' => 'cancelled',
        ':id' => $cancelCallId,
    ]);

    $cancelledUpdate = videochat_update_call($pdo, $cancelCallId, $adminUserId, 'admin', [
        'title' => 'Should Not Update Cancelled',
    ]);
    videochat_call_update_assert($cancelledUpdate['ok'] === false, 'cancelled call update should fail');
    videochat_call_update_assert($cancelledUpdate['reason'] === 'validation_failed', 'cancelled call update reason mismatch');
    videochat_call_update_assert(
        (string) (($cancelledUpdate['errors'] ?? [])['status'] ?? '') === 'immutable_for_edit',
        'cancelled call immutable status error mismatch'
    );

    $missingUpdate = videochat_update_call($pdo, 'call_missing_contract', $adminUserId, 'admin', [
        'title' => 'Missing',
    ]);
    videochat_call_update_assert($missingUpdate['ok'] === false, 'missing call update should fail');
    videochat_call_update_assert($missingUpdate['reason'] === 'not_found', 'missing call update reason mismatch');

    @unlink($databasePath);
    fwrite(STDOUT, "[call-update-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[call-update-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
