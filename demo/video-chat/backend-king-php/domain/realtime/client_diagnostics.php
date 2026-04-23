<?php

declare(strict_types=1);

function videochat_client_diagnostics_truncate_text(mixed $value, int $maxLength): string
{
    $normalized = trim((string) $value);
    if ($normalized === '') {
        return '';
    }

    if ($maxLength <= 0 || strlen($normalized) <= $maxLength) {
        return $normalized;
    }

    return substr($normalized, 0, $maxLength);
}

function videochat_client_diagnostics_normalize_identifier(mixed $value, string $fallback = ''): string
{
    $normalized = strtolower(videochat_client_diagnostics_truncate_text($value, 80));
    $normalized = preg_replace('/[^a-z0-9._:-]+/', '_', $normalized) ?? '';
    $normalized = trim($normalized, '._:- ');

    if ($normalized !== '') {
        return $normalized;
    }

    return $fallback;
}

function videochat_client_diagnostics_normalize_level(mixed $value): string
{
    $normalized = strtolower(trim((string) $value));
    if (in_array($normalized, ['debug', 'info', 'warning', 'error'], true)) {
        return $normalized;
    }

    if ($normalized === 'warn') {
        return 'warning';
    }

    return 'error';
}

function videochat_client_diagnostics_utf8_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return (int) mb_strlen($value, '8bit');
    }

    return strlen($value);
}

function videochat_client_diagnostics_sanitize_value(mixed $value, int $depth = 0): mixed
{
    if ($depth >= 4) {
        return '[depth_limited]';
    }

    if ($value instanceof Throwable) {
        return [
            'type' => 'throwable',
            'class' => get_class($value),
            'message' => videochat_client_diagnostics_truncate_text($value->getMessage(), 400),
            'code' => (int) $value->getCode(),
        ];
    }

    if (is_null($value) || is_bool($value) || is_int($value) || is_float($value)) {
        return $value;
    }

    if (is_string($value)) {
        return videochat_client_diagnostics_truncate_text($value, 1200);
    }

    if (is_array($value)) {
        $normalized = [];
        $count = 0;
        foreach ($value as $key => $entry) {
            if ($count >= 24) {
                $normalized['__truncated__'] = true;
                break;
            }
            $normalized[(string) $key] = videochat_client_diagnostics_sanitize_value($entry, $depth + 1);
            $count++;
        }
        return $normalized;
    }

    if (is_object($value)) {
        return videochat_client_diagnostics_sanitize_value(get_object_vars($value), $depth + 1);
    }

    return videochat_client_diagnostics_truncate_text((string) $value, 400);
}

