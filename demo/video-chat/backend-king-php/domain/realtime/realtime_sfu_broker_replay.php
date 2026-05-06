<?php

declare(strict_types=1);

function videochat_sfu_broker_publisher_leave_grace_ms(): int
{
    return 3000;
}

function videochat_sfu_receive_poll_timeout_ms(): int
{
    return 15;
}

function videochat_sfu_broker_poll_interval_ms(): int
{
    return 100;
}

function videochat_sfu_live_frame_relay_ttl_ms(): int
{
    return 2500;
}

function videochat_sfu_live_frame_relay_max_files_per_room(): int
{
    return 300;
}

function videochat_sfu_live_frame_relay_max_room_bytes(): int
{
    return 96 * 1024 * 1024;
}

function videochat_sfu_live_frame_relay_cleanup_interval_ms(): int
{
    return 250;
}

function videochat_sfu_live_frame_relay_poll_interval_ms(): int
{
    return 50;
}

function videochat_sfu_live_frame_relay_poll_batch_limit(): int
{
    return 12;
}

function videochat_sfu_live_frame_relay_max_record_bytes(array $frame): int
{
    $payloadBudgetBytes = max(
        (int) ($frame['budget_max_keyframe_bytes_per_frame'] ?? 0),
        (int) ($frame['budget_max_encoded_bytes_per_frame'] ?? 0),
        (int) ($frame['max_payload_bytes'] ?? 0),
        (int) ($frame['payload_bytes'] ?? 0)
    );
    if ($payloadBudgetBytes <= 0) {
        return 10 * 1024 * 1024;
    }

    return min(10 * 1024 * 1024, max(512 * 1024, (int) ceil($payloadBudgetBytes * 1.5) + 64 * 1024));
}

function videochat_sfu_live_frame_relay_should_cleanup(string $roomId, int $nowMs): bool
{
    static $nextCleanupAtByRoom = [];

    $nextCleanupAtMs = (int) ($nextCleanupAtByRoom[$roomId] ?? 0);
    if ($nowMs < $nextCleanupAtMs) {
        return false;
    }
    $nextCleanupAtByRoom[$roomId] = $nowMs + videochat_sfu_live_frame_relay_cleanup_interval_ms();
    return true;
}

function videochat_sfu_live_frame_relay_root(): string
{
    return 'ram://king-videochat-sfu-live-relay';
}

/**
 * @return array{rooms?: array<string, array{records?: array<string, array<string, mixed>>, bytes?: int}>}
 */
function &videochat_sfu_live_frame_relay_state(): array
{
    if (!isset($GLOBALS['videochat_sfu_live_frame_relay_ram']) || !is_array($GLOBALS['videochat_sfu_live_frame_relay_ram'])) {
        $GLOBALS['videochat_sfu_live_frame_relay_ram'] = ['rooms' => []];
    }

    return $GLOBALS['videochat_sfu_live_frame_relay_ram'];
}

function videochat_sfu_live_frame_relay_room_key(string $roomId): string
{
    return videochat_presence_normalize_room_storage_key($roomId, '');
}

function videochat_sfu_live_frame_relay_room_dir(string $roomId): string
{
    $normalizedRoomId = videochat_sfu_live_frame_relay_room_key($roomId);
    if ($normalizedRoomId === '') {
        return '';
    }

    return videochat_sfu_live_frame_relay_root() . '/' . hash('sha256', $normalizedRoomId);
}

function videochat_sfu_live_frame_relay_ensure_room_dir(string $roomId): string
{
    $roomKey = videochat_sfu_live_frame_relay_room_key($roomId);
    if ($roomKey === '') {
        return '';
    }

    $state = &videochat_sfu_live_frame_relay_state();
    if (!isset($state['rooms']) || !is_array($state['rooms'])) {
        $state['rooms'] = [];
    }
    if (!isset($state['rooms'][$roomKey]) || !is_array($state['rooms'][$roomKey])) {
        $state['rooms'][$roomKey] = ['records' => [], 'bytes' => 0];
    }
    if (!isset($state['rooms'][$roomKey]['records']) || !is_array($state['rooms'][$roomKey]['records'])) {
        $state['rooms'][$roomKey]['records'] = [];
    }
    $state['rooms'][$roomKey]['bytes'] = max(0, (int) ($state['rooms'][$roomKey]['bytes'] ?? 0));

    return $roomKey;
}

function videochat_sfu_live_frame_relay_filename(int $nowMs): string
{
    static $sequence = 0;

    $sequence = ($sequence + 1) % 1_000_000;
    return sprintf(
        '%013d_%05d_%06d.json',
        max(0, $nowMs),
        max(0, (int) getmypid()) % 100000,
        $sequence
    );
}

