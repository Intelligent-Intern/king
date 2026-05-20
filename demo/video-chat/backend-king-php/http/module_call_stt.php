<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/calls/call_stt.php';

function videochat_call_stt_authenticated_user(array $apiAuthContext): array
{
    $user = is_array($apiAuthContext['user'] ?? null) ? $apiAuthContext['user'] : [];

    return [
        'id' => (int) ($user['id'] ?? 0),
        'role' => (string) ($user['role'] ?? 'user'),
    ];
}

function videochat_call_stt_request_audio_bytes(array $request): string
{
    $body = $request['body'] ?? '';
    return is_string($body) ? $body : '';
}

function videochat_call_stt_request_chunk_id(array $request): string
{
    $headers = is_array($request['headers'] ?? null) ? $request['headers'] : [];
    foreach ($headers as $name => $value) {
        if (strtolower((string) $name) !== 'x-call-stt-chunk-id') {
            continue;
        }
        $candidate = trim((string) $value);
        if ($candidate !== '' && preg_match('/^[A-Za-z0-9._:-]{1,160}$/', $candidate) === 1) {
            return $candidate;
        }
    }

    return '';
}

function videochat_call_stt_request_content_type(array $request): string
{
    $headers = is_array($request['headers'] ?? null) ? $request['headers'] : [];
    foreach ($headers as $name => $value) {
        if (strtolower((string) $name) !== 'content-type') {
            continue;
        }
        return trim((string) $value);
    }

    return '';
}

