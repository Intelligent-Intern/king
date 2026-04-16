<?php

declare(strict_types=1);

$host = getenv('MODEL_INFERENCE_KING_HOST') ?: '127.0.0.1';
$port = (int) (getenv('MODEL_INFERENCE_KING_PORT') ?: '18090');
$wsPath = getenv('MODEL_INFERENCE_KING_WS_PATH') ?: '/ws';
$dbPath = getenv('MODEL_INFERENCE_KING_DB_PATH') ?: (__DIR__ . '/.local/model-inference.sqlite');
$appVersion = getenv('MODEL_INFERENCE_KING_BACKEND_VERSION') ?: '1.0.6-beta';
$appEnv = getenv('MODEL_INFERENCE_KING_ENV') ?: 'development';
$nodeIdEnv = trim((string) (getenv('MODEL_INFERENCE_KING_NODE_ID') ?: ''));
$rawServerMode = strtolower(trim((string) (getenv('MODEL_INFERENCE_KING_SERVER_MODE') ?: 'all')));
$serverMode = in_array($rawServerMode, ['all', 'http', 'ws'], true) ? $rawServerMode : 'all';
$debugRequests = in_array(
    strtolower(trim((string) (getenv('MODEL_INFERENCE_DEBUG_REQUESTS') ?: '0'))),
    ['1', 'true', 'yes', 'on'],
    true
);

if ($port < 1 || $port > 65535) {
    fwrite(STDERR, "[model-inference][king-php-backend] invalid port: {$port}\n");
    exit(1);
}

if (!extension_loaded('king')) {
    fwrite(STDERR, "[model-inference][king-php-backend] King extension is not loaded.\n");
    exit(1);
}

$log = static function (string $message): void {
    fwrite(STDERR, '[model-inference][king-php-backend] ' . $message . "\n");
};

require_once __DIR__ . '/support/database.php';
require_once __DIR__ . '/support/object_store.php';
require_once __DIR__ . '/support/semantic_dns.php';
require_once __DIR__ . '/domain/registry/model_registry.php';
require_once __DIR__ . '/domain/inference/inference_session.php';
require_once __DIR__ . '/domain/inference/transcript_store.php';
require_once __DIR__ . '/domain/telemetry/inference_metrics.php';
require_once __DIR__ . '/http/router.php';

$objectStoreRoot = getenv('MODEL_INFERENCE_KING_OBJECT_STORE_ROOT') ?: (dirname($dbPath) . '/object-store');
$maxObjectStoreBytes = (int) (getenv('MODEL_INFERENCE_KING_OBJECT_STORE_MAX_BYTES') ?: (string) (4 * 1024 * 1024 * 1024));
$llamaHome = getenv('LLAMA_CPP_HOME') ?: '/opt/llama-cpp/llama-b8802';
$ggufCacheRoot = getenv('MODEL_INFERENCE_GGUF_CACHE_ROOT') ?: (dirname($dbPath) . '/gguf-cache');

$nodeId = $nodeIdEnv !== ''
    ? $nodeIdEnv
    : 'node_' . substr(bin2hex(random_bytes(8)), 0, 16);

$databaseRuntime = null;
$maxBootstrapAttempts = 40;
for ($attempt = 1; $attempt <= $maxBootstrapAttempts; $attempt += 1) {
    try {
        $databaseRuntime = model_inference_bootstrap_sqlite($dbPath);
        break;
    } catch (Throwable $error) {
        $message = $error->getMessage();
        $isSqliteLock = stripos($message, 'database is locked') !== false;
        if ($isSqliteLock && $attempt < $maxBootstrapAttempts) {
            usleep(100_000);
            continue;
        }
        $log('database bootstrap failed: ' . $message);
        exit(1);
    }
}

if (!is_array($databaseRuntime)) {
    $log('database bootstrap failed: no runtime snapshot returned.');
    exit(1);
}

try {
    $databasePdo = model_inference_open_sqlite_pdo($dbPath);
    model_inference_registry_schema_migrate($databasePdo);
    unset($databasePdo);
} catch (Throwable $error) {
    $log('registry schema migration failed: ' . $error->getMessage());
    exit(1);
}

