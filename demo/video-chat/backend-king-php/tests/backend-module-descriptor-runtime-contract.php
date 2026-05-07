<?php

declare(strict_types=1);

require_once __DIR__ . '/../http/module_backend_modules.php';

function backend_module_descriptor_assert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[backend-module-descriptor-runtime-contract] FAIL: {$message}\n");
    exit(1);
}

try {
    if (!class_exists('\\King\\PipelineOrchestrator')) {
        if ((string) getenv('KING_BACKEND_MODULE_DESCRIPTOR_REQUIRE_ORCHESTRATOR') === '1') {
            backend_module_descriptor_assert(false, 'King\\PipelineOrchestrator is not loaded');
        }
        fwrite(STDOUT, "[backend-module-descriptor-runtime-contract] SKIP: King extension not loaded\n");
        exit(0);
    }

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
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
            'time' => gmdate('c'),
        ]);
    };

    $decodeJsonBody = static function (array $request): array {
        $decoded = json_decode((string) ($request['body'] ?? ''), true);
        return is_array($decoded) ? [$decoded, null] : [null, 'invalid_json'];
    };

    $request = [
        'method' => 'POST',
        'path' => '/api/admin/backend-modules/diagnostics/ping',
        'uri' => '/api/admin/backend-modules/diagnostics/ping?source=contract',
        'headers' => [
            'x-correlation-id' => 'backend-module-descriptor-contract',
            'idempotency-key' => 'backend-module-descriptor-once',
        ],
        'body' => json_encode(['payload' => ['value' => 42]], JSON_UNESCAPED_SLASHES),
    ];

    $authContext = [
        'user' => [
            'id' => 1,
            'email' => 'admin@intelligent-intern.com',
            'role' => 'admin',
        ],
        'session' => [
            'id' => 'sess-contract',
        ],
        'tenant' => [
            'id' => 1,
            'public_id' => '00000000-0000-4000-8000-000000000001',
            'slug' => 'default',
        ],
    ];

    $response = videochat_handle_backend_module_routes(
        (string) $request['path'],
        (string) $request['method'],
        $request,
        $authContext,
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody
    );

    backend_module_descriptor_assert(is_array($response), 'route returned null');
    backend_module_descriptor_assert((int) ($response['status'] ?? 0) === 200, 'route did not return 200');

    $payload = json_decode((string) ($response['body'] ?? ''), true);
    backend_module_descriptor_assert(is_array($payload), 'response body is not JSON object');

    $result = is_array($payload['result'] ?? null) ? (array) $payload['result'] : [];
    backend_module_descriptor_assert(($result['module_key'] ?? null) === 'backend_modules', 'module key mismatch');
    backend_module_descriptor_assert(($result['route_key'] ?? null) === 'backend_modules.diagnostics.ping', 'route key mismatch');
    backend_module_descriptor_assert(($result['event_name'] ?? null) === 'backend_modules.diagnostics.ping.requested', 'event name mismatch');
    backend_module_descriptor_assert(($result['correlation_id'] ?? null) === 'backend-module-descriptor-contract', 'correlation id mismatch');

    $pipelineResult = is_array($result['pipeline_result'] ?? null) ? (array) $result['pipeline_result'] : [];
    backend_module_descriptor_assert((bool) ($pipelineResult['diagnostics']['handled'] ?? false), 'pipeline handler did not run');

    $runId = (string) ($result['run_id'] ?? '');
    backend_module_descriptor_assert($runId !== '', 'missing orchestrator run id');

    $run = \King\PipelineOrchestrator::getRun($runId);
    backend_module_descriptor_assert(is_array($run), 'orchestrator snapshot missing');
    $initial = is_array($run['initial_data'] ?? null) ? (array) $run['initial_data'] : [];
    backend_module_descriptor_assert(($initial['module_key'] ?? null) === 'backend_modules', 'snapshot module key mismatch');
    backend_module_descriptor_assert(($initial['route_key'] ?? null) === 'backend_modules.diagnostics.ping', 'snapshot route key mismatch');
    backend_module_descriptor_assert(($initial['event_name'] ?? null) === 'backend_modules.diagnostics.ping.requested', 'snapshot event name mismatch');
    backend_module_descriptor_assert(($initial['actor']['user_id'] ?? null) === 1, 'snapshot actor context mismatch');
    backend_module_descriptor_assert(($initial['session']['id'] ?? null) === 'sess-contract', 'snapshot session context mismatch');
    backend_module_descriptor_assert(($initial['tenant']['slug'] ?? null) === 'default', 'snapshot tenant context mismatch');
    backend_module_descriptor_assert(($initial['request']['query']['source'] ?? null) === 'contract', 'snapshot query context mismatch');
    backend_module_descriptor_assert(($initial['correlation_id'] ?? null) === 'backend-module-descriptor-contract', 'snapshot correlation mismatch');
    backend_module_descriptor_assert(($initial['idempotency_key'] ?? null) === 'backend-module-descriptor-once', 'snapshot idempotency mismatch');
    backend_module_descriptor_assert(($run['status'] ?? null) === 'completed', 'snapshot run status mismatch');

    $forbidden = videochat_handle_backend_module_routes(
        (string) $request['path'],
        (string) $request['method'],
        $request,
        ['user' => ['id' => 2, 'role' => 'user']],
        $jsonResponse,
        $errorResponse,
        $decodeJsonBody
    );
    backend_module_descriptor_assert((int) ($forbidden['status'] ?? 0) === 403, 'non-admin route did not fail closed');

    $loaded = videochat_backend_module_load_descriptors();
    backend_module_descriptor_assert((bool) ($loaded['ok'] ?? false), 'descriptor loader failed');
    $descriptors = is_array($loaded['descriptors'] ?? null) ? (array) $loaded['descriptors'] : [];
    $descriptor = is_array($descriptors['backend_modules'] ?? null) ? (array) $descriptors['backend_modules'] : [];
    $match = videochat_backend_module_match_route($descriptors, 'POST', '/api/admin/backend-modules/diagnostics/ping');
    backend_module_descriptor_assert(is_array($match), 'descriptor route did not resolve');
    $route = is_array($match['route'] ?? null) ? (array) $match['route'] : [];
    $event = videochat_backend_module_event_from_request($descriptor, $route, $request, $authContext, ['payload' => ['value' => 42]]);

    $unknownToolDescriptor = $descriptor;
    $unknownToolDescriptor['pipelines'][0]['steps'][0]['tool'] = 'unknown-backend-module-tool';
    $unknownTool = videochat_backend_module_submit_event(
        $unknownToolDescriptor,
        $route,
        $event,
        static fn (array $event, array $steps, array $options): array => $event
    );
    backend_module_descriptor_assert(($unknownTool['ok'] ?? true) === false, 'unknown tool did not fail closed');
    backend_module_descriptor_assert(($unknownTool['reason'] ?? null) === 'unknown_tool', 'unknown tool reason mismatch');

    $missingHandlerDescriptor = $descriptor;
    $missingHandlerDescriptor['handlers'][0]['handler'] = 'videochat_backend_module_missing_handler';
    $missingHandler = videochat_backend_module_submit_event($missingHandlerDescriptor, $route, $event);
    backend_module_descriptor_assert(($missingHandler['ok'] ?? true) === false, 'missing handler did not fail closed');
    backend_module_descriptor_assert(($missingHandler['reason'] ?? null) === 'handler_not_ready', 'missing handler reason mismatch');

    fwrite(STDOUT, "[backend-module-descriptor-runtime-contract] PASS\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '[backend-module-descriptor-runtime-contract] ERROR: ' . $error->getMessage() . "\n");
    exit(1);
}