function videochat_handle_call_stt_routes(
    string $path,
    string $method,
    array $request,
    array $apiAuthContext,
    callable $jsonResponse,
    callable $errorResponse,
    callable $decodeJsonBody,
    callable $openDatabase
): ?array {
    $auth = videochat_call_stt_authenticated_user($apiAuthContext);
    $userId = (int) ($auth['id'] ?? 0);
    $role = (string) ($auth['role'] ?? 'user');
    if ($userId <= 0) {
        return null;
    }

    if (preg_match('#^/api/calls/([A-Za-z0-9._-]{1,200})/stt$#', $path, $stateMatch) === 1) {
        $callId = (string) ($stateMatch[1] ?? '');
        $config = videochat_stt_config();

        if ($method === 'GET') {
            try {
                $result = videochat_stt_read_call_state($openDatabase(), $callId, $userId, $role, $config);
            } catch (Throwable) {
                return $errorResponse(500, 'call_stt_state_failed', 'Could not load call STT state.', ['reason' => 'internal_error']);
            }

            if (!(bool) ($result['ok'] ?? false)) {
                $reason = (string) ($result['reason'] ?? 'internal_error');
                if ($reason === 'not_found') {
                    return $errorResponse(404, 'calls_not_found', 'The requested call does not exist.', ['call_id' => $callId]);
                }
                if ($reason === 'forbidden') {
                    return $errorResponse(403, 'calls_forbidden', 'You are not allowed to view this call.', ['call_id' => $callId]);
                }

                return $errorResponse(500, 'call_stt_state_failed', 'Could not load call STT state.', ['reason' => $reason]);
            }

            return $jsonResponse(200, [
                'status' => 'ok',
                'result' => [
                    'state' => 'ready',
                    'stt' => $result['state'] ?? null,
                    'call' => $result['call'] ?? null,
                ],
                'time' => gmdate('c'),
            ]);
        }

        if ($method !== 'PATCH') {
            return $errorResponse(405, 'method_not_allowed', 'Use GET or PATCH for /api/calls/{id}/stt.', ['allowed_methods' => ['GET', 'PATCH']]);
        }

        [$payload, $decodeError] = $decodeJsonBody($request);
        if (!is_array($payload)) {
            return $errorResponse(400, 'call_stt_invalid_request_body', 'Call STT payload must be a JSON object.', ['reason' => $decodeError]);
        }

        try {
            $result = videochat_stt_set_call_state($openDatabase(), $callId, $userId, $role, $payload, $config);
        } catch (Throwable) {
            return $errorResponse(500, 'call_stt_update_failed', 'Could not update call STT state.', ['reason' => 'internal_error']);
        }

        if (!(bool) ($result['ok'] ?? false)) {
            $reason = (string) ($result['reason'] ?? 'internal_error');
            if ($reason === 'validation_failed') {
                return $errorResponse(422, 'call_stt_validation_failed', 'Call STT payload failed validation.', ['fields' => is_array($result['errors'] ?? null) ? $result['errors'] : []]);
            }
            if ($reason === 'not_found') {
                return $errorResponse(404, 'calls_not_found', 'The requested call does not exist.', ['call_id' => $callId]);
            }
            if ($reason === 'forbidden') {
                return $errorResponse(403, 'call_stt_forbidden', 'You are not allowed to control STT for this call.', ['call_id' => $callId]);
            }

            return $errorResponse(500, 'call_stt_update_failed', 'Could not update call STT state.', ['reason' => $reason]);
        }

        return $jsonResponse(200, [
            'status' => 'ok',
            'result' => [
                'state' => 'updated',
                'stt' => $result['state'] ?? null,
            ],
            'time' => gmdate('c'),
        ]);
    }

    if (preg_match('#^/api/calls/([A-Za-z0-9._-]{1,200})/stt/chunks$#', $path, $uploadMatch) !== 1) {
        return null;
    }

    if ($method !== 'POST') {
        return $errorResponse(405, 'method_not_allowed', 'Use POST for /api/calls/{id}/stt/chunks.', ['allowed_methods' => ['POST']]);
    }

    $callId = (string) ($uploadMatch[1] ?? '');
    try {
        $result = videochat_process_call_stt_chunk(
            $openDatabase(),
            $callId,
            $userId,
            $role,
            videochat_call_stt_request_audio_bytes($request),
            videochat_stt_config(),
            videochat_call_stt_request_chunk_id($request),
            videochat_call_stt_request_content_type($request)
        );
    } catch (Throwable) {
        return $errorResponse(500, 'call_stt_upload_failed', 'Could not process STT audio chunk.', ['reason' => 'internal_error']);
    }

    if (!(bool) ($result['ok'] ?? false)) {
        $reason = (string) ($result['reason'] ?? 'internal_error');
        if ($reason === 'empty_audio') {
            return $errorResponse(422, 'call_stt_empty_audio', 'STT audio chunk is empty.', []);
        }
        if ($reason === 'audio_too_large') {
            return $errorResponse(413, 'call_stt_audio_too_large', 'STT audio chunk exceeds the configured byte limit.', ['details' => is_array($result['details'] ?? null) ? $result['details'] : []]);
        }
        if ($reason === 'not_found') {
            return $errorResponse(404, 'calls_not_found', 'The requested call does not exist.', ['call_id' => $callId]);
        }
        if ($reason === 'forbidden') {
            return $errorResponse(403, 'calls_forbidden', 'You are not allowed to upload audio for this call.', ['call_id' => $callId]);
        }
        if ($reason === 'runtime_disabled') {
            return $errorResponse(503, 'call_stt_runtime_disabled', 'Call STT runtime is disabled.', []);
        }
        if ($reason === 'call_stt_disabled') {
            return $errorResponse(409, 'call_stt_disabled', 'Call STT is disabled for this call.', ['call_id' => $callId]);
        }

        return $errorResponse(500, 'call_stt_upload_failed', 'Could not process STT audio chunk.', [
            'reason' => $reason,
            'details' => is_array($result['details'] ?? null) ? $result['details'] : [],
        ]);
    }

    return $jsonResponse(200, [
        'status' => 'ok',
        'result' => [
            'state' => (string) ($result['state'] ?? 'archived'),
            'reason' => (string) ($result['reason'] ?? 'transcribed'),
            'message' => $result['message'] ?? null,
            'details' => is_array($result['details'] ?? null) ? $result['details'] : [],
        ],
        'time' => gmdate('c'),
    ]);
}
