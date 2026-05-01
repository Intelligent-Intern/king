<?php

declare(strict_types=1);

require_once __DIR__ . '/../domain/embedding/embedding_session.php';
require_once __DIR__ . '/../domain/registry/model_registry.php';
require_once __DIR__ . '/../domain/discovery/service_embedding_store.php';
require_once __DIR__ . '/../domain/discovery/semantic_discover.php';
require_once __DIR__ . '/../domain/discovery/hybrid_discover.php';
require_once __DIR__ . '/../domain/discovery/tool_descriptor_store.php';
require_once __DIR__ . '/../domain/discovery/tool_discover.php';
require_once __DIR__ . '/../domain/discovery/mcp_pick.php';
require_once __DIR__ . '/../domain/discovery/graph_store.php';
require_once __DIR__ . '/../domain/discovery/graph_expand.php';
require_once __DIR__ . '/../domain/telemetry/discovery_metrics.php';

function model_inference_handle_discover_routes(
    string $path,
    string $method,
    array $request,
    callable $jsonResponse,
    callable $errorResponse,
    callable $openDatabase,
    ?callable $getEmbeddingSession,
    ?callable $getDiscoveryMetrics = null
): ?array {
    if ($path === '/api/discover') {
        return model_inference_handle_service_discover_route(
            $method, $request, $jsonResponse, $errorResponse, $openDatabase, $getEmbeddingSession, $getDiscoveryMetrics
        );
    }
    if ($path === '/api/tools/discover') {
        return model_inference_handle_tool_discover_route(
            $method, $request, $jsonResponse, $errorResponse, $openDatabase, $getEmbeddingSession, $getDiscoveryMetrics
        );
    }
    if ($path === '/api/tools/pick') {
        return model_inference_handle_tool_pick_route(
            $method, $request, $jsonResponse, $errorResponse, $openDatabase, $getEmbeddingSession, $getDiscoveryMetrics
        );
    }
    return null;
}

