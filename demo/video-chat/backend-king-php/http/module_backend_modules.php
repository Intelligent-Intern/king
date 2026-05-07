<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/backend_modules.php';

function videochat_backend_module_diagnostics_echo_handler(array $context): array
{
    $input = $context['input'] ?? [];
    if (!is_array($input)) {
        throw new RuntimeException('backend module diagnostics handler received invalid input');
    }

    $input['diagnostics'] = [
        'handled' => true,
        'tool' => (string) ($context['tool']['name'] ?? ''),
        'backend' => (string) ($context['run']['execution_backend'] ?? ''),
    ];

    return ['output' => $input];
}

function videochat_handle_backend_module_routes(
    string $path,
    string $method,
    array $request,
    array $apiAuthContext,
    callable $jsonResponse,
    callable $errorResponse,
    callable $decodeJsonBody,
    ?callable $pipelineRunner = null
): ?array {
    if (!str_starts_with($path, '/api/admin/backend-modules')) {
        return null;
    }

    $loaded = videochat_backend_module_load_descriptors();
    if (!(bool) ($loaded['ok'] ?? false)) {
        return $errorResponse(500, 'backend_module_descriptor_load_failed', 'Backend module descriptors could not be loaded.', [
            'reason' => (string) ($loaded['reason'] ?? 'descriptor_load_failed'),
        ]);
    }

    $descriptors = is_array($loaded['descriptors'] ?? null) ? (array) $loaded['descriptors'] : [];
    $match = videochat_backend_module_match_route($descriptors, $method, $path);
    if (!is_array($match)) {
        if (videochat_backend_module_path_exists($descriptors, $path)) {
            return $errorResponse(405, 'method_not_allowed', 'Use the descriptor-defined method for this backend module route.', [
                'allowed_methods' => ['POST'],
            ]);
        }

        return null;
    }

    $user = is_array($apiAuthContext['user'] ?? null) ? (array) $apiAuthContext['user'] : [];
    if ((string) ($user['role'] ?? '') !== 'admin') {
        return $errorResponse(403, 'backend_module_admin_required', 'Backend module diagnostics require an administrator session.', [
            'required_role' => 'admin',
        ]);
    }

    [$payload, $decodeError] = $decodeJsonBody($request);
    if (!is_array($payload)) {
        return $errorResponse(400, 'backend_module_invalid_request_body', 'Backend module route payload must be a JSON object.', [
            'reason' => $decodeError,
        ]);
    }

    $descriptor = is_array($match['descriptor'] ?? null) ? (array) $match['descriptor'] : [];
    $route = is_array($match['route'] ?? null) ? (array) $match['route'] : [];
    $event = videochat_backend_module_event_from_request($descriptor, $route, $request, $apiAuthContext, $payload);
    $submission = videochat_backend_module_submit_event($descriptor, $route, $event, $pipelineRunner);
    if (!(bool) ($submission['ok'] ?? false)) {
        $reason = (string) ($submission['reason'] ?? 'backend_module_pipeline_failed');
        $status = $reason === 'orchestrator_unavailable' ? 503 : 500;

        return $errorResponse($status, 'backend_module_pipeline_failed', 'Backend module pipeline could not be executed.', [
            'reason' => $reason,
            'module_key' => (string) ($event['module_key'] ?? ''),
            'route_key' => (string) ($event['route_key'] ?? ''),
        ]);
    }

    return $jsonResponse(200, [
        'status' => 'ok',
        'result' => [
            'module_key' => (string) ($event['module_key'] ?? ''),
            'route_key' => (string) ($event['route_key'] ?? ''),
            'event_name' => (string) ($event['event_name'] ?? ''),
            'correlation_id' => (string) ($event['correlation_id'] ?? ''),
            'run_id' => $submission['run_id'] ?? null,
            'pipeline_result' => is_array($submission['result'] ?? null) ? (array) $submission['result'] : [],
        ],
        'time' => gmdate('c'),
    ]);
}