try {
    model_inference_object_store_init($objectStoreRoot, $maxObjectStoreBytes);
} catch (Throwable $error) {
    $log('object-store init failed: ' . $error->getMessage());
    $log('hint: enable king.security_allow_config_override=1 in the PHP ini');
    exit(1);
}

require_once __DIR__ . '/domain/profile/hardware_profile.php';
$bootProfile = model_inference_hardware_profile($nodeId, "http://{$host}:{$port}/health", 'ready');
$dnsRegistered = model_inference_semantic_dns_register($nodeId, $host, $port, $bootProfile);
if ($dnsRegistered) {
    $dnsVisible = model_inference_semantic_dns_heartbeat_after_ready($nodeId);
    $log('semantic-dns: registered as king.inference.v1, visible=' . ($dnsVisible ? 'true' : 'false'));
} else {
    $log('semantic-dns: registration skipped (kernel unavailable or failed)');
}

$openDatabase = static function () use ($dbPath): PDO {
    return model_inference_open_sqlite_pdo($dbPath);
};

$inferenceSession = new InferenceSession(
    $llamaHome . '/llama-server',
    $llamaHome,
    $ggufCacheRoot
);
$getInferenceSession = static function () use ($inferenceSession): InferenceSession {
    return $inferenceSession;
};
register_shutdown_function(static function () use ($inferenceSession, $nodeId, $log): void {
    model_inference_semantic_dns_deregister($nodeId);
    $log('semantic-dns: deregistered (drain)');
    $inferenceSession->drainAll();
});

$metricsCapacity = (int) (getenv('MODEL_INFERENCE_METRICS_CAPACITY') ?: '100');
$inferenceMetrics = new InferenceMetricsRing($metricsCapacity);
$getInferenceMetrics = static function () use ($inferenceMetrics): InferenceMetricsRing {
    return $inferenceMetrics;
};

