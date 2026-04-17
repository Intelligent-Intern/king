<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../domain/retrieval/document_store.php';
require_once __DIR__ . '/../http/router.php';
require_once __DIR__ . '/../domain/telemetry/inference_metrics.php';

function document_ingest_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[document-ingest-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    // 1. Function signatures exist.
    document_ingest_contract_assert(
        function_exists('model_inference_document_generate_id'),
        'model_inference_document_generate_id must exist'
    );
    document_ingest_contract_assert(
        function_exists('model_inference_document_ingest'),
        'model_inference_document_ingest must exist'
    );
    document_ingest_contract_assert(
        function_exists('model_inference_document_get'),
        'model_inference_document_get must exist'
    );
    document_ingest_contract_assert(
        function_exists('model_inference_document_list'),
        'model_inference_document_list must exist'
    );
    document_ingest_contract_assert(
        function_exists('model_inference_document_schema_migrate'),
        'model_inference_document_schema_migrate must exist'
    );

    // 2. Document ID format.
    $id = model_inference_document_generate_id();
    document_ingest_contract_assert(
        preg_match('/^doc-[a-f0-9]{16}$/', $id) === 1,
        'document ID must match doc-{16hex} (got ' . $id . ')'
    );

    // 3. Schema migration creates documents table.
    $dbPath = sys_get_temp_dir() . '/doc-ingest-contract-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        $pdo = model_inference_open_sqlite_pdo($dbPath);
        model_inference_document_schema_migrate($pdo);

        $columns = $pdo->query('PRAGMA table_info(documents)')->fetchAll();
        $columnNames = array_column($columns, 'name');
        foreach (['document_id', 'object_store_key', 'byte_length', 'sha256_hex', 'content_type', 'ingested_at'] as $col) {
            document_ingest_contract_assert(
                in_array($col, $columnNames, true),
                "documents table must have {$col} column"
            );
        }

        // 4. document_get returns null for non-existent ID.
        $missing = model_inference_document_get($pdo, 'doc-0000000000000000');
        document_ingest_contract_assert($missing === null, 'document_get must return null for non-existent ID');

        // 5. document_list returns empty array on clean DB.
        $list = model_inference_document_list($pdo);
        document_ingest_contract_assert(is_array($list) && count($list) === 0, 'document_list must return empty array on clean DB');
    } finally {
        @unlink($dbPath);
    }

    // 6. Dispatcher wires /api/documents routes.
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
        throw new RuntimeException('openDatabase reached — ingest module is wired.');
    };
    $getInferenceSession = static function () { throw new RuntimeException('not reached'); };
    $getInferenceMetrics = static function () { return new InferenceMetricsRing(); };

    // GET /api/documents trips openDatabase (proves it's wired).
    $tripped = false;
    try {
        model_inference_dispatch_request(
            ['method' => 'GET', 'path' => '/api/documents', 'uri' => '/api/documents', 'headers' => []],
            $jsonResponse, $errorResponse, $methodFromRequest, $pathFromRequest,
            $runtimeEnvelope, $openDatabase, $getInferenceSession, $getInferenceMetrics,
            '/ws', '127.0.0.1', 18090
        );
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), 'openDatabase reached')) {
            $tripped = true;
        }
    }
    document_ingest_contract_assert($tripped, 'GET /api/documents must dispatch through ingest module');

    // POST /api/documents with empty body returns 400.
    $emptyResp = model_inference_dispatch_request(
        ['method' => 'POST', 'path' => '/api/documents', 'uri' => '/api/documents', 'headers' => [], 'body' => ''],
        $jsonResponse, $errorResponse, $methodFromRequest, $pathFromRequest,
        $runtimeEnvelope, $openDatabase, $getInferenceSession, $getInferenceMetrics,
        '/ws', '127.0.0.1', 18090
    );
    document_ingest_contract_assert((int) ($emptyResp['status'] ?? 0) === 400, 'POST /api/documents with empty body must return 400');

    // GET /api/documents/{doc_id} trips openDatabase.
    $trippedGet = false;
    try {
        model_inference_dispatch_request(
            ['method' => 'GET', 'path' => '/api/documents/doc-0000000000000000', 'uri' => '/api/documents/doc-0000000000000000', 'headers' => []],
            $jsonResponse, $errorResponse, $methodFromRequest, $pathFromRequest,
            $runtimeEnvelope, $openDatabase, $getInferenceSession, $getInferenceMetrics,
            '/ws', '127.0.0.1', 18090
        );
    } catch (RuntimeException $e) {
        if (str_contains($e->getMessage(), 'openDatabase reached')) {
            $trippedGet = true;
        }
    }
    document_ingest_contract_assert($trippedGet, 'GET /api/documents/{id} must dispatch through ingest module');

    // Module order includes ingest.
    $order = model_inference_dispatch_route_module_order();
    document_ingest_contract_assert(in_array('ingest', $order, true), 'module order must include ingest');

    fwrite(STDOUT, "[document-ingest-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[document-ingest-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
