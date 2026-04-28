<?php

declare(strict_types=1);

function videochat_sfu_broker_replay_max_frame_age_ms(): int
{
    return 2500;
}

function videochat_sfu_broker_replay_max_frames_per_poll(): int
{
    return 16;
}

function videochat_sfu_broker_publisher_leave_grace_ms(): int
{
    return 3000;
}

/**
 * @param array<string, int|bool> $knownPublishers
 * @param array<string, bool> $activePublishers
 */
function videochat_sfu_broker_mark_active_publisher(
    mixed $websocket,
    string $roomId,
    string $publisherId,
    array &$knownPublishers,
    array &$activePublishers,
    int $nowMs
): void {
    if ($publisherId === '') {
        return;
    }
    $activePublishers[$publisherId] = true;
    if (!isset($knownPublishers[$publisherId])) {
        videochat_presence_send_frame($websocket, [
            'type' => 'sfu/joined',
            'room_id' => $roomId,
            'publishers' => [$publisherId],
        ]);
    }
    $knownPublishers[$publisherId] = $nowMs;
}

/**
 * @param array<string, string> $trackSignatures
 * @param array<int, array{id: string, kind: string, label: string}> $tracks
 */
function videochat_sfu_broker_send_tracks_if_changed(
    mixed $websocket,
    string $roomId,
    string $publisherId,
    string $publisherUserId,
    string $publisherName,
    array $tracks,
    array &$trackSignatures
): void {
    $trackSignature = hash('sha256', json_encode($tracks, JSON_UNESCAPED_SLASHES) ?: '');
    if ($tracks === [] || ($trackSignatures[$publisherId] ?? '') === $trackSignature) {
        return;
    }
    $trackSignatures[$publisherId] = $trackSignature;
    videochat_presence_send_frame($websocket, [
        'type' => 'sfu/tracks',
        'room_id' => $roomId,
        'publisher_id' => $publisherId,
        'publisher_user_id' => $publisherUserId,
        'publisher_name' => $publisherName,
        'tracks' => $tracks,
    ]);
}

/**
 * @param array<int, array<string, mixed>> $frames
 * @return array<string, array{publisher_user_id: string, tracks: array<string, array{id: string, kind: string, label: string}>}>
 */
function videochat_sfu_broker_frame_track_announcements(array $frames): array
{
    $announcements = [];
    foreach ($frames as $frame) {
        $publisherId = (string) ($frame['publisher_id'] ?? '');
        $trackId = (string) ($frame['track_id'] ?? '');
        if ($publisherId === '' || $trackId === '') {
            continue;
        }
        if (!isset($announcements[$publisherId])) {
            $announcements[$publisherId] = [
                'publisher_user_id' => (string) ($frame['publisher_user_id'] ?? ''),
                'tracks' => [],
            ];
        }
        $announcements[$publisherId]['tracks'][$trackId] = [
            'id' => $trackId,
            'kind' => 'video',
            'label' => 'Remote video',
        ];
    }

    return $announcements;
}

/**
 * @param array<int, array<string, mixed>> $frames
 * @return array<int, array<string, mixed>>
 */
function videochat_sfu_select_live_broker_replay_frames(array $frames, string $roomId, int &$lastFrameId): array
{
    if ($frames === []) {
        return [];
    }

    $maxFrameId = $lastFrameId;
    foreach ($frames as $frame) {
        $maxFrameId = max($maxFrameId, (int) ($frame['id'] ?? 0));
    }

    $maxAgeMs = videochat_sfu_broker_replay_max_frame_age_ms();
    $cutoffMs = videochat_sfu_now_ms() - $maxAgeMs;
    $freshFrames = [];
    $droppedFrames = 0;
    foreach ($frames as $frame) {
        $createdAtMs = (int) ($frame['created_at_ms'] ?? 0);
        if ($createdAtMs > 0 && $createdAtMs < $cutoffMs) {
            $droppedFrames += 1;
            continue;
        }
        $freshFrames[] = $frame;
    }

    $maxFrames = videochat_sfu_broker_replay_max_frames_per_poll();
    if (count($freshFrames) > $maxFrames) {
        $droppedFrames += count($freshFrames) - $maxFrames;
        $freshFrames = array_slice($freshFrames, -$maxFrames);
    }

    $lastFrameId = max($lastFrameId, $maxFrameId);
    if ($droppedFrames > 0) {
        videochat_sfu_log_runtime_event('sfu_frame_broker_replay_stale_dropped', [
            'room_id' => $roomId,
            'dropped_frame_count' => $droppedFrames,
            'selected_frame_count' => count($freshFrames),
            'max_frame_age_ms' => $maxAgeMs,
            'last_frame_id' => $lastFrameId,
        ], 3000);
    }

    return $freshFrames;
}

