<?php

declare(strict_types=1);

function videochat_sfu_recovery_request_ttl_ms(): int
{
    return 15_000;
}

function videochat_sfu_bootstrap_recovery_requests(PDO $pdo): void
{
    $pdo->exec(
        <<<'SQL'
CREATE TABLE IF NOT EXISTS sfu_recovery_requests (
    request_id INTEGER PRIMARY KEY AUTOINCREMENT,
    room_id TEXT NOT NULL,
    publisher_id TEXT NOT NULL,
    requester_id TEXT NOT NULL,
    requester_user_id TEXT NOT NULL,
    track_id TEXT NOT NULL DEFAULT '',
    reason TEXT NOT NULL DEFAULT '',
    requested_action TEXT NOT NULL DEFAULT '',
    request_full_keyframe INTEGER NOT NULL DEFAULT 0,
    requested_video_layer TEXT NOT NULL DEFAULT '',
    requested_video_quality_profile TEXT NOT NULL DEFAULT '',
    frame_sequence INTEGER NOT NULL DEFAULT 0,
    created_at_ms INTEGER NOT NULL,
    payload_json TEXT NOT NULL
)
SQL
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sfu_recovery_room_publisher ON sfu_recovery_requests(room_id, publisher_id, request_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_sfu_recovery_room_created ON sfu_recovery_requests(room_id, created_at_ms)');
}

function videochat_sfu_cleanup_recovery_requests(PDO $pdo, ?int $nowMs = null): void
{
    $cutoffMs = ($nowMs ?? videochat_sfu_now_ms()) - videochat_sfu_recovery_request_ttl_ms();
    $statement = $pdo->prepare('DELETE FROM sfu_recovery_requests WHERE created_at_ms < :cutoff_ms');
    $statement->execute([':cutoff_ms' => $cutoffMs]);
}

/**
 * @return array<string, mixed>
 */
function videochat_sfu_normalize_media_recovery_request(
    string $roomId,
    string $publisherId,
    string $requesterId,
    string $requesterUserId,
    array $msg,
    ?int $nowMs = null
): array {
    $reason = strtolower(trim((string) ($msg['reason'] ?? 'sfu_receiver_media_recovery')));
    if ($reason === '' || preg_match('/^[a-z0-9_.:-]{1,96}$/', $reason) !== 1) {
        $reason = 'sfu_receiver_media_recovery';
    }

    $requestedAction = strtolower(trim((string) ($msg['requested_action'] ?? ($msg['requestedAction'] ?? ''))));
    if ($requestedAction === '' || preg_match('/^[a-z0-9_.:-]{1,96}$/', $requestedAction) !== 1) {
        $requestedAction = 'force_full_keyframe';
    }

    $requestedVideoLayer = strtolower(trim((string) ($msg['requested_video_layer'] ?? ($msg['requestedVideoLayer'] ?? ''))));
    if (!in_array($requestedVideoLayer, ['', 'primary', 'thumbnail'], true)) {
        $requestedVideoLayer = '';
    }

    $requestedVideoQualityProfile = strtolower(trim((string) ($msg['requested_video_quality_profile'] ?? ($msg['requestedVideoQualityProfile'] ?? ''))));
    if ($requestedVideoQualityProfile !== '' && preg_match('/^[a-z0-9_.:-]{1,96}$/', $requestedVideoQualityProfile) !== 1) {
        $requestedVideoQualityProfile = '';
    }

    $primaryLayerPreferenceRequested = $requestedAction === 'prefer_primary_video_layer'
        || $reason === 'sfu_receiver_primary_layer_preference';
    $requestFullKeyframe = (
        (bool) ($msg['request_full_keyframe'] ?? ($msg['requestFullKeyframe'] ?? false))
        && !$primaryLayerPreferenceRequested
    ) || $requestedAction === 'force_full_keyframe';

    return [
        'type' => 'sfu/publisher-recovery-request',
        'room_id' => $roomId,
        'publisher_id' => $publisherId,
        'requester_id' => $requesterId,
        'requester_user_id' => $requesterUserId,
        'track_id' => trim((string) ($msg['track_id'] ?? ($msg['trackId'] ?? ''))),
        'reason' => $reason,
        'requested_action' => $requestedAction,
        'request_full_keyframe' => $requestFullKeyframe,
        'requested_video_layer' => $requestedVideoLayer,
        'requested_video_quality_profile' => $requestedVideoQualityProfile,
        'frame_sequence' => max(0, (int) ($msg['frame_sequence'] ?? ($msg['frameSequence'] ?? 0))),
        'control_transport' => videochat_sfu_control_transport_id(),
        'media_transport' => videochat_sfu_fallback_media_transport_id(),
        'created_at_ms' => $nowMs ?? videochat_sfu_now_ms(),
    ];
}

function videochat_sfu_insert_recovery_request(PDO $pdo, array $request): bool
{
    $payloadJson = json_encode($request, JSON_UNESCAPED_SLASHES);
    if (!is_string($payloadJson) || $payloadJson === '') {
        return false;
    }

    $statement = $pdo->prepare(
        <<<'SQL'
INSERT INTO sfu_recovery_requests (
    room_id,
    publisher_id,
    requester_id,
    requester_user_id,
    track_id,
    reason,
    requested_action,
    request_full_keyframe,
    requested_video_layer,
    requested_video_quality_profile,
    frame_sequence,
    created_at_ms,
    payload_json
) VALUES (
    :room_id,
    :publisher_id,
    :requester_id,
    :requester_user_id,
    :track_id,
    :reason,
    :requested_action,
    :request_full_keyframe,
    :requested_video_layer,
    :requested_video_quality_profile,
    :frame_sequence,
    :created_at_ms,
    :payload_json
)
SQL
    );

    return $statement->execute([
        ':room_id' => (string) ($request['room_id'] ?? ''),
        ':publisher_id' => (string) ($request['publisher_id'] ?? ''),
        ':requester_id' => (string) ($request['requester_id'] ?? ''),
        ':requester_user_id' => (string) ($request['requester_user_id'] ?? ''),
        ':track_id' => (string) ($request['track_id'] ?? ''),
        ':reason' => (string) ($request['reason'] ?? ''),
        ':requested_action' => (string) ($request['requested_action'] ?? ''),
        ':request_full_keyframe' => !empty($request['request_full_keyframe']) ? 1 : 0,
        ':requested_video_layer' => (string) ($request['requested_video_layer'] ?? ''),
        ':requested_video_quality_profile' => (string) ($request['requested_video_quality_profile'] ?? ''),
        ':frame_sequence' => max(0, (int) ($request['frame_sequence'] ?? 0)),
        ':created_at_ms' => max(0, (int) ($request['created_at_ms'] ?? videochat_sfu_now_ms())),
        ':payload_json' => $payloadJson,
    ]);
}

/**
 * @return array<int, array<string, mixed>>
 */
function videochat_sfu_fetch_recovery_requests(PDO $pdo, string $roomId, string $publisherId, int $afterRequestId, int $limit = 12): array
{
    $statement = $pdo->prepare(
        <<<'SQL'
SELECT request_id, payload_json
FROM sfu_recovery_requests
WHERE room_id = :room_id
  AND publisher_id = :publisher_id
  AND request_id > :after_request_id
ORDER BY request_id ASC
LIMIT :limit
SQL
    );
    $statement->bindValue(':room_id', $roomId, PDO::PARAM_STR);
    $statement->bindValue(':publisher_id', $publisherId, PDO::PARAM_STR);
    $statement->bindValue(':after_request_id', max(0, $afterRequestId), PDO::PARAM_INT);
    $statement->bindValue(':limit', max(1, min(50, $limit)), PDO::PARAM_INT);
    if (!$statement->execute()) {
        return [];
    }

    $requests = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $requestId = max(0, (int) ($row['request_id'] ?? 0));
        $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
        if (!is_array($payload) || $requestId <= 0) {
            continue;
        }
        $payload['broker_request_id'] = $requestId;
        $requests[] = $payload;
    }
    return $requests;
}

function videochat_sfu_poll_recovery_requests(
    PDO $pdo,
    mixed $websocket,
    string $roomId,
    string $publisherId,
    int &$cursor
): void {
    foreach (videochat_sfu_fetch_recovery_requests($pdo, $roomId, $publisherId, $cursor) as $request) {
        $cursor = max($cursor, (int) ($request['broker_request_id'] ?? 0));
        king_websocket_send($websocket, json_encode($request));
    }
}
