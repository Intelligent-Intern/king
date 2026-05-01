<?php

declare(strict_types=1);

require_once __DIR__ . '/../http/module_discover.php';

function discover_envelope_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[discover-envelope-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $rulesAsserted = 0;

    discover_envelope_contract_assert(
        function_exists('model_inference_handle_discover_routes'),
        'model_inference_handle_discover_routes must exist'
    );
    discover_envelope_contract_assert(
        function_exists('model_inference_parse_discover_body'),
        'model_inference_parse_discover_body must exist'
    );
    discover_envelope_contract_assert(
        function_exists('model_inference_keyword_rank_services'),
        'model_inference_keyword_rank_services must exist'
    );
    discover_envelope_contract_assert(
        function_exists('model_inference_keyword_rank_tools'),
        'model_inference_keyword_rank_tools must exist'
    );
    $rulesAsserted += 4;

    // Inline error-response capture.
    $errorResponse = static function (int $status, string $code, string $message, array $details = []): array {
        return [
            'status' => $status,
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode([
                'status' => 'error',
                'error' => ['code' => $code, 'message' => $message, 'details' => $details],
                'time' => gmdate('c'),
            ], JSON_UNESCAPED_SLASHES),
        ];
    };

    // Helper: parse body, return whichever outcome came back.
    $parse = static function (array $body, bool $forTools = false) use ($errorResponse): array {
        $request = ['body' => json_encode($body, JSON_UNESCAPED_SLASHES)];
        $result = model_inference_parse_discover_body($request, $forTools ? '/api/tools/discover' : '/api/discover', $errorResponse, $forTools);
        return $result;
    };

    $parseRaw = static function (string $raw, bool $forTools = false) use ($errorResponse): array {
        return model_inference_parse_discover_body(['body' => $raw], $forTools ? '/api/tools/discover' : '/api/discover', $errorResponse, $forTools);
    };

    // 1. Empty body rejected.
    $out = $parseRaw('');
    discover_envelope_contract_assert(
        isset($out['__error']) && $out['__error']['status'] === 400,
        'empty body -> 400'
    );
    $rulesAsserted++;

    // 2. Invalid JSON rejected.
    $out = $parseRaw('not json');
    discover_envelope_contract_assert(
        isset($out['__error']) && $out['__error']['status'] === 400,
        'invalid json -> 400'
    );
    $rulesAsserted++;

    // 3. Unknown top-level key rejected.
    $out = $parse(['query' => 'q', 'foo' => 'bar', 'mode' => 'keyword']);
    discover_envelope_contract_assert(
        isset($out['__error']) && $out['__error']['status'] === 400,
        'unknown top-level key -> 400'
    );
    $rulesAsserted++;

    // 4. Missing query rejected.
    $out = $parse(['mode' => 'keyword']);
    discover_envelope_contract_assert(isset($out['__error']), 'missing query -> error');
    $rulesAsserted++;

    // 5. Query too long rejected.
    $out = $parse(['query' => str_repeat('x', 2049), 'mode' => 'keyword']);
    discover_envelope_contract_assert(isset($out['__error']), 'query >2048 -> error');
    $rulesAsserted++;

    // 6. Invalid mode rejected.
    $out = $parse(['query' => 'q', 'mode' => 'fuzzy']);
    discover_envelope_contract_assert(isset($out['__error']), 'invalid mode -> error');
    $rulesAsserted++;

    // 7. Invalid top_k rejected.
    foreach ([0, 51, -1, 'abc'] as $bad) {
        $out = $parse(['query' => 'q', 'mode' => 'keyword', 'top_k' => $bad]);
        discover_envelope_contract_assert(isset($out['__error']), "invalid top_k ($bad) rejected");
        $rulesAsserted++;
    }

    // 8. Invalid min_score rejected.
    foreach ([-0.1, 1.1, 'x'] as $bad) {
        $out = $parse(['query' => 'q', 'mode' => 'keyword', 'min_score' => $bad]);
        discover_envelope_contract_assert(isset($out['__error']), "invalid min_score ($bad) rejected");
        $rulesAsserted++;
    }

    // 9. Invalid alpha rejected.
    foreach ([-0.01, 1.01] as $bad) {
        $out = $parse(['query' => 'q', 'mode' => 'hybrid', 'alpha' => $bad, 'model_selector' => ['model_name' => 'm', 'quantization' => 'Q8_0']]);
        discover_envelope_contract_assert(isset($out['__error']), "invalid alpha ($bad) rejected");
        $rulesAsserted++;
    }

    // 10. Hybrid/semantic require model_selector.
    foreach (['semantic', 'hybrid'] as $mode) {
        $out = $parse(['query' => 'q', 'mode' => $mode]);
        discover_envelope_contract_assert(isset($out['__error']), "{$mode} mode without model_selector -> error");
        $rulesAsserted++;
    }

    // 11. Keyword mode accepted without model_selector.
    $out = $parse(['query' => 'find the weather tool', 'mode' => 'keyword']);
    discover_envelope_contract_assert(
        !isset($out['__error']) && $out['mode'] === 'keyword',
        'keyword mode accepted without model_selector'
    );
    discover_envelope_contract_assert($out['query'] === 'find the weather tool', 'query preserved');
    discover_envelope_contract_assert($out['top_k'] === 5, 'top_k defaults to 5');
    discover_envelope_contract_assert($out['min_score'] === 0.0, 'min_score defaults to 0.0');
    discover_envelope_contract_assert($out['alpha'] === 0.5, 'alpha defaults to 0.5');
    $rulesAsserted += 5;

    // 12. Semantic mode with valid model_selector accepted.
    $out = $parse(['query' => 'q', 'mode' => 'semantic', 'service_type' => 'king.inference.v1', 'top_k' => 3, 'min_score' => 0.2, 'model_selector' => ['model_name' => 'm', 'quantization' => 'Q8_0']]);
    discover_envelope_contract_assert(!isset($out['__error']), 'semantic mode envelope accepted');
    discover_envelope_contract_assert($out['mode'] === 'semantic', 'mode=semantic preserved');
    discover_envelope_contract_assert($out['service_type'] === 'king.inference.v1', 'service_type preserved');
    discover_envelope_contract_assert($out['top_k'] === 3, 'top_k preserved');
    discover_envelope_contract_assert($out['min_score'] === 0.2, 'min_score preserved');
    discover_envelope_contract_assert($out['model_selector']['model_name'] === 'm', 'model selector preserved');
    $rulesAsserted += 6;

    // 13. Invalid quantization rejected.
    $out = $parse(['query' => 'q', 'mode' => 'semantic', 'model_selector' => ['model_name' => 'm', 'quantization' => 'INVALID']]);
    discover_envelope_contract_assert(isset($out['__error']), 'invalid quantization -> error');
    $rulesAsserted++;

    // 14. Tools envelope rejects service_type.
    $out = $parse(['query' => 'q', 'mode' => 'keyword', 'service_type' => 'king.tool.v1'], true);
    discover_envelope_contract_assert(isset($out['__error']), 'service_type on tools envelope -> 400');
    $rulesAsserted++;

    // 15. Method-not-allowed on GET /api/discover.
    $jsonResponse = static function (int $status, array $payload): array {
        return ['status' => $status, 'headers' => ['content-type' => 'application/json'], 'body' => json_encode($payload)];
    };
    $openDatabase = static function (): PDO {
        throw new RuntimeException('database should not be opened for method-check');
    };
    $mnaResponse = model_inference_handle_discover_routes(
        '/api/discover', 'GET',
        [],
        $jsonResponse, $errorResponse, $openDatabase,
        null, null
    );
    discover_envelope_contract_assert(
        is_array($mnaResponse) && $mnaResponse['status'] === 405,
        'GET /api/discover -> 405'
    );
    $rulesAsserted++;

    $mnaResponseTools = model_inference_handle_discover_routes(
        '/api/tools/discover', 'GET',
        [],
        $jsonResponse, $errorResponse, $openDatabase,
        null, null
    );
    discover_envelope_contract_assert(
        is_array($mnaResponseTools) && $mnaResponseTools['status'] === 405,
        'GET /api/tools/discover -> 405'
    );
    $rulesAsserted++;

    // 16. Non-matching paths fall through.
    $none = model_inference_handle_discover_routes('/api/runtime', 'GET', [], $jsonResponse, $errorResponse, $openDatabase, null, null);
    discover_envelope_contract_assert($none === null, 'non-matching path returns null (fall-through)');
    $rulesAsserted++;

    // 17. Keyword rank function handles empty input.
    $kw = model_inference_keyword_rank_services([], 'query', 5, 0.0);
    discover_envelope_contract_assert($kw['result_count'] === 0, 'empty rows -> 0 results');
    discover_envelope_contract_assert($kw['candidates_scanned'] === 0, 'empty rows -> 0 candidates');
    $rulesAsserted += 2;

    $kwTools = model_inference_keyword_rank_tools([], 'query', 5, 0.0);
    discover_envelope_contract_assert($kwTools['result_count'] === 0, 'empty tool rows -> 0 results');
    $rulesAsserted++;

    // 18. Keyword rank handles inline rows with known token overlap.
    $rows = [
        [
            'service_id' => 'svc-weather',
            'service_type' => 'king.tool.v1',
            'vector_id' => 'svec-1',
            'dimensions' => 4,
            'descriptor' => ['name' => 'weather', 'description' => 'weather forecast lookup'],
        ],
        [
            'service_id' => 'svc-stocks',
            'service_type' => 'king.tool.v1',
            'vector_id' => 'svec-2',
            'dimensions' => 4,
            'descriptor' => ['name' => 'stocks', 'description' => 'market quotes ticker'],
        ],
    ];
    $kwResult = model_inference_keyword_rank_services($rows, 'weather forecast', 5, 0.0);
    discover_envelope_contract_assert($kwResult['result_count'] === 2, 'keyword rank returns all matching rows');
    discover_envelope_contract_assert($kwResult['results'][0]['service_id'] === 'svc-weather', 'weather ranks first');
    discover_envelope_contract_assert($kwResult['results'][0]['keyword_score'] > $kwResult['results'][1]['keyword_score'], 'weather score > stocks score');
    $rulesAsserted += 3;

    fwrite(STDOUT, "[discover-envelope-contract] PASS ({$rulesAsserted} rules asserted)\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[discover-envelope-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
