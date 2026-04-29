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
    return 32 * 1024 * 1024;
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
        return 2 * 1024 * 1024;
    }

    return min(3 * 1024 * 1024, max(128 * 1024, (int) ceil($payloadBudgetBytes * 1.5) + 64 * 1024));
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
    $configured = trim((string) (getenv('VIDEOCHAT_KING_SFU_FRAME_RELAY_PATH') ?: ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    if (is_dir('/dev/shm') && is_writable('/dev/shm')) {
        return '/dev/shm/king-videochat-sfu-live-relay';
    }

    return rtrim(sys_get_temp_dir(), '/') . '/king-videochat-sfu-live-relay';
}

function videochat_sfu_live_frame_relay_room_dir(string $roomId): string
{
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId, '');
    if ($normalizedRoomId === '') {
        return '';
    }

    return videochat_sfu_live_frame_relay_root() . '/' . hash('sha256', $normalizedRoomId);
}

function videochat_sfu_live_frame_relay_ensure_room_dir(string $roomId): string
{
    $roomDir = videochat_sfu_live_frame_relay_room_dir($roomId);
    if ($roomDir === '') {
        return '';
    }

    if (!is_dir($roomDir)) {
        @mkdir($roomDir, 0770, true);
    }

    return is_dir($roomDir) && is_writable($roomDir) ? $roomDir : '';
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
    $roomDir = videochat_sfu_live_frame_relay_room_dir($roomId);
    if ($roomDir === '' || !is_dir($roomDir)) {
        return;
    }

    $effectiveNowMs = $nowMs ?? videochat_sfu_now_ms();
    $files = glob($roomDir . '/*.json') ?: [];
    sort($files, SORT_STRING);
    $keptFiles = [];
    $keptBytes = 0;
    foreach ($files as $file) {
        if (!is_string($file) || !is_file($file)) {
            continue;
        }
        $basename = basename($file);
        $createdAtMs = (int) substr($basename, 0, 13);
        if ($createdAtMs <= 0 || ($effectiveNowMs - $createdAtMs) > videochat_sfu_live_frame_relay_ttl_ms()) {
            @unlink($file);
            continue;
        }
        $fileBytes = max(0, (int) @filesize($file));
        $keptBytes += $fileBytes;
        $keptFiles[] = ['path' => $file, 'bytes' => $fileBytes];
    }

    while (
        $keptFiles !== []
        && (
            count($keptFiles) > videochat_sfu_live_frame_relay_max_files_per_room()
            || $keptBytes > videochat_sfu_live_frame_relay_max_room_bytes()
        )
    ) {
        $oldest = array_shift($keptFiles);
        if (!is_array($oldest)) {
            continue;
        }
        @unlink((string) ($oldest['path'] ?? ''));
        $keptBytes -= max(0, (int) ($oldest['bytes'] ?? 0));
    }

    if ((glob($roomDir . '/*.json') ?: []) === []) {
        @rmdir($roomDir);
    }
}

