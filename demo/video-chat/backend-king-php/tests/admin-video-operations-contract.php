<?php

declare(strict_types=1);

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDOUT, "[admin-video-operations-contract] SKIP: pdo_sqlite is not available\n");
    exit(0);
}

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../http/module_operations.php';

function videochat_admin_video_ops_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[admin-video-operations-contract] FAIL: {$message}\n");
    exit(1);
}

/** @return array<string, mixed> */
function videochat_admin_video_ops_decode(array $response): array
{
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    return is_array($decoded) ? $decoded : [];
}

try {
    $databasePath = sys_get_temp_dir() . '/videochat-admin-video-ops-' . bin2hex(random_bytes(6)) . '.sqlite';
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
    videochat_admin_video_ops_assert($adminUserId > 0, 'expected seeded admin user');

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
    videochat_admin_video_ops_assert($standardUserId > 0, 'expected seeded standard user');

    $insertCall = $pdo->prepare(
        <<<'SQL'
INSERT INTO calls(id, room_id, title, owner_user_id, status, starts_at, ends_at, created_at, updated_at)
VALUES(:id, 'lobby', :title, :owner_user_id, :status, :starts_at, :ends_at, :created_at, :updated_at)
SQL
    );

    $calls = [
        ['call-ops-alpha', 'Operations Alpha', $adminUserId, 'scheduled', '2026-04-21T09:55:00Z', '2026-04-21T12:30:00Z'],
        ['call-ops-beta', 'Operations Beta', $standardUserId, 'active', '2026-04-21T11:00:00Z', '2026-04-21T12:45:00Z'],
        ['call-ops-idle', 'Idle Assigned Call', $adminUserId, 'scheduled', '2026-04-21T12:30:00Z', '2026-04-21T13:00:00Z'],
        ['call-ops-cancelled', 'Cancelled With Stale Presence', $adminUserId, 'cancelled', '2026-04-21T10:00:00Z', '2026-04-21T10:30:00Z'],
        ['call-ops-ended', 'Ended With Stale Presence', $adminUserId, 'ended', '2026-04-21T10:00:00Z', '2026-04-21T10:30:00Z'],
    ];

    foreach ($calls as [$id, $title, $ownerUserId, $status, $startsAt, $endsAt]) {
        $insertCall->execute([
            ':id' => $id,
            ':title' => $title,
            ':owner_user_id' => $ownerUserId,
            ':status' => $status,
            ':starts_at' => $startsAt,
            ':ends_at' => $endsAt,
            ':created_at' => $startsAt,
            ':updated_at' => $startsAt,
        ]);
    }

    $insertParticipant = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_participants(call_id, user_id, email, display_name, source, call_role, invite_state, joined_at, left_at)
VALUES(:call_id, :user_id, :email, :display_name, :source, :call_role, :invite_state, :joined_at, :left_at)
SQL
    );
    $participants = [
        ['call-ops-alpha', $adminUserId, 'admin@intelligent-intern.com', 'Platform Admin', 'internal', 'owner', 'allowed', '2026-04-21T10:00:00Z', null],
        ['call-ops-alpha', $standardUserId, 'user@intelligent-intern.com', 'Call User', 'internal', 'participant', 'allowed', '2026-04-21T10:05:00Z', null],
        ['call-ops-alpha', null, 'guest-alpha@example.test', 'Guest Alpha', 'external', 'participant', 'invited', null, null],
        ['call-ops-beta', null, 'guest-beta@example.test', 'Guest Beta', 'external', 'participant', 'allowed', '2026-04-21T11:30:00Z', null],
        ['call-ops-beta', $standardUserId, 'left-beta@example.test', 'Left Beta', 'internal', 'participant', 'allowed', '2026-04-21T11:10:00Z', '2026-04-21T11:15:00Z'],
        ['call-ops-idle', $standardUserId, 'idle@example.test', 'Idle User', 'internal', 'participant', 'invited', null, null],
        ['call-ops-cancelled', $standardUserId, 'cancelled@example.test', 'Cancelled User', 'internal', 'participant', 'allowed', '2026-04-21T10:00:00Z', null],
        ['call-ops-ended', $standardUserId, 'ended@example.test', 'Ended User', 'internal', 'participant', 'allowed', '2026-04-21T10:00:00Z', null],
    ];

    foreach ($participants as [$callId, $userId, $email, $displayName, $source, $callRole, $inviteState, $joinedAt, $leftAt]) {
        $insertParticipant->execute([
            ':call_id' => $callId,
            ':user_id' => $userId,
            ':email' => $email,
            ':display_name' => $displayName,
            ':source' => $source,
            ':call_role' => $callRole,
            ':invite_state' => $inviteState,
            ':joined_at' => $joinedAt,
            ':left_at' => $leftAt,
        ]);
    }

    $snapshot = videochat_video_operations_snapshot($pdo, strtotime('2026-04-21T12:00:00Z'));
    videochat_admin_video_ops_assert((string) ($snapshot['status'] ?? '') === 'ok', 'snapshot status mismatch');
    videochat_admin_video_ops_assert((int) (($snapshot['metrics'] ?? [])['live_calls'] ?? -1) === 2, 'live call metric must count only active presence calls');
    videochat_admin_video_ops_assert((int) (($snapshot['metrics'] ?? [])['concurrent_participants'] ?? -1) === 3, 'concurrent participant metric must count only joined participants without left_at');

    $runningCalls = (array) ($snapshot['running_calls'] ?? []);
    videochat_admin_video_ops_assert(count($runningCalls) === 2, 'running call list should contain two calls');
    videochat_admin_video_ops_assert((string) (($runningCalls[0] ?? [])['id'] ?? '') === 'call-ops-alpha', 'first live call should be alpha by running_since');
    videochat_admin_video_ops_assert((int) (($runningCalls[0]['live_participants'] ?? [])['total'] ?? 0) === 2, 'alpha live participants mismatch');
    videochat_admin_video_ops_assert((int) (($runningCalls[0]['assigned_participants'] ?? [])['total'] ?? 0) === 3, 'alpha assigned participants mismatch');
    videochat_admin_video_ops_assert((int) (($runningCalls[0] ?? [])['uptime_seconds'] ?? 0) === 7200, 'alpha uptime mismatch');
    videochat_admin_video_ops_assert((int) (($runningCalls[1]['live_participants'] ?? [])['external'] ?? 0) === 1, 'beta live external participants mismatch');

    $jsonResponse = static function (int $status, array $payload): array {
        return [
            'status' => $status,
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    };

    $errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
        return $jsonResponse($status, [
            'status' => 'error',
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
            'time' => gmdate('c'),
        ]);
    };

    $openDatabase = static fn (): PDO => $pdo;

    $methodBlocked = videochat_handle_operations_routes(
        '/api/admin/video-operations',
        'POST',
        $jsonResponse,
        $errorResponse,
        $openDatabase
    );
    videochat_admin_video_ops_assert(is_array($methodBlocked), 'POST should be handled');
    videochat_admin_video_ops_assert((int) ($methodBlocked['status'] ?? 0) === 405, 'POST should be rejected with 405');

    $unrelated = videochat_handle_operations_routes(
        '/api/admin/infrastructure',
        'GET',
        $jsonResponse,
        $errorResponse,
        $openDatabase
    );
    videochat_admin_video_ops_assert($unrelated === null, 'unrelated route should not be handled');

    $response = videochat_handle_operations_routes(
        '/api/admin/video-operations',
        'GET',
        $jsonResponse,
        $errorResponse,
        $openDatabase
    );
    videochat_admin_video_ops_assert(is_array($response), 'GET should be handled');
    videochat_admin_video_ops_assert((int) ($response['status'] ?? 0) === 200, 'GET should return 200');
    $payload = videochat_admin_video_ops_decode($response);
    videochat_admin_video_ops_assert((int) (($payload['metrics'] ?? [])['live_calls'] ?? -1) === 2, 'route live call metric mismatch');
    videochat_admin_video_ops_assert((int) (($payload['metrics'] ?? [])['concurrent_participants'] ?? -1) === 3, 'route concurrent participant metric mismatch');

    @unlink($databasePath);
    fwrite(STDOUT, "[admin-video-operations-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[admin-video-operations-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
