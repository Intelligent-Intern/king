<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/inference/inference_request.php';
require_once __DIR__ . '/../domain/inference/inference_session.php';
require_once __DIR__ . '/../domain/registry/model_registry.php';
require_once __DIR__ . '/../domain/registry/model_fit_selector.php';
require_once __DIR__ . '/../domain/profile/hardware_profile.php';

/**
 * @param array<string, mixed> $request
 */
function model_inference_handle_inference_routes(
    string $path,
    string $method,
    array $request,
    callable $jsonResponse,
    callable $errorResponse,
    callable $openDatabase,
    callable $getInferenceSession,
    callable $runtimeEnvelope
): ?array {
    if ($path !== '/api/infer') {
        return null;
    }
    if ($method !== 'POST') {
        return $errorResponse(405, 'method_not_allowed', 'POST required.', [
            'path' => $path,
            'method' => $method,
            'allowed' => ['POST'],
        ]);
    }

    $body = $request['body'] ?? '';
    if (!is_string($body) || trim($body) === '') {
        return $errorResponse(400, 'invalid_request_envelope', 'POST /api/infer requires a JSON body.', [
            'field' => '',
            'reason' => 'empty_body',
        ]);
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return $errorResponse(400, 'invalid_request_envelope', 'POST /api/infer body is not valid JSON.', [
            'field' => '',
            'reason' => 'invalid_json',
        ]);
    }

    try {
        $validated = model_inference_validate_infer_request($decoded, ['transport' => 'http']);
    } catch (InferenceRequestValidationError $validation) {
        return $errorResponse(400, 'invalid_request_envelope', 'Inference request envelope is invalid.', $validation->toDetails());
    }

    $pdo = $openDatabase();

    $stmt = $pdo->prepare('SELECT * FROM models WHERE model_name = :n AND quantization = :q LIMIT 1');
    $stmt->execute([
        ':n' => $validated['model_selector']['model_name'],
        ':q' => $validated['model_selector']['quantization'],
    ]);
    $row = $stmt->fetch();
    if ($row === false) {
        return $errorResponse(404, 'model_not_found', 'No model matches the requested selector.', [
            'field' => 'model_selector',
            'reason' => 'no_registry_row',
            'observed' => $validated['model_selector']['model_name'] . '/' . $validated['model_selector']['quantization'],
        ]);
    }
    $entry = model_inference_registry_row_to_envelope((array) $row);

    $runtime = $runtimeEnvelope();
    $nodeId = (string) (($runtime['node'] ?? [])['node_id'] ?? 'node_unknown');
    $healthUrl = '';
    $profile = model_inference_hardware_profile($nodeId, $healthUrl, 'ready');

    $selection = model_inference_select_model_fit($profile, [$entry]);
    if ($selection['winner'] === null) {
        $rejection = $selection['rejected'][0]['reason'] ?? 'unknown_rejection';
        return $errorResponse(422, 'model_fit_unavailable', 'The registered model cannot be hosted on this node.', [
            'field' => 'model_selector',
            'reason' => $rejection,
            'observed' => $entry['model_id'],
        ]);
    }
    $chosen = $selection['winner'];
    $effectiveMaxTokens = min(
        (int) $validated['sampling']['max_tokens'],
        max(1, (int) $chosen['context_length'])
    );

    /** @var InferenceSession $session */
    $session = $getInferenceSession();

    try {
        $worker = $session->workerFor(
            (string) $chosen['model_id'],
            (string) $chosen['artifact']['object_store_key'],
            max(256, (int) $chosen['context_length']),
            $effectiveMaxTokens
        );
    } catch (Throwable $workerError) {
        return $errorResponse(503, 'worker_unavailable', 'The inference worker failed to start.', [
            'field' => 'worker',
            'reason' => $workerError->getMessage(),
            'observed' => (string) $chosen['model_id'],
        ]);
    }

    try {
        $result = $session->completeNonStreaming($worker, $validated, $effectiveMaxTokens);
    } catch (Throwable $error) {
        return $errorResponse(502, 'worker_unavailable', 'The inference worker failed during generation.', [
            'field' => 'worker',
            'reason' => $error->getMessage(),
            'observed' => (string) $chosen['model_id'],
        ]);
    }

    $requestId = model_inference_generate_request_id();

    return $jsonResponse(200, [
        'status' => 'ok',
        'request_id' => $requestId,
        'session_id' => $validated['session_id'],
        'model' => [
            'model_id' => (string) $chosen['model_id'],
            'model_name' => (string) $chosen['model_name'],
            'quantization' => (string) $chosen['quantization'],
        ],
        'completion' => [
            'text' => (string) $result['content'],
            'tokens_in' => (int) $result['tokens_in'],
            'tokens_out' => (int) $result['tokens_out'],
            'ttft_ms' => (int) $result['ttft_ms'],
            'duration_ms' => (int) $result['duration_ms'],
            'request_wall_ms' => (int) $result['request_wall_ms'],
            'stop' => $result['stop'],
        ],
        'worker' => $result['worker'],
        'time' => gmdate('c'),
    ]);
}

function model_inference_generate_request_id(): string
{
    return 'req_' . bin2hex(random_bytes(8));
}
