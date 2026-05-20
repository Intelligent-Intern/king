<?php

declare(strict_types=1);

require_once __DIR__ . '/call_management.php';
require_once __DIR__ . '/../realtime/chat_archive.php';
require_once __DIR__ . '/../realtime/realtime_chat.php';

function videochat_stt_env_bool(string $name, bool $default): bool
{
    $value = getenv($name);
    if ($value === false || trim((string) $value) === '') {
        return $default;
    }

    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}

function videochat_stt_env_int(string $name, int $default, int $min, int $max): int
{
    $value = filter_var(getenv($name), FILTER_VALIDATE_INT);
    if (!is_int($value)) {
        $value = $default;
    }

    return max($min, min($value, $max));
}

function videochat_stt_env_string_alias(string $primary, string $fallback, string $default): string
{
    $value = getenv($primary);
    if ($value !== false && trim((string) $value) !== '') {
        return trim((string) $value);
    }

    $value = getenv($fallback);
    if ($value !== false && trim((string) $value) !== '') {
        return trim((string) $value);
    }

    return $default;
}

/**
 * @return array{enabled: bool, provider: string, command: string, ffmpeg_command: string, model_path: string, temp_dir: string, max_bytes: int, min_speech_bytes: int, timeout_seconds: int}
 */
function videochat_stt_config(): array
{
    return [
        'enabled' => videochat_stt_env_bool('VIDEOCHAT_STT_ACTIVE', videochat_stt_env_bool('VIDEOCHAT_STT_ENABLED', false)),
        'provider' => strtolower(trim((string) (getenv('VIDEOCHAT_STT_PROVIDER') ?: 'command'))),
        'command' => trim((string) (getenv('VIDEOCHAT_STT_COMMAND') ?: 'whisper-cli')),
        'ffmpeg_command' => trim((string) (getenv('VIDEOCHAT_STT_FFMPEG_COMMAND') ?: 'ffmpeg')),
        'model_path' => videochat_stt_env_string_alias('VIDEOCHAT_STT_MODEL', 'VIDEOCHAT_STT_MODEL_PATH', ''),
        'temp_dir' => trim((string) (getenv('VIDEOCHAT_STT_TEMP_DIR') ?: sys_get_temp_dir())),
        'max_bytes' => videochat_stt_env_int('VIDEOCHAT_STT_MAX_BYTES', 2 * 1024 * 1024, 1024, 32 * 1024 * 1024),
        'min_speech_bytes' => videochat_stt_env_int('VIDEOCHAT_STT_MIN_SPEECH_BYTES', 512, 0, 1024 * 1024),
        'timeout_seconds' => videochat_stt_env_int('VIDEOCHAT_STT_TIMEOUT_SECONDS', 20, 1, 120),
    ];
}

function videochat_stt_audio_mime_needs_wav_conversion(string $mimeType): bool
{
    $normalized = strtolower(trim(explode(';', $mimeType, 2)[0] ?? $mimeType));
    if ($normalized === '') {
        return false;
    }

    return in_array($normalized, ['audio/webm', 'audio/ogg', 'audio/mp4', 'video/webm'], true);
}

/**
 * @return array{ok: bool, path: string, reason: string, exit_code: int}
 */
function videochat_stt_prepare_transcription_input(array $config, string $inputPath, string $mimeType): array
{
    if (!videochat_stt_audio_mime_needs_wav_conversion($mimeType)) {
        return ['ok' => true, 'path' => $inputPath, 'reason' => 'original', 'exit_code' => 0];
    }

    $ffmpeg = trim((string) ($config['ffmpeg_command'] ?? 'ffmpeg'));
    if ($ffmpeg === '') {
        return ['ok' => false, 'path' => '', 'reason' => 'audio_conversion_not_configured', 'exit_code' => 0];
    }

    $wavPath = $inputPath . '.wav';
    $convert = videochat_stt_run_process(
        [$ffmpeg, '-hide_banner', '-loglevel', 'error', '-y', '-i', $inputPath, '-ac', '1', '-ar', '16000', '-f', 'wav', $wavPath],
        (int) ($config['timeout_seconds'] ?? 20)
    );
    if (!(bool) ($convert['ok'] ?? false) || !is_file($wavPath) || filesize($wavPath) <= 0) {
        if (is_file($wavPath)) {
            @unlink($wavPath);
        }
        return [
            'ok' => false,
            'path' => '',
            'reason' => (string) ($convert['reason'] ?? 'audio_conversion_failed'),
            'exit_code' => (int) ($convert['exit_code'] ?? 0),
        ];
    }

    return ['ok' => true, 'path' => $wavPath, 'reason' => 'converted_wav', 'exit_code' => 0];
}