function model_inference_handle_service_discover_route(
    string $method,
    array $request,
    callable $jsonResponse,
    callable $errorResponse,
    callable $openDatabase,
    ?callable $getEmbeddingSession,
    ?callable $getDiscoveryMetrics
): array {
    if ($method !== 'POST') {
        return $errorResponse(405, 'method_not_allowed', 'POST required.', [
            'path' => '/api/discover',
            'method' => $method,
            'allowed' => ['POST'],
        ]);
    }

    $validated = model_inference_parse_discover_body($request, '/api/discover', $errorResponse, false);
    if (!is_array($validated) || isset($validated['__error'])) {
        return $validated['__error'] ?? $errorResponse(400, 'invalid_request_envelope', 'Discover request envelope is invalid.', []);
    }

    $pdo = $openDatabase();

    $mode = $validated['mode'];

    // Keyword-only mode needs no embedding.
    if ($mode === 'keyword') {
        $t0 = microtime(true);
        $rows = model_inference_service_embedding_load_all(
            $pdo,
            $validated['service_type']
        );
        $ranked = model_inference_keyword_rank_services(
            $rows,
            $validated['query'],
            $validated['top_k'],
            $validated['min_score']
        );
        $searchMs = (int) round((microtime(true) - $t0) * 1000);
        $requestId = 'dis_' . bin2hex(random_bytes(8));
        $response = [
            'status' => 'ok',
            'request_id' => $requestId,
            'mode' => 'keyword',
            'results' => $ranked['results'],
            'result_count' => $ranked['result_count'],
            'candidates_scanned' => $ranked['candidates_scanned'],
            'search_strategy' => 'keyword_bm25',
            'embedding_ms' => 0,
            'search_ms' => $searchMs,
            'time' => gmdate('c'),
        ];
        $response = model_inference_discover_attach_graph_expansion($response, $pdo, $validated);
        model_inference_record_discovery_metric($getDiscoveryMetrics, $requestId, $validated, 0, $searchMs, $ranked['candidates_scanned'], 'keyword');
        return $jsonResponse(200, $response);
    }

    if ($getEmbeddingSession === null) {
        return $errorResponse(503, 'embedding_worker_unavailable_discovery', 'Embedding session not configured for semantic/hybrid discovery.', [
            'field' => 'mode',
            'reason' => 'embedding_session_missing',
            'observed' => $mode,
        ]);
    }

    $modelEntry = model_inference_registry_find_embedding_model(
        $pdo,
        $validated['model_selector']['model_name'],
        $validated['model_selector']['quantization']
    );
    if ($modelEntry === null) {
        return $errorResponse(404, 'model_not_found', 'No embedding model matches the requested selector.', [
            'field' => 'model_selector',
            'observed' => $validated['model_selector']['model_name'] . '/' . $validated['model_selector']['quantization'],
        ]);
    }

    /** @var EmbeddingSession $session */
    $session = $getEmbeddingSession();
    try {
        $worker = $session->workerFor(
            (string) $modelEntry['model_id'],
            (string) $modelEntry['artifact']['object_store_key'],
            max(256, (int) $modelEntry['context_length'])
        );
    } catch (Throwable $error) {
        return $errorResponse(503, 'embedding_worker_unavailable_discovery', 'Embedding worker failed to start.', [
            'reason' => $error->getMessage(),
        ]);
    }

    $embT0 = microtime(true);
    try {
        $embResult = $session->embed($worker, [$validated['query']], true);
    } catch (Throwable $error) {
        return $errorResponse(502, 'embedding_worker_unavailable_discovery', 'Embedding generation failed for discovery query.', [
            'reason' => $error->getMessage(),
        ]);
    }
    $embeddingMs = (int) round((microtime(true) - $embT0) * 1000);

    $queryVector = $embResult['embeddings'][0] ?? [];
    if (count($queryVector) === 0) {
        return $errorResponse(500, 'internal_server_error', 'Embedding produced empty vector.', []);
    }

    if ($mode === 'semantic') {
        $ranked = model_inference_semantic_discover(
            $pdo,
            $queryVector,
            $validated['service_type'],
            $validated['top_k'],
            $validated['min_score']
        );
        $searchStrategy = $ranked['search_strategy'];
    } else {
        $ranked = model_inference_hybrid_discover(
            $pdo,
            $queryVector,
            $validated['query'],
            $validated['service_type'],
            $validated['top_k'],
            $validated['min_score'],
            $validated['alpha']
        );
        $searchStrategy = $ranked['search_strategy'];
    }

    $requestId = 'dis_' . bin2hex(random_bytes(8));
    $response = [
        'status' => 'ok',
        'request_id' => $requestId,
        'mode' => $mode,
        'results' => $ranked['results'],
        'result_count' => $ranked['result_count'],
        'candidates_scanned' => $ranked['candidates_scanned'],
        'search_strategy' => $searchStrategy,
        'embedding_ms' => $embeddingMs,
        'search_ms' => $ranked['search_ms'],
        'model' => [
            'model_id' => (string) $modelEntry['model_id'],
            'model_name' => (string) $modelEntry['model_name'],
            'quantization' => (string) $modelEntry['quantization'],
        ],
        'time' => gmdate('c'),
    ];
    if ($mode === 'hybrid') {
        $response['alpha'] = $ranked['alpha'];
        $response['bm25_k1'] = $ranked['bm25_k1'];
        $response['bm25_b'] = $ranked['bm25_b'];
    }
    $response = model_inference_discover_attach_graph_expansion($response, $pdo, $validated);
    model_inference_record_discovery_metric($getDiscoveryMetrics, $requestId, $validated, $embeddingMs, $ranked['search_ms'], $ranked['candidates_scanned'], $mode);
    return $jsonResponse(200, $response);
}