function videochat_sfu_live_frame_relay_publish(string $roomId, string $publisherId, array $frame): bool
{
    $normalizedRoomId = videochat_presence_normalize_room_id($roomId, '');
    $normalizedPublisherId = trim($publisherId);
    if ($normalizedRoomId === '' || $normalizedPublisherId === '') {
        return false;
    }

    $roomDir = videochat_sfu_live_frame_relay_ensure_room_dir($normalizedRoomId);
    if ($roomDir === '') {
        return false;
    }

    $frame['type'] = 'sfu/frame';
    $frame['publisher_id'] = (string) ($frame['publisher_id'] ?? $normalizedPublisherId);
    $nowMs = videochat_sfu_now_ms();
    $record = [
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

    $filename = videochat_sfu_live_frame_relay_filename($nowMs);
    $temporaryPath = $roomDir . '/.' . $filename . '.tmp';
    $targetPath = $roomDir . '/' . $filename;
    $written = @file_put_contents($temporaryPath, $encoded, LOCK_EX);
    if (!is_int($written) || $written <= 0) {
        @unlink($temporaryPath);
        return false;
    }

    if (!@rename($temporaryPath, $targetPath)) {
        @unlink($temporaryPath);
        return false;
    }

    if (videochat_sfu_live_frame_relay_should_cleanup($normalizedRoomId, $nowMs)) {
        videochat_sfu_live_frame_relay_cleanup_room($normalizedRoomId, $nowMs);
    }

    return true;
}

/**
 * @param array<int|string, string|bool> $localPublisherIds
 * @param array<string, int> $seenFrameFiles
 * @return array<int, array<string, mixed>>
 */
function videochat_sfu_live_frame_relay_read(
    string $roomId,
    string $clientId,
    array $localPublisherIds,
    string &$cursor,
    array &$seenFrameFiles,
    int $limit = 80
): array {
    $roomDir = videochat_sfu_live_frame_relay_room_dir($roomId);
    if ($roomDir === '' || !is_dir($roomDir)) {
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
    foreach ($seenFrameFiles as $file => $seenAtMs) {
        if (($nowMs - (int) $seenAtMs) > videochat_sfu_live_frame_relay_ttl_ms()) {
            unset($seenFrameFiles[$file]);
        }
    }

    $files = glob($roomDir . '/*.json') ?: [];
    sort($files, SORT_STRING);
    $frames = [];
    foreach ($files as $file) {
        if (count($frames) >= $limit) {
            break;
        }
        if (!is_string($file) || !is_file($file)) {
            continue;
        }
        $basename = basename($file);
        // Every SFU subscriber is also usually a publisher. A single filename
        // watermark must not advance over self/local frames, otherwise a remote
        // frame that becomes visible slightly later can be skipped forever.
        if (isset($seenFrameFiles[$basename])) {
            continue;
        }
        $seenFrameFiles[$basename] = $nowMs;
        $createdAtMs = (int) substr($basename, 0, 13);
        if ($createdAtMs <= 0 || ($nowMs - $createdAtMs) > videochat_sfu_live_frame_relay_ttl_ms()) {
            continue;
        }
        $decoded = json_decode((string) @file_get_contents($file), true);
        if (!is_array($decoded)) {
            continue;
        }
        $publisherId = trim((string) ($decoded['publisher_id'] ?? ''));
        if ($publisherId === '' || $publisherId === $normalizedClientId || isset($localPublisherLookup[$publisherId])) {
            continue;
        }
        $frame = $decoded['frame'] ?? null;
        if (!is_array($frame) || strtolower(trim((string) ($frame['type'] ?? ''))) !== 'sfu/frame') {
            continue;
        }
        $frame['publisher_id'] = (string) ($frame['publisher_id'] ?? $publisherId);
        $frame['live_relay_age_ms'] = max(0, $nowMs - $createdAtMs);
        if ($cursor === '' || strcmp($basename, $cursor) > 0) {
            $cursor = $basename;
        }
        $frames[] = $frame;
    }

    return $frames;
}

/**
 * @param array<int|string, string|bool> $localPublisherIds
 * @param array<string, int> $seenFrameFiles
 */
function videochat_sfu_live_frame_relay_poll(
    mixed $websocket,
    string $roomId,
    string $clientId,
    array $localPublisherIds,
    string &$cursor,
    array &$seenFrameFiles
): int {
    $sentCount = 0;
    foreach (
        videochat_sfu_live_frame_relay_read(
            $roomId,
            $clientId,
            $localPublisherIds,
            $cursor,
            $seenFrameFiles,
            videochat_sfu_live_frame_relay_poll_batch_limit()
        ) as $frame
    ) {
        $subscriberSendStartedAtMs = videochat_sfu_now_ms();
        $kingFanoutStartedAtMs = max(0, (int) ($frame['king_receive_at_ms'] ?? 0));
        if ($kingFanoutStartedAtMs > 0) {
            $frame['subscriber_send_latency_ms'] = max(0, $subscriberSendStartedAtMs - $kingFanoutStartedAtMs);
        }
        if (videochat_sfu_send_outbound_message($websocket, $frame, [
            'sfu_send_path' => 'live_relay_poll',
            'room_id' => $roomId,
            'subscriber_id' => $clientId,
            'worker_pid' => getmypid(),
            'subscriber_send_latency_ms' => (float) ($frame['subscriber_send_latency_ms'] ?? 0),
            'live_relay_age_ms' => (float) ($frame['live_relay_age_ms'] ?? 0),
        ])) {
            $sentCount++;
        }
    }

    return $sentCount;
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
    int &$cursor
): int {
    $sentCount = 0;
    foreach (
        videochat_sfu_fetch_buffered_frames(
            $pdo,
            $roomId,
            $clientId,
            $localPublisherIds,
            $cursor,
            videochat_sfu_frame_buffer_poll_batch_limit()
        ) as $frame
    ) {
        $subscriberSendStartedAtMs = videochat_sfu_now_ms();
        $kingFanoutStartedAtMs = max(0, (int) ($frame['king_receive_at_ms'] ?? 0));
        if ($kingFanoutStartedAtMs > 0) {
            $frame['subscriber_send_latency_ms'] = max(0, $subscriberSendStartedAtMs - $kingFanoutStartedAtMs);
        }
        if (videochat_sfu_send_outbound_message($websocket, $frame, [
            'sfu_send_path' => 'sqlite_frame_buffer_poll',
            'room_id' => $roomId,
            'subscriber_id' => $clientId,
            'worker_pid' => getmypid(),
            'subscriber_send_latency_ms' => (float) ($frame['subscriber_send_latency_ms'] ?? 0),
            'sqlite_buffer_age_ms' => (float) ($frame['sqlite_buffer_age_ms'] ?? 0),
        ])) {
            $sentCount++;
        }
    }

    return $sentCount;
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
