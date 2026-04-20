<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/inference/inference_request.php';
require_once __DIR__ . '/../domain/inference/inference_session.php';
require_once __DIR__ . '/../domain/inference/inference_stream.php';
require_once __DIR__ . '/../domain/inference/transcript_store.php';
require_once __DIR__ . '/../domain/conversation/conversation_store.php';
require_once __DIR__ . '/../domain/registry/model_registry.php';
require_once __DIR__ . '/../domain/registry/model_fit_selector.php';
require_once __DIR__ . '/../domain/profile/hardware_profile.php';
require_once __DIR__ . '/../domain/telemetry/inference_metrics.php';
require_once __DIR__ . '/../support/token_frame.php';

/**
 * Handle the WS upgrade + token-frame streaming loop.
 *
 * Shape for the current leaf:
 *   1. GET /ws with a valid Upgrade handshake arrives.
 *   2. Handler calls king_server_upgrade_to_websocket on this request's
 *      session/stream pair and gets a live WS handle.
 *   3. Handler reads one text frame off the WS — expected shape:
 *        { "event": "infer.start", "payload": <inference-request-envelope
 *          with stream=true> }
 *   4. Handler validates the envelope (transport='ws'), resolves the
 *      registry row, scores it against the node profile, and spawns /
 *      reuses the cached LlamaCppWorker.
 *   5. model_inference_stream_completion() bridges llama.cpp's SSE into
 *      TokenFrame binary frames via king_websocket_send($ws, $bytes, true)
 *      with is_binary=true.
 *   6. Final frame is frame_type=end with the real timing summary.
 *   7. Handler closes the WS with a 1000 normal-close.
 *
 * Scope fence: the upgrade handler runs the full WS session
 * synchronously — the inner fdispatcher cannot accept a new
 * connection until this stream completes. Concurrent WS sessions
 * are a future hardening leaf. Call it out in the README rather
 * than pretend a parallel accept loop exists.
 *
 * @param array<string, mixed> $request
 */
function model_inference_handle_realtime_routes(
    string $path,
    string $method,
    array $request,
    callable $jsonResponse,
    callable $errorResponse,
    callable $openDatabase,
    callable $getInferenceSession,
    callable $getInferenceMetrics,
    callable $runtimeEnvelope,
    string $wsPath
): ?array {
    if ($path !== $wsPath) {
        return null;
    }
    if ($method !== 'GET') {
        return $errorResponse(405, 'method_not_allowed', 'WS upgrade requires GET.', [
            'path' => $path,
            'method' => $method,
            'allowed' => ['GET'],
        ]);
    }

    $headers = model_inference_realtime_lower_case_headers($request['headers'] ?? []);
    $upgrade = strtolower(trim((string) ($headers['upgrade'] ?? '')));
    $connectionHeader = strtolower((string) ($headers['connection'] ?? ''));
    $wsVersion = trim((string) ($headers['sec-websocket-version'] ?? ''));
    $wsKey = trim((string) ($headers['sec-websocket-key'] ?? ''));
    if ($upgrade !== 'websocket' || !str_contains($connectionHeader, 'upgrade') || $wsVersion !== '13' || $wsKey === '') {
        return $errorResponse(400, 'invalid_request_envelope', 'WS handshake headers missing or invalid.', [
            'field' => 'headers',
            'reason' => 'bad_ws_handshake',
            'observed' => ['upgrade' => $upgrade, 'connection' => $connectionHeader, 'version' => $wsVersion, 'has_key' => $wsKey !== ''],
        ]);
    }

    $sessionHandle = $request['session'] ?? null;
    $streamId = $request['stream_id'] ?? null;
    if ($sessionHandle === null || $streamId === null) {
        return $errorResponse(500, 'internal_server_error', 'WS upgrade requires request.session + request.stream_id.', [
            'field' => 'request',
            'reason' => 'missing_session_or_stream_id',
        ]);
    }

    if (!function_exists('king_server_upgrade_to_websocket') || !function_exists('king_websocket_send')) {
        return $errorResponse(500, 'internal_server_error', 'King WS primitives are unavailable.', [
            'field' => 'extension',
            'reason' => 'king_websocket_functions_missing',
        ]);
    }
    $ws = king_server_upgrade_to_websocket($sessionHandle, (int) $streamId);
    if (!is_resource($ws)) {
        return $errorResponse(400, 'invalid_request_envelope', 'WS upgrade rejected by the runtime.', [
            'field' => 'headers',
            'reason' => 'upgrade_failed',
        ]);
    }

    try {
        model_inference_realtime_run_session(
            $ws,
            $request,
            $openDatabase,
            $getInferenceSession,
            $getInferenceMetrics,
            $runtimeEnvelope
        );
    } catch (Throwable $error) {
        model_inference_realtime_emit_error($ws, 'internal_server_error', $error->getMessage());
    } finally {
        if (is_resource($ws)) {
            @king_client_websocket_close($ws, 1000, 'done');
        }
    }

    return [
        'status' => 101,
        'headers' => [],
        'body' => '',
    ];
}

/**
 * @param array<string, mixed> $request
 */
