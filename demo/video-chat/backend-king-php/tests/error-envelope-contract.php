<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/error_envelope.php';
require_once __DIR__ . '/../domain/realtime/realtime_presence.php';

function videochat_error_envelope_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[error-envelope-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $details = [
        'reason' => 'contract_test',
        'path' => '/api/protected',
    ];
    $restEnvelope = videochat_error_envelope('rbac_forbidden', 'Session role is not allowed.', $details, '2026-04-19T21:00:00+00:00');
    videochat_error_envelope_contract_assert(($restEnvelope['status'] ?? '') === 'error', 'REST envelope status mismatch');
    videochat_error_envelope_contract_assert((string) (($restEnvelope['error'] ?? [])['code'] ?? '') === 'rbac_forbidden', 'REST error code mismatch');
    videochat_error_envelope_contract_assert((string) (($restEnvelope['error'] ?? [])['message'] ?? '') === 'Session role is not allowed.', 'REST error message mismatch');
    videochat_error_envelope_contract_assert((($restEnvelope['error'] ?? [])['details'] ?? null) === $details, 'REST details mismatch');
    videochat_error_envelope_contract_assert((string) ($restEnvelope['time'] ?? '') === '2026-04-19T21:00:00+00:00', 'REST time mismatch');

    $wsFrame = videochat_realtime_error_frame('websocket_forbidden', 'Session role is not allowed for websocket access.', $details, '2026-04-19T21:00:01+00:00');
    videochat_error_envelope_contract_assert(($wsFrame['type'] ?? '') === 'system/error', 'WS frame type mismatch');
    videochat_error_envelope_contract_assert(($wsFrame['status'] ?? '') === 'error', 'WS envelope status mismatch');
    videochat_error_envelope_contract_assert((string) ($wsFrame['code'] ?? '') === 'websocket_forbidden', 'WS top-level code mismatch');
    videochat_error_envelope_contract_assert((string) (($wsFrame['error'] ?? [])['code'] ?? '') === 'websocket_forbidden', 'WS nested code mismatch');
    videochat_error_envelope_contract_assert((string) ($wsFrame['message'] ?? '') === 'Session role is not allowed for websocket access.', 'WS top-level message mismatch');
    videochat_error_envelope_contract_assert((string) (($wsFrame['error'] ?? [])['message'] ?? '') === 'Session role is not allowed for websocket access.', 'WS nested message mismatch');
    videochat_error_envelope_contract_assert(($wsFrame['details'] ?? null) === $details, 'WS top-level details mismatch');
    videochat_error_envelope_contract_assert((($wsFrame['error'] ?? [])['details'] ?? null) === $details, 'WS nested details mismatch');
    videochat_error_envelope_contract_assert((string) ($wsFrame['time'] ?? '') === '2026-04-19T21:00:01+00:00', 'WS time mismatch');

    $capturedPayload = null;
    $sendResult = videochat_presence_send_frame(
        null,
        [
            'type' => 'system/error',
            'code' => 'invalid_websocket_command',
            'message' => 'WebSocket command is invalid.',
            'details' => ['type' => 'unknown'],
            'time' => '2026-04-19T21:00:02+00:00',
            'legacy_context' => 'kept',
        ],
        static function (mixed $_socket, array $payload) use (&$capturedPayload): bool {
            $capturedPayload = $payload;
            return true;
        }
    );
    videochat_error_envelope_contract_assert($sendResult === true, 'presence send should use injected sender');
    videochat_error_envelope_contract_assert(is_array($capturedPayload), 'presence sender payload should be captured');
    videochat_error_envelope_contract_assert((string) (($capturedPayload['error'] ?? [])['code'] ?? '') === 'invalid_websocket_command', 'normalized sender nested code mismatch');
    videochat_error_envelope_contract_assert((string) (($capturedPayload['error'] ?? [])['message'] ?? '') === 'WebSocket command is invalid.', 'normalized sender nested message mismatch');
    videochat_error_envelope_contract_assert((($capturedPayload['error'] ?? [])['details'] ?? null) === ['type' => 'unknown'], 'normalized sender nested details mismatch');
    videochat_error_envelope_contract_assert((string) ($capturedPayload['legacy_context'] ?? '') === 'kept', 'normalized sender should preserve extra fields');

    $withoutDetails = videochat_error_envelope('not_found', 'Missing.', [], '2026-04-19T21:00:03+00:00');
    videochat_error_envelope_contract_assert(!array_key_exists('details', $withoutDetails['error'] ?? []), 'empty details should stay absent');

    fwrite(STDOUT, "[error-envelope-contract] PASS\n");
} catch (Throwable $error) {
    fwrite(STDERR, '[error-envelope-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
