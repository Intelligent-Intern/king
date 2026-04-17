<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/embedding/embedding_request.php';
require_once __DIR__ . '/../domain/embedding/embedding_session.php';
require_once __DIR__ . '/../domain/registry/model_registry.php';
require_once __DIR__ . '/../domain/retrieval/retrieval_pipeline.php';
require_once __DIR__ . '/../domain/retrieval/rag_orchestrator.php';
require_once __DIR__ . '/../domain/telemetry/rag_metrics.php';

function model_inference_handle_retrieve_routes(
    string $path,
    string $method,
    array $request,
    callable $jsonResponse,
    callable $errorResponse,
    callable $openDatabase,
    ?callable $getEmbeddingSession,
    ?callable $getInferenceSession = null,
    ?callable $getRagMetrics = null
): ?array {
    if ($path === '/api/rag') {
        return model_inference_handle_rag_route(
            $method, $request, $jsonResponse, $errorResponse,
            $openDatabase, $getEmbeddingSession, $getInferenceSession, $getRagMetrics
        );
    }
    if ($path !== '/api/retrieve') {
        return null;
    }
    if ($method !== 'POST') {
        return $errorResponse(405, 'method_not_allowed', 'POST required.', [
            'path' => $path,
            'method' => $method,
            'allowed' => ['POST'],
        ]);
    }
    if ($getEmbeddingSession === null) {
        return $errorResponse(503, 'worker_unavailable', 'Embedding session not configured.', []);
    }

    $body = $request['body'] ?? '';
    if (!is_string($body) || trim($body) === '') {
        return $errorResponse(400, 'invalid_request_envelope', 'POST /api/retrieve requires a JSON body.', [
            'field' => '', 'reason' => 'empty_body',
        ]);
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return $errorResponse(400, 'invalid_request_envelope', 'POST /api/retrieve body is not valid JSON.', [
            'field' => '', 'reason' => 'invalid_json',
        ]);
    }

    $validated = model_inference_validate_retrieve_request($decoded);
    if ($validated === null) {
        return $errorResponse(400, 'invalid_request_envelope', 'Retrieval request envelope is invalid.', [
            'field' => '', 'reason' => 'validation_failed',
        ]);
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
            'reason' => $workerError->getMessage(),
        ]);
    }

    $embT0 = microtime(true);
    try {
        $embResult = $session->embed($worker, [$validated['query']], true);
    } catch (Throwable $error) {
        return $errorResponse(502, 'worker_unavailable', 'Embedding generation failed for query.', [
            'reason' => $error->getMessage(),
        ]);
    }
    $embeddingMs = (int) round((microtime(true) - $embT0) * 1000);

    $queryVector = $embResult['embeddings'][0] ?? [];
    if (count($queryVector) === 0) {
        return $errorResponse(500, 'internal_server_error', 'Embedding produced empty vector.', []);
    }

    $searchResult = model_inference_retrieval_search(
        $pdo,
        $queryVector,
        $validated['document_ids'],
        $validated['top_k'],
        $validated['min_score']
    );

    $requestId = 'ret_' . bin2hex(random_bytes(8));

    return $jsonResponse(200, [
        'status' => 'ok',
        'request_id' => $requestId,
        'results' => $searchResult['results'],
        'result_count' => $searchResult['result_count'],
        'search_strategy' => $searchResult['search_strategy'],
        'embedding_ms' => $embeddingMs,
        'search_ms' => $searchResult['search_ms'],
        'vectors_scanned' => $searchResult['vectors_scanned'],
        'time' => gmdate('c'),
    ]);
}

/** @return array<string, mixed>|null */
function model_inference_validate_retrieve_request(array $payload): ?array
{
    if (!isset($payload['query']) || !is_string($payload['query'])) {
        return null;
    }
    $query = $payload['query'];
    if (strlen($query) < 1 || strlen($query) > 32768) {
        return null;
    }

    if (!isset($payload['model_selector']) || !is_array($payload['model_selector'])) {
        return null;
    }
    $selector = $payload['model_selector'];
    if (!isset($selector['model_name']) || !is_string($selector['model_name']) || strlen($selector['model_name']) < 1) {
        return null;
    }
    if (!isset($selector['quantization']) || !is_string($selector['quantization'])) {
        return null;
    }
    $allowedQuants = ['Q2_K', 'Q3_K', 'Q4_0', 'Q4_K', 'Q5_K', 'Q6_K', 'Q8_0', 'F16'];
    if (!in_array($selector['quantization'], $allowedQuants, true)) {
        return null;
    }

    $documentIds = null;
    if (array_key_exists('document_ids', $payload) && $payload['document_ids'] !== null) {
        if (!is_array($payload['document_ids'])) {
            return null;
        }
        $documentIds = [];
        foreach ($payload['document_ids'] as $id) {
            if (!is_string($id) || $id === '') {
                return null;
            }
            $documentIds[] = $id;
        }
    }

    $topK = 5;
    if (array_key_exists('top_k', $payload)) {
        if (!is_int($payload['top_k'])) {
            return null;
        }
        $topK = $payload['top_k'];
        if ($topK < 1 || $topK > 100) {
            return null;
        }
    }

    $minScore = 0.0;
    if (array_key_exists('min_score', $payload)) {
        $ms = $payload['min_score'];
        if (is_int($ms)) {
            $ms = (float) $ms;
        }
        if (!is_float($ms) || $ms < 0.0 || $ms > 1.0) {
            return null;
        }
        $minScore = $ms;
    }

    return [
        'query' => $query,
        'model_selector' => [
            'model_name' => $selector['model_name'],
            'quantization' => $selector['quantization'],
        ],
        'document_ids' => $documentIds,
        'top_k' => $topK,
        'min_score' => $minScore,
    ];
}

