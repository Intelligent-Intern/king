<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/discovery/service_embedding_upsert.php';

function service_embedding_upsert_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[service-embedding-upsert-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    $rulesAsserted = 0;

    // 1. Function exists.
    service_embedding_upsert_contract_assert(
        function_exists('model_inference_service_embedding_upsert'),
        'model_inference_service_embedding_upsert must exist'
    );
    service_embedding_upsert_contract_assert(
        function_exists('model_inference_service_embedding_session_embedder'),
        'model_inference_service_embedding_session_embedder must exist'
    );
    $rulesAsserted += 2;

    $dbPath = sys_get_temp_dir() . '/service-embedding-upsert-contract-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        $pdo = model_inference_open_sqlite_pdo($dbPath);
        model_inference_service_embedding_schema_migrate($pdo);

        $descriptor = [
            'service_id' => 'svc-node-a',
            'service_type' => 'king.inference.v1',
            'name' => 'node-a',
            'description' => 'Primary inference node serving SmolLM2-135M-Instruct with Q4_K quantization.',
            'capabilities' => ['chat', 'streaming'],
            'tags' => ['primary'],
        ];

        // 2. Empty embedding_model_id rejected up front.
        $rejected = false;
        try {
            model_inference_service_embedding_upsert($pdo, $descriptor, '', static fn (): array => ['vector' => [0.1]]);
        } catch (InvalidArgumentException $e) {
            $rejected = true;
        }
        service_embedding_upsert_contract_assert($rejected, 'empty embedding_model_id must be rejected');
        $rulesAsserted++;

        // 3. Descriptor validation errors propagate.
        $rejected2 = false;
        try {
            model_inference_service_embedding_upsert(
                $pdo,
                ['service_id' => '', 'service_type' => 'x', 'name' => 'n', 'description' => 'd'],
                'mdl-test',
                static fn (): array => ['vector' => [0.1]]
            );
        } catch (ServiceDescriptorValidationError $e) {
            $rejected2 = true;
        }
        service_embedding_upsert_contract_assert($rejected2, 'invalid descriptor must raise ServiceDescriptorValidationError');
        $rulesAsserted++;

        // 4. Embedder returning non-array rejected.
        $rejected3 = false;
        try {
            model_inference_service_embedding_upsert(
                $pdo,
                $descriptor,
                'mdl-test',
                static fn (): string => 'not-an-array'
            );
        } catch (RuntimeException $e) {
            $rejected3 = str_contains($e->getMessage(), 'embedder_returned_invalid_shape');
        }
        service_embedding_upsert_contract_assert($rejected3, 'non-array embedder result must be rejected');
        $rulesAsserted++;

        // 5. Embedder returning missing vector key rejected.
        $rejected4 = false;
        try {
            model_inference_service_embedding_upsert(
                $pdo,
                $descriptor,
                'mdl-test',
                static fn (): array => ['duration_ms' => 10]
            );
        } catch (RuntimeException $e) {
            $rejected4 = str_contains($e->getMessage(), 'embedder_returned_invalid_shape');
        }
        service_embedding_upsert_contract_assert($rejected4, 'missing vector key must be rejected');
        $rulesAsserted++;

        // 6. Embedder returning empty vector rejected.
        $rejected5 = false;
        try {
            model_inference_service_embedding_upsert(
                $pdo,
                $descriptor,
                'mdl-test',
                static fn (): array => ['vector' => []]
            );
        } catch (RuntimeException $e) {
            $rejected5 = str_contains($e->getMessage(), 'empty_embedding_vector');
        }
        service_embedding_upsert_contract_assert($rejected5, 'empty vector must be rejected');
        $rulesAsserted++;

        // 7. Embedder is called with the descriptor embedding text.
        $capturedText = null;
        $fakeEmbedder = static function (string $text) use (&$capturedText): array {
            $capturedText = $text;
            return ['vector' => [0.1, 0.2, 0.3, 0.4], 'duration_ms' => 42, 'tokens_used' => 7];
        };

        // With no object-store extension, the store call fails closed — this still proves the
        // embedder was invoked with the right text before the store call tried to execute.
        try {
            model_inference_service_embedding_upsert($pdo, $descriptor, 'mdl-test', $fakeEmbedder);
        } catch (RuntimeException $e) {
            if (!function_exists('king_object_store_put')) {
                service_embedding_upsert_contract_assert(
                    str_contains($e->getMessage(), 'king_object_store_put not available'),
                    'expected fail-closed without extension (got: ' . $e->getMessage() . ')'
                );
                $rulesAsserted++;
            } else {
                throw $e;
            }
        }

        service_embedding_upsert_contract_assert(
            is_string($capturedText) && str_contains($capturedText, 'node-a'),
            'embedder received text containing name'
        );
        service_embedding_upsert_contract_assert(
            str_contains((string) $capturedText, 'SmolLM2-135M-Instruct'),
            'embedder received text containing description'
        );
        service_embedding_upsert_contract_assert(
            str_contains((string) $capturedText, 'chat'),
            'embedder received text containing capability'
        );
        service_embedding_upsert_contract_assert(
            str_contains((string) $capturedText, 'primary'),
            'embedder received text containing tag'
        );
        $rulesAsserted += 4;

        // 8. Full round trip when object-store extension is loaded.
        if (function_exists('king_object_store_put') && function_exists('king_object_store_get')) {
            $result = model_inference_service_embedding_upsert(
                $pdo,
                $descriptor,
                'mdl-test',
                $fakeEmbedder
            );
            service_embedding_upsert_contract_assert($result['service_id'] === 'svc-node-a', 'service_id returned');
            service_embedding_upsert_contract_assert($result['dimensions'] === 4, 'dimensions returned');
            service_embedding_upsert_contract_assert($result['embedding_duration_ms'] === 42, 'embedding_duration_ms returned');
            service_embedding_upsert_contract_assert($result['tokens_used'] === 7, 'tokens_used returned');
            service_embedding_upsert_contract_assert($result['replaced'] === false, 'first insert replaced=false');
            $rulesAsserted += 5;

            // Second call replaces.
            $result2 = model_inference_service_embedding_upsert(
                $pdo,
                $descriptor,
                'mdl-test',
                static fn (): array => ['vector' => [0.5, 0.5, 0.5, 0.5], 'duration_ms' => 30, 'tokens_used' => 5]
            );
            service_embedding_upsert_contract_assert($result2['replaced'] === true, 'second insert replaced=true');
            service_embedding_upsert_contract_assert($result2['vector_id'] === $result['vector_id'], 'vector_id stable across replace');

            // Round-trip vector from object-store.
            $vec = model_inference_service_vector_load($result2['vector_id']);
            service_embedding_upsert_contract_assert(is_array($vec) && count($vec) === 4, 'vector roundtrip from object store');
            $rulesAsserted += 3;
        }

        // 9. session embedder adapter shape.
        $fakeSession = new class {
            /** @return array<string, mixed> */
            public function embed(object $worker, array $texts, bool $normalize): array
            {
                return [
                    'embeddings' => [array_map(static fn (string $t): float => (float) strlen($t) / 100.0, $texts)],
                    'duration_ms' => 11,
                    'tokens_used' => 3,
                ];
            }
        };
        $fakeWorker = new class { };
        $adapter = model_inference_service_embedding_session_embedder($fakeSession, $fakeWorker, true);
        $adapted = $adapter('hello world');
        service_embedding_upsert_contract_assert(is_array($adapted['vector']), 'adapter returns vector array');
        service_embedding_upsert_contract_assert($adapted['duration_ms'] === 11, 'adapter preserves duration_ms');
        service_embedding_upsert_contract_assert($adapted['tokens_used'] === 3, 'adapter preserves tokens_used');
        $rulesAsserted += 3;
    } finally {
        @unlink($dbPath);
    }

    fwrite(STDOUT, "[service-embedding-upsert-contract] PASS ({$rulesAsserted} rules asserted)\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[service-embedding-upsert-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