/**
 * G-batch (#W.8 / #W.9): attach graph expansion data to a finished
 * /api/discover response if `graph_expand` was supplied. The core
 * ranked result is left in place verbatim; we only add an `expanded`
 * list of graph neighbors plus a `graph_expand` echo block.
 *
 * @param array<string, mixed> $response
 * @param array<string, mixed> $validated
 * @return array<string, mixed>
 */
function model_inference_discover_attach_graph_expansion(array $response, PDO $pdo, array $validated): array
{
    $ge = $validated['graph_expand'] ?? null;
    if (!is_array($ge)) {
        return $response;
    }
    model_inference_graph_schema_migrate($pdo);
    $enriched = model_inference_graph_expand_results(
        $pdo,
        (array) ($response['results'] ?? []),
        $ge['edge_types'] ?? null,
        (int) ($ge['max_hops'] ?? 1)
    );
    $response['expanded'] = $enriched['expanded'];
    $response['expanded_count'] = $enriched['neighbor_count'];
    $response['graph_expand'] = [
        'hops' => $enriched['hops'],
        'edge_types' => $enriched['edge_types'],
    ];
    return $response;
}

function model_inference_handle_tool_discover_route(
    string $method,
    array $request,
    callable $jsonResponse,
    callable $errorResponse,
    callable $openDatabase,
    ?callable $getEmbeddingSession,
    ?callable $getDiscoveryMetrics
): array {
    if ($method !== 'POST') {
        return $errorResponse(405, 'method_not_allowed', 'POST required.', [
            'path' => '/api/tools/discover',
            'method' => $method,
            'allowed' => ['POST'],
        ]);
    }

    $validated = model_inference_parse_discover_body($request, '/api/tools/discover', $errorResponse, true);
    if (!is_array($validated) || isset($validated['__error'])) {
        return $validated['__error'] ?? $errorResponse(400, 'invalid_request_envelope', 'Tool discover request envelope is invalid.', []);
    }

    $pdo = $openDatabase();
    $mode = $validated['mode'];

    if ($mode === 'keyword') {
        $t0 = microtime(true);
        $rows = model_inference_tool_embedding_load_all($pdo);
        $ranked = model_inference_keyword_rank_tools($rows, $validated['query'], $validated['top_k'], $validated['min_score']);
        $searchMs = (int) round((microtime(true) - $t0) * 1000);
        $requestId = 'dis_tools_' . bin2hex(random_bytes(8));
        model_inference_record_discovery_metric($getDiscoveryMetrics, $requestId, $validated, 0, $searchMs, $ranked['candidates_scanned'], 'keyword');
        return $jsonResponse(200, [
            'status' => 'ok',
            'request_id' => $requestId,
            'mode' => 'keyword',
            'results' => $ranked['results'],
            'result_count' => $ranked['result_count'],
            'candidates_scanned' => $ranked['candidates_scanned'],
            'search_strategy' => 'keyword_bm25',
            'embedding_ms' => 0,
            'search_ms' => $searchMs,
            'time' => gmdate('c'),
        ]);
    }

    if ($getEmbeddingSession === null) {
        return $errorResponse(503, 'embedding_worker_unavailable_discovery', 'Embedding session not configured for semantic/hybrid discovery.', [
            'field' => 'mode', 'observed' => $mode,
        ]);
    }

    $modelEntry = model_inference_registry_find_embedding_model(
        $pdo,
        $validated['model_selector']['model_name'],
        $validated['model_selector']['quantization']
    );
    if ($modelEntry === null) {
        return $errorResponse(404, 'model_not_found', 'No embedding model matches the requested selector.', [
            'field' => 'model_selector',
            'observed' => $validated['model_selector']['model_name'] . '/' . $validated['model_selector']['quantization'],
        ]);
    }

    /** @var EmbeddingSession $session */
    $session = $getEmbeddingSession();
    try {
        $worker = $session->workerFor(
            (string) $modelEntry['model_id'],
            (string) $modelEntry['artifact']['object_store_key'],
            max(256, (int) $modelEntry['context_length'])
        );
    } catch (Throwable $error) {
        return $errorResponse(503, 'embedding_worker_unavailable_discovery', 'Embedding worker failed to start.', [
            'reason' => $error->getMessage(),
        ]);
    }

    $embT0 = microtime(true);
    try {
        $embResult = $session->embed($worker, [$validated['query']], true);
    } catch (Throwable $error) {
        return $errorResponse(502, 'embedding_worker_unavailable_discovery', 'Embedding generation failed for discovery query.', [
            'reason' => $error->getMessage(),
        ]);
    }
    $embeddingMs = (int) round((microtime(true) - $embT0) * 1000);
    $queryVector = $embResult['embeddings'][0] ?? [];
    if (count($queryVector) === 0) {
        return $errorResponse(500, 'internal_server_error', 'Embedding produced empty vector.', []);
    }

    if ($mode === 'semantic') {
        $ranked = model_inference_tool_semantic_discover($pdo, $queryVector, $validated['top_k'], $validated['min_score']);
    } else {
        $ranked = model_inference_tool_hybrid_discover($pdo, $queryVector, $validated['query'], $validated['top_k'], $validated['min_score'], $validated['alpha']);
    }

    $requestId = 'dis_tools_' . bin2hex(random_bytes(8));
    $response = [
        'status' => 'ok',
        'request_id' => $requestId,
        'mode' => $mode,
        'results' => $ranked['results'],
        'result_count' => $ranked['result_count'],
        'candidates_scanned' => $ranked['candidates_scanned'],
        'search_strategy' => $ranked['search_strategy'],
        'embedding_ms' => $embeddingMs,
        'search_ms' => $ranked['search_ms'],
        'model' => [
            'model_id' => (string) $modelEntry['model_id'],
            'model_name' => (string) $modelEntry['model_name'],
            'quantization' => (string) $modelEntry['quantization'],
        ],
        'time' => gmdate('c'),
    ];
    if ($mode === 'hybrid') {
        $response['alpha'] = $ranked['alpha'];
        $response['bm25_k1'] = $ranked['bm25_k1'];
        $response['bm25_b'] = $ranked['bm25_b'];
    }
    model_inference_record_discovery_metric($getDiscoveryMetrics, $requestId, $validated, $embeddingMs, $ranked['search_ms'], $ranked['candidates_scanned'], $mode);
    return $jsonResponse(200, $response);
}