function model_inference_realtime_run_session(
    $ws,
    array $request,
    callable $openDatabase,
    callable $getInferenceSession,
    callable $getInferenceMetrics,
    callable $runtimeEnvelope
): void {
    $rawFrame = king_client_websocket_receive($ws, 30_000);
    if (!is_string($rawFrame) || $rawFrame === '') {
        model_inference_realtime_emit_error($ws, 'invalid_request_envelope', 'no infer.start frame received');
        return;
    }
    $message = json_decode($rawFrame, true);
    if (!is_array($message) || ($message['event'] ?? null) !== 'infer.start') {
        model_inference_realtime_emit_error($ws, 'invalid_request_envelope', 'first WS frame must be {"event":"infer.start"}');
        return;
    }
    $payload = $message['payload'] ?? null;
    if (!is_array($payload)) {
        model_inference_realtime_emit_error($ws, 'invalid_request_envelope', 'infer.start.payload missing');
        return;
    }

    try {
        $validated = model_inference_validate_infer_request($payload, ['transport' => 'ws']);
    } catch (InferenceRequestValidationError $validation) {
        model_inference_realtime_emit_error($ws, 'invalid_request_envelope', $validation->reason . ' @ ' . $validation->field);
        return;
    }

    $pdo = $openDatabase();
    $stmt = $pdo->prepare('SELECT * FROM models WHERE model_name = :n AND quantization = :q LIMIT 1');
    $stmt->execute([
        ':n' => $validated['model_selector']['model_name'],
        ':q' => $validated['model_selector']['quantization'],
    ]);
    $row = $stmt->fetch();
    if ($row === false) {
        model_inference_realtime_emit_error($ws, 'model_not_found', 'no registry row for selector');
        return;
    }
    $entry = model_inference_registry_row_to_envelope((array) $row);

    $runtime = $runtimeEnvelope();
    $nodeId = (string) (($runtime['node'] ?? [])['node_id'] ?? 'node_unknown');
    $profile = model_inference_hardware_profile($nodeId, '', 'ready');
    $selection = model_inference_select_model_fit($profile, [$entry]);
    if ($selection['winner'] === null) {
        $reason = $selection['rejected'][0]['reason'] ?? 'unknown_rejection';
        model_inference_realtime_emit_error($ws, 'model_fit_unavailable', $reason);
        return;
    }

    /** @var InferenceSession $session */
    $session = $getInferenceSession();
    try {
        $worker = $session->workerFor(
            (string) $entry['model_id'],
            (string) $entry['artifact']['object_store_key'],
            max(256, (int) $entry['context_length']),
            min((int) $validated['sampling']['max_tokens'], max(1, (int) $entry['context_length']))
        );
    } catch (Throwable $workerError) {
        model_inference_realtime_emit_error($ws, 'worker_unavailable', $workerError->getMessage());
        return;
    }

    $requestId = 'req_' . bin2hex(random_bytes(8));
    $sendBinaryFrame = static function (string $frameBytes) use ($ws): void {
        king_websocket_send($ws, $frameBytes, true);
    };

    $streamSummary = model_inference_stream_completion(
        $worker,
        $validated,
        $requestId,
        $sendBinaryFrame,
        min((int) $validated['sampling']['max_tokens'], max(1, (int) $entry['context_length']))
    );

    /** @var InferenceMetricsRing $metrics */
    $metrics = $getInferenceMetrics();
    $metrics->record(model_inference_metrics_entry_from_ws($streamSummary, $validated, $entry, $profile));

    model_inference_transcript_save(
        $requestId,
        model_inference_transcript_from_ws($streamSummary, $validated, $entry)
    );

    // C-batch (#V.8): persist the streamed turn for conversation replay.
    try {
        $assistantText = (string) ($streamSummary['concatenated_text'] ?? '');
        if ($assistantText !== '') {
            $pdo = $openDatabase();
            model_inference_conversation_schema_migrate($pdo);
            model_inference_conversation_append_turn(
                $pdo, $validated, $assistantText, $requestId, $entry
            );
        }
    } catch (Throwable $ignored) {
        // never corrupt the WS stream on persistence failure
    }
}

/**
 * Emit a frame_type=error JSON-body frame and close the session.
 */
function model_inference_realtime_emit_error($ws, string $code, string $message): void
{
    if (!is_resource($ws) || !function_exists('king_websocket_send')) {
        return;
    }
    try {
        $errorFrame = TokenFrame::encodeError(
            1,
            TokenFrame::requestIdCrc32('session-error'),
            $code,
            $message
        );
        king_websocket_send($ws, $errorFrame, true);
    } catch (Throwable $ignored) {
        // Best-effort — if we can't even send an error frame, the client
        // will surface the hard-close itself.
    }
}

/**
 * @param array<string, mixed> $headers
 * @return array<string, string>
 */
function model_inference_realtime_lower_case_headers(array $headers): array
{
    $out = [];
    foreach ($headers as $name => $value) {
        if (!is_string($name)) {
            continue;
        }
        $out[strtolower($name)] = is_array($value) ? (string) reset($value) : (string) $value;
    }
    return $out;
}
