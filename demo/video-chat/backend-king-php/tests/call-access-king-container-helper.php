<?php

declare(strict_types=1);

require_once __DIR__ . '/call-access-king-participants-helper.php';

function videochat_iam_king_container_name(string $containerId): string
{
    $normalized = strtolower(trim($containerId));
    $normalized = (string) preg_replace('/[^a-z0-9_.-]+/', '-', $normalized);
    $normalized = trim($normalized, '-');
    return 'king-' . ($normalized !== '' ? $normalized : 'participant');
}

function videochat_iam_king_container_media_profile(string $containerId, string $identityKind): array
{
    $seed = 'king-ci-dummy-media:' . videochat_iam_king_container_name($containerId) . ':' . strtolower(trim($identityKind));
    $hash = hash('sha256', $seed);

    return [
        'mode' => 'deterministic_dummy_media',
        'seed_hash' => $hash,
        'audio' => [
            'codec' => 'pcm_s16le',
            'sample_rate_hz' => 48000,
            'channels' => 1,
            'frame_samples' => 960,
            'tone_hz' => 220 + (hexdec(substr($hash, 0, 2)) % 440),
        ],
        'video' => [
            'codec' => 'raw_rgba',
            'width' => 320,
            'height' => 180,
            'fps' => 15,
            'frame_pattern_hash' => substr($hash, 0, 16),
        ],
    ];
}

function videochat_iam_king_container_create(string $containerId, string $identityKind): array
{
    return [
        'container_id' => $containerId,
        'container_name' => videochat_iam_king_container_name($containerId),
        'identity_kind' => $identityKind,
        'identity_state' => [],
        'connection' => null,
        'connected' => false,
        'terminated' => false,
        'network_blocked' => false,
        'call_id' => '',
        'room_id' => '',
        'user_id' => 0,
        'display_name' => '',
        'session_id' => '',
        'tenant_id' => 0,
        'media' => videochat_iam_king_container_media_profile($containerId, $identityKind),
        'logs' => [],
    ];
}

function videochat_iam_king_container_safe_log_fields(array $fields): array
{
    $safe = [];
    foreach ($fields as $key => $value) {
        $normalizedKey = strtolower((string) $key);
        if (str_contains($normalizedKey, 'email') || str_contains($normalizedKey, 'token') || str_contains($normalizedKey, 'access')) {
            continue;
        }
        if (is_array($value)) {
            $safe[$key] = videochat_iam_king_container_safe_log_fields($value);
            continue;
        }
        if (is_bool($value) || is_int($value) || is_float($value) || is_string($value) || $value === null) {
            $stringValue = is_string($value) ? $value : '';
            if ($stringValue !== '' && (str_contains($stringValue, '@') || str_starts_with($stringValue, 'sess_'))) {
                $safe[$key] = '[redacted]';
            } else {
                $safe[$key] = $value;
            }
        }
    }

    return $safe;
}

function videochat_iam_king_container_log(array &$container, string $eventType, array $fields = []): void
{
    $container['logs'][] = [
        'sequence' => count((array) ($container['logs'] ?? [])) + 1,
        'container_name' => (string) ($container['container_name'] ?? ''),
        'event_type' => $eventType,
        'identity_kind' => (string) ($container['identity_kind'] ?? ''),
        'call_id' => (string) ($container['call_id'] ?? ''),
        'room_id' => (string) ($container['room_id'] ?? ''),
        'user_id' => (int) ($container['user_id'] ?? 0),
        'fields' => videochat_iam_king_container_safe_log_fields($fields),
    ];
}

function videochat_iam_king_container_identity_state(array $container, array $connection = []): array
{
    $effectiveConnection = $connection !== [] ? $connection : (is_array($container['connection'] ?? null) ? $container['connection'] : []);

    return [
        'container_name' => (string) ($container['container_name'] ?? ''),
        'identity_kind' => (string) ($container['identity_kind'] ?? ''),
        'user_id' => (int) ($container['user_id'] ?? ($effectiveConnection['user_id'] ?? 0)),
        'display_name' => (string) ($container['display_name'] ?? ($effectiveConnection['display_name'] ?? '')),
        'session_id' => (string) ($container['session_id'] ?? ($effectiveConnection['session_id'] ?? '')),
        'auth_role' => videochat_normalize_role_slug((string) ($effectiveConnection['role'] ?? 'user')),
        'call_role' => videochat_normalize_call_participant_role((string) ($effectiveConnection['call_role'] ?? 'participant')),
        'can_moderate_call' => (bool) ($effectiveConnection['can_moderate_call'] ?? false),
        'connected' => (bool) ($container['connected'] ?? false),
        'terminated' => (bool) ($container['terminated'] ?? false),
    ];
}

