<?php

declare(strict_types=1);

require_once __DIR__ . '/../http/router.php';

function model_inference_catalog_contract_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, "[contract-catalog-parity-contract] FAIL: {$message}\n");
    exit(1);
}

/** @return array<string, mixed> */
function model_inference_catalog_contract_json_decode(string $raw): array
{
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

try {
    $catalogPath = realpath(__DIR__ . '/../../contracts/v1/api-ws-contract.catalog.json');
    model_inference_catalog_contract_assert(
        is_string($catalogPath) && is_file($catalogPath),
        'catalog fixture not found at demo/model-inference/contracts/v1/api-ws-contract.catalog.json'
    );

    $raw = file_get_contents($catalogPath);
    model_inference_catalog_contract_assert($raw !== false, 'failed to read catalog fixture');

    $catalog = json_decode((string) $raw, true);
    model_inference_catalog_contract_assert(is_array($catalog), 'catalog fixture is not valid JSON object');

    // Envelope invariants.
    model_inference_catalog_contract_assert(
        (string) ($catalog['catalog_version'] ?? '') === 'v1.0.0',
        'catalog_version must be v1.0.0'
    );
    model_inference_catalog_contract_assert(
        (string) ($catalog['catalog_name'] ?? '') === 'king-model-inference-api-ws',
        'catalog_name must be king-model-inference-api-ws'
    );

    // Live surface: must be exhaustively declared under catalog.api.
    // Each entry is (method, paths[]) owned by the currently deployed
    // dispatcher. Update this list together with the catalog when a new
    // route lands.
    $expectedLiveApi = [
        'runtime_health' => ['method' => 'GET', 'paths' => ['/health', '/api/runtime']],
        'bootstrap'      => ['method' => 'GET', 'paths' => ['/', '/api/bootstrap']],
        'version'        => ['method' => 'GET', 'paths' => ['/api/version']],
        'node_profile'   => ['method' => 'GET', 'paths' => ['/api/node/profile']],
    ];

    $liveApi = $catalog['api'] ?? null;
    model_inference_catalog_contract_assert(is_array($liveApi), 'catalog.api must be an object');

    model_inference_catalog_contract_assert(
        array_keys($expectedLiveApi) === array_keys((array) $liveApi),
        'catalog.api keys drift: expected=' . json_encode(array_keys($expectedLiveApi))
            . ' actual=' . json_encode(array_keys((array) $liveApi))
    );

    foreach ($expectedLiveApi as $key => $expected) {
        $entry = $liveApi[$key] ?? null;
        model_inference_catalog_contract_assert(is_array($entry), "catalog.api.{$key} must be an object");
        model_inference_catalog_contract_assert(
            (string) ($entry['method'] ?? '') === $expected['method'],
            "catalog.api.{$key}.method must be {$expected['method']}"
        );
        model_inference_catalog_contract_assert(
            ($entry['paths'] ?? null) === $expected['paths'],
            "catalog.api.{$key}.paths drift: expected=" . json_encode($expected['paths'])
        );
    }

    // Every declared live path must resolve through the dispatcher (200)
    // and conversely every listed path must exist in the catalog.
    $jsonResponse = static function (int $status, array $payload): array {
        return [
            'status' => $status,
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    };
    $errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
        return $jsonResponse($status, [
            'status' => 'error',
            'error' => ['code' => $code, 'message' => $message, 'details' => $details],
            'time' => gmdate('c'),
        ]);
    };
    $methodFromRequest = static function (array $request): string {
        return strtoupper(trim((string) ($request['method'] ?? 'GET')));
    };
    $pathFromRequest = static function (array $request): string {
        return (string) ($request['path'] ?? '/');
    };
    $runtimeEnvelope = static function (): array {
        return [
            'service' => 'model-inference-backend-king-php',
            'app' => ['name' => 'king-model-inference-backend', 'version' => 'contract', 'environment' => 'test'],
            'runtime' => [
                'king_version' => 'test',
                'transport' => 'king_http1_server_listen_once',
                'ws_path' => '/ws',
                'health' => ['build' => 'b', 'module_version' => 'm'],
            ],
            'database' => ['status' => 'ready'],
            'node' => ['node_id' => 'node_contract', 'role' => 'inference-serving'],
            'time' => gmdate('c'),
        ];
    };

    foreach ($expectedLiveApi as $key => $expected) {
        foreach ($expected['paths'] as $path) {
            $response = model_inference_dispatch_request(
                ['method' => $expected['method'], 'path' => $path, 'uri' => $path, 'headers' => []],
                $jsonResponse,
                $errorResponse,
                $methodFromRequest,
                $pathFromRequest,
                $runtimeEnvelope,
                '/ws',
                '127.0.0.1',
                18090
            );
            model_inference_catalog_contract_assert(
                (int) ($response['status'] ?? 0) === 200,
                "catalog.api.{$key} path {$path} must resolve to 200 via the dispatcher (got {$response['status']})"
            );
        }
    }

    // ws section: initially carries path + introduced_by but no live events
    // until M-11 lands; the events map must be present and empty.
    $liveWs = $catalog['ws'] ?? null;
    model_inference_catalog_contract_assert(is_array($liveWs), 'catalog.ws must be an object');
    model_inference_catalog_contract_assert(
        (string) ($liveWs['path'] ?? '') === '/ws',
        'catalog.ws.path must be /ws'
    );
    model_inference_catalog_contract_assert(
        is_array($liveWs['events'] ?? null) && $liveWs['events'] === [],
        'catalog.ws.events must be empty until #M-11 lands WS upgrade support'
    );

    // Error codes currently emitted by the dispatcher must be listed.
    $liveErrorCodes = (array) (($catalog['errors'] ?? [])['codes'] ?? []);
    foreach (['extension_not_loaded', 'internal_server_error', 'not_implemented'] as $required) {
        model_inference_catalog_contract_assert(
            in_array($required, $liveErrorCodes, true),
            "catalog.errors.codes must list '{$required}'"
        );
    }

    // Target-shape section is present and names each surface's introducing
    // leaf. This is the honest forward-looking ledger; parity rules apply
    // only to the live section above.
    $targetShape = $catalog['planned_surfaces_target_shape'] ?? null;
    model_inference_catalog_contract_assert(
        is_array($targetShape),
        'catalog.planned_surfaces_target_shape must exist so future surfaces are declared without faking parity'
    );
    $targetShapeApi = (array) ($targetShape['api'] ?? []);
    foreach (['models_list', 'models_create', 'infer_http', 'transcripts_get', 'telemetry_recent', 'route_diagnostic'] as $requiredKey) {
        model_inference_catalog_contract_assert(
            isset($targetShapeApi[$requiredKey]),
            "catalog.planned_surfaces_target_shape.api must list '{$requiredKey}' until its live leaf lands"
        );
    }
    // A surface MUST NOT appear in both live and target-shape sections.
    model_inference_catalog_contract_assert(
        !isset($targetShapeApi['node_profile']),
        "node_profile moved to live catalog.api at #M-4 and must not remain in planned_surfaces_target_shape"
    );

    // Target-shape paths must NOT be advertised in the live api section.
    foreach ($targetShapeApi as $name => $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $paths = (array) ($entry['paths'] ?? []);
        foreach ($paths as $path) {
            foreach ($expectedLiveApi as $liveEntry) {
                model_inference_catalog_contract_assert(
                    !in_array($path, $liveEntry['paths'], true),
                    "target-shape path {$path} (catalog.planned_surfaces_target_shape.api.{$name}) leaked into live catalog.api"
                );
            }
        }
    }

    fwrite(STDOUT, "[contract-catalog-parity-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[contract-catalog-parity-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