function videochat_sfu_live_frame_relay_cleanup_room(string $roomId, ?int $nowMs = null): void
{
    $roomKey = videochat_sfu_live_frame_relay_room_key($roomId);
    if ($roomKey === '') {
        return;
    }

    $state = &videochat_sfu_live_frame_relay_state();
    if (!isset($state['rooms'][$roomKey]) || !is_array($state['rooms'][$roomKey])) {
        return;
    }
    if (!isset($state['rooms'][$roomKey]['records']) || !is_array($state['rooms'][$roomKey]['records'])) {
        unset($state['rooms'][$roomKey]);
        return;
    }

    $effectiveNowMs = $nowMs ?? videochat_sfu_now_ms();
    $records = &$state['rooms'][$roomKey]['records'];
    ksort($records, SORT_STRING);
    $keptBytes = 0;
    foreach ($records as $relayId => $record) {
        if (!is_string($relayId) || !is_array($record)) {
            unset($records[$relayId]);
            continue;
        }
        $createdAtMs = (int) ($record['created_at_ms'] ?? 0);
        if ($createdAtMs <= 0 || ($effectiveNowMs - $createdAtMs) > videochat_sfu_live_frame_relay_ttl_ms()) {
            unset($records[$relayId]);
            continue;
        }
        $keptBytes += max(0, (int) ($record['bytes'] ?? 0));
    }
    $state['rooms'][$roomKey]['bytes'] = $keptBytes;

    while (
        $records !== []
        && (
            count($records) > videochat_sfu_live_frame_relay_max_files_per_room()
            || $state['rooms'][$roomKey]['bytes'] > videochat_sfu_live_frame_relay_max_room_bytes()
        )
    ) {
        $oldestRelayId = array_key_first($records);
        if (!is_string($oldestRelayId)) {
            break;
        }
        $state['rooms'][$roomKey]['bytes'] -= max(0, (int) ($records[$oldestRelayId]['bytes'] ?? 0));
        unset($records[$oldestRelayId]);
    }

    if ($records === []) {
        unset($records);
        unset($state['rooms'][$roomKey]);
        return;
    }

    $state['rooms'][$roomKey]['bytes'] = max(0, (int) ($state['rooms'][$roomKey]['bytes'] ?? 0));
    unset($records);
}

function videochat_sfu_live_frame_relay_publish(string $roomId, string $publisherId, array $frame): bool
{
    $normalizedRoomId = videochat_sfu_live_frame_relay_room_key($roomId);
    $normalizedPublisherId = trim($publisherId);
    if ($normalizedRoomId === '' || $normalizedPublisherId === '') {
        return false;
    }

    $roomKey = videochat_sfu_live_frame_relay_ensure_room_dir($normalizedRoomId);
    if ($roomKey === '') {
        return false;
    }

    $frame['type'] = 'sfu/frame';
    $frame['publisher_id'] = (string) ($frame['publisher_id'] ?? $normalizedPublisherId);
    $nowMs = videochat_sfu_now_ms();
    $relayId = videochat_sfu_live_frame_relay_filename($nowMs);
    $record = [
        'relay_id' => $relayId,
        'created_at_ms' => $nowMs,
        'room_id' => $normalizedRoomId,
        'publisher_id' => $normalizedPublisherId,
        'frame' => $frame,
    ];
    $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded) || $encoded === '') {
        return false;
    }
    if (strlen($encoded) > videochat_sfu_live_frame_relay_max_record_bytes($frame)) {
        return false;
    }

    $state = &videochat_sfu_live_frame_relay_state();
    $previousRecordBytes = max(0, (int) ($state['rooms'][$roomKey]['records'][$relayId]['bytes'] ?? 0));
    $record['bytes'] = strlen($encoded);
    $state['rooms'][$roomKey]['records'][$relayId] = $record;
    $state['rooms'][$roomKey]['bytes'] = max(
        0,
        (int) ($state['rooms'][$roomKey]['bytes'] ?? 0) - $previousRecordBytes + (int) $record['bytes']
    );

    if (
        videochat_sfu_live_frame_relay_should_cleanup($normalizedRoomId, $nowMs)
        || count($state['rooms'][$roomKey]['records'] ?? []) > videochat_sfu_live_frame_relay_max_files_per_room()
        || (int) ($state['rooms'][$roomKey]['bytes'] ?? 0) > videochat_sfu_live_frame_relay_max_room_bytes()
    ) {
        videochat_sfu_live_frame_relay_cleanup_room($normalizedRoomId, $nowMs);
    }

    return true;
}

/**
 * @param array<int|string, string|bool> $localPublisherIds
 * @param array<string, int> $seenRelayIds
 * @return array<int, array<string, mixed>>
 */
