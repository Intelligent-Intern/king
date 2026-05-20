<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/calls/call_management.php';
require_once __DIR__ . '/../domain/realtime/realtime_owner_absence.php';

const VIDEOCHAT_PERMANENT_LIFECYCLE_CALL_ID = '39c5b3ea-855b-40fd-b030-c8af1d512605';

function videochat_permanent_lifecycle_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[call-permanent-lifecycle-contract] FAIL: {$message}\n");
    exit(1);
}

function videochat_permanent_lifecycle_call_row(PDO $pdo): array
{
    $statement = $pdo->prepare('SELECT id, room_id, owner_user_id, status, ends_at, cancelled_at, cancel_reason, cancel_message FROM calls WHERE id = :id LIMIT 1');
    $statement->execute([':id' => VIDEOCHAT_PERMANENT_LIFECYCLE_CALL_ID]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

function videochat_permanent_lifecycle_owner_row(PDO $pdo): array
{
    $statement = $pdo->prepare(
        <<<'SQL'
SELECT invite_state, left_at
FROM call_participants
WHERE call_id = :call_id
  AND user_id = 1
  AND source = 'internal'
LIMIT 1
SQL
    );
    $statement->execute([':call_id' => VIDEOCHAT_PERMANENT_LIFECYCLE_CALL_ID]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
}

function videochat_permanent_lifecycle_status(PDO $pdo): string
{
    return strtolower(trim((string) (videochat_permanent_lifecycle_call_row($pdo)['status'] ?? '')));
}

function videochat_permanent_lifecycle_access_link_expires_at(PDO $pdo, string $accessId): string
{
    $statement = $pdo->prepare('SELECT expires_at FROM call_access_links WHERE id = :id LIMIT 1');
    $statement->execute([':id' => $accessId]);
    return trim((string) ($statement->fetchColumn() ?: ''));
}

try {
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "[call-permanent-lifecycle-contract] SKIP: pdo_sqlite unavailable\n");
        exit(0);
    }

    $databasePath = sys_get_temp_dir() . '/videochat-call-permanent-lifecycle-' . bin2hex(random_bytes(6)) . '.sqlite';
    if (is_file($databasePath)) {
        @unlink($databasePath);
    }

    videochat_bootstrap_sqlite($databasePath);
    $pdo = videochat_open_sqlite_pdo($databasePath);

    videochat_permanent_lifecycle_assert(
        videochat_is_permanent_call(VIDEOCHAT_PERMANENT_LIFECYCLE_CALL_ID),
        'target live call id must be registered as permanent'
    );

    $firstEnsure = videochat_permanent_call_ensure_active($pdo, VIDEOCHAT_PERMANENT_LIFECYCLE_CALL_ID, 'contract_restore');
    videochat_permanent_lifecycle_assert((bool) ($firstEnsure['ok'] ?? false), 'missing permanent call should be restorable');
    videochat_permanent_lifecycle_assert((bool) ($firstEnsure['restored'] ?? false), 'first ensure should restore the permanent call');

    $callRow = videochat_permanent_lifecycle_call_row($pdo);
    videochat_permanent_lifecycle_assert((string) ($callRow['status'] ?? '') === 'active', 'restored permanent call should be active');
    videochat_permanent_lifecycle_assert((string) ($callRow['ends_at'] ?? '') === videochat_permanent_call_guard_ends_at(), 'restored permanent call should use guard end date');
    videochat_permanent_lifecycle_assert((int) ($callRow['owner_user_id'] ?? 0) === 1, 'restored permanent call owner should be primary admin');

    $accessId = 'permanent-call-contract-link';
    $insertAccessLink = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_access_links(id, call_id, participant_user_id, participant_email, invite_code_id, created_by_user_id, created_at, expires_at, last_used_at, consumed_at)
VALUES(:id, :call_id, NULL, NULL, NULL, 1, :created_at, :expires_at, NULL, NULL)
SQL
    );
    $insertAccessLink->execute([
        ':id' => $accessId,
        ':call_id' => VIDEOCHAT_PERMANENT_LIFECYCLE_CALL_ID,
        ':created_at' => gmdate('c', time() - 3600),
        ':expires_at' => '2020-01-01T00:00:00+00:00',
    ]);

    $pdo->prepare(
        <<<'SQL'
UPDATE calls
SET status = 'ended',
    ends_at = '2020-01-01T00:00:00+00:00',
    cancelled_at = '2020-01-01T00:00:00+00:00',
    cancel_reason = 'contract',
    cancel_message = 'contract'
WHERE id = :id
SQL
    )->execute([':id' => VIDEOCHAT_PERMANENT_LIFECYCLE_CALL_ID]);
    $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET invite_state = 'cancelled',
    left_at = '2020-01-01T00:00:00+00:00'
WHERE call_id = :call_id
  AND user_id = 1
SQL
    )->execute([':call_id' => VIDEOCHAT_PERMANENT_LIFECYCLE_CALL_ID]);

    $repair = videochat_permanent_call_ensure_active($pdo, VIDEOCHAT_PERMANENT_LIFECYCLE_CALL_ID, 'contract_repair');
    videochat_permanent_lifecycle_assert((bool) ($repair['ok'] ?? false), 'ensure should repair terminal permanent call');
    videochat_permanent_lifecycle_assert((bool) ($repair['changed'] ?? false), 'repair should report changed state');
    $repairedCall = videochat_permanent_lifecycle_call_row($pdo);
    videochat_permanent_lifecycle_assert((string) ($repairedCall['status'] ?? '') === 'active', 'repair should reactivate permanent call');
    videochat_permanent_lifecycle_assert((string) ($repairedCall['ends_at'] ?? '') === videochat_permanent_call_guard_ends_at(), 'repair should extend permanent call');
    videochat_permanent_lifecycle_assert(trim((string) ($repairedCall['cancelled_at'] ?? '')) === '', 'repair should clear cancelled_at');
    videochat_permanent_lifecycle_assert(trim((string) ($repairedCall['cancel_reason'] ?? '')) === '', 'repair should clear cancel_reason');
    videochat_permanent_lifecycle_assert(trim((string) ($repairedCall['cancel_message'] ?? '')) === '', 'repair should clear cancel_message');
    $ownerRow = videochat_permanent_lifecycle_owner_row($pdo);
    videochat_permanent_lifecycle_assert((string) ($ownerRow['invite_state'] ?? '') === 'allowed', 'repair should keep owner allowed');
    videochat_permanent_lifecycle_assert(trim((string) ($ownerRow['left_at'] ?? '')) === '', 'repair should clear owner left_at');
    videochat_permanent_lifecycle_assert(
        videochat_permanent_lifecycle_access_link_expires_at($pdo, $accessId) === videochat_permanent_call_guard_ends_at(),
        'repair should extend permanent call access links'
    );

    foreach ([
        'end' => videochat_end_call($pdo, VIDEOCHAT_PERMANENT_LIFECYCLE_CALL_ID, 1, 'admin'),
        'delete' => videochat_delete_call($pdo, VIDEOCHAT_PERMANENT_LIFECYCLE_CALL_ID, 1, 'admin'),
        'cancel' => videochat_cancel_call($pdo, VIDEOCHAT_PERMANENT_LIFECYCLE_CALL_ID, 1, 'admin', [
            'cancel_reason' => 'contract',
            'cancel_message' => 'contract',
        ]),
    ] as $operation => $result) {
        videochat_permanent_lifecycle_assert(!(bool) ($result['ok'] ?? true), $operation . ' should be blocked for permanent call');
        videochat_permanent_lifecycle_assert(
            (string) (($result['errors'] ?? [])['call'] ?? '') === 'permanent_call_protected',
            $operation . ' should return permanent_call_protected'
        );
        videochat_permanent_lifecycle_assert(videochat_permanent_lifecycle_status($pdo) === 'active', $operation . ' must not terminalize permanent call');
    }

    $ownerLeave = videochat_leave_call($pdo, VIDEOCHAT_PERMANENT_LIFECYCLE_CALL_ID, 1, 'admin');
    videochat_permanent_lifecycle_assert((bool) ($ownerLeave['ok'] ?? false), 'owner leave should be accepted');
    videochat_permanent_lifecycle_assert((string) ($ownerLeave['reason'] ?? '') === 'owner_left_permanent_call_active', 'owner leave should keep permanent call active');
    videochat_permanent_lifecycle_assert(videochat_permanent_lifecycle_status($pdo) === 'active', 'owner leave must not end permanent call');

    $ownerAbsence = videochat_realtime_owner_absence_snapshot(
        $pdo,
        VIDEOCHAT_PERMANENT_LIFECYCLE_CALL_ID,
        VIDEOCHAT_PERMANENT_LIFECYCLE_CALL_ID,
        videochat_realtime_owner_absence_now_ms() + (60 * 60 * 1000)
    );
    videochat_permanent_lifecycle_assert((bool) ($ownerAbsence['owner_absence_immune'] ?? false), 'owner absence should be immune for permanent call');
    videochat_permanent_lifecycle_assert((string) ($ownerAbsence['status'] ?? '') === 'permanent_call_immune', 'owner absence status should explain permanent immunity');

    $standardUserId = (int) $pdo->query(
        <<<'SQL'
SELECT users.id
FROM users
INNER JOIN roles ON roles.id = users.role_id
WHERE roles.slug = 'user'
ORDER BY users.id ASC
LIMIT 1
SQL
    )->fetchColumn();
    $created = videochat_create_call($pdo, 1, [
        'room_id' => 'lobby',
        'title' => 'Delete All Non Permanent Contract',
        'starts_at' => gmdate('c', time() - 60),
        'ends_at' => gmdate('c', time() + 3600),
        'internal_participant_user_ids' => [$standardUserId],
    ]);
    videochat_permanent_lifecycle_assert((bool) ($created['ok'] ?? false), 'non-permanent setup call should create');
    $deleteAll = videochat_delete_all_calls($pdo, 1, 'admin', ['confirm' => 'delete_all_calls']);
    videochat_permanent_lifecycle_assert((bool) ($deleteAll['ok'] ?? false), 'delete all should succeed for non-permanent calls');
    videochat_permanent_lifecycle_assert((int) ($deleteAll['deleted_count'] ?? 0) >= 1, 'delete all should report deleted non-permanent calls');
    videochat_permanent_lifecycle_assert(videochat_permanent_lifecycle_status($pdo) === 'active', 'delete all must preserve permanent call');

    fwrite(STDOUT, "[call-permanent-lifecycle-contract] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[call-permanent-lifecycle-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
