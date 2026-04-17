<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/embedding/embedding_request.php';
require_once __DIR__ . '/../domain/embedding/embedding_session.php';
require_once __DIR__ . '/../domain/registry/model_registry.php';

function model_inference_handle_embed_routes(
    string $path,
    string $method,
    array $request,
    callable $jsonResponse,
    callable $errorResponse,
    callable $openDatabase,
    callable $getEmbeddingSession
): ?array {
    if ($path !== '/api/embed') {
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
        return $errorResponse(400, 'invalid_request_envelope', 'POST /api/embed requires a JSON body.', [
            'field' => '',
            'reason' => 'empty_body',
        ]);
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return $errorResponse(400, 'invalid_request_envelope', 'POST /api/embed body is not valid JSON.', [
            'field' => '',
            'reason' => 'invalid_json',
        ]);
    }

    try {
        $validated = model_inference_validate_embedding_request($decoded);
    } catch (EmbeddingRequestValidationError $validation) {
        return $errorResponse(400, 'invalid_request_envelope', 'Embedding request envelope is invalid.', $validation->toDetails());
    }

    $pdo = $openDatabase();

    $entry = model_inference_registry_find_embedding_model(
        $pdo,
        $validated['model_selector']['model_name'],
        $validated['model_selector']['quantization']
    );
    if ($entry === null) {
        return $errorResponse(404, 'model_not_found', 'No embedding model matches the requested selector.', [
            'field' => 'model_selector',
            'reason' => 'no_embedding_registry_row',
            'observed' => $validated['model_selector']['model_name'] . '/' . $validated['model_selector']['quantization'],
        ]);
    }

    /** @var EmbeddingSession $session */
    $session = $getEmbeddingSession();

    try {
        $worker = $session->workerFor(
            (string) $entry['model_id'],
            (string) $entry['artifact']['object_store_key'],
            max(256, (int) $entry['context_length'])
        );
    } catch (Throwable $workerError) {
        return $errorResponse(503, 'worker_unavailable', 'The embedding worker failed to start.', [
            'field' => 'worker',
            'reason' => $workerError->getMessage(),
            'observed' => (string) $entry['model_id'],
        ]);
    }

    try {
        $result = $session->embed($worker, $validated['texts'], $validated['options']['normalize']);
    } catch (Throwable $error) {
        return $errorResponse(502, 'worker_unavailable', 'The embedding worker failed during generation.', [
            'field' => 'worker',
            'reason' => $error->getMessage(),
            'observed' => (string) $entry['model_id'],
        ]);
    }

    $requestId = 'emb_' . bin2hex(random_bytes(8));

    return $jsonResponse(200, [
        'status' => 'ok',
        'request_id' => $requestId,
        'embeddings' => $result['embeddings'],
        'dimensions' => $result['dimensions'],
        'model' => [
            'model_id' => (string) $entry['model_id'],
            'model_name' => (string) $entry['model_name'],
            'quantization' => (string) $entry['quantization'],
        ],
        'tokens_used' => $result['tokens_used'],
        'duration_ms' => $result['duration_ms'],
        'time' => gmdate('c'),
    ]);
}
