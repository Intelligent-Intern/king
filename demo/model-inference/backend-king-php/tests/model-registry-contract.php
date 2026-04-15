<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/database.php';
require_once __DIR__ . '/../support/object_store.php';
require_once __DIR__ . '/../domain/registry/model_registry.php';
require_once __DIR__ . '/../http/router.php';

function model_inference_registry_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[model-registry-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    model_inference_registry_contract_assert(extension_loaded('king'), 'king extension must be loaded');
    model_inference_registry_contract_assert(function_exists('king_object_store_init'), 'king_object_store_init must be available');

    $tmpRoot = sys_get_temp_dir() . '/king-model-inference-test-' . bin2hex(random_bytes(6));
    $storageRoot = $tmpRoot . '/object-store';
    $dbPath = $tmpRoot . '/registry.sqlite';

    @mkdir($tmpRoot, 0775, true);
    @mkdir($storageRoot, 0775, true);

    model_inference_object_store_init($storageRoot, 256 * 1024 * 1024);

    $pdo = model_inference_open_sqlite_pdo($dbPath);
    model_inference_registry_schema_migrate($pdo);

    // 1. Empty registry: GET /api/models returns empty list.
    $jsonResponse = static function (int $status, array $payload): array {
        return ['status' => $status, 'headers' => ['content-type' => 'application/json'], 'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    };
    $errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
        return $jsonResponse($status, ['status' => 'error', 'error' => ['code' => $code, 'message' => $message, 'details' => $details], 'time' => gmdate('c')]);
    };
    $methodFromRequest = static function (array $request): string {
        return strtoupper(trim((string) ($request['method'] ?? 'GET')));
    };
    $pathFromRequest = static function (array $request): string {
        return (string) ($request['path'] ?? '/');
    };
    $runtimeEnvelope = static function (): array {
        return ['node' => ['node_id' => 'node_registry_contract', 'role' => 'inference-serving']];
    };
    $openDatabase = static function () use ($pdo): PDO {
        return $pdo;
    };
    $dispatch = static function (string $method, string $path, array $headers = [], string $body = '') use (
        $jsonResponse,
        $errorResponse,
        $methodFromRequest,
        $pathFromRequest,
        $runtimeEnvelope,
        $openDatabase
    ): array {
        return model_inference_dispatch_request(
            ['method' => $method, 'path' => $path, 'uri' => $path, 'headers' => $headers, 'body' => $body],
            $jsonResponse,
            $errorResponse,
            $methodFromRequest,
            $pathFromRequest,
            $runtimeEnvelope,
            $openDatabase,
            '/ws',
            '127.0.0.1',
            18090
        );
    };

    $listEmpty = $dispatch('GET', '/api/models');
    model_inference_registry_contract_assert((int) ($listEmpty['status'] ?? 0) === 200, 'empty list should return 200');
    $listEmptyPayload = json_decode((string) ($listEmpty['body'] ?? ''), true);
    model_inference_registry_contract_assert(is_array($listEmptyPayload) && ($listEmptyPayload['count'] ?? null) === 0, 'empty list should report count=0');
    model_inference_registry_contract_assert(($listEmptyPayload['items'] ?? null) === [], 'empty list items must be []');

    // 2. POST /api/models with valid metadata + deterministic body creates a model.
    $artifactBytes = random_bytes(128 * 1024); // 128 KiB fake GGUF
    $expectedSha = hash('sha256', $artifactBytes);
    $headers = [
        'X-Model-Name' => 'TinyLlama-Test',
        'X-Model-Family' => 'llama',
        'X-Model-Quantization' => 'Q4_0',
        'X-Model-Parameter-Count' => '1100000000',
        'X-Model-Context-Length' => '2048',
        'X-Model-License' => 'apache-2.0',
        'X-Model-Min-Ram-Bytes' => '2147483648',
        'X-Model-Min-Vram-Bytes' => '0',
        'X-Model-Prefers-Gpu' => '0',
        'X-Model-Source-Url' => 'https://example.invalid/tinyllama-test-q4_0.gguf',
    ];
    $createResponse = $dispatch('POST', '/api/models', $headers, $artifactBytes);
    model_inference_registry_contract_assert((int) ($createResponse['status'] ?? 0) === 201, 'create should return 201 (got ' . $createResponse['status'] . ')');
    $createPayload = json_decode((string) ($createResponse['body'] ?? ''), true);
    model_inference_registry_contract_assert(is_array($createPayload), 'create payload must be object');
    model_inference_registry_contract_assert(($createPayload['status'] ?? null) === 'created', 'create payload status must be "created"');
    $envelope = $createPayload['model'] ?? null;
    model_inference_registry_contract_assert(is_array($envelope), 'create payload must carry model envelope');
    model_inference_registry_contract_assert(preg_match('/^mdl-[a-f0-9]{16}$/', (string) ($envelope['model_id'] ?? '')) === 1, 'model_id must match mdl-<16hex>');
    model_inference_registry_contract_assert(($envelope['model_name'] ?? null) === 'TinyLlama-Test', 'model_name passthrough');
    model_inference_registry_contract_assert(($envelope['quantization'] ?? null) === 'Q4_0', 'quantization passthrough');
    model_inference_registry_contract_assert(((int) ($envelope['artifact']['byte_length'] ?? 0)) === strlen($artifactBytes), 'artifact.byte_length must equal body length');
    model_inference_registry_contract_assert(($envelope['artifact']['sha256_hex'] ?? null) === $expectedSha, 'artifact.sha256_hex must equal hash of request body (bit-identical round-trip)');
    model_inference_registry_contract_assert(($envelope['artifact']['object_store_key'] ?? null) === ($envelope['model_id'] ?? null), 'object_store_key must equal model_id');
    model_inference_registry_contract_assert(($envelope['source_url'] ?? null) === 'https://example.invalid/tinyllama-test-q4_0.gguf', 'source_url passthrough');
    $modelId = (string) $envelope['model_id'];

    // 3. Bit-identical readback: king_object_store_get returns original bytes.
    $readback = king_object_store_get($modelId);
    model_inference_registry_contract_assert(hash('sha256', (string) $readback) === $expectedSha, 'direct king_object_store_get readback must match original SHA-256');

    // 4. GET /api/models/{model_id} returns the same envelope.
    $getResponse = $dispatch('GET', '/api/models/' . $modelId);
    model_inference_registry_contract_assert((int) ($getResponse['status'] ?? 0) === 200, 'get should return 200');
    $getPayload = json_decode((string) ($getResponse['body'] ?? ''), true);
    model_inference_registry_contract_assert(is_array($getPayload) && ($getPayload['model']['model_id'] ?? null) === $modelId, 'get must return the same model_id');
    model_inference_registry_contract_assert(($getPayload['model']['artifact']['sha256_hex'] ?? null) === $expectedSha, 'get envelope must carry the same sha256_hex');

    // 5. GET /api/models lists exactly one item with the expected envelope.
    $listFull = $dispatch('GET', '/api/models');
    $listFullPayload = json_decode((string) ($listFull['body'] ?? ''), true);
    model_inference_registry_contract_assert(is_array($listFullPayload) && ($listFullPayload['count'] ?? null) === 1, 'list count must be 1 after create');
    model_inference_registry_contract_assert(($listFullPayload['items'][0]['model_id'] ?? null) === $modelId, 'list item model_id mismatch');

    // 6. Duplicate create (same name + quantization) conflicts with 409.
    $dupResponse = $dispatch('POST', '/api/models', $headers, $artifactBytes);
    model_inference_registry_contract_assert((int) ($dupResponse['status'] ?? 0) === 409, 'duplicate create should 409 (got ' . $dupResponse['status'] . ')');
    $dupPayload = json_decode((string) ($dupResponse['body'] ?? ''), true);
    model_inference_registry_contract_assert((($dupPayload['error'] ?? [])['code'] ?? null) === 'model_registry_conflict', 'duplicate create must emit model_registry_conflict');

    // 7. Missing header validation returns 400 invalid_request_envelope.
    $badHeaders = $headers;
    unset($badHeaders['X-Model-Quantization']);
    $badResponse = $dispatch('POST', '/api/models', $badHeaders, $artifactBytes);
    model_inference_registry_contract_assert((int) ($badResponse['status'] ?? 0) === 400, 'missing quantization should 400');
    $badPayload = json_decode((string) ($badResponse['body'] ?? ''), true);
    model_inference_registry_contract_assert((($badPayload['error'] ?? [])['code'] ?? null) === 'invalid_request_envelope', 'missing quantization must emit invalid_request_envelope');
    model_inference_registry_contract_assert((($badPayload['error']['details'] ?? [])['field'] ?? null) === 'quantization', 'error details must name the failing field');

    // 8. Empty body returns 400 invalid_request_envelope.
    $emptyResponse = $dispatch('POST', '/api/models', $headers, '');
    model_inference_registry_contract_assert((int) ($emptyResponse['status'] ?? 0) === 400, 'empty body should 400');
    $emptyPayload = json_decode((string) ($emptyResponse['body'] ?? ''), true);
    model_inference_registry_contract_assert((($emptyPayload['error']['details'] ?? [])['field'] ?? null) === 'body', 'empty-body error must reference body field');

    // 9. GET a non-existent model returns 404 model_not_found.
    $missingResponse = $dispatch('GET', '/api/models/mdl-00000000deadbeef');
    model_inference_registry_contract_assert((int) ($missingResponse['status'] ?? 0) === 404, 'missing model get should 404');
    $missingPayload = json_decode((string) ($missingResponse['body'] ?? ''), true);
    model_inference_registry_contract_assert((($missingPayload['error'] ?? [])['code'] ?? null) === 'model_not_found', 'missing model must emit model_not_found');

    // 10. DELETE removes the model from both the registry and the object store.
    $deleteResponse = $dispatch('DELETE', '/api/models/' . $modelId);
    model_inference_registry_contract_assert((int) ($deleteResponse['status'] ?? 0) === 200, 'delete should return 200');
    $deletePayload = json_decode((string) ($deleteResponse['body'] ?? ''), true);
    model_inference_registry_contract_assert(($deletePayload['status'] ?? null) === 'deleted', 'delete payload status must be "deleted"');

    $afterDelete = $dispatch('GET', '/api/models/' . $modelId);
    model_inference_registry_contract_assert((int) ($afterDelete['status'] ?? 0) === 404, 'get after delete should 404');
    $afterGetBytes = king_object_store_get($modelId);
    model_inference_registry_contract_assert($afterGetBytes === false, 'object-store delete must drop the artifact bytes too');

    // 11. Catalog known error codes ∩ this leaf's codes.
    $catalogRaw = file_get_contents(__DIR__ . '/../../contracts/v1/api-ws-contract.catalog.json');
    $catalog = is_string($catalogRaw) ? (json_decode($catalogRaw, true) ?? []) : [];
    $catalogCodes = (array) (($catalog['errors'] ?? [])['codes'] ?? []);
    foreach (['model_not_found', 'invalid_request_envelope', 'model_registry_conflict', 'model_artifact_write_failed', 'model_artifact_too_large', 'method_not_allowed'] as $required) {
        model_inference_registry_contract_assert(in_array($required, $catalogCodes, true), "catalog.errors.codes must list '{$required}'");
    }

    // Cleanup.
    @unlink($dbPath);
    foreach (glob($storageRoot . '/*') ?: [] as $entry) {
        if (is_dir($entry)) {
            foreach (glob($entry . '/*') ?: [] as $inner) {
                @unlink($inner);
            }
            @rmdir($entry);
        } else {
            @unlink($entry);
        }
    }
    @rmdir($storageRoot);
    @rmdir($tmpRoot);

    fwrite(STDOUT, "[model-registry-contract] PASS (sha256 bit-identical; " . strlen($artifactBytes) . " byte round-trip through king_object_store_put_from_stream)\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[model-registry-contract] ERROR: ' . $error->getMessage() . ' @ ' . $error->getFile() . ':' . $error->getLine() . "\n");
    exit(1);
}
