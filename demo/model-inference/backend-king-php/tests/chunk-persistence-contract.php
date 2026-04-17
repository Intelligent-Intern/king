<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/retrieval/document_store.php';
require_once __DIR__ . '/../domain/retrieval/text_chunker.php';
require_once __DIR__ . '/../http/router.php';
require_once __DIR__ . '/../domain/telemetry/inference_metrics.php';

function chunk_persistence_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[chunk-persistence-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    // 1. chunk_store_texts and chunk_load_text exist.
    chunk_persistence_contract_assert(
        function_exists('model_inference_chunk_store_texts'),
        'model_inference_chunk_store_texts must exist'
    );
    chunk_persistence_contract_assert(
        function_exists('model_inference_chunk_load_text'),
        'model_inference_chunk_load_text must exist'
    );

    // 2. Graceful fallback when object store is unavailable.
    if (!function_exists('king_object_store_put')) {
        model_inference_chunk_store_texts([['chunk_id' => 'chk-test0001-0000', 'text' => 'hello']]);
        $loaded = model_inference_chunk_load_text('chk-test0001-0000');
        chunk_persistence_contract_assert(
            $loaded === null,
            'chunk_load_text must return null when object store unavailable'
        );
    }

    // 3. Full round-trip: chunk → persist to SQLite → list.
    $dbPath = sys_get_temp_dir() . '/chunk-persist-contract-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        $pdo = model_inference_open_sqlite_pdo($dbPath);
        model_inference_document_schema_migrate($pdo);
        model_inference_chunk_schema_migrate($pdo);

        $pdo->exec("INSERT INTO documents (document_id, object_store_key, byte_length, sha256_hex, content_type, ingested_at) VALUES ('doc-aaaaaaaaaaaaaaaa', 'doc-aaaaaaaaaaaaaaaa', 200, '" . str_repeat('a', 64) . "', 'text/plain', '" . gmdate('c') . "')");

        $text = str_repeat('The quick brown fox jumps over the lazy dog. ', 5);
        $chunks = model_inference_chunk_text($text, 'doc-aaaaaaaaaaaaaaaa', ['chunk_size' => 50, 'overlap' => 10]);

        chunk_persistence_contract_assert(count($chunks) > 0, 'must produce chunks');

        model_inference_chunk_persist($pdo, $chunks);
        $loaded = model_inference_chunk_list_by_document($pdo, 'doc-aaaaaaaaaaaaaaaa');

        chunk_persistence_contract_assert(
            count($loaded) === count($chunks),
            'loaded count must match persisted count (expected ' . count($chunks) . ', got ' . count($loaded) . ')'
        );

        foreach ($loaded as $i => $row) {
            chunk_persistence_contract_assert(
                $row['chunk_id'] === $chunks[$i]['chunk_id'],
                "loaded[$i] chunk_id must match"
            );
            chunk_persistence_contract_assert(
                $row['document_id'] === 'doc-aaaaaaaaaaaaaaaa',
                "loaded[$i] document_id must match"
            );
            chunk_persistence_contract_assert(
                $row['sequence'] === $i,
                "loaded[$i] sequence must be $i"
            );
        }

        // 4. Idempotent persistence (INSERT OR IGNORE).
        model_inference_chunk_persist($pdo, $chunks);
        $reloaded = model_inference_chunk_list_by_document($pdo, 'doc-aaaaaaaaaaaaaaaa');
        chunk_persistence_contract_assert(
            count($reloaded) === count($chunks),
            're-persist must not duplicate rows'
        );
    } finally {
        @unlink($dbPath);
    }

    // 5. GET /api/documents/{id}/chunks dispatches through ingest module.
    $jsonResponse = static function (int $status, array $payload): array {
        return ['status' => $status, 'headers' => ['content-type' => 'application/json'], 'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    };
    $errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
        return $jsonResponse($status, ['status' => 'error', 'error' => ['code' => $code, 'message' => $message, 'details' => $details], 'time' => gmdate('c')]);
    };
    $methodFromRequest = static function (array $r): string { return strtoupper(trim((string) ($r['method'] ?? 'GET'))); };
    $pathFromRequest = static function (array $r): string { return (string) ($r['path'] ?? '/'); };
    $runtimeEnvelope = static function (): array {
        return ['service' => 'test', 'app' => ['name' => 'test', 'version' => 'test', 'environment' => 'test'], 'runtime' => ['king_version' => 'test', 'transport' => 'king_http1_server_listen_once', 'ws_path' => '/ws', 'health' => ['build' => 'b', 'module_version' => 'm']], 'database' => ['status' => 'ready'], 'node' => ['node_id' => 'test', 'role' => 'inference-serving'], 'time' => gmdate('c')];
    };
    $openDatabase = static function (): PDO {
        throw new RuntimeException('openDatabase reached — chunks route is wired.');
    };
    $getInferenceSession = static function () { throw new RuntimeException('not reached'); };
    $getInferenceMetrics = static function () { return new InferenceMetricsRing(); };

    $tripped = false;
    try {
        model_inference_dispatch_request(
            ['method' => 'GET', 'path' => '/api/documents/doc-aaaaaaaaaaaaaaaa/chunks', 'uri' => '/api/documents/doc-aaaaaaaaaaaaaaaa/chunks', 'headers' => []],
            $jsonResponse, $errorResponse, $methodFromRequest, $pathFromRequest,
            $runtimeEnvelope, $openDatabase, $getInferenceSession, $getInferenceMetrics,
            '/ws', '127.0.0.1', 18090
        );
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), 'openDatabase reached')) {
            $tripped = true;
        }
    }
    chunk_persistence_contract_assert($tripped, 'GET /api/documents/{id}/chunks must dispatch through ingest module');

    fwrite(STDOUT, "[chunk-persistence-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[chunk-persistence-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