function videochat_iam_king_container_join(
    PDO $pdo,
    array &$presenceState,
    array &$container,
    string $roomId,
    string $callId,
    int $userId,
    string $displayName,
    string $authRole,
    string $callRole,
    int $tenantId,
    int $nowMs,
    string $sessionId = ''
): array {
    $suffix = (string) ($container['container_id'] ?? 'participant') . '-' . (count((array) ($container['logs'] ?? [])) + 1);
    $connection = videochat_iam_king_participant_client(
        $pdo,
        $presenceState,
        $roomId,
        $callId,
        $userId,
        $displayName,
        $authRole,
        $callRole,
        $tenantId,
        $nowMs,
        $suffix
    );
    if ($sessionId !== '') {
        $connection['session_id'] = $sessionId;
    }

    $container['connection'] = $connection;
    $container['connected'] = true;
    $container['terminated'] = false;
    $container['network_blocked'] = false;
    $container['call_id'] = $callId;
    $container['room_id'] = $roomId;
    $container['user_id'] = $userId;
    $container['display_name'] = $displayName;
    $container['session_id'] = $sessionId;
    $container['tenant_id'] = $tenantId;
    $container['identity_state'] = videochat_iam_king_container_identity_state($container, $connection);
    videochat_iam_king_container_log($container, 'join', [
        'now_ms' => $nowMs,
        'call_role' => videochat_normalize_call_participant_role($callRole),
        'auth_role' => videochat_normalize_role_slug($authRole),
    ]);
    videochat_iam_king_container_log($container, 'media_state', [
        'state' => 'deterministic_dummy_media_ready',
        'audio_codec' => (string) (($container['media']['audio'] ?? [])['codec'] ?? ''),
        'video_codec' => (string) (($container['media']['video'] ?? [])['codec'] ?? ''),
    ]);

    return $connection;
}

function videochat_iam_king_container_dummy_media_frames(array $container, int $frameCount): array
{
    $profileHash = (string) (($container['media'] ?? [])['seed_hash'] ?? '');
    $frameCount = max(0, $frameCount);
    $frames = [];
    for ($index = 0; $index < $frameCount; $index++) {
        $frames[] = [
            'sequence' => $index + 1,
            'audio_hash' => hash('sha256', $profileHash . ':audio:' . $index),
            'video_hash' => hash('sha256', $profileHash . ':video:' . $index),
        ];
    }

    return $frames;
}

function videochat_iam_king_container_stream_dummy_media(array &$container, int $frameCount): array
{
    $frames = videochat_iam_king_container_dummy_media_frames($container, $frameCount);
    videochat_iam_king_container_log($container, 'media_stream', [
        'mode' => 'deterministic_dummy_media',
        'frame_count' => count($frames),
        'first_video_hash' => (string) (($frames[0] ?? [])['video_hash'] ?? ''),
    ]);

    return $frames;
}

function videochat_iam_king_container_graceful_disconnect(
    PDO $pdo,
    array &$presenceState,
    array &$container,
    int $nowMs
): void {
    $connection = is_array($container['connection'] ?? null) ? $container['connection'] : [];
    if ($connection !== []) {
        $container['connection'] = videochat_iam_king_participant_leave($pdo, $presenceState, $connection, $nowMs);
    }
    $container['connected'] = false;
    $container['network_blocked'] = false;
    $container['identity_state'] = videochat_iam_king_container_identity_state($container);
    videochat_iam_king_container_log($container, 'disconnect', ['mode' => 'graceful', 'now_ms' => $nowMs]);
}

function videochat_iam_king_container_abrupt_disconnect(
    PDO $pdo,
    array &$presenceState,
    array &$container,
    int $nowMs
): void {
    $connection = is_array($container['connection'] ?? null) ? $container['connection'] : [];
    if ($connection !== []) {
        $connectionId = (string) ($connection['connection_id'] ?? '');
        $removed = videochat_presence_remove_connection($presenceState, $connectionId);
        $effectiveConnection = is_array($removed) ? $removed : $connection;
        videochat_realtime_remove_call_presence(static fn (): PDO => $pdo, $effectiveConnection);
        videochat_iam_king_participant_set_times(
            $pdo,
            videochat_realtime_connection_call_id($effectiveConnection),
            (int) ($effectiveConnection['user_id'] ?? 0),
            null,
            $nowMs
        );
        $container['connection'] = $effectiveConnection;
    }
    $container['connected'] = false;
    $container['network_blocked'] = false;
    $container['identity_state'] = videochat_iam_king_container_identity_state($container);
    videochat_iam_king_container_log($container, 'disconnect', ['mode' => 'abrupt', 'now_ms' => $nowMs]);
}

