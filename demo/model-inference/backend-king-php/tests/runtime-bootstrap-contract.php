<?php

declare(strict_types=1);

require_once __DIR__ . '/../http/router.php';

function model_inference_runtime_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[runtime-bootstrap-contract] FAIL: {$message}\n");
    exit(1);
}

/** @return array<string, mixed> */
function model_inference_runtime_contract_json_decode(string $raw): array
{
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

try {
    $jsonResponse = static function (int $status, array $payload): array {
        return [
            'status' => $status,
            'headers' => ['content-type' => 'application/json; charset=utf-8'],
            'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    };

    $errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
        $error = ['code' => $code, 'message' => $message];
        if ($details !== []) {
            $error['details'] = $details;
        }
        return $jsonResponse($status, [
            'status' => 'error',
            'error' => $error,
            'time' => gmdate('c'),
        ]);
    };

    $methodFromRequest = static function (array $request): string {
        $method = strtoupper(trim((string) ($request['method'] ?? 'GET')));
        return $method === '' ? 'GET' : $method;
    };

    $pathFromRequest = static function (array $request): string {
        $path = $request['path'] ?? null;
        if (is_string($path) && $path !== '') {
            return $path;
        }
        $uri = $request['uri'] ?? null;
        if (is_string($uri) && $uri !== '') {
            return (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
        }
        return '/';
    };

    $runtimeEnvelope = static function (): array {
        return [
            'service' => 'model-inference-backend-king-php',
            'app' => [
                'name' => 'king-model-inference-backend',
                'version' => 'contract',
                'environment' => 'test',
            ],
            'runtime' => [
                'king_version' => 'test',
                'transport' => 'king_http1_server_listen_once',
                'ws_path' => '/ws',
                'health' => [
                    'module_status' => 'ok',
                    'system_status' => 'ok',
                    'build' => 'test-build',
                    'module_version' => 'test-module-version',
                    'active_runtime_count' => 0,
                ],
            ],
            'database' => [
                'status' => 'ready',
                'schema_version' => 1,
                'migrations_applied' => 1,
                'migrations_total' => 1,
                'path' => ':memory:',
            ],
            'node' => [
                'node_id' => 'node_contract_test',
                'role' => 'inference-serving',
            ],
            'time' => gmdate('c'),
        ];
    };

    $openDatabase = static function (): PDO {
        throw new RuntimeException('openDatabase should not be called by runtime-bootstrap paths.');
    };
    $getInferenceSession = static function () {
        throw new RuntimeException('inference session should not be reached by runtime-bootstrap paths.');
    };
    $dispatch = static function (string $method, string $path) use (
        $jsonResponse,
        $errorResponse,
        $methodFromRequest,
        $pathFromRequest,
        $runtimeEnvelope,
        $openDatabase,
        $getInferenceSession
    ): array {
        return model_inference_dispatch_request(
            ['method' => $method, 'path' => $path, 'uri' => $path, 'headers' => []],
            $jsonResponse,
            $errorResponse,
            $methodFromRequest,
            $pathFromRequest,
            $runtimeEnvelope,
            $openDatabase,
            $getInferenceSession,
            '/ws',
            '127.0.0.1',
            18090
        );
    };

    // 1. /health returns 200 with status=ok and the expected service label.
    $healthResponse = $dispatch('GET', '/health');
    model_inference_runtime_contract_assert((int) ($healthResponse['status'] ?? 0) === 200, '/health should return 200');
    $healthPayload = model_inference_runtime_contract_json_decode((string) ($healthResponse['body'] ?? ''));
    model_inference_runtime_contract_assert(($healthPayload['status'] ?? null) === 'ok', '/health payload status should be ok');
    model_inference_runtime_contract_assert(($healthPayload['service'] ?? null) === 'model-inference-backend-king-php', '/health service label mismatch');

    // 2. /api/runtime returns 200 with the full runtime envelope including node + runtime.health.
    $runtimeResponse = $dispatch('GET', '/api/runtime');
    model_inference_runtime_contract_assert((int) ($runtimeResponse['status'] ?? 0) === 200, '/api/runtime should return 200');
    $runtimePayload = model_inference_runtime_contract_json_decode((string) ($runtimeResponse['body'] ?? ''));
    model_inference_runtime_contract_assert(is_array($runtimePayload['runtime'] ?? null), '/api/runtime should include runtime section');
    model_inference_runtime_contract_assert(is_array($runtimePayload['runtime']['health'] ?? null), '/api/runtime should include runtime.health');
    model_inference_runtime_contract_assert(($runtimePayload['node']['role'] ?? null) === 'inference-serving', '/api/runtime node.role should be inference-serving');
    model_inference_runtime_contract_assert(($runtimePayload['runtime']['transport'] ?? null) === 'king_http1_server_listen_once', '/api/runtime runtime.transport mismatch');

    // 3. /api/bootstrap returns 200 with status=bootstrapped and the expected endpoint map.
    $bootstrapResponse = $dispatch('GET', '/api/bootstrap');
    model_inference_runtime_contract_assert((int) ($bootstrapResponse['status'] ?? 0) === 200, '/api/bootstrap should return 200');
    $bootstrapPayload = model_inference_runtime_contract_json_decode((string) ($bootstrapResponse['body'] ?? ''));
    model_inference_runtime_contract_assert(($bootstrapPayload['status'] ?? null) === 'bootstrapped', '/api/bootstrap status should be bootstrapped');
    model_inference_runtime_contract_assert(($bootstrapPayload['ws_path'] ?? null) === '/ws', '/api/bootstrap ws_path should be /ws');
    model_inference_runtime_contract_assert(($bootstrapPayload['runtime_endpoint'] ?? null) === '/api/runtime', '/api/bootstrap runtime_endpoint should be /api/runtime');
    model_inference_runtime_contract_assert(($bootstrapPayload['version_endpoint'] ?? null) === '/api/version', '/api/bootstrap version_endpoint should be /api/version');

    // 4. / (root) returns the same bootstrap envelope as /api/bootstrap.
    $rootResponse = $dispatch('GET', '/');
    model_inference_runtime_contract_assert((int) ($rootResponse['status'] ?? 0) === 200, '/ should return 200');
    $rootPayload = model_inference_runtime_contract_json_decode((string) ($rootResponse['body'] ?? ''));
    model_inference_runtime_contract_assert(($rootPayload['status'] ?? null) === 'bootstrapped', '/ status should be bootstrapped');

    // 5. /api/version returns 200 with service + app + runtime summary and no extra sections.
    $versionResponse = $dispatch('GET', '/api/version');
    model_inference_runtime_contract_assert((int) ($versionResponse['status'] ?? 0) === 200, '/api/version should return 200');
    $versionPayload = model_inference_runtime_contract_json_decode((string) ($versionResponse['body'] ?? ''));
    model_inference_runtime_contract_assert(($versionPayload['service'] ?? null) === 'model-inference-backend-king-php', '/api/version service mismatch');
    model_inference_runtime_contract_assert(is_array($versionPayload['runtime'] ?? null), '/api/version should include runtime summary');
    model_inference_runtime_contract_assert(array_key_exists('king_version', $versionPayload['runtime']), '/api/version runtime should expose king_version');
    model_inference_runtime_contract_assert(!isset($versionPayload['node']), '/api/version must not leak node envelope');
    model_inference_runtime_contract_assert(!isset($versionPayload['database']), '/api/version must not leak database envelope');

    // 6. CORS preflight OPTIONS returns 204 with CORS headers.
    $preflightResponse = $dispatch('OPTIONS', '/api/runtime');
    model_inference_runtime_contract_assert((int) ($preflightResponse['status'] ?? 0) === 204, 'OPTIONS should return 204');
    model_inference_runtime_contract_assert(isset($preflightResponse['headers']['access-control-allow-origin']), 'OPTIONS must set CORS origin header');

    // 7. Unknown path returns 404 with error code not_implemented and lists deployed modules.
    $unknownResponse = $dispatch('GET', '/api/does-not-exist');
    model_inference_runtime_contract_assert((int) ($unknownResponse['status'] ?? 0) === 404, 'unknown path should return 404');
    $unknownPayload = model_inference_runtime_contract_json_decode((string) ($unknownResponse['body'] ?? ''));
    model_inference_runtime_contract_assert(($unknownPayload['status'] ?? null) === 'error', 'unknown path payload should be error-shaped');
    model_inference_runtime_contract_assert((($unknownPayload['error'] ?? [])['code'] ?? null) === 'not_implemented', 'unknown path should return not_implemented code');
    model_inference_runtime_contract_assert(is_array((($unknownPayload['error'] ?? [])['details'] ?? [])['deployed_modules'] ?? null), 'unknown path details must list deployed_modules');

    fwrite(STDOUT, "[runtime-bootstrap-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[runtime-bootstrap-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