function videochat_client_diagnostics_encode_payload(mixed $payload, int $maxBytes = 8192): string
{
    $sanitized = videochat_client_diagnostics_sanitize_value($payload);
    $encoded = json_encode($sanitized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (is_string($encoded) && videochat_client_diagnostics_utf8_length($encoded) <= $maxBytes) {
        return $encoded;
    }

    $fallback = json_encode([
        'truncated' => true,
        'preview' => videochat_client_diagnostics_truncate_text($encoded ?: '', 2000),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return is_string($fallback) ? $fallback : '{"truncated":true}';
}

/**
 * @return array{
 *   ok: bool,
 *   entries?: array<int, array<string, mixed>>,
 *   errors?: array<string, string>,
 *   invalid_count?: int,
 *   dropped_count?: int
 * }
 */
function videochat_client_diagnostics_normalize_batch(array $payload, int $authenticatedUserId, string $sessionId): array
{
    $entries = $payload['entries'] ?? null;
    if (!is_array($entries) || $entries === []) {
        return [
            'ok' => false,
            'errors' => [
                'entries' => 'At least one diagnostics entry is required.',
            ],
        ];
    }

    $accepted = [];
    $errors = [];
    $droppedCount = 0;
    $maxEntries = 20;
    if (count($entries) > $maxEntries) {
        $droppedCount += count($entries) - $maxEntries;
        $entries = array_slice($entries, 0, $maxEntries);
    }

    foreach ($entries as $index => $row) {
        if (!is_array($row)) {
            $errors["entries.{$index}"] = 'Each diagnostics entry must be an object.';
            continue;
        }

        $eventType = videochat_client_diagnostics_normalize_identifier(
            $row['event_type'] ?? $row['eventType'] ?? '',
            ''
        );
        if ($eventType === '') {
            $errors["entries.{$index}.event_type"] = 'Diagnostics event_type is required.';
            continue;
        }

        $accepted[] = [
            'user_id' => $authenticatedUserId,
            'session_id' => videochat_client_diagnostics_truncate_text(
                $row['session_id'] ?? $row['sessionId'] ?? $sessionId,
                160
            ),
            'call_id' => videochat_client_diagnostics_truncate_text(
                $row['call_id'] ?? $row['callId'] ?? '',
                120
            ),
            'room_id' => strtolower(videochat_client_diagnostics_truncate_text(
                $row['room_id'] ?? $row['roomId'] ?? '',
                120
            )),
            'category' => videochat_client_diagnostics_normalize_identifier($row['category'] ?? 'media', 'media'),
            'level' => videochat_client_diagnostics_normalize_level($row['level'] ?? 'error'),
            'event_type' => $eventType,
            'code' => videochat_client_diagnostics_normalize_identifier($row['code'] ?? '', ''),
            'message' => videochat_client_diagnostics_truncate_text($row['message'] ?? '', 500),
            'payload_json' => videochat_client_diagnostics_encode_payload($row['payload'] ?? []),
            'repeat_count' => min(99, max(1, (int) ($row['repeat_count'] ?? $row['repeatCount'] ?? 1))),
            'client_time' => videochat_client_diagnostics_truncate_text(
                $row['client_time'] ?? $row['clientTime'] ?? '',
                48
            ),
        ];
    }

    if ($accepted === []) {
        return [
            'ok' => false,
            'errors' => $errors === [] ? ['entries' => 'No valid diagnostics entries were provided.'] : $errors,
            'invalid_count' => count($errors),
            'dropped_count' => $droppedCount,
        ];
    }

    return [
        'ok' => true,
        'entries' => $accepted,
        'errors' => $errors,
        'invalid_count' => count($errors),
        'dropped_count' => $droppedCount,
    ];
}

/**
 * @param array<int, array<string, mixed>> $entries
 */
function videochat_log_client_diagnostics_entries(array $entries): void
{
    foreach ($entries as $entry) {
        $payload = [
            'user_id' => (int) ($entry['user_id'] ?? 0),
            'session_id' => (string) ($entry['session_id'] ?? ''),
            'call_id' => (string) ($entry['call_id'] ?? ''),
            'room_id' => (string) ($entry['room_id'] ?? ''),
            'category' => (string) ($entry['category'] ?? 'media'),
            'level' => (string) ($entry['level'] ?? 'error'),
            'event_type' => (string) ($entry['event_type'] ?? ''),
            'code' => (string) ($entry['code'] ?? ''),
            'message' => (string) ($entry['message'] ?? ''),
            'repeat_count' => (int) ($entry['repeat_count'] ?? 1),
            'client_time' => (string) ($entry['client_time'] ?? ''),
            'payload' => json_decode((string) ($entry['payload_json'] ?? '{}'), true),
        ];

        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            $encoded = '{"event_type":"diagnostics_encode_failed"}';
        }

        error_log('[video-chat][client-diagnostics] ' . $encoded);
    }
}

/**
 * @param array<int, array<string, mixed>> $entries
 * @return array{stored_count: int}
 */
function videochat_store_client_diagnostics(PDO $pdo, array $entries): array
{
    $statement = $pdo->prepare(
        <<<'SQL'
INSERT INTO client_diagnostics (
    user_id,
    session_id,
    call_id,
    room_id,
    category,
    level,
    event_type,
    code,
    message,
    payload_json,
    repeat_count,
    client_time
) VALUES (
    :user_id,
    :session_id,
    :call_id,
    :room_id,
    :category,
    :level,
    :event_type,
    :code,
    :message,
    :payload_json,
    :repeat_count,
    :client_time
)
SQL
    );

    $storedCount = 0;
    foreach ($entries as $entry) {
        $statement->execute([
            ':user_id' => (int) ($entry['user_id'] ?? 0),
            ':session_id' => (string) ($entry['session_id'] ?? ''),
            ':call_id' => (string) ($entry['call_id'] ?? ''),
            ':room_id' => (string) ($entry['room_id'] ?? ''),
            ':category' => (string) ($entry['category'] ?? 'media'),
            ':level' => (string) ($entry['level'] ?? 'error'),
            ':event_type' => (string) ($entry['event_type'] ?? ''),
            ':code' => (string) ($entry['code'] ?? ''),
            ':message' => (string) ($entry['message'] ?? ''),
            ':payload_json' => (string) ($entry['payload_json'] ?? '{}'),
            ':repeat_count' => (int) ($entry['repeat_count'] ?? 1),
            ':client_time' => (string) ($entry['client_time'] ?? ''),
        ]);
        $storedCount++;
    }

    return [
        'stored_count' => $storedCount,
    ];
}