function videochat_iam_king_container_network_loss(array &$container, int $lastSeenMs): int
{
    $container['network_blocked'] = true;
    videochat_iam_king_container_log($container, 'disconnect', [
        'mode' => 'network_loss',
        'last_seen_ms' => $lastSeenMs,
        'stale_at_ms' => $lastSeenMs + videochat_realtime_presence_db_ttl_ms(),
    ]);

    return $lastSeenMs + videochat_realtime_presence_db_ttl_ms();
}

function videochat_iam_king_container_reconnect_same_identity(
    PDO $pdo,
    array &$presenceState,
    array &$container,
    int $nowMs
): array {
    $identity = videochat_iam_king_container_identity_state($container);
    $userId = (int) ($identity['user_id'] ?? 0);
    if ($userId <= 0) {
        throw new RuntimeException('king container cannot reconnect without an established identity');
    }

    $connection = videochat_iam_king_container_join(
        $pdo,
        $presenceState,
        $container,
        (string) ($container['room_id'] ?? ''),
        (string) ($container['call_id'] ?? ''),
        $userId,
        (string) ($identity['display_name'] ?? ''),
        (string) ($identity['auth_role'] ?? 'user'),
        (string) ($identity['call_role'] ?? 'participant'),
        (int) (($container['tenant_id'] ?? 0) ?: (($container['connection']['tenant_id'] ?? null) ?: 1)),
        $nowMs,
        (string) ($identity['session_id'] ?? '')
    );
    videochat_iam_king_container_log($container, 'reconnect', ['mode' => 'same_identity', 'now_ms' => $nowMs]);

    return $connection;
}

function videochat_iam_king_container_call_state(PDO $pdo, array $presenceState, array $viewerContainer, int $nowMs, string $reason): array
{
    $connection = is_array($viewerContainer['connection'] ?? null) ? $viewerContainer['connection'] : [];
    if ($connection === []) {
        throw new RuntimeException('king container call state requires a connected viewer');
    }

    $snapshot = videochat_iam_king_participant_snapshot($pdo, $presenceState, $connection, $nowMs, $reason);
    $ownerAbsence = (array) (($snapshot['call_lifecycle'] ?? [])['owner_absence'] ?? []);

    return [
        'call_id' => (string) ($viewerContainer['call_id'] ?? ''),
        'room_id' => (string) ($viewerContainer['room_id'] ?? ''),
        'status' => (string) (($snapshot['call_lifecycle'] ?? [])['status'] ?? ''),
        'participant_count' => (int) ($snapshot['participant_count'] ?? 0),
        'owner_absence' => $ownerAbsence,
    ];
}

function videochat_iam_king_container_collect_logs(array $containers, string $artifactDir, string $reason): array
{
    if (!is_dir($artifactDir) && !mkdir($artifactDir, 0775, true) && !is_dir($artifactDir)) {
        throw new RuntimeException('could not create king container artifact directory: ' . $artifactDir);
    }

    $files = [];
    foreach ($containers as $container) {
        if (!is_array($container)) {
            continue;
        }
        $name = videochat_iam_king_container_name((string) ($container['container_id'] ?? 'participant'));
        $path = rtrim($artifactDir, '/') . '/' . $name . '.jsonl';
        $lines = [];
        foreach ((array) ($container['logs'] ?? []) as $entry) {
            $lines[] = json_encode($entry, JSON_UNESCAPED_SLASHES);
        }
        file_put_contents($path, implode("\n", $lines) . "\n");
        $files[] = $path;
    }

    $summaryPath = rtrim($artifactDir, '/') . '/summary.json';
    file_put_contents($summaryPath, json_encode([
        'reason' => $reason,
        'container_count' => count($files),
        'files' => array_map('basename', $files),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    $files[] = $summaryPath;

    return $files;
}

function videochat_iam_king_container_terminate(
    PDO $pdo,
    array &$presenceState,
    array &$container,
    int $nowMs
): void {
    if ((bool) ($container['connected'] ?? false) || is_array($container['connection'] ?? null)) {
        videochat_iam_king_container_abrupt_disconnect($pdo, $presenceState, $container, $nowMs);
    }
    $container['terminated'] = true;
    $container['connected'] = false;
    $container['network_blocked'] = false;
    $container['identity_state'] = videochat_iam_king_container_identity_state($container);
    videochat_iam_king_container_log($container, 'terminate', ['now_ms' => $nowMs, 'clean' => true]);
}

function videochat_iam_king_container_presence_count(PDO $pdo, string $callId): int
{
    $statement = $pdo->prepare('SELECT COUNT(*) FROM realtime_presence_connections WHERE call_id = :call_id');
    $statement->execute([':call_id' => $callId]);
    return max(0, (int) ($statement->fetchColumn() ?: 0));
}
