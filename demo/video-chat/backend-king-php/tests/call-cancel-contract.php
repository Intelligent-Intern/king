<?php

declare(strict_types=1);

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../call_management.php';
require_once __DIR__ . '/../call_directory.php';

function videochat_call_cancel_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-cancel-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-call-cancel-' . bin2hex(random_bytes(6)) . '.sqlite';
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
    videochat_call_cancel_assert($adminUserId > 0, 'expected seeded admin user');

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
    videochat_call_cancel_assert($userUserId > 0, 'expected seeded user user');

    $moderatorRoleId = (int) $pdo->query("SELECT id FROM roles WHERE slug = 'moderator' LIMIT 1")->fetchColumn();
    videochat_call_cancel_assert($moderatorRoleId > 0, 'expected moderator role');
    $moderatorPassword = password_hash('moderator123', PASSWORD_DEFAULT);
    videochat_call_cancel_assert(is_string($moderatorPassword) && $moderatorPassword !== '', 'moderator password hash failed');
    $createModerator = $pdo->prepare(
        <<<'SQL'
INSERT INTO users(email, display_name, password_hash, role_id, status, time_format, theme, updated_at)
VALUES(:email, :display_name, :password_hash, :role_id, 'active', '24h', 'dark', :updated_at)
SQL
    );
    $createModerator->execute([
        ':email' => 'moderator-cancel-call@intelligent-intern.com',
        ':display_name' => 'Moderator Cancel Call',
        ':password_hash' => $moderatorPassword,
        ':role_id' => $moderatorRoleId,
        ':updated_at' => gmdate('c'),
    ]);
    $moderatorUserId = (int) $pdo->lastInsertId();
    videochat_call_cancel_assert($moderatorUserId > 0, 'expected inserted moderator user');

    $created = videochat_create_call($pdo, $adminUserId, [
        'room_id' => 'lobby',
        'title' => 'Cancel Me',
        'starts_at' => '2026-06-12T09:00:00Z',
        'ends_at' => '2026-06-12T10:00:00Z',
        'internal_participant_user_ids' => [$userUserId],
    ]);
    videochat_call_cancel_assert($created['ok'] === true, 'setup create should succeed');
    $callId = (string) (($created['call'] ?? [])['id'] ?? '');
    videochat_call_cancel_assert($callId !== '', 'setup call id should be non-empty');

    $joinedAt = '2026-06-12T09:15:00Z';
    $markJoined = $pdo->prepare(
        'UPDATE call_participants SET joined_at = :joined_at, left_at = NULL WHERE call_id = :call_id AND user_id = :user_id'
    );
    $markJoined->execute([
        ':joined_at' => $joinedAt,
        ':call_id' => $callId,
        ':user_id' => $userUserId,
    ]);

    $invalidCancel = videochat_cancel_call($pdo, $callId, $adminUserId, 'admin', []);
    videochat_call_cancel_assert($invalidCancel['ok'] === false, 'empty cancel payload should fail');
    videochat_call_cancel_assert($invalidCancel['reason'] === 'validation_failed', 'empty cancel reason mismatch');
    videochat_call_cancel_assert(
        (string) (($invalidCancel['errors'] ?? [])['cancel_reason'] ?? '') === 'required_non_empty_string',
        'empty cancel reason field mismatch'
    );
    videochat_call_cancel_assert(
        (string) (($invalidCancel['errors'] ?? [])['cancel_message'] ?? '') === 'required_non_empty_string',
        'empty cancel message field mismatch'
    );

    $forbiddenCancel = videochat_cancel_call($pdo, $callId, $userUserId, 'user', [
        'cancel_reason' => 'policy',
        'cancel_message' => 'Not allowed',
    ]);
    videochat_call_cancel_assert($forbiddenCancel['ok'] === false, 'non-owner user cancel should fail');
    videochat_call_cancel_assert($forbiddenCancel['reason'] === 'forbidden', 'non-owner cancel reason mismatch');

    $cancelResult = videochat_cancel_call($pdo, $callId, $moderatorUserId, 'moderator', [
        'cancel_reason' => 'scheduler_conflict',
        'cancel_message' => 'Call cancelled due to scheduling conflict.',
    ]);
    videochat_call_cancel_assert($cancelResult['ok'] === true, 'moderator cancel should succeed');
    videochat_call_cancel_assert($cancelResult['reason'] === 'cancelled', 'cancel result reason mismatch');
    videochat_call_cancel_assert(
        (string) (($cancelResult['call'] ?? [])['status'] ?? '') === 'cancelled',
        'cancelled call status mismatch'
    );
    videochat_call_cancel_assert(
        (string) (($cancelResult['call'] ?? [])['cancel_reason'] ?? '') === 'scheduler_conflict',
        'cancel reason payload mismatch'
    );
    videochat_call_cancel_assert(
        (string) (($cancelResult['call'] ?? [])['cancel_message'] ?? '') === 'Call cancelled due to scheduling conflict.',
        'cancel message payload mismatch'
    );
    videochat_call_cancel_assert(
        (($cancelResult['call'] ?? [])['my_participation'] ?? null) === false,
        'cancelled call must not report active participation'
    );

    $cancelledAt = (string) (($cancelResult['call'] ?? [])['cancelled_at'] ?? '');
    videochat_call_cancel_assert($cancelledAt !== '', 'cancelled call timestamp should be set');

    $callRowQuery = $pdo->prepare(
        'SELECT status, cancel_reason, cancel_message, cancelled_at FROM calls WHERE id = :id LIMIT 1'
    );
    $callRowQuery->execute([':id' => $callId]);
    $callRow = $callRowQuery->fetch();
    videochat_call_cancel_assert(is_array($callRow), 'cancelled call row should exist');
    videochat_call_cancel_assert((string) ($callRow['status'] ?? '') === 'cancelled', 'cancelled call row status mismatch');
    videochat_call_cancel_assert(
        (string) ($callRow['cancel_reason'] ?? '') === 'scheduler_conflict',
        'cancelled call row reason mismatch'
    );
    videochat_call_cancel_assert(
        (string) ($callRow['cancel_message'] ?? '') === 'Call cancelled due to scheduling conflict.',
        'cancelled call row message mismatch'
    );

    $participantRows = $pdo->prepare(
        <<<'SQL'
SELECT user_id, email, invite_state, joined_at, left_at
FROM call_participants
WHERE call_id = :call_id
ORDER BY email ASC
SQL
    );
    $participantRows->execute([':call_id' => $callId]);
    $participants = $participantRows->fetchAll();
    videochat_call_cancel_assert(is_array($participants) && count($participants) === 2, 'cancelled participant count mismatch');
    foreach ($participants as $participant) {
        videochat_call_cancel_assert(
            (string) ($participant['invite_state'] ?? '') === 'cancelled',
            'cancelled participant invite state mismatch'
        );
    }
    $userParticipant = null;
    foreach ($participants as $participant) {
        if ((int) ($participant['user_id'] ?? 0) === $userUserId) {
            $userParticipant = $participant;
            break;
        }
    }
    videochat_call_cancel_assert(is_array($userParticipant), 'cancelled user participant row should exist');
    videochat_call_cancel_assert(
        (string) ($userParticipant['joined_at'] ?? '') === $joinedAt,
        'cancelled user participant joined_at should be preserved'
    );
    videochat_call_cancel_assert(
        (string) ($userParticipant['left_at'] ?? '') === $cancelledAt,
        'cancelled user participant left_at should be set to cancellation timestamp'
    );

    $userFilters = videochat_calls_list_filters([
        'scope' => 'my',
        'status' => 'all',
        'page' => '1',
        'page_size' => '10',
    ], 'user');
    videochat_call_cancel_assert($userFilters['ok'] === true, 'valid user list filters should pass');
    $userListing = videochat_list_calls($pdo, $userUserId, $userFilters);
    videochat_call_cancel_assert((int) ($userListing['total'] ?? 0) === 0, 'cancelled call should be excluded from active join scope');

    $repeatedCancel = videochat_cancel_call($pdo, $callId, $adminUserId, 'admin', [
        'cancel_reason' => 'second_attempt',
        'cancel_message' => 'Second cancel attempt',
    ]);
    videochat_call_cancel_assert($repeatedCancel['ok'] === false, 'already cancelled call should fail');
    videochat_call_cancel_assert($repeatedCancel['reason'] === 'validation_failed', 'already cancelled reason mismatch');
    videochat_call_cancel_assert(
        (string) (($repeatedCancel['errors'] ?? [])['status'] ?? '') === 'already_cancelled',
        'already cancelled status error mismatch'
    );

    $endedCall = videochat_create_call($pdo, $adminUserId, [
        'title' => 'Ended Call',
        'starts_at' => '2026-06-13T09:00:00Z',
        'ends_at' => '2026-06-13T10:00:00Z',
    ]);
    videochat_call_cancel_assert($endedCall['ok'] === true, 'ended setup call create should succeed');
    $endedCallId = (string) (($endedCall['call'] ?? [])['id'] ?? '');
    $setEnded = $pdo->prepare('UPDATE calls SET status = :status WHERE id = :id');
    $setEnded->execute([
        ':status' => 'ended',
        ':id' => $endedCallId,
    ]);

    $endedCancel = videochat_cancel_call($pdo, $endedCallId, $adminUserId, 'admin', [
        'cancel_reason' => 'ended_transition',
        'cancel_message' => 'Should not transition from ended to cancelled',
    ]);
    videochat_call_cancel_assert($endedCancel['ok'] === false, 'ended call cancel should fail');
    videochat_call_cancel_assert($endedCancel['reason'] === 'validation_failed', 'ended call cancel reason mismatch');
    videochat_call_cancel_assert(
        (string) (($endedCancel['errors'] ?? [])['status'] ?? '') === 'transition_not_allowed',
        'ended call transition error mismatch'
    );

    @unlink($databasePath);
    fwrite(STDOUT, "[call-cancel-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "[call-cancel-contract] ERROR: " . $error->getMessage() . "\n");
    exit(1);
}