function videochat_sfu_poll_broker(
    PDO $pdo,
    mixed $websocket,
    string $roomId,
    string $clientId,
    array &$knownPublishers,
    array &$trackSignatures,
    int &$lastFrameId
): void {
    $activePublishers = [];
    $publishersWithBrokerTracks = [];
    $nowMs = videochat_sfu_now_ms();
    foreach (videochat_sfu_fetch_publishers($pdo, $roomId) as $publisher) {
        $publisherId = (string) ($publisher['publisher_id'] ?? '');
        if ($publisherId === '' || $publisherId === $clientId) {
            continue;
        }
        videochat_sfu_broker_mark_active_publisher(
            $websocket,
            $roomId,
            $publisherId,
            $knownPublishers,
            $activePublishers,
            $nowMs
        );

        $tracks = videochat_sfu_fetch_tracks($pdo, $roomId, $publisherId);
        if ($tracks !== []) {
            $publishersWithBrokerTracks[$publisherId] = true;
        }
        videochat_sfu_broker_send_tracks_if_changed(
            $websocket,
            $roomId,
            $publisherId,
            (string) ($publisher['user_id'] ?? ''),
            (string) ($publisher['user_name'] ?? ''),
            $tracks,
            $trackSignatures
        );
    }

    $frames = videochat_sfu_select_live_broker_replay_frames(
        videochat_sfu_fetch_frames_since($pdo, $roomId, $lastFrameId, $clientId, 200),
        $roomId,
        $lastFrameId
    );
    foreach (videochat_sfu_broker_frame_track_announcements($frames) as $publisherId => $announcement) {
        videochat_sfu_broker_mark_active_publisher(
            $websocket,
            $roomId,
            $publisherId,
            $knownPublishers,
            $activePublishers,
            $nowMs
        );
        if (isset($publishersWithBrokerTracks[$publisherId])) {
            continue;
        }
        videochat_sfu_broker_send_tracks_if_changed(
            $websocket,
            $roomId,
            $publisherId,
            (string) ($announcement['publisher_user_id'] ?? ''),
            '',
            array_values($announcement['tracks'] ?? []),
            $trackSignatures
        );
    }

    foreach (array_keys($knownPublishers) as $publisherId) {
        if (isset($activePublishers[$publisherId])) {
            continue;
        }
        $lastSeenMs = max(0, (int) ($knownPublishers[$publisherId] ?? 0));
        if ($lastSeenMs > 0 && ($nowMs - $lastSeenMs) < videochat_sfu_broker_publisher_leave_grace_ms()) {
            continue;
        }
        unset($knownPublishers[$publisherId], $trackSignatures[$publisherId]);
        videochat_presence_send_frame($websocket, [
            'type' => 'sfu/publisher_left',
            'publisher_id' => $publisherId,
        ]);
    }

    foreach ($frames as $frame) {
        $decodedData = json_decode((string) ($frame['data_json'] ?? '[]'), true);
        $storedPayload = is_array($decodedData) ? videochat_sfu_decode_stored_frame_payload($decodedData) : [];
        $storedMetadata = is_array($storedPayload['metadata'] ?? null) ? $storedPayload['metadata'] : [];
        $transportMetricFields = videochat_sfu_transport_metric_fields($storedMetadata, 0);
        $binaryPayload = is_string($frame['data_blob'] ?? null) ? (string) $frame['data_blob'] : '';
        if ($binaryPayload !== '' && videochat_sfu_binary_frame_has_magic($binaryPayload)) {
            try {
                if (king_websocket_send($websocket, $binaryPayload, true) === true) {
                    videochat_sfu_log_runtime_event('sfu_frame_broker_replay_binary', [
                        'room_id' => $roomId,
                        'publisher_id' => (string) ($frame['publisher_id'] ?? ''),
                        'track_id' => (string) ($frame['track_id'] ?? ''),
                        'frame_type' => (string) ($frame['frame_type'] ?? 'delta'),
                        'wire_payload_bytes' => strlen($binaryPayload),
                        ...videochat_sfu_transport_metric_fields($storedMetadata, strlen($binaryPayload)),
                    ], 3000);
                    continue;
                }
            } catch (Throwable) {
                // Re-decode stored metadata below, but keep media replay on binary only.
            }
        }
        videochat_sfu_log_runtime_event('sfu_frame_broker_replay_binary_required_retry', [
            'room_id' => $roomId,
            'publisher_id' => (string) ($frame['publisher_id'] ?? ''),
            'track_id' => (string) ($frame['track_id'] ?? ''),
            'frame_type' => (string) ($frame['frame_type'] ?? 'delta'),
            'has_data_blob' => $binaryPayload !== '',
            ...$transportMetricFields,
        ], 3000);
        if (!is_array($decodedData)) {
            continue;
        }
        $outboundFrame = [
            'type' => 'sfu/frame',
            'publisher_id' => (string) ($frame['publisher_id'] ?? ''),
            'publisher_user_id' => (string) ($frame['publisher_user_id'] ?? ''),
            'track_id' => (string) ($frame['track_id'] ?? ''),
            'timestamp' => (int) ($frame['timestamp'] ?? 0),
            'frame_type' => (string) ($frame['frame_type'] ?? 'delta'),
            'protection_mode' => (string) ($storedPayload['protection_mode'] ?? 'transport_only'),
            'protocol_version' => (int) ($storedMetadata['protocol_version'] ?? 1),
            'frame_sequence' => (int) ($storedMetadata['frame_sequence'] ?? 0),
            'sender_sent_at_ms' => (int) ($storedMetadata['sender_sent_at_ms'] ?? 0),
            'payload_chars' => (int) ($storedMetadata['payload_chars'] ?? 0),
            'chunk_count' => (int) ($storedMetadata['chunk_count'] ?? 1),
            'codec_id' => (string) ($storedMetadata['codec_id'] ?? 'wlvc_unknown'),
            'runtime_id' => (string) ($storedMetadata['runtime_id'] ?? 'unknown_runtime'),
            'layout_mode' => (string) ($storedMetadata['layout_mode'] ?? ''),
            'layer_id' => (string) ($storedMetadata['layer_id'] ?? ''),
            'cache_epoch' => (int) ($storedMetadata['cache_epoch'] ?? 0),
            'tile_columns' => (int) ($storedMetadata['tile_columns'] ?? 0),
            'tile_rows' => (int) ($storedMetadata['tile_rows'] ?? 0),
            'tile_width' => (int) ($storedMetadata['tile_width'] ?? 0),
            'tile_height' => (int) ($storedMetadata['tile_height'] ?? 0),
            'tile_indices' => is_array($storedMetadata['tile_indices'] ?? null)
                ? array_values($storedMetadata['tile_indices'])
                : [],
            'roi_norm_x' => (float) ($storedMetadata['roi_norm_x'] ?? 0),
            'roi_norm_y' => (float) ($storedMetadata['roi_norm_y'] ?? 0),
            'roi_norm_width' => (float) ($storedMetadata['roi_norm_width'] ?? 1),
            'roi_norm_height' => (float) ($storedMetadata['roi_norm_height'] ?? 1),
        ];
        $storedFrameId = trim((string) ($storedMetadata['frame_id'] ?? ''));
        if ($storedFrameId !== '') {
            $outboundFrame['frame_id'] = $storedFrameId;
        }
        if (($storedPayload['protected_frame'] ?? '') !== '') {
            $outboundFrame['protected_frame'] = $storedPayload['protected_frame'];
        } elseif (($storedPayload['data_base64'] ?? '') !== '') {
            $outboundFrame['data_base64'] = $storedPayload['data_base64'];
        } else {
            $outboundFrame['data'] = $storedPayload['data'];
        }
        if (!videochat_sfu_send_outbound_message($websocket, $outboundFrame)) {
            videochat_sfu_log_runtime_event('sfu_frame_broker_replay_binary_required_failed', [
                'room_id' => $roomId,
                'publisher_id' => (string) ($frame['publisher_id'] ?? ''),
                'track_id' => (string) ($frame['track_id'] ?? ''),
                'frame_type' => (string) ($frame['frame_type'] ?? 'delta'),
                ...$transportMetricFields,
            ], 3000);
        }
    }
}