function model_inference_handle_tool_pick_route(
    string $method,
    array $request,
    callable $jsonResponse,
    callable $errorResponse,
    callable $openDatabase,
    ?callable $getEmbeddingSession,
    ?callable $getDiscoveryMetrics
): array {
    if ($method !== 'POST') {
        return $errorResponse(405, 'method_not_allowed', 'POST required.', [
            'path' => '/api/tools/pick',
            'method' => $method,
            'allowed' => ['POST'],
        ]);
    }

    $validated = model_inference_parse_discover_body($request, '/api/tools/pick', $errorResponse, true);
    if (!is_array($validated) || isset($validated['__error'])) {
        return $validated['__error'] ?? $errorResponse(400, 'invalid_request_envelope', 'Tool pick request envelope is invalid.', []);
    }

    // The discover route will record the metric; we just call its result here.
    $subRequest = $request;
    $subRequest['body'] = (string) json_encode([
        'query' => $validated['query'],
        'mode' => $validated['mode'],
        'top_k' => 1,
        'min_score' => $validated['min_score'],
        'alpha' => $validated['alpha'],
        'model_selector' => $validated['model_selector'] ?? null,
    ], JSON_UNESCAPED_SLASHES);
    $discover = model_inference_handle_tool_discover_route(
        'POST', $subRequest, $jsonResponse, $errorResponse, $openDatabase, $getEmbeddingSession, $getDiscoveryMetrics
    );
    $body = is_string($discover['body'] ?? null) ? $discover['body'] : '';
    $decoded = json_decode($body, true);
    if (!is_array($decoded) || ($decoded['status'] ?? null) !== 'ok' || empty($decoded['results'])) {
        return $errorResponse(404, 'no_semantic_match', 'No tool matched above min_score.', [
            'field' => 'min_score',
            'observed' => $validated['min_score'],
            'candidates_scanned' => is_array($decoded) ? ($decoded['candidates_scanned'] ?? 0) : 0,
        ]);
    }
    $top = $decoded['results'][0];
    return $jsonResponse(200, [
        'status' => 'ok',
        'request_id' => 'pick_' . bin2hex(random_bytes(8)),
        'tool_id' => $top['tool_id'],
        'mcp_target' => $top['mcp_target'],
        'score' => $top['score'],
        'mode' => $decoded['mode'],
        'embedding_ms' => $decoded['embedding_ms'],
        'search_ms' => $decoded['search_ms'],
        'candidates_scanned' => $decoded['candidates_scanned'],
        'time' => gmdate('c'),
    ]);
}