function model_inference_handle_rag_route(
    string $method,
    array $request,
    callable $jsonResponse,
    callable $errorResponse,
    callable $openDatabase,
    ?callable $getEmbeddingSession,
    ?callable $getInferenceSession,
    ?callable $getRagMetrics
): array {
    if ($method !== 'POST') {
        return $errorResponse(405, 'method_not_allowed', 'POST required.', [
            'path' => '/api/rag',
            'method' => $method,
            'allowed' => ['POST'],
        ]);
    }
    if ($getEmbeddingSession === null || $getInferenceSession === null) {
        return $errorResponse(503, 'worker_unavailable', 'Embedding or inference session not configured.', []);
    }

    $body = $request['body'] ?? '';
    if (!is_string($body) || trim($body) === '') {
        return $errorResponse(400, 'invalid_request_envelope', 'POST /api/rag requires a JSON body.', [
            'field' => '', 'reason' => 'empty_body',
        ]);
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return $errorResponse(400, 'invalid_request_envelope', 'POST /api/rag body is not valid JSON.', [
            'field' => '', 'reason' => 'invalid_json',
        ]);
    }

    $validated = model_inference_validate_rag_request($decoded);
    if ($validated === null) {
        return $errorResponse(400, 'invalid_request_envelope', 'RAG request envelope is invalid.', [
            'field' => '', 'reason' => 'validation_failed',
        ]);
    }

    $pdo = $openDatabase();

    $embeddingModel = model_inference_registry_find_embedding_model(
        $pdo,
        $validated['model_selector']['embedding']['model_name'],
        $validated['model_selector']['embedding']['quantization']
    );
    if ($embeddingModel === null) {
        return $errorResponse(404, 'model_not_found', 'No embedding model matches the requested selector.', [
            'field' => 'model_selector.embedding',
            'observed' => $validated['model_selector']['embedding']['model_name'] . '/' . $validated['model_selector']['embedding']['quantization'],
        ]);
    }

    $chatStmt = $pdo->prepare('SELECT * FROM models WHERE model_name = :n AND quantization = :q AND model_type = :t LIMIT 1');
    $chatStmt->execute([
        ':n' => $validated['model_selector']['chat']['model_name'],
        ':q' => $validated['model_selector']['chat']['quantization'],
        ':t' => 'chat',
    ]);
    $chatRow = $chatStmt->fetch();
    if ($chatRow === false) {
        return $errorResponse(404, 'model_not_found', 'No chat model matches the requested selector.', [
            'field' => 'model_selector.chat',
            'observed' => $validated['model_selector']['chat']['model_name'] . '/' . $validated['model_selector']['chat']['quantization'],
        ]);
    }
    $chatModel = model_inference_registry_row_to_envelope((array) $chatRow);

    /** @var EmbeddingSession $embSession */
    $embSession = $getEmbeddingSession();
    /** @var InferenceSession $infSession */
    $infSession = $getInferenceSession();

    try {
        $result = model_inference_rag_execute(
            $pdo, $validated, $embSession, $infSession, $embeddingModel, $chatModel
        );
    } catch (Throwable $error) {
        return $errorResponse(502, 'worker_unavailable', 'RAG pipeline failed.', [
            'reason' => $error->getMessage(),
        ]);
    }

    $requestId = 'rag_' . bin2hex(random_bytes(8));

    if ($getRagMetrics !== null) {
        /** @var RagMetricsRing $metrics */
        $metrics = $getRagMetrics();
        $metrics->record([
            'request_id' => $requestId,
            'query_length' => strlen($validated['query']),
            'embedding_ms' => $result['timing']['embedding_ms'],
            'retrieval_ms' => $result['timing']['retrieval_ms'],
            'inference_ms' => $result['timing']['inference_ms'],
            'total_ms' => $result['timing']['total_ms'],
            'chunks_used' => $result['context']['chunks_used'],
            'vectors_scanned' => $result['context']['vectors_scanned'],
            'tokens_in' => $result['inference']['tokens_in'],
            'tokens_out' => $result['inference']['tokens_out'],
            'chat_model' => $chatModel['model_name'] . '/' . $chatModel['quantization'],
            'embedding_model' => $embeddingModel['model_name'] . '/' . $embeddingModel['quantization'],
        ]);
    }

    return $jsonResponse(200, [
        'status' => 'ok',
        'request_id' => $requestId,
        'completion' => $result['completion'],
        'context' => $result['context'],
        'model' => $result['model'],
        'timing' => $result['timing'],
        'inference' => $result['inference'],
        'time' => gmdate('c'),
    ]);
}