/**
 * @return array{enabled: bool, provider: string, max_bytes: int, min_speech_bytes: int, temp_dir_configured: bool, model_configured: bool}
 */
function videochat_stt_public_runtime_config(array $config): array
{
    return [
        'enabled' => (bool) ($config['enabled'] ?? false),
        'provider' => (string) ($config['provider'] ?? 'command'),
        'max_bytes' => (int) ($config['max_bytes'] ?? 0),
        'min_speech_bytes' => (int) ($config['min_speech_bytes'] ?? 0),
        'temp_dir_configured' => trim((string) ($config['temp_dir'] ?? '')) !== '',
        'model_configured' => trim((string) ($config['model_path'] ?? '')) !== '',
    ];
}

function videochat_stt_bootstrap(PDO $pdo): void
{
    $pdo->exec(
        <<<'SQL'
CREATE TABLE IF NOT EXISTS call_stt_settings (
    call_id TEXT PRIMARY KEY REFERENCES calls(id) ON UPDATE CASCADE ON DELETE CASCADE,
    enabled INTEGER NOT NULL DEFAULT 0 CHECK (enabled IN (0, 1)),
    updated_by_user_id INTEGER REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now')),
    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%fZ', 'now'))
)
SQL
    );
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_call_stt_settings_enabled ON call_stt_settings(enabled)');
}

function videochat_stt_can_control_call(array $call, int $userId, string $role): bool
{
    if (videochat_normalize_role_slug($role) === 'admin') {
        return true;
    }
    if ($userId > 0 && (int) (($call['owner'] ?? [])['user_id'] ?? 0) === $userId) {
        return true;
    }

    foreach ((array) (($call['participants'] ?? [])['internal'] ?? []) as $participant) {
        if ((int) ($participant['user_id'] ?? 0) !== $userId) {
            continue;
        }
        $callRole = videochat_normalize_call_participant_role((string) ($participant['call_role'] ?? 'participant'));
        return in_array($callRole, ['owner', 'moderator'], true);
    }

    return false;
}

function videochat_stt_can_upload_for_call(array $call, int $userId, string $role): bool
{
    if (videochat_stt_can_control_call($call, $userId, $role)) {
        return true;
    }

    return (bool) ($call['my_participation'] ?? false);
}

/**
 * @return array{enabled: bool, call_id: string, updated_by_user_id: ?int, updated_at: ?string, created_at: ?string}
 */