/**
 * @return array<string, mixed>|array{__error: array<string, mixed>}
 */
function model_inference_parse_discover_body(
    array $request,
    string $path,
    callable $errorResponse,
    bool $forTools
) {
    $body = $request['body'] ?? '';
    if (!is_string($body) || trim($body) === '') {
        return ['__error' => $errorResponse(400, 'invalid_request_envelope', "POST {$path} requires a JSON body.", [
            'field' => '', 'reason' => 'empty_body',
        ])];
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return ['__error' => $errorResponse(400, 'invalid_request_envelope', "POST {$path} body is not valid JSON.", [
            'field' => '', 'reason' => 'invalid_json',
        ])];
    }

    $allowedKeys = $forTools
        ? ['query', 'top_k', 'min_score', 'mode', 'alpha', 'model_selector']
        : ['query', 'service_type', 'top_k', 'min_score', 'mode', 'alpha', 'model_selector', 'graph_expand'];
    foreach (array_keys($decoded) as $k) {
        if (!is_string($k) || !in_array($k, $allowedKeys, true)) {
            return ['__error' => $errorResponse(400, 'invalid_request_envelope', "Unknown top-level key: {$k}.", [
                'field' => (string) $k,
                'reason' => 'unknown_top_level_key',
            ])];
        }
    }

    if (!isset($decoded['query']) || !is_string($decoded['query'])) {
        return ['__error' => $errorResponse(400, 'invalid_request_envelope', 'query is required.', ['field' => 'query'])];
    }
    $query = $decoded['query'];
    if (strlen($query) < 1 || strlen($query) > 2048) {
        return ['__error' => $errorResponse(400, 'invalid_request_envelope', 'query length must be 1..2048.', ['field' => 'query', 'observed' => strlen($query)])];
    }

    $mode = 'semantic';
    if (array_key_exists('mode', $decoded)) {
        if (!is_string($decoded['mode']) || !in_array($decoded['mode'], ['keyword', 'semantic', 'hybrid'], true)) {
            return ['__error' => $errorResponse(400, 'invalid_request_envelope', 'mode must be keyword|semantic|hybrid.', ['field' => 'mode'])];
        }
        $mode = $decoded['mode'];
    }

    $topK = 5;
    if (array_key_exists('top_k', $decoded)) {
        if (!is_int($decoded['top_k']) || $decoded['top_k'] < 1 || $decoded['top_k'] > 50) {
            return ['__error' => $errorResponse(400, 'invalid_request_envelope', 'top_k must be 1..50.', ['field' => 'top_k'])];
        }
        $topK = $decoded['top_k'];
    }

    $minScore = 0.0;
    if (array_key_exists('min_score', $decoded)) {
        $ms = $decoded['min_score'];
        if (is_int($ms)) {
            $ms = (float) $ms;
        }
        if (!is_float($ms) || $ms < 0.0 || $ms > 1.0) {
            return ['__error' => $errorResponse(400, 'invalid_request_envelope', 'min_score must be 0.0..1.0.', ['field' => 'min_score'])];
        }
        $minScore = $ms;
    }

    $alpha = 0.5;
    if (array_key_exists('alpha', $decoded)) {
        $a = $decoded['alpha'];
        if (is_int($a)) {
            $a = (float) $a;
        }
        if (!is_float($a) || $a < 0.0 || $a > 1.0) {
            return ['__error' => $errorResponse(400, 'invalid_request_envelope', 'alpha must be 0.0..1.0.', ['field' => 'alpha'])];
        }
        $alpha = $a;
    }

    $serviceType = null;
    if (!$forTools && array_key_exists('service_type', $decoded)) {
        if (!is_string($decoded['service_type']) || $decoded['service_type'] === '') {
            return ['__error' => $errorResponse(400, 'invalid_request_envelope', 'service_type must be a non-empty string.', ['field' => 'service_type'])];
        }
        $serviceType = $decoded['service_type'];
    }

    $modelSelector = null;
    if ($mode !== 'keyword') {
        if (!isset($decoded['model_selector']) || !is_array($decoded['model_selector'])) {
            return ['__error' => $errorResponse(400, 'invalid_request_envelope', 'model_selector is required for semantic/hybrid modes.', ['field' => 'model_selector'])];
        }
        $selector = $decoded['model_selector'];
        if (!isset($selector['model_name']) || !is_string($selector['model_name']) || $selector['model_name'] === '') {
            return ['__error' => $errorResponse(400, 'invalid_request_envelope', 'model_selector.model_name required.', ['field' => 'model_selector.model_name'])];
        }
        if (!isset($selector['quantization']) || !is_string($selector['quantization'])) {
            return ['__error' => $errorResponse(400, 'invalid_request_envelope', 'model_selector.quantization required.', ['field' => 'model_selector.quantization'])];
        }
        if (!in_array($selector['quantization'], ['Q2_K', 'Q3_K', 'Q4_0', 'Q4_K', 'Q5_K', 'Q6_K', 'Q8_0', 'F16'], true)) {
            return ['__error' => $errorResponse(400, 'invalid_request_envelope', 'invalid quantization.', ['field' => 'model_selector.quantization'])];
        }
        $modelSelector = [
            'model_name' => $selector['model_name'],
            'quantization' => $selector['quantization'],
        ];
    }

    // G-batch (#W.8 / #W.9): optional graph_expand. Enriches the ranked
    // result with up-to-max_hops graph neighbors. Core ranking is
    // unchanged regardless of this field.
    $graphExpand = null;
    if (!$forTools && array_key_exists('graph_expand', $decoded)) {
        $ge = $decoded['graph_expand'];
        if (!is_array($ge)) {
            return ['__error' => $errorResponse(400, 'invalid_request_envelope', 'graph_expand must be an object.', ['field' => 'graph_expand'])];
        }
        foreach (array_keys($ge) as $k) {
            if (!in_array($k, ['edge_types', 'max_hops'], true)) {
                return ['__error' => $errorResponse(400, 'invalid_request_envelope', 'unknown key inside graph_expand: ' . $k, ['field' => 'graph_expand.' . $k])];
            }
        }
        $maxHops = 1;
        if (array_key_exists('max_hops', $ge)) {
            if (!is_int($ge['max_hops']) || $ge['max_hops'] < 1 || $ge['max_hops'] > 2) {
                return ['__error' => $errorResponse(400, 'invalid_request_envelope', 'graph_expand.max_hops must be int 1..2.', ['field' => 'graph_expand.max_hops'])];
            }
            $maxHops = $ge['max_hops'];
        }
        $edgeTypes = null;
        if (array_key_exists('edge_types', $ge)) {
            if (!is_array($ge['edge_types']) || $ge['edge_types'] !== array_values($ge['edge_types'])) {
                return ['__error' => $errorResponse(400, 'invalid_request_envelope', 'graph_expand.edge_types must be an array of strings.', ['field' => 'graph_expand.edge_types'])];
            }
            $typesNormalized = [];
            foreach ($ge['edge_types'] as $i => $t) {
                if (!is_string($t) || $t === '' || strlen($t) > 64) {
                    return ['__error' => $errorResponse(400, 'invalid_request_envelope', "graph_expand.edge_types[{$i}] invalid.", ['field' => "graph_expand.edge_types[{$i}]"])];
                }
                $typesNormalized[] = $t;
            }
            if (count($typesNormalized) > 16) {
                return ['__error' => $errorResponse(400, 'invalid_request_envelope', 'graph_expand.edge_types must contain at most 16 items.', ['field' => 'graph_expand.edge_types'])];
            }
            $edgeTypes = $typesNormalized;
        }
        $graphExpand = ['max_hops' => $maxHops, 'edge_types' => $edgeTypes];
    }

    return [
        'query' => $query,
        'service_type' => $serviceType,
        'top_k' => $topK,
        'min_score' => $minScore,
        'mode' => $mode,
        'alpha' => $alpha,
        'model_selector' => $modelSelector,
        'graph_expand' => $graphExpand,
    ];
}

