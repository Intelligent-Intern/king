<?php

declare(strict_types=1);

require_once __DIR__ . '/module_runtime.php';
require_once __DIR__ . '/module_profile.php';
require_once __DIR__ . '/module_registry.php';
require_once __DIR__ . '/module_inference.php';
require_once __DIR__ . '/module_realtime.php';
require_once __DIR__ . '/module_telemetry.php';
require_once __DIR__ . '/module_embed.php';
require_once __DIR__ . '/module_ingest.php';
require_once __DIR__ . '/module_retrieve.php';
require_once __DIR__ . '/module_routing.php';
require_once __DIR__ . '/module_ui.php';

/**
 * Deterministic module-registration order for the inference backend.
 *
 * This list is the ground truth for the #M-2 router module-order contract
 * test. New leaves add one entry to this list when their module lands and
 * remove nothing. The end-of-sprint intended order is:
 *   runtime, profile, registry, worker, inference, telemetry, routing, realtime
 *
 * Current order (only shipped modules are listed — intentionally narrower
 * than the plan's target shape until the corresponding leaves land):
 *
 * @return array<int, string>
 */
function model_inference_dispatch_route_module_order(): array
{
    return [
        'runtime',
        'profile',
        'registry',
        'embed',
        'ingest',
        'retrieve',
        'inference',
        'realtime',
        'telemetry',
        'routing',
        'ui',
    ];
}

/**
 * Deterministic HTTP/WS dispatcher that wires focused backend modules.
 *
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function model_inference_dispatch_request(
    array $request,
    callable $jsonResponse,
    callable $errorResponse,
    callable $methodFromRequest,
    callable $pathFromRequest,
    callable $runtimeEnvelope,
    callable $openDatabase,
    callable $getInferenceSession,
    callable $getInferenceMetrics,
    string $wsPath,
    string $host,
    int $port,
    ?callable $getEmbeddingSession = null,
    ?callable $getRagMetrics = null
): array {
    $path = $pathFromRequest($request);
    $method = $methodFromRequest($request);
    $corsHeaders = [
        'access-control-allow-origin' => '*',
        'access-control-allow-methods' => 'GET,POST,PATCH,DELETE,OPTIONS',
        'access-control-allow-headers' => 'Authorization, Content-Type, X-Session-Id, X-Model-Name, X-Model-Family, X-Model-Quantization, X-Model-Parameter-Count, X-Model-Context-Length, X-Model-License, X-Model-Min-Ram-Bytes, X-Model-Min-Vram-Bytes, X-Model-Prefers-Gpu, X-Model-Source-Url',
        'access-control-max-age' => '600',
    ];

    if ($method === 'OPTIONS') {
        return [
            'status' => 204,
            'headers' => $corsHeaders,
            'body' => '',
        ];
    }

    $runtimeResponse = model_inference_handle_runtime_routes(
        $path,
        $method,
        $jsonResponse,
        $runtimeEnvelope,
        $wsPath
    );
    if ($runtimeResponse !== null) {
        return $runtimeResponse;
    }

    $profileResponse = model_inference_handle_profile_routes(
        $path,
        $method,
        $jsonResponse,
        $errorResponse,
        $runtimeEnvelope,
        $host,
        $port
    );
    if ($profileResponse !== null) {
        return $profileResponse;
    }

    $registryResponse = model_inference_handle_registry_routes(
        $path,
        $method,
        $request,
        $jsonResponse,
        $errorResponse,
        $openDatabase
    );
    if ($registryResponse !== null) {
        return $registryResponse;
    }

    if ($getEmbeddingSession !== null) {
        $embedResponse = model_inference_handle_embed_routes(
            $path,
            $method,
            $request,
            $jsonResponse,
            $errorResponse,
            $openDatabase,
            $getEmbeddingSession
        );
        if ($embedResponse !== null) {
            return $embedResponse;
        }
    }

    $ingestResponse = model_inference_handle_ingest_routes(
        $path,
        $method,
        $request,
        $jsonResponse,
        $errorResponse,
        $openDatabase
    );
    if ($ingestResponse !== null) {
        return $ingestResponse;
    }

    $retrieveResponse = model_inference_handle_retrieve_routes(
        $path,
        $method,
        $request,
        $jsonResponse,
        $errorResponse,
        $openDatabase,
        $getEmbeddingSession,
        $getInferenceSession,
        $getRagMetrics
    );
    if ($retrieveResponse !== null) {
        return $retrieveResponse;
    }

    $inferenceResponse = model_inference_handle_inference_routes(
        $path,
        $method,
        $request,
        $jsonResponse,
        $errorResponse,
        $openDatabase,
        $getInferenceSession,
        $getInferenceMetrics,
        $runtimeEnvelope
    );
    if ($inferenceResponse !== null) {
        return $inferenceResponse;
    }

    $realtimeResponse = model_inference_handle_realtime_routes(
        $path,
        $method,
        $request,
        $jsonResponse,
        $errorResponse,
        $openDatabase,
        $getInferenceSession,
        $getInferenceMetrics,
        $runtimeEnvelope,
        $wsPath
    );
    if ($realtimeResponse !== null) {
        return $realtimeResponse;
    }

    $telemetryResponse = model_inference_handle_telemetry_routes(
        $path,
        $method,
        $jsonResponse,
        $errorResponse,
        $getInferenceMetrics,
        $getRagMetrics
    );
    if ($telemetryResponse !== null) {
        return $telemetryResponse;
    }

    $routingResponse = model_inference_handle_routing_routes(
        $path,
        $method,
        $request,
        $jsonResponse,
        $errorResponse
    );
    if ($routingResponse !== null) {
        return $routingResponse;
    }

    $uiResponse = model_inference_handle_ui_routes(
        $path,
        $method,
        $jsonResponse,
        $errorResponse
    );
    if ($uiResponse !== null) {
        return $uiResponse;
    }

    return $errorResponse(404, 'not_implemented', 'Route has no handler on this backend build.', [
        'path' => $path,
        'method' => $method,
        'deployed_modules' => model_inference_dispatch_route_module_order(),
    ]);
}