function videochat_sfu_live_frame_relay_read(
    string $roomId,
    string $clientId,
    array $localPublisherIds,
    string &$cursor,
    array &$seenRelayIds,
    int $limit = 80
): array {
    $roomKey = videochat_sfu_live_frame_relay_room_key($roomId);
    if ($roomKey === '') {
        return [];
    }

    $state = &videochat_sfu_live_frame_relay_state();
    $room = $state['rooms'][$roomKey] ?? null;
    if (!is_array($room) || !isset($room['records']) || !is_array($room['records'])) {
        return [];
    }

    $normalizedClientId = trim($clientId);
    $localPublisherLookup = [];
    foreach ($localPublisherIds as $key => $value) {
        $publisherId = is_string($key) && is_bool($value) ? $key : (string) $value;
        $publisherId = trim($publisherId);
        if ($publisherId !== '') {
            $localPublisherLookup[$publisherId] = true;
        }
    }

    $nowMs = videochat_sfu_now_ms();
    foreach ($seenRelayIds as $relayId => $seenAtMs) {
        if (($nowMs - (int) $seenAtMs) > videochat_sfu_live_frame_relay_ttl_ms()) {
            unset($seenRelayIds[$relayId]);
        }
    }

    $records = $room['records'];
    ksort($records, SORT_STRING);
    $frames = [];
    foreach ($records as $relayId => $record) {
        if (count($frames) >= $limit) {
            break;
        }
        if (!is_string($relayId) || !is_array($record)) {
            continue;
        }
        // Every SFU subscriber is also usually a publisher. A single relay-id
        // watermark must not advance over self/local frames, otherwise a remote
        // frame that becomes visible slightly later can be skipped forever.
        if (isset($seenRelayIds[$relayId])) {
            continue;
        }
        $seenRelayIds[$relayId] = $nowMs;
        $createdAtMs = (int) ($record['created_at_ms'] ?? 0);
        if ($createdAtMs <= 0 || ($nowMs - $createdAtMs) > videochat_sfu_live_frame_relay_ttl_ms()) {
            continue;
        }
        $publisherId = trim((string) ($record['publisher_id'] ?? ''));
        if ($publisherId === '' || $publisherId === $normalizedClientId || isset($localPublisherLookup[$publisherId])) {
            continue;
        }
        $frame = $record['frame'] ?? null;
        if (!is_array($frame) || strtolower(trim((string) ($frame['type'] ?? ''))) !== 'sfu/frame') {
            continue;
        }
        $frame['publisher_id'] = (string) ($frame['publisher_id'] ?? $publisherId);
        $frame['live_relay_age_ms'] = max(0, $nowMs - $createdAtMs);
        if ($cursor === '' || strcmp($relayId, $cursor) > 0) {
            $cursor = $relayId;
        }
        $frames[] = $frame;
    }

    return $frames;
}

/**
 * @param array<int|string, string|bool> $localPublisherIds
 * @param array<string, int> $seenRelayIds
 */
function videochat_sfu_live_frame_relay_poll(
    mixed $websocket,
    string $roomId,
    string $clientId,
    array $localPublisherIds,
    string &$cursor,
    array &$seenRelayIds,
    array &$slowSubscriberBlockedUntilMs,
    array $subscriber = []
): int {
    $frames = videochat_sfu_live_frame_relay_read(
        $roomId,
        $clientId,
        $localPublisherIds,
        $cursor,
        $seenRelayIds,
        videochat_sfu_live_frame_relay_poll_batch_limit()
    );
    foreach ($frames as &$frame) {
        $subscriberSendStartedAtMs = videochat_sfu_now_ms();
        $kingFanoutStartedAtMs = max(0, (int) ($frame['king_receive_at_ms'] ?? 0));
        if ($kingFanoutStartedAtMs > 0) {
            $frame['subscriber_send_latency_ms'] = max(0, $subscriberSendStartedAtMs - $kingFanoutStartedAtMs);
        }
    }
    unset($frame);

    return videochat_sfu_send_replay_frames_to_subscriber(
        $websocket,
        $frames,
        $roomId,
        $clientId,
        'live_relay_poll',
        $slowSubscriberBlockedUntilMs,
        $subscriber
    );
}

/**
 * @param array<int|string, string|bool> $localPublisherIds
 */
function videochat_sfu_sqlite_frame_buffer_poll(
    PDO $pdo,
    mixed $websocket,
    string $roomId,
    string $clientId,
    array $localPublisherIds,
    int &$cursor,
    array &$slowSubscriberBlockedUntilMs,
    array $subscriber = []
): int {
    $frames = videochat_sfu_fetch_buffered_frames(
        $pdo,
        $roomId,
        $clientId,
        $localPublisherIds,
        $cursor,
        videochat_sfu_frame_buffer_poll_batch_limit()
    );
    foreach ($frames as &$frame) {
        $subscriberSendStartedAtMs = videochat_sfu_now_ms();
        $kingFanoutStartedAtMs = max(0, (int) ($frame['king_receive_at_ms'] ?? 0));
        if ($kingFanoutStartedAtMs > 0) {
            $frame['subscriber_send_latency_ms'] = max(0, $subscriberSendStartedAtMs - $kingFanoutStartedAtMs);
        }
    }
    unset($frame);

    return videochat_sfu_send_replay_frames_to_subscriber(
        $websocket,
        $frames,
        $roomId,
        $clientId,
        'sqlite_frame_buffer_poll',
        $slowSubscriberBlockedUntilMs,
        $subscriber
    );
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

function videochat_sfu_poll_broker(
    PDO $pdo,
    mixed $websocket,
    string $roomId,
    string $clientId,
    array &$knownPublishers,
    array &$trackSignatures
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
}