/**
 * Keyword-only ranking over service embedding rows using BM25 alone.
 *
 * @param array<int, array<string, mixed>> $rows rows from service_embedding_load_all
 * @return array{
 *     results: array<int, array<string, mixed>>,
 *     result_count: int,
 *     candidates_scanned: int
 * }
 */
function model_inference_keyword_rank_services(array $rows, string $query, int $topK, float $minScore): array
{
    $queryTokens = model_inference_hybrid_tokenize($query);
    $N = count($rows);
    $docFreq = [];
    $tokensByRow = [];
    $totalTokens = 0;
    foreach ($rows as $i => $row) {
        $descriptor = is_array($row['descriptor'] ?? null) ? $row['descriptor'] : [];
        $tokens = model_inference_hybrid_tokenize_descriptor($descriptor);
        $tokensByRow[$i] = $tokens;
        $totalTokens += count($tokens);
        $seen = [];
        foreach ($tokens as $t) {
            if (isset($seen[$t])) continue;
            $seen[$t] = true;
            $docFreq[$t] = ($docFreq[$t] ?? 0) + 1;
        }
    }
    $avgdl = $N > 0 ? ((float) $totalTokens) / $N : 0.0;
    $raw = [];
    foreach ($rows as $i => $row) {
        $raw[$i] = model_inference_hybrid_bm25_score(
            $queryTokens, $tokensByRow[$i], $docFreq, $N, $avgdl, 1.2, 0.75
        );
    }
    $norm = model_inference_hybrid_minmax_normalize($raw);
    $scored = [];
    foreach ($rows as $i => $row) {
        $score = $norm[$i];
        if ($score < $minScore) continue;
        $scored[] = [
            'service_id' => (string) $row['service_id'],
            'service_type' => (string) $row['service_type'],
            'vector_id' => (string) $row['vector_id'],
            'dimensions' => (int) $row['dimensions'],
            'score' => $score,
            'keyword_score' => $score,
            'keyword_raw' => $raw[$i],
            'descriptor' => is_array($row['descriptor'] ?? null) ? $row['descriptor'] : [],
        ];
    }
    usort($scored, static function (array $a, array $b): int {
        $cmp = $b['score'] <=> $a['score'];
        if ($cmp !== 0) return $cmp;
        return strcmp((string) $a['service_id'], (string) $b['service_id']);
    });
    return [
        'results' => array_slice($scored, 0, $topK),
        'result_count' => min(count($scored), $topK),
        'candidates_scanned' => $N,
    ];
}

