<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/discovery/dns_semantic_query.php';

function dns_semantic_query_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[dns-semantic-query-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $rulesAsserted = 0;

    dns_semantic_query_contract_assert(
        function_exists('model_inference_dns_discover_with_semantic_query'),
        'model_inference_dns_discover_with_semantic_query exists'
    );
    $rulesAsserted++;

    $dbPath = sys_get_temp_dir() . '/dns-semantic-query-contract-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        $pdo = model_inference_open_sqlite_pdo($dbPath);
        model_inference_service_embedding_schema_migrate($pdo);

        // Validation rejections.
        $rej = false;
        try {
            model_inference_dns_discover_with_semantic_query($pdo, 'king.inference.v1', 'q', [0.1], -2.0, 5);
        } catch (InvalidArgumentException $e) {
            $rej = true;
        }
        dns_semantic_query_contract_assert($rej, 'min_semantic_score < -1 rejected');
        $rulesAsserted++;

        $rej = false;
        try {
            model_inference_dns_discover_with_semantic_query($pdo, 'king.inference.v1', 'q', [], 0.0, 5);
        } catch (InvalidArgumentException $e) {
            $rej = true;
        }
        dns_semantic_query_contract_assert($rej, 'empty query_vector rejected');
        $rulesAsserted++;

        $rej = false;
        try {
            model_inference_dns_discover_with_semantic_query($pdo, 'king.inference.v1', 'q', [0.1], 0.0, 0);
        } catch (InvalidArgumentException $e) {
            $rej = true;
        }
        dns_semantic_query_contract_assert($rej, 'top_k 0 rejected');
        $rulesAsserted++;

        // Empty state — no DNS extension, empty embeddings.
        $empty = model_inference_dns_discover_with_semantic_query(
            $pdo, 'king.inference.v1', 'any', [0.1, 0.2, 0.3, 0.4], 0.0, 5
        );
        dns_semantic_query_contract_assert($empty['result_count'] === 0, 'empty everything -> 0 results');
        dns_semantic_query_contract_assert($empty['candidates_scanned_embeddings'] === 0, 'candidates_scanned_embeddings=0');
        dns_semantic_query_contract_assert($empty['query'] === 'any', 'query echoed');
        dns_semantic_query_contract_assert($empty['min_semantic_score'] === 0.0, 'min_semantic_score echoed');
        dns_semantic_query_contract_assert(array_key_exists('keyword_path_available', $empty), 'keyword_path_available flag present');
        $rulesAsserted += 5;

        if (function_exists('king_object_store_put') && function_exists('king_object_store_get')) {
            require_once __DIR__ . '/../domain/discovery/service_embedding_upsert.php';

            // Seed two services in embeddings (no DNS registration yet).
            model_inference_service_embedding_upsert(
                $pdo,
                ['service_id' => 'svc-node-a', 'service_type' => 'king.inference.v1', 'name' => 'node-a', 'description' => 'primary'],
                'mdl',
                static fn () => ['vector' => [1.0, 0.0, 0.0, 0.0]]
            );
            model_inference_service_embedding_upsert(
                $pdo,
                ['service_id' => 'svc-node-b', 'service_type' => 'king.inference.v1', 'name' => 'node-b', 'description' => 'secondary'],
                'mdl',
                static fn () => ['vector' => [0.0, 1.0, 0.0, 0.0]]
            );

            // Without DNS registration, the intersection is empty EVEN WITH strong embedding match.
            $withoutDns = model_inference_dns_discover_with_semantic_query(
                $pdo, 'king.inference.v1', 'primary inference', [1.0, 0.0, 0.0, 0.0], 0.0, 5
            );
            dns_semantic_query_contract_assert(
                $withoutDns['candidates_scanned_embeddings'] >= 2,
                'embeddings scan >= 2 rows after seeding'
            );
            // On a host without king_semantic_dns_register_service loaded, no DNS candidates exist,
            // so intersection produces 0 results.
            if (!function_exists('king_semantic_dns_register_service')) {
                dns_semantic_query_contract_assert(
                    $withoutDns['result_count'] === 0,
                    'no DNS + embeddings-only -> empty intersection (fail-closed contract)'
                );
                $rulesAsserted++;
            }
            $rulesAsserted++;
        }

        // Telemetry: search_ms is an int.
        dns_semantic_query_contract_assert(is_int($empty['semantic_search_ms']), 'semantic_search_ms is int');
        $rulesAsserted++;
    } finally {
        @unlink($dbPath);
    }

    fwrite(STDOUT, "[dns-semantic-query-contract] PASS ({$rulesAsserted} rules asserted)\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[dns-semantic-query-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
