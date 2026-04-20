<?php

declare(strict_types=1);

/**
 * Persist a completed inference transcript to the King object store.
 *
 * Key format is flat (King rejects slashes): transcript-{yyyymmdd}-{request_id}
 * Value is a JSON envelope capturing prompt, completion, model info, and
 * telemetry so a later GET /api/transcripts/{request_id} can return the
 * full conversation turn.
 *
 * @param array<string, mixed> $transcript
 */
function model_inference_transcript_save(string $requestId, array $transcript): bool
{
    if (!function_exists('king_object_store_put')) {
        return false;
    }

    $key = model_inference_transcript_key($requestId);
    $json = json_encode($transcript, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (!is_string($json)) {
        return false;
    }

    try {
        return king_object_store_put($key, $json) === true;
    } catch (Throwable $error) {
        return false;
    }
}

/**
 * Load a transcript by request_id from the object store.
 *
 * @return array<string, mixed>|null null when not found or unreadable
 */
function model_inference_transcript_load(string $requestId): ?array
{
    if (!function_exists('king_object_store_get')) {
        return null;
    }

    $key = model_inference_transcript_key($requestId);
    try {
        $raw = king_object_store_get($key);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    } catch (Throwable $error) {
        return null;
    }
}

/**
 * Build a flat object-store key for a transcript.
 */
function model_inference_transcript_key(string $requestId): string
{
    return 'transcript-' . gmdate('Ymd') . '-' . $requestId;
}

/**
 * Build the transcript envelope from an HTTP /api/infer response.
 *
 * @param array<string, mixed> $responseEnvelope
 * @param array<string, mixed> $validatedRequest
 * @return array<string, mixed>
 */
function model_inference_transcript_from_http(
    array $responseEnvelope,
    array $validatedRequest
): array {
    return [
        'request_id' => (string) ($responseEnvelope['request_id'] ?? ''),
        'session_id' => (string) ($responseEnvelope['session_id'] ?? ''),
        'transport' => 'http',
        'model' => $responseEnvelope['model'] ?? [],
        'prompt' => (string) ($validatedRequest['prompt'] ?? ''),
        'system' => $validatedRequest['system'] ?? null,
        'messages' => $validatedRequest['messages'] ?? null,
        'sampling' => $validatedRequest['sampling'] ?? [],
        'completion' => $responseEnvelope['completion'] ?? [],
        'recorded_at' => gmdate('c'),
    ];
}

/**
 * Build the transcript envelope from a WS stream summary.
 *
 * @param array<string, mixed> $streamSummary
 * @param array<string, mixed> $validatedRequest
 * @param array<string, mixed> $modelEnvelope
 * @return array<string, mixed>
 */
function model_inference_transcript_from_ws(
    array $streamSummary,
    array $validatedRequest,
    array $modelEnvelope
): array {
    return [
        'request_id' => (string) ($streamSummary['request_id'] ?? ''),
        'session_id' => (string) ($validatedRequest['session_id'] ?? ''),
        'transport' => 'ws',
        'model' => [
            'model_id' => (string) ($modelEnvelope['model_id'] ?? ''),
            'model_name' => (string) ($modelEnvelope['model_name'] ?? ''),
            'quantization' => (string) ($modelEnvelope['quantization'] ?? ''),
        ],
        'prompt' => (string) ($validatedRequest['prompt'] ?? ''),
        'system' => $validatedRequest['system'] ?? null,
        'messages' => $validatedRequest['messages'] ?? null,
        'sampling' => $validatedRequest['sampling'] ?? [],
        'completion' => [
            'text' => (string) ($streamSummary['concatenated_text'] ?? ''),
            'tokens_in' => (int) ($streamSummary['tokens_in'] ?? 0),
            'tokens_out' => (int) ($streamSummary['tokens_out'] ?? 0),
            'ttft_ms' => (int) ($streamSummary['ttft_ms'] ?? 0),
            'duration_ms' => (int) ($streamSummary['duration_ms'] ?? 0),
            'stop' => $streamSummary['stop'] ?? [],
        ],
        'recorded_at' => gmdate('c'),
    ];
}