/**
 * Keyword-only ranking over tool embedding rows using BM25 alone.
 *
 * @param array<int, array<string, mixed>> $rows
 * @return array{results: array<int, array<string, mixed>>, result_count: int, candidates_scanned: int}
 */
function model_inference_keyword_rank_tools(array $rows, string $query, int $topK, float $minScore): array
{
    $queryTokens = model_inference_hybrid_tokenize($query);
    $N = count($rows);
    $docFreq = [];
    $tokensByRow = [];
    $totalTokens = 0;
    foreach ($rows as $i => $row) {
        $descriptor = is_array($row['descriptor'] ?? null) ? $row['descriptor'] : [];
        $tokens = model_inference_hybrid_tokenize_tool_descriptor($descriptor);
        $tokensByRow[$i] = $tokens;
        $totalTokens += count($tokens);
        $seen = [];
        foreach ($tokens as $t) {
            if (isset($seen[$t])) continue;
            $seen[$t] = true;
            $docFreq[$t] = ($docFreq[$t] ?? 0) + 1;
        }
    }
    $avgdl = $N > 0 ? ((float) $totalTokens) / $N : 0.0;
    $raw = [];
    foreach ($rows as $i => $row) {
        $raw[$i] = model_inference_hybrid_bm25_score(
            $queryTokens, $tokensByRow[$i], $docFreq, $N, $avgdl, 1.2, 0.75
        );
    }
    $norm = model_inference_hybrid_minmax_normalize($raw);
    $scored = [];
    foreach ($rows as $i => $row) {
        $score = $norm[$i];
        if ($score < $minScore) continue;
        $descriptor = is_array($row['descriptor'] ?? null) ? $row['descriptor'] : [];
        $scored[] = [
            'tool_id' => (string) $row['tool_id'],
            'vector_id' => (string) $row['vector_id'],
            'dimensions' => (int) $row['dimensions'],
            'mcp_target' => is_array($descriptor['mcp_target'] ?? null) ? $descriptor['mcp_target'] : null,
            'score' => $score,
            'keyword_score' => $score,
            'keyword_raw' => $raw[$i],
            'descriptor' => $descriptor,
        ];
    }
    usort($scored, static function (array $a, array $b): int {
        $cmp = $b['score'] <=> $a['score'];
        if ($cmp !== 0) return $cmp;
        return strcmp((string) $a['tool_id'], (string) $b['tool_id']);
    });
    return [
        'results' => array_slice($scored, 0, $topK),
        'result_count' => min(count($scored), $topK),
        'candidates_scanned' => $N,
    ];
}

/** @param array<string, mixed> $validated */
function model_inference_record_discovery_metric(
    ?callable $getDiscoveryMetrics,
    string $requestId,
    array $validated,
    int $embeddingMs,
    int $searchMs,
    int $candidatesScanned,
    string $mode
): void {
    if ($getDiscoveryMetrics === null) {
        return;
    }
    $metrics = $getDiscoveryMetrics();
    if (!is_object($metrics) || !method_exists($metrics, 'record')) {
        return;
    }
    $metrics->record([
        'request_id' => $requestId,
        'mode' => $mode,
        'embedding_ms' => $embeddingMs,
        'search_ms' => $searchMs,
        'total_ms' => $embeddingMs + $searchMs,
        'candidates_scanned' => $candidatesScanned,
        'query_length' => strlen((string) ($validated['query'] ?? '')),
        'service_type' => (string) ($validated['service_type'] ?? ''),
        'top_k' => (int) ($validated['top_k'] ?? 0),
        'min_score' => (float) ($validated['min_score'] ?? 0.0),
        'alpha' => (float) ($validated['alpha'] ?? 0.0),
    ]);
}
