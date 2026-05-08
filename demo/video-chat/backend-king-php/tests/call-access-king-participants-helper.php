<?php

declare(strict_types=1);

require_once __DIR__ . '/call-access-rejoin-kick-membership-helper.php';

function videochat_iam_king_participant_iso(int $nowMs): string
{
    return gmdate('c', (int) floor(max(0, $nowMs) / 1000));
}

function videochat_iam_king_participant_set_times(PDO $pdo, string $callId, int $userId, ?int $joinedAtMs, ?int $leftAtMs): void
{
    $statement = $pdo->prepare(
        <<<'SQL'
UPDATE call_participants
SET joined_at = CASE
        WHEN :joined_at IS NULL THEN joined_at
        ELSE :joined_at
    END,
    left_at = :left_at
WHERE call_id = :call_id
  AND user_id = :user_id
  AND source = 'internal'
SQL
    );
    $statement->execute([
        ':joined_at' => is_int($joinedAtMs) ? videochat_iam_king_participant_iso($joinedAtMs) : null,
        ':left_at' => is_int($leftAtMs) ? videochat_iam_king_participant_iso($leftAtMs) : null,
        ':call_id' => $callId,
        ':user_id' => $userId,
    ]);
}

function videochat_iam_king_participant_client(
    PDO $pdo,
    array &$presenceState,
    string $roomId,
    string $callId,
    int $userId,
    string $displayName,
    string $authRole,
    string $callRole,
    int $tenantId,
    int $nowMs,
    string $connectionSuffix
): array {
    $connection = videochat_presence_connection_descriptor(
        [
            'id' => $userId,
            'display_name' => $displayName,
            'role' => $authRole,
            'tenant' => ['id' => $tenantId],
        ],
        'sess-king-' . $connectionSuffix,
        'conn-king-' . $connectionSuffix,
        'socket-king-' . $connectionSuffix,
        $roomId,
        (int) floor($nowMs / 1000)
    );
    $connection['tenant_id'] = $tenantId;
    $connection['requested_call_id'] = $callId;
    $connection['active_call_id'] = $callId;
    $connection['call_role'] = videochat_normalize_call_participant_role($callRole);
    $connection['effective_call_role'] = $connection['call_role'];
    $connection['invite_state'] = 'allowed';
    $connection['can_moderate_call'] = in_array($connection['call_role'], ['owner', 'moderator'], true)
        || videochat_normalize_role_slug($authRole) === 'admin';
    $connection['can_manage_call_owner'] = $connection['call_role'] === 'owner'
        || videochat_normalize_role_slug($authRole) === 'admin';

    $join = videochat_presence_join_room($presenceState, $connection, $roomId);
    $connection = (array) ($join['connection'] ?? $connection);
    videochat_realtime_presence_db_upsert($pdo, $connection, $nowMs);
    videochat_iam_king_participant_set_times($pdo, $callId, $userId, $nowMs, null);

    return $connection;
}

function videochat_iam_king_participant_touch(PDO $pdo, array $connection, int $nowMs): void
{
    videochat_realtime_presence_db_upsert($pdo, $connection, $nowMs);
}

function videochat_iam_king_participant_leave(
    PDO $pdo,
    array &$presenceState,
    array $connection,
    int $nowMs,
    ?callable $sender = null
): array {
    $connectionId = (string) ($connection['connection_id'] ?? '');
    $leftConnection = videochat_presence_remove_connection($presenceState, $connectionId, $sender);
    $effectiveConnection = is_array($leftConnection) ? $leftConnection : $connection;
    videochat_realtime_remove_call_presence(static fn (): PDO => $pdo, $effectiveConnection);
    videochat_realtime_mark_call_participant_left(static fn (): PDO => $pdo, $effectiveConnection, $presenceState);
    videochat_iam_king_participant_set_times(
        $pdo,
        videochat_realtime_connection_call_id($effectiveConnection),
        (int) ($effectiveConnection['user_id'] ?? 0),
        null,
        $nowMs
    );

    return $effectiveConnection;
}

function videochat_iam_king_participant_snapshot(
    PDO $pdo,
    array $presenceState,
    array $viewerConnection,
    int $nowMs,
    string $reason
): array {
    $openDatabase = static fn (): PDO => $pdo;
    $payload = videochat_realtime_room_snapshot_payload($presenceState, $viewerConnection, $openDatabase, $reason, $nowMs);
    $payload['call_lifecycle']['status'] = (string) (($payload['call_lifecycle']['owner_absence'] ?? [])['call_status'] ?? '');
    return $payload;
}
