<?php

declare(strict_types=1);

require_once __DIR__ . '/../http/router.php';
require_once __DIR__ . '/../domain/telemetry/inference_metrics.php';

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
        'runtime_health'   => ['method' => 'GET',    'paths' => ['/health', '/api/runtime']],
        'bootstrap'        => ['method' => 'GET',    'paths' => ['/', '/api/bootstrap']],
        'version'          => ['method' => 'GET',    'paths' => ['/api/version']],
        'node_profile'     => ['method' => 'GET',    'paths' => ['/api/node/profile']],
        'models_list'      => ['method' => 'GET',    'paths' => ['/api/models']],
        'models_create'    => ['method' => 'POST',   'paths' => ['/api/models']],
        'model_get'        => ['method' => 'GET',    'paths' => ['/api/models/{model_id}']],
        'model_delete'     => ['method' => 'DELETE', 'paths' => ['/api/models/{model_id}']],
        'chat_ui'          => ['method' => 'GET',    'paths' => ['/ui', '/ui/']],
        'telemetry_recent' => ['method' => 'GET',    'paths' => ['/api/telemetry/inference/recent']],
        'documents_list'   => ['method' => 'GET',    'paths' => ['/api/documents']],
        'documents_create' => ['method' => 'POST',   'paths' => ['/api/documents']],
        'document_get'     => ['method' => 'GET',    'paths' => ['/api/documents/{document_id}']],
        'document_chunks'  => ['method' => 'GET',    'paths' => ['/api/documents/{document_id}/chunks']],
        'embed'            => ['method' => 'POST',   'paths' => ['/api/embed']],
        'rag'              => ['method' => 'POST',   'paths' => ['/api/rag']],
        'telemetry_rag_recent' => ['method' => 'GET', 'paths' => ['/api/telemetry/rag/recent']],
        'retrieve'         => ['method' => 'POST',   'paths' => ['/api/retrieve']],
        'infer_http'       => ['method' => 'POST',   'paths' => ['/api/infer']],
        'transcripts_get'  => ['method' => 'GET',    'paths' => ['/api/transcripts/{request_id}']],
        'route_diagnostic' => ['method' => 'GET',    'paths' => ['/api/route']],
        'discover'         => ['method' => 'POST',   'paths' => ['/api/discover']],
        'tools_discover'   => ['method' => 'POST',   'paths' => ['/api/tools/discover']],
        'tools_pick'       => ['method' => 'POST',   'paths' => ['/api/tools/pick']],
        'telemetry_discovery_recent' => ['method' => 'GET', 'paths' => ['/api/telemetry/discovery/recent']],
        'conversation_messages_list' => ['method' => 'GET', 'paths' => ['/api/conversations/{session_id}/messages']],
        'conversation_meta_get' => ['method' => 'GET', 'paths' => ['/api/conversations/{session_id}']],
        'conversation_delete' => ['method' => 'DELETE', 'paths' => ['/api/conversations/{session_id}']],
        'conversation_list_me' => ['method' => 'GET', 'paths' => ['/api/conversations/me']],
        'auth_login' => ['method' => 'POST', 'paths' => ['/api/auth/login']],
        'auth_logout' => ['method' => 'POST', 'paths' => ['/api/auth/logout']],
        'auth_whoami' => ['method' => 'GET', 'paths' => ['/api/auth/whoami']],
        'login_ui' => ['method' => 'GET', 'paths' => ['/login', '/login/']],
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

    $openDatabase = static function (): PDO {
        throw new RuntimeException('openDatabase must not be reached by parity probes (registry catalog entries are not exercised with a body here).');
    };
    $getInferenceSession = static function () {
        throw new RuntimeException('inference session must not be reached by parity probes (infer_http catalog entry is not exercised with a body here).');
    };
    $getInferenceMetrics = static function () {
        return new InferenceMetricsRing();
    };
    $getEmbeddingSession = static function () {
        throw new RuntimeException('embedding session must not be reached by parity probes.');
    };

    // Live-catalog resolution: every path must either resolve to 200 (list /
    // runtime / profile) OR produce a registry-owned 4xx that proves the
    // registry module IS wired (models_create without a body, model_get on a
    // non-existent id, model_delete on a non-existent id). The parity gate
    // refuses not_implemented for any live entry.
    $parityProbes = [
        'runtime_health' => [['method' => 'GET',    'path' => '/health',                                     'expect_status' => 200]],
        'bootstrap'      => [['method' => 'GET',    'path' => '/api/bootstrap',                              'expect_status' => 200]],
        'version'        => [['method' => 'GET',    'path' => '/api/version',                                'expect_status' => 200]],
        'node_profile'   => [['method' => 'GET',    'path' => '/api/node/profile',                           'expect_status' => 200]],
        // models_list and models_create need a live db; they are exercised by
        // model-registry-contract. Here we only prove the path is NOT 404.
        'models_list'    => [['method' => 'GET',    'path' => '/api/models',                                 'expect_not_status' => 404]],
        'models_create'  => [['method' => 'POST',   'path' => '/api/models',                                 'expect_not_status' => 404]],
        'model_get'      => [['method' => 'GET',    'path' => '/api/models/mdl-00000000deadbeef',             'expect_not_status' => 404]],
        'model_delete'     => [['method' => 'DELETE', 'path' => '/api/models/mdl-00000000deadbeef',           'expect_not_status' => 404]],
        'chat_ui'          => [['method' => 'GET',    'path' => '/ui',                                       'expect_status' => 200]],
        'telemetry_recent' => [['method' => 'GET',    'path' => '/api/telemetry/inference/recent',           'expect_status' => 200]],
        'documents_list'   => [['method' => 'GET',    'path' => '/api/documents',                            'expect_not_status' => 404]],
        'documents_create' => [['method' => 'POST',   'path' => '/api/documents',                            'expect_not_status' => 404]],
        'document_get'     => [['method' => 'GET',    'path' => '/api/documents/doc-00000000deadbeef',        'expect_not_status' => 404]],
        'document_chunks'  => [['method' => 'GET',    'path' => '/api/documents/doc-00000000deadbeef/chunks', 'expect_not_status' => 404]],
        'embed'            => [['method' => 'POST',   'path' => '/api/embed',                                'expect_not_status' => 404]],
        'rag'              => [['method' => 'POST',   'path' => '/api/rag',                                  'expect_not_status' => 404]],
        'telemetry_rag_recent' => [['method' => 'GET', 'path' => '/api/telemetry/rag/recent',                'expect_status' => 200]],
        'retrieve'         => [['method' => 'POST',   'path' => '/api/retrieve',                             'expect_not_status' => 404]],
        'infer_http'       => [['method' => 'POST',   'path' => '/api/infer',                                'expect_not_status' => 404]],
        'transcripts_get'  => [['method' => 'GET',    'path' => '/api/transcripts/req_00000000deadbeef',      'expect_not_status' => 405]],
        'route_diagnostic' => [['method' => 'GET',    'path' => '/api/route',                                'expect_status' => 200]],
        'discover'         => [['method' => 'POST',   'path' => '/api/discover',                             'expect_not_status' => 404]],
        'tools_discover'   => [['method' => 'POST',   'path' => '/api/tools/discover',                       'expect_not_status' => 404]],
        'tools_pick'       => [['method' => 'POST',   'path' => '/api/tools/pick',                           'expect_not_status' => 404]],
        'telemetry_discovery_recent' => [['method' => 'GET', 'path' => '/api/telemetry/discovery/recent',    'expect_status' => 200]],
        'conversation_messages_list' => [['method' => 'GET', 'path' => '/api/conversations/sess-probe/messages', 'expect_not_status' => 404]],
        'conversation_meta_get' => [['method' => 'GET', 'path' => '/api/conversations/sess-probe',           'expect_not_status' => 404]],
        'conversation_delete' => [['method' => 'DELETE', 'path' => '/api/conversations/sess-probe',          'expect_not_status' => 404]],
        'conversation_list_me' => [['method' => 'GET', 'path' => '/api/conversations/me',                    'expect_not_status' => 404]],
        'auth_login' => [['method' => 'POST', 'path' => '/api/auth/login',                                   'expect_not_status' => 404]],
        'auth_logout' => [['method' => 'POST', 'path' => '/api/auth/logout',                                 'expect_not_status' => 404]],
        'auth_whoami' => [['method' => 'GET', 'path' => '/api/auth/whoami',                                  'expect_not_status' => 404]],
        'login_ui' => [['method' => 'GET', 'path' => '/login',                                               'expect_status' => 200]],
    ];
    foreach ($parityProbes as $key => $probes) {
        foreach ($probes as $probe) {
            $method = (string) $probe['method'];
            $path = (string) $probe['path'];
            try {
                $response = model_inference_dispatch_request(
                    ['method' => $method, 'path' => $path, 'uri' => $path, 'headers' => []],
                    $jsonResponse,
                    $errorResponse,
                    $methodFromRequest,
                    $pathFromRequest,
                    $runtimeEnvelope,
                    $openDatabase,
                    $getInferenceSession,
                    $getInferenceMetrics,
                    '/ws',
                    '127.0.0.1',
                    18090,
                    $getEmbeddingSession
                );
            } catch (RuntimeException $error) {
                // Registry paths trip openDatabase deliberately; infer_http
                // trips it too (it calls $openDatabase before looking up
                // the model). Either tripping proves the router DID
                // dispatch to the right module (not a not_implemented
                // fallthrough).
                if (
                    str_contains($error->getMessage(), 'openDatabase must not be reached')
                    || str_contains($error->getMessage(), 'inference session must not be reached')
                    || str_contains($error->getMessage(), 'embedding session must not be reached')
                ) {
                    continue;
                }
                throw $error;
            }
            $status = (int) ($response['status'] ?? 0);
            if (isset($probe['expect_status'])) {
                model_inference_catalog_contract_assert(
                    $status === (int) $probe['expect_status'],
                    "catalog.api.{$key} path {$path} must resolve to {$probe['expect_status']} (got {$status})"
                );
            } elseif (isset($probe['expect_not_status'])) {
                model_inference_catalog_contract_assert(
                    $status !== (int) $probe['expect_not_status'],
                    "catalog.api.{$key} path {$path} must not return {$probe['expect_not_status']} (the module is not wired)"
                );
                // Live live-registry paths that need db WILL trip openDatabase
                // before a not_implemented response, which is also valid.
            }
        }
    }

    // ws section: #M-11 onward must list the streaming events (infer.start
    // inbound, infer.token/infer.end/infer.error outbound). The handshake
    // block is also pinned so clients can depend on version + required
    // headers.
    $liveWs = $catalog['ws'] ?? null;
    model_inference_catalog_contract_assert(is_array($liveWs), 'catalog.ws must be an object');
    model_inference_catalog_contract_assert(
        (string) ($liveWs['path'] ?? '') === '/ws',
        'catalog.ws.path must be /ws'
    );
    $handshake = $liveWs['handshake'] ?? null;
    model_inference_catalog_contract_assert(is_array($handshake), 'catalog.ws.handshake must be pinned from #M-11 onward');
    $hsHeaders = (array) ($handshake['required_headers'] ?? []);
    foreach (['Upgrade', 'Connection', 'Sec-WebSocket-Key', 'Sec-WebSocket-Version'] as $required) {
        model_inference_catalog_contract_assert(
            isset($hsHeaders[$required]),
            "catalog.ws.handshake.required_headers must list '{$required}'"
        );
    }
    $clientEvents = (array) ($liveWs['client_events'] ?? []);
    model_inference_catalog_contract_assert(
        isset($clientEvents['infer.start']) && ($clientEvents['infer.start']['frame_type'] ?? null) === 'text',
        'catalog.ws.client_events.infer.start must be pinned as a text frame'
    );
    $serverEvents = (array) ($liveWs['server_events'] ?? []);
    foreach (['infer.token' => 'delta', 'infer.end' => 'end', 'infer.error' => 'error'] as $eventName => $expectedFrameType) {
        $entry = $serverEvents[$eventName] ?? null;
        model_inference_catalog_contract_assert(is_array($entry), "catalog.ws.server_events.{$eventName} must be pinned");
        model_inference_catalog_contract_assert(
            (string) ($entry['frame_type'] ?? '') === 'binary',
            "catalog.ws.server_events.{$eventName}.frame_type must be 'binary'"
        );
        model_inference_catalog_contract_assert(
            (string) ($entry['frame_type_value'] ?? '') === $expectedFrameType,
            "catalog.ws.server_events.{$eventName}.frame_type_value must be '{$expectedFrameType}'"
        );
        model_inference_catalog_contract_assert(
            (string) ($entry['body_contract'] ?? '') === 'demo/model-inference/contracts/v1/token-frame.contract.json',
            "catalog.ws.server_events.{$eventName}.body_contract must point at token-frame.contract.json"
        );
    }

    // Error codes currently emitted by the dispatcher must be listed.
    $liveErrorCodes = (array) (($catalog['errors'] ?? [])['codes'] ?? []);
    foreach ([
        'extension_not_loaded',
        'internal_server_error',
        'not_implemented',
        'method_not_allowed',
        'invalid_request_envelope',
        'model_not_found',
        'model_artifact_write_failed',
        'model_artifact_too_large',
        'model_registry_conflict',
        'model_fit_unavailable',
        'worker_unavailable',
        'transcript_not_found',
        'routing_no_candidate',
        'document_not_found',
        'document_too_large',
        'invalid_service_descriptor',
        'invalid_tool_descriptor',
        'embedding_worker_unavailable_discovery',
        'no_semantic_match',
        'invalid_credentials',
        'session_expired',
        'session_revoked',
        'ownership_denied',
    ] as $required) {
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
    foreach (['worker_status'] as $requiredKey) {
        model_inference_catalog_contract_assert(
            isset($targetShapeApi[$requiredKey]),
            "catalog.planned_surfaces_target_shape.api must list '{$requiredKey}' until its live leaf lands"
        );
    }
    // A surface MUST NOT appear in both live and target-shape sections.
    foreach (['node_profile', 'models_list', 'models_create', 'model_get', 'model_delete', 'documents_list', 'documents_create', 'document_get', 'document_chunks', 'embed', 'rag', 'telemetry_rag_recent', 'retrieve', 'infer_http', 'telemetry_recent', 'chat_ui', 'transcripts_get', 'route_diagnostic', 'discover', 'tools_discover', 'tools_pick', 'telemetry_discovery_recent', 'conversation_messages_list', 'conversation_meta_get', 'conversation_delete', 'conversation_list_me', 'auth_login', 'auth_logout', 'auth_whoami', 'login_ui'] as $shipped) {
        model_inference_catalog_contract_assert(
            !isset($targetShapeApi[$shipped]),
            "{$shipped} has shipped and must not remain in planned_surfaces_target_shape"
        );
    }

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
