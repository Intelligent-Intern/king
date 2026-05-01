<?php

declare(strict_types=1);

function videochat_error_envelope_string(mixed $value, string $fallback): string
{
    if (!is_scalar($value)) {
        return $fallback;
    }

    $normalized = trim((string) $value);
    return $normalized === '' ? $fallback : $normalized;
}

function videochat_error_envelope_time(?string $time = null): string
{
    $normalized = trim((string) $time);
    return $normalized === '' ? gmdate('c') : $normalized;
}

/**
 * @return array{status: string, error: array<string, mixed>, time: string}
 */
function videochat_error_envelope(string $code, string $message, array $details = [], ?string $time = null): array
{
    $normalizedCode = videochat_error_envelope_string($code, 'unknown_error');
    $normalizedMessage = videochat_error_envelope_string($message, 'An error occurred.');
    $error = [
        'code' => $normalizedCode,
        'message' => $normalizedMessage,
    ];
    if ($details !== []) {
        $error['details'] = $details;
    }

    return [
        'status' => 'error',
        'error' => $error,
        'time' => videochat_error_envelope_time($time),
    ];
}

/**
 * @return array<string, mixed>
 */
function videochat_realtime_error_frame(
    string $code,
    string $message,
    array $details = [],
    ?string $time = null
): array {
    $envelope = videochat_error_envelope($code, $message, $details, $time);
    $error = is_array($envelope['error'] ?? null) ? $envelope['error'] : [];

    $frame = [
        'type' => 'system/error',
        'code' => (string) ($error['code'] ?? 'unknown_error'),
        'message' => (string) ($error['message'] ?? 'An error occurred.'),
        'time' => (string) ($envelope['time'] ?? gmdate('c')),
        'status' => 'error',
        'error' => $error,
    ];
    if ($details !== []) {
        $frame['details'] = $details;
    }

    return $frame;
}

/**
 * Adds the shared REST-style error envelope to legacy realtime system/error
 * frames while preserving their existing top-level fields for older clients.
 *
 * @return array<string, mixed>
 */
function videochat_realtime_normalize_error_frame(array $payload): array
{
    if ((string) ($payload['type'] ?? '') !== 'system/error') {
        return $payload;
    }

    $details = is_array($payload['details'] ?? null) ? (array) $payload['details'] : [];
    $frame = videochat_realtime_error_frame(
        videochat_error_envelope_string($payload['code'] ?? null, 'unknown_error'),
        videochat_error_envelope_string($payload['message'] ?? null, 'An error occurred.'),
        $details,
        is_scalar($payload['time'] ?? null) ? (string) $payload['time'] : null
    );

    foreach ($payload as $key => $value) {
        if (!is_string($key) || array_key_exists($key, $frame)) {
            continue;
        }
        $frame[$key] = $value;
    }

    return $frame;
}