// Optional: auto-seed SmolLM2 GGUF fixtures when they are missing from
// the registry. Gated on MODEL_INFERENCE_AUTOSEED=1 so CI / contract
// tests that start from a clean slate are never surprised by an implicit
// registry row. Every matching file in the fixtures dir is considered;
// already-registered (model_name, quantization) pairs are skipped
// silently so re-boots are idempotent.
if (in_array(strtolower((string) (getenv('MODEL_INFERENCE_AUTOSEED') ?: '0')), ['1', 'true', 'yes', 'on'], true)) {
    $fixtureDir = getenv('MODEL_INFERENCE_AUTOSEED_FIXTURE_DIR')
        ?: (__DIR__ . '/.local/fixtures');

    // Filename (as published by bartowski/SmolLM2-135M-Instruct-GGUF) → canonical
    // quantization tag pinned by the inference-request / model-registry contracts.
    $knownFixtures = [
        'SmolLM2-135M-Instruct-Q2_K.gguf'   => 'Q2_K',
        'SmolLM2-135M-Instruct-Q3_K_M.gguf' => 'Q3_K',
        'SmolLM2-135M-Instruct-Q4_0.gguf'   => 'Q4_0',
        'SmolLM2-135M-Instruct-Q4_K_S.gguf' => 'Q4_K',
        'SmolLM2-135M-Instruct-Q5_K_M.gguf' => 'Q5_K',
        'SmolLM2-135M-Instruct-Q6_K.gguf'   => 'Q6_K',
        'SmolLM2-135M-Instruct-Q8_0.gguf'   => 'Q8_0',
        'SmolLM2-135M-Instruct-f16.gguf'    => 'F16',
    ];

    $seededCount = 0;
    $skippedCount = 0;
    try {
        $seedPdo = model_inference_open_sqlite_pdo($dbPath);
        $conflictStmt = $seedPdo->prepare('SELECT COUNT(1) AS c FROM models WHERE model_name = :n AND quantization = :q');
        foreach ($knownFixtures as $fileName => $quantization) {
            $path = $fixtureDir . '/' . $fileName;
            if (!is_file($path)) {
                continue;
            }
            $conflictStmt->execute([':n' => 'SmolLM2-135M-Instruct', ':q' => $quantization]);
            if ((int) ($conflictStmt->fetch()['c'] ?? 0) > 0) {
                $skippedCount++;
                continue;
            }
            $stream = @fopen($path, 'rb');
            if (!is_resource($stream)) {
                $log('auto-seed skipped (cannot open): ' . $path);
                continue;
            }
            try {
                model_inference_registry_create_from_stream($seedPdo, [
                    'model_name' => 'SmolLM2-135M-Instruct',
                    'family' => 'smollm2',
                    'quantization' => $quantization,
                    'parameter_count' => 135000000,
                    'context_length' => 2048,
                    'license' => 'apache-2.0',
                    'min_ram_bytes' => 268435456,
                    'min_vram_bytes' => 0,
                    'prefers_gpu' => false,
                    'source_url' => 'https://huggingface.co/bartowski/SmolLM2-135M-Instruct-GGUF',
                ], $stream);
                $seededCount++;
                $log(sprintf('auto-seeded SmolLM2-135M-Instruct/%s from %s', $quantization, $fileName));
            } catch (Throwable $seedEntryError) {
                $log('auto-seed entry failed (non-fatal): ' . $seedEntryError->getMessage());
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
        unset($seedPdo);
    } catch (Throwable $seedError) {
        $log('auto-seed bootstrap failed (non-fatal): ' . $seedError->getMessage());
    }

    $log(sprintf('auto-seed summary: seeded=%d skipped_existing=%d fixture_dir=%s', $seededCount, $skippedCount, $fixtureDir));
}

register_shutdown_function(static function () use ($log): void {
    $error = error_get_last();
    if (is_array($error)) {
        $log(sprintf(
            'shutdown with last error: %s (%s:%d)',
            (string) ($error['message'] ?? 'unknown'),
            (string) ($error['file'] ?? 'n/a'),
            (int) ($error['line'] ?? 0)
        ));
        return;
    }
    $log('shutdown complete.');
});

$jsonResponse = static function (int $status, array $payload): array {
    $corsHeaders = [
        'access-control-allow-origin' => '*',
        'access-control-allow-methods' => 'GET,POST,PATCH,DELETE,OPTIONS',
        'access-control-allow-headers' => 'Authorization, Content-Type, X-Session-Id',
        'access-control-max-age' => '600',
    ];

    return [
        'status' => $status,
        'headers' => [
            'content-type' => 'application/json; charset=utf-8',
            ...$corsHeaders,
        ],
        'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
};

$errorResponse = static function (int $status, string $code, string $message, array $details = []) use ($jsonResponse): array {
    $error = [
        'code' => $code,
        'message' => $message,
    ];
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

$runtimeHealthSummary = static function (): array {
    $moduleStatus = 'unknown';
    $moduleBuild = null;
    $moduleVersion = null;
    $activeRuntimeCount = 0;

    if (function_exists('king_health')) {
        try {
            $moduleHealth = king_health();
            if (is_array($moduleHealth)) {
                $moduleStatus = is_string($moduleHealth['status'] ?? null) ? $moduleHealth['status'] : $moduleStatus;
                $moduleBuild = is_string($moduleHealth['build'] ?? null) ? $moduleHealth['build'] : null;
                $moduleVersion = is_string($moduleHealth['version'] ?? null) ? $moduleHealth['version'] : null;
                $activeRuntimeCount = (int) ($moduleHealth['active_runtime_count'] ?? 0);
            }
        } catch (Throwable $error) {
            $moduleStatus = 'error';
        }
    }

    $systemStatus = 'not_initialized';
    if (function_exists('king_system_health_check')) {
        try {
            $systemHealth = king_system_health_check();
            if (is_array($systemHealth)) {
                $systemStatus = is_string($systemHealth['status'] ?? null) ? $systemHealth['status'] : $systemStatus;
            }
        } catch (Throwable $error) {
            $systemStatus = 'error';
        }
    }

    return [
        'module_status' => $moduleStatus,
        'system_status' => $systemStatus,
        'build' => $moduleBuild,
        'module_version' => $moduleVersion,
        'active_runtime_count' => $activeRuntimeCount,
    ];
};

$runtimeEnvelope = static function () use (
    $appVersion,
    $appEnv,
    $databaseRuntime,
    $wsPath,
    $runtimeHealthSummary,
    $nodeId
): array {
    return [
        'service' => 'model-inference-backend-king-php',
        'app' => [
            'name' => 'king-model-inference-backend',
            'version' => $appVersion,
            'environment' => $appEnv,
        ],
        'runtime' => [
            'king_version' => function_exists('king_version') ? (string) king_version() : 'n/a',
            'transport' => 'king_http1_server_listen_once',
            'ws_path' => $wsPath,
            'health' => $runtimeHealthSummary(),
        ],
        'database' => $databaseRuntime,
        'node' => [
            'node_id' => $nodeId,
            'role' => 'inference-serving',
        ],
        'time' => gmdate('c'),
    ];
};

$log('king_version=' . (function_exists('king_version') ? (string) king_version() : 'n/a'));
$log(sprintf(
    'sqlite bootstrap: schema v%d (%d/%d migrations) at %s',
    (int) ($databaseRuntime['schema_version'] ?? 0),
    (int) ($databaseRuntime['migrations_applied'] ?? 0),
    (int) ($databaseRuntime['migrations_total'] ?? 0),
    (string) ($databaseRuntime['path'] ?? $dbPath)
));
$log("node_id={$nodeId}");
$log("http endpoint bound: http://{$host}:{$port}/");
$log("websocket endpoint bound: ws://{$host}:{$port}{$wsPath} (handshake not yet landed — introduced at #M-11)");
$log("server mode: {$serverMode}");

$handler = static function (array $request) use (
    $jsonResponse,
    $errorResponse,
    $methodFromRequest,
    $pathFromRequest,
    $runtimeEnvelope,
    $openDatabase,
    $getInferenceSession,
    $getInferenceMetrics,
    $wsPath,
    $host,
    $port,
    $log,
    $serverMode,
    $debugRequests
): array {
    $path = $pathFromRequest($request);
    $method = $methodFromRequest($request);
    if ($debugRequests) {
        $log(sprintf('request: %s %s', $method, $path));
    }

    if ($serverMode === 'http' && $path === $wsPath) {
        return $errorResponse(404, 'websocket_endpoint_disabled', 'WebSocket endpoint is disabled on this listener.', [
            'path' => $path,
            'listener_mode' => $serverMode,
            'ws_path' => $wsPath,
        ]);
    }

    if ($serverMode === 'ws' && !in_array($path, [$wsPath, '/health'], true)) {
        return $errorResponse(404, 'rest_endpoint_disabled', 'REST endpoint is disabled on this listener.', [
            'path' => $path,
            'listener_mode' => $serverMode,
            'ws_path' => $wsPath,
        ]);
    }

    try {
        return model_inference_dispatch_request(
            $request,
            $jsonResponse,
            $errorResponse,
            $methodFromRequest,
            $pathFromRequest,
            $runtimeEnvelope,
            $openDatabase,
            $getInferenceSession,
            $getInferenceMetrics,
            $wsPath,
            $host,
            $port
        );
    } catch (Throwable $error) {
        $log(sprintf(
            'unhandled request error on %s %s: %s (%s:%d)',
            $method,
            $path,
            $error->getMessage(),
            $error->getFile(),
            $error->getLine()
        ));

        return $errorResponse(500, 'internal_server_error', 'Request handling failed unexpectedly.', [
            'path' => $path,
            'method' => $method,
        ]);
    }
};

$log('starting King HTTP/1 one-shot listener loop...');
while (true) {
    $ok = king_http1_server_listen_once($host, $port, null, $handler);
    if ($ok === false) {
        $lastError = function_exists('king_get_last_error') ? trim((string) king_get_last_error()) : '';
        if (
            $lastError !== ''
            && stripos($lastError, 'timed out while waiting for the HTTP/1 accept phase') === false
        ) {
            $log('listen_once failure: ' . $lastError);
        }
        usleep(50_000);
    }
}