function videochat_stt_get_call_state(PDO $pdo, string $callId): array
{
    videochat_stt_bootstrap($pdo);
    $statement = $pdo->prepare(
        <<<'SQL'
SELECT call_id, enabled, updated_by_user_id, updated_at, created_at
FROM call_stt_settings
WHERE call_id = :call_id
LIMIT 1
SQL
    );
    $statement->execute([':call_id' => trim($callId)]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    if (!is_array($row)) {
        return [
            'enabled' => false,
            'call_id' => trim($callId),
            'updated_by_user_id' => null,
            'updated_at' => null,
            'created_at' => null,
        ];
    }

    return [
        'enabled' => (int) ($row['enabled'] ?? 0) === 1,
        'call_id' => (string) ($row['call_id'] ?? trim($callId)),
        'updated_by_user_id' => $row['updated_by_user_id'] === null ? null : (int) $row['updated_by_user_id'],
        'updated_at' => is_string($row['updated_at'] ?? null) ? (string) $row['updated_at'] : null,
        'created_at' => is_string($row['created_at'] ?? null) ? (string) $row['created_at'] : null,
    ];
}

/**
 * @return array{ok: bool, reason: string, state: array<string, mixed>|null, call: array<string, mixed>|null}
 */
function videochat_stt_read_call_state(PDO $pdo, string $callId, int $userId, string $role, array $config): array
{
    $callFetch = videochat_get_call_for_user($pdo, $callId, $userId, $role);
    if (!(bool) ($callFetch['ok'] ?? false)) {
        return ['ok' => false, 'reason' => (string) ($callFetch['reason'] ?? 'not_found'), 'state' => null, 'call' => null];
    }

    $call = is_array($callFetch['call'] ?? null) ? $callFetch['call'] : [];
    $state = videochat_stt_get_call_state($pdo, (string) ($call['id'] ?? $callId));
    $state['runtime_config'] = videochat_stt_public_runtime_config($config);
    $state['can_control'] = videochat_stt_can_control_call($call, $userId, $role);

    return ['ok' => true, 'reason' => 'ok', 'state' => $state, 'call' => $call];
}

/**
 * @return array{ok: bool, reason: string, errors: array<string, string>, state: array<string, mixed>|null}
 */
function videochat_stt_set_call_state(PDO $pdo, string $callId, int $userId, string $role, array $payload, array $config): array
{
    $callFetch = videochat_get_call_for_user($pdo, $callId, $userId, $role);
    if (!(bool) ($callFetch['ok'] ?? false)) {
        return ['ok' => false, 'reason' => (string) ($callFetch['reason'] ?? 'not_found'), 'errors' => [], 'state' => null];
    }

    $call = is_array($callFetch['call'] ?? null) ? $callFetch['call'] : [];
    if (!videochat_stt_can_control_call($call, $userId, $role)) {
        return ['ok' => false, 'reason' => 'forbidden', 'errors' => [], 'state' => null];
    }
    if (!array_key_exists('enabled', $payload) || !is_bool($payload['enabled'])) {
        return ['ok' => false, 'reason' => 'validation_failed', 'errors' => ['enabled' => 'must_be_boolean'], 'state' => null];
    }

    videochat_stt_bootstrap($pdo);
    $now = gmdate('c');
    $statement = $pdo->prepare(
        <<<'SQL'
INSERT INTO call_stt_settings(call_id, enabled, updated_by_user_id, updated_at, created_at)
VALUES(:call_id, :enabled, :updated_by_user_id, :updated_at, :created_at)
ON CONFLICT(call_id) DO UPDATE SET
    enabled = excluded.enabled,
    updated_by_user_id = excluded.updated_by_user_id,
    updated_at = excluded.updated_at
SQL
    );
    $statement->execute([
        ':call_id' => (string) ($call['id'] ?? $callId),
        ':enabled' => (bool) $payload['enabled'] ? 1 : 0,
        ':updated_by_user_id' => $userId,
        ':updated_at' => $now,
        ':created_at' => $now,
    ]);

    $state = videochat_stt_get_call_state($pdo, (string) ($call['id'] ?? $callId));
    $state['runtime_config'] = videochat_stt_public_runtime_config($config);
    $state['can_control'] = true;

    return ['ok' => true, 'reason' => 'updated', 'errors' => [], 'state' => $state];
}

function videochat_stt_speech_byte_count(string $audioBytes): int
{
    $count = 0;
    $length = strlen($audioBytes);
    for ($offset = 0; $offset < $length; $offset++) {
        $byte = ord($audioBytes[$offset]);
        if ($byte !== 0 && $byte !== 128 && $byte !== 255) {
            $count++;
        }
    }

    return $count;
}

/**
 * @return array<int, string>
 */
function videochat_stt_command_argv(array $config, string $audioPath): array
{
    $command = trim((string) ($config['command'] ?? ''));
    if ($command === '') {
        return [];
    }

    $tokens = str_getcsv($command, ' ', '"', '\\');
    $argv = [];
    $hasPlaceholder = false;
    foreach ($tokens as $token) {
        $part = trim((string) $token);
        if ($part === '') {
            continue;
        }
        if (str_contains($part, '{audio}') || str_contains($part, '{model}')) {
            $hasPlaceholder = true;
        }
        $argv[] = str_replace(['{audio}', '{model}'], [$audioPath, (string) ($config['model_path'] ?? '')], $part);
    }

    if ($hasPlaceholder) {
        return $argv;
    }

    $modelPath = trim((string) ($config['model_path'] ?? ''));
    $binary = strtolower(basename((string) ($argv[0] ?? '')));
    if (str_contains($binary, 'whisper') && $modelPath !== '') {
        return array_merge($argv, ['-m', $modelPath, '-f', $audioPath]);
    }

    $argv[] = $audioPath;
    if ($modelPath !== '') {
        $argv[] = $modelPath;
    }

    return $argv;
}

/**
 * @return array{ok: bool, stdout: string, stderr: string, exit_code: int, reason: string}
 */
function videochat_stt_run_process(array $argv, int $timeoutSeconds): array
{
    $pipes = [];
    $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        return ['ok' => false, 'stdout' => '', 'stderr' => '', 'exit_code' => 0, 'reason' => 'command_start_failed'];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + max(1, $timeoutSeconds);

    while (true) {
        $status = proc_get_status($process);
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        if (!(bool) ($status['running'] ?? false)) {
            break;
        }
        if (microtime(true) >= $deadline) {
            proc_terminate($process);
            foreach ([1, 2] as $index) {
                if (is_resource($pipes[$index])) {
                    fclose($pipes[$index]);
                }
            }
            proc_close($process);
            return ['ok' => false, 'stdout' => $stdout, 'stderr' => $stderr, 'exit_code' => 124, 'reason' => 'command_timeout'];
        }
        usleep(30_000);
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'ok' => $exitCode === 0,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'exit_code' => is_int($exitCode) ? $exitCode : 0,
        'reason' => $exitCode === 0 ? 'ok' : 'command_failed',
    ];
}

/**
 * @return array{ok: bool, text: string, reason: string, exit_code: int}
 */
function videochat_stt_transcribe_file(array $config, string $audioPath): array
{
    $provider = strtolower(trim((string) ($config['provider'] ?? 'command')));
    if (!in_array($provider, ['command', 'local_command'], true)) {
        return ['ok' => false, 'text' => '', 'reason' => 'provider_not_supported', 'exit_code' => 0];
    }

    $argv = videochat_stt_command_argv($config, $audioPath);
    if ($argv === []) {
        return ['ok' => false, 'text' => '', 'reason' => 'command_not_configured', 'exit_code' => 0];
    }

    $process = videochat_stt_run_process($argv, (int) ($config['timeout_seconds'] ?? 20));
    if (!(bool) ($process['ok'] ?? false)) {
        return [
            'ok' => false,
            'text' => '',
            'reason' => (string) ($process['reason'] ?? 'command_failed'),
            'exit_code' => (int) ($process['exit_code'] ?? 0),
        ];
    }

    $raw = trim((string) ($process['stdout'] ?? ''));
    $decoded = json_decode($raw, true);
    $text = is_array($decoded) && is_string($decoded['text'] ?? null) ? trim((string) $decoded['text']) : $raw;
    if ($text === '') {
        return ['ok' => false, 'text' => '', 'reason' => 'empty_transcript', 'exit_code' => (int) ($process['exit_code'] ?? 0)];
    }

    return ['ok' => true, 'text' => $text, 'reason' => 'transcribed', 'exit_code' => (int) ($process['exit_code'] ?? 0)];
}

function videochat_stt_message_id(string $callId, int $userId, string $chunkId, string $text): string
{
    return 'chat_stt_' . substr(hash('sha256', $callId . "\n" . $userId . "\n" . $chunkId . "\n" . $text), 0, 32);
}

/**
 * @return array<string, mixed>
 */
function videochat_stt_chat_event(array $call, array $user, string $chunkId, string $text, int $serverUnixMs, array $metadata): array
{
    $callId = (string) ($call['id'] ?? '');
    $messageId = videochat_stt_message_id($callId, (int) ($user['id'] ?? 0), $chunkId, $text);
    $serverTime = gmdate('c', (int) floor($serverUnixMs / 1000));

    return [
        'type' => 'chat/message',
        'room_id' => (string) ($call['room_id'] ?? 'lobby'),
        'source' => 'call_stt',
        'message' => [
            'id' => $messageId,
            'client_message_id' => $chunkId,
            'text' => $text,
            'attachments' => [],
            'sender' => [
                'user_id' => (int) ($user['id'] ?? 0),
                'display_name' => (string) ($user['display_name'] ?? 'Call participant'),
                'role' => videochat_normalize_role_slug((string) ($user['role'] ?? 'user')),
            ],
            'server_unix_ms' => $serverUnixMs,
            'server_time' => $serverTime,
            'source' => 'call_stt',
            'metadata' => $metadata,
        ],
        'time' => $serverTime,
    ];
}

/**
 * @return array{ok: bool, reason: string, state: string, message: array<string, mixed>|null, details: array<string, mixed>}
 */
function videochat_process_call_stt_chunk(
    PDO $pdo,
    string $callId,
    int $userId,
    string $role,
    string $audioBytes,
    array $config,
    string $chunkId = '',
    string $mimeType = ''
): array {
    if (!(bool) ($config['enabled'] ?? false)) {
        return ['ok' => false, 'reason' => 'runtime_disabled', 'state' => 'disabled', 'message' => null, 'details' => []];
    }

    $byteLength = strlen($audioBytes);
    if ($byteLength <= 0) {
        return ['ok' => false, 'reason' => 'empty_audio', 'state' => 'rejected', 'message' => null, 'details' => []];
    }
    $maxBytes = (int) ($config['max_bytes'] ?? 0);
    if ($maxBytes > 0 && $byteLength > $maxBytes) {
        return ['ok' => false, 'reason' => 'audio_too_large', 'state' => 'rejected', 'message' => null, 'details' => ['max_bytes' => $maxBytes, 'received_bytes' => $byteLength]];
    }

    $callFetch = videochat_get_call_for_user($pdo, $callId, $userId, $role);
    if (!(bool) ($callFetch['ok'] ?? false)) {
        return ['ok' => false, 'reason' => (string) ($callFetch['reason'] ?? 'not_found'), 'state' => 'rejected', 'message' => null, 'details' => []];
    }

    $call = is_array($callFetch['call'] ?? null) ? $callFetch['call'] : [];
    if (!videochat_stt_can_upload_for_call($call, $userId, $role)) {
        return ['ok' => false, 'reason' => 'forbidden', 'state' => 'rejected', 'message' => null, 'details' => ['call_id' => (string) ($call['id'] ?? $callId)]];
    }

    $state = videochat_stt_get_call_state($pdo, (string) ($call['id'] ?? $callId));
    if (!(bool) ($state['enabled'] ?? false)) {
        return ['ok' => false, 'reason' => 'call_stt_disabled', 'state' => 'disabled', 'message' => null, 'details' => ['call_id' => (string) ($call['id'] ?? $callId)]];
    }

    $speechBytes = videochat_stt_speech_byte_count($audioBytes);
    $minSpeechBytes = (int) ($config['min_speech_bytes'] ?? 0);
    if ($speechBytes < $minSpeechBytes) {
        return ['ok' => true, 'reason' => 'min_speech_filter', 'state' => 'filtered', 'message' => null, 'details' => ['speech_bytes' => $speechBytes, 'min_speech_bytes' => $minSpeechBytes]];
    }

    $tempDir = (string) ($config['temp_dir'] ?? sys_get_temp_dir());
    if ($tempDir === '' || (!is_dir($tempDir) && !mkdir($tempDir, 0700, true) && !is_dir($tempDir)) || !is_writable($tempDir)) {
        return ['ok' => false, 'reason' => 'temp_dir_unavailable', 'state' => 'rejected', 'message' => null, 'details' => []];
    }

    $tempPath = tempnam($tempDir, 'videochat-stt-');
    if (!is_string($tempPath) || $tempPath === '') {
        return ['ok' => false, 'reason' => 'temp_file_failed', 'state' => 'rejected', 'message' => null, 'details' => []];
    }

    try {
        $written = file_put_contents($tempPath, $audioBytes, LOCK_EX);
        if ($written !== $byteLength) {
            return ['ok' => false, 'reason' => 'temp_write_failed', 'state' => 'rejected', 'message' => null, 'details' => []];
        }
        $prepared = videochat_stt_prepare_transcription_input($config, $tempPath, $mimeType);
        if (!(bool) ($prepared['ok'] ?? false)) {
            return [
                'ok' => false,
                'reason' => (string) ($prepared['reason'] ?? 'audio_conversion_failed'),
                'state' => 'failed',
                'message' => null,
                'details' => ['exit_code' => (int) ($prepared['exit_code'] ?? 0)],
            ];
        }
        $transcript = videochat_stt_transcribe_file($config, (string) ($prepared['path'] ?? $tempPath));
    } finally {
        $wavPath = $tempPath . '.wav';
        if (is_file($wavPath)) {
            @unlink($wavPath);
        }
        if (is_file($tempPath)) {
            @unlink($tempPath);
        }
    }

    if (!(bool) ($transcript['ok'] ?? false)) {
        return ['ok' => false, 'reason' => (string) ($transcript['reason'] ?? 'transcription_failed'), 'state' => 'failed', 'message' => null, 'details' => ['exit_code' => (int) ($transcript['exit_code'] ?? 0)]];
    }

    $userIdentity = videochat_active_user_identity($pdo, $userId);
    $user = is_array($userIdentity)
        ? ['id' => $userId, 'display_name' => (string) ($userIdentity['display_name'] ?? ''), 'role' => $role]
        : ['id' => $userId, 'display_name' => 'Call participant', 'role' => $role];
    $effectiveChunkId = trim($chunkId) !== ''
        ? trim($chunkId)
        : 'stt:' . substr(hash('sha256', (string) ($call['id'] ?? $callId) . "\n" . $userId . "\n" . $audioBytes), 0, 24);
    $event = videochat_stt_chat_event(
        $call,
        $user,
        $effectiveChunkId,
        trim((string) ($transcript['text'] ?? '')),
        (int) floor(microtime(true) * 1000),
        [
            'provider' => (string) ($config['provider'] ?? 'command'),
            'audio_bytes' => $byteLength,
            'speech_bytes' => $speechBytes,
            'mime_type' => strtolower(trim(explode(';', $mimeType, 2)[0] ?? $mimeType)),
        ]
    );

    $archive = videochat_chat_archive_append_message($pdo, (string) ($call['id'] ?? $callId), (string) ($call['room_id'] ?? 'lobby'), $event);
    if (!(bool) ($archive['ok'] ?? false)) {
        return ['ok' => false, 'reason' => (string) ($archive['reason'] ?? 'archive_failed'), 'state' => 'failed', 'message' => null, 'details' => []];
    }

    $brokerPublished = false;
    try {
        videochat_chat_broker_bootstrap($pdo);
        $brokerPublished = videochat_chat_broker_insert_event($pdo, (string) ($call['room_id'] ?? 'lobby'), $event);
    } catch (Throwable) {
        $brokerPublished = false;
    }

    return [
        'ok' => true,
        'reason' => 'transcribed',
        'state' => 'archived',
        'message' => $event,
        'details' => ['audio_bytes' => $byteLength, 'speech_bytes' => $speechBytes, 'broker_published' => $brokerPublished],
    ];
}
