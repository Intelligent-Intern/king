<?php

declare(strict_types=1);

require_once __DIR__ . '/auth_request.php';

/**
 * @return array{ok: bool, descriptors?: array<string, array<string, mixed>>, reason?: string, path?: string}
 */
function videochat_backend_module_load_descriptors(?string $moduleRoot = null): array
{
    $root = $moduleRoot ?? dirname(__DIR__) . '/modules';
    if (!is_dir($root)) {
        return ['ok' => false, 'reason' => 'module_root_missing', 'path' => $root];
    }

    $paths = glob(rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'manifest.json');
    if (!is_array($paths)) {
        return ['ok' => false, 'reason' => 'module_manifest_scan_failed', 'path' => $root];
    }

    sort($paths);
    $descriptors = [];
    foreach ($paths as $path) {
        if (!is_string($path)) {
            continue;
        }

        $loaded = videochat_backend_module_load_descriptor_file($path);
        if (!(bool) ($loaded['ok'] ?? false)) {
            return [
                'ok' => false,
                'reason' => (string) ($loaded['reason'] ?? 'module_manifest_invalid'),
                'path' => $path,
            ];
        }

        $descriptor = is_array($loaded['descriptor'] ?? null) ? (array) $loaded['descriptor'] : [];
        $moduleKey = (string) ($descriptor['module_key'] ?? '');
        $descriptors[$moduleKey] = $descriptor;
    }

    return ['ok' => true, 'descriptors' => $descriptors];
}

/**
 * @return array{ok: bool, descriptor?: array<string, mixed>, reason?: string}
 */
function videochat_backend_module_load_descriptor_file(string $path): array
{
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return ['ok' => false, 'reason' => 'module_manifest_empty'];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'reason' => 'module_manifest_json_invalid'];
    }

    return videochat_backend_module_validate_descriptor($decoded);
}

/**
 * @return array{ok: bool, descriptor?: array<string, mixed>, reason?: string}
 */
function videochat_backend_module_validate_descriptor(array $descriptor): array
{
    $moduleKey = trim((string) ($descriptor['module_key'] ?? ''));
    $version = trim((string) ($descriptor['version'] ?? ''));
    if ($moduleKey === '' || !preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)*$/', $moduleKey)) {
        return ['ok' => false, 'reason' => 'module_key_invalid'];
    }
    if ($version === '') {
        return ['ok' => false, 'reason' => 'module_version_missing'];
    }

    foreach (['routes', 'events', 'pipelines', 'tools', 'handlers', 'i18n_namespaces'] as $listKey) {
        if (!is_array($descriptor[$listKey] ?? null)) {
            return ['ok' => false, 'reason' => $listKey . '_missing'];
        }
    }

    foreach ((array) $descriptor['routes'] as $route) {
        if (!is_array($route)) {
            return ['ok' => false, 'reason' => 'route_invalid'];
        }
        foreach (['route_key', 'method', 'path', 'event_name', 'pipeline_key'] as $field) {
            if (trim((string) ($route[$field] ?? '')) === '') {
                return ['ok' => false, 'reason' => 'route_' . $field . '_missing'];
            }
        }
    }

    return ['ok' => true, 'descriptor' => $descriptor];
}

/**
 * @return array{descriptor: array<string, mixed>, route: array<string, mixed>}|null
 */
function videochat_backend_module_match_route(array $descriptors, string $method, string $path): ?array
{
    $normalizedMethod = strtoupper(trim($method));
    $normalizedPath = trim($path);
    foreach ($descriptors as $descriptor) {
        if (!is_array($descriptor)) {
            continue;
        }
        foreach ((array) ($descriptor['routes'] ?? []) as $route) {
            if (!is_array($route)) {
                continue;
            }
            if (strtoupper((string) ($route['method'] ?? '')) !== $normalizedMethod) {
                continue;
            }
            if ((string) ($route['path'] ?? '') !== $normalizedPath) {
                continue;
            }

            return ['descriptor' => $descriptor, 'route' => $route];
        }
    }

    return null;
}

function videochat_backend_module_path_exists(array $descriptors, string $path): bool
{
    $normalizedPath = trim($path);
    foreach ($descriptors as $descriptor) {
        foreach ((array) ($descriptor['routes'] ?? []) as $route) {
            if (is_array($route) && (string) ($route['path'] ?? '') === $normalizedPath) {
                return true;
            }
        }
    }

    return false;
}

/**
 * @return array<int, array<string, mixed>>
 */
function videochat_backend_module_pipeline_steps(array $descriptor, string $pipelineKey): array
{
    foreach ((array) ($descriptor['pipelines'] ?? []) as $pipeline) {
        if (!is_array($pipeline) || (string) ($pipeline['pipeline_key'] ?? '') !== $pipelineKey) {
            continue;
        }
        $steps = $pipeline['steps'] ?? [];
        return is_array($steps) ? array_values(array_filter($steps, 'is_array')) : [];
    }

    return [];
}

/**
 * @return array<int, string>
 */
function videochat_backend_module_tool_names(array $descriptor): array
{
    $names = [];
    foreach ((array) ($descriptor['tools'] ?? []) as $tool) {
        if (!is_array($tool)) {
            continue;
        }
        $toolName = trim((string) ($tool['tool_name'] ?? ''));
        if ($toolName !== '') {
            $names[] = $toolName;
        }
    }

    return array_values(array_unique($names));
}

/**
 * @return array<string, mixed>
 */
function videochat_backend_module_event_from_request(
    array $descriptor,
    array $route,
    array $request,
    array $authContext,
    array $payload
): array {
    $correlationId = videochat_request_header_value($request, 'x-correlation-id');
    if ($correlationId === '') {
        $correlationId = 'backend-module-' . bin2hex(random_bytes(8));
    }

    $idempotencyKey = videochat_request_header_value($request, 'idempotency-key');
    $user = is_array($authContext['user'] ?? null) ? (array) $authContext['user'] : [];

    return [
        'module_key' => (string) ($descriptor['module_key'] ?? ''),
        'route_key' => (string) ($route['route_key'] ?? ''),
        'event_name' => (string) ($route['event_name'] ?? ''),
        'actor' => [
            'user_id' => (int) ($user['id'] ?? 0),
            'role' => (string) ($user['role'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
        ],
        'session' => is_array($authContext['session'] ?? null) ? (array) $authContext['session'] : null,
        'tenant' => is_array($authContext['tenant'] ?? null) ? (array) $authContext['tenant'] : null,
        'request' => [
            'method' => strtoupper((string) ($request['method'] ?? 'GET')),
            'path' => (string) ($route['path'] ?? ''),
            'query' => videochat_request_query_params($request),
            'payload' => $payload,
        ],
        'correlation_id' => $correlationId,
        'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
        'created_at' => gmdate('c'),
    ];
}

/**
 * @return array{ok: bool, result?: array<string, mixed>, run_id?: string, reason?: string}
 */
function videochat_backend_module_submit_event(
    array $descriptor,
    array $route,
    array $event,
    ?callable $pipelineRunner = null
): array {
    $pipelineKey = (string) ($route['pipeline_key'] ?? '');
    $steps = videochat_backend_module_pipeline_steps($descriptor, $pipelineKey);
    if ($pipelineKey === '' || $steps === []) {
        return ['ok' => false, 'reason' => 'pipeline_not_found'];
    }
    $toolNames = videochat_backend_module_tool_names($descriptor);
    foreach ($steps as $step) {
        $toolName = trim((string) ($step['tool'] ?? ''));
        if ($toolName === '' || !in_array($toolName, $toolNames, true)) {
            return ['ok' => false, 'reason' => 'unknown_tool'];
        }
    }

    $options = [
        'trace_id' => (string) ($event['correlation_id'] ?? ''),
        'module_key' => (string) ($event['module_key'] ?? ''),
        'route_key' => (string) ($event['route_key'] ?? ''),
        'event_name' => (string) ($event['event_name'] ?? ''),
    ];

    try {
        if ($pipelineRunner !== null) {
            $result = $pipelineRunner($event, $steps, $options);
            return [
                'ok' => true,
                'result' => is_array($result) ? $result : ['value' => $result],
            ];
        }

        if (!class_exists('\\King\\PipelineOrchestrator')) {
            return ['ok' => false, 'reason' => 'orchestrator_unavailable'];
        }

        $registered = videochat_backend_module_register_orchestrator_bindings($descriptor);
        if (!(bool) ($registered['ok'] ?? false)) {
            return ['ok' => false, 'reason' => (string) ($registered['reason'] ?? 'orchestrator_binding_failed')];
        }

        $result = \King\PipelineOrchestrator::run($event, $steps, $options);
        $info = function_exists('king_system_get_component_info') ? king_system_get_component_info('pipeline_orchestrator') : [];
        $runId = is_array($info) ? (string) (($info['configuration']['last_run_id'] ?? '') ?: '') : '';

        return [
            'ok' => true,
            'result' => is_array($result) ? $result : ['value' => $result],
            'run_id' => $runId !== '' ? $runId : null,
        ];
    } catch (Throwable) {
        return ['ok' => false, 'reason' => 'pipeline_run_failed'];
    }
}

/**
 * @return array{ok: bool, reason?: string}
 */
function videochat_backend_module_register_orchestrator_bindings(array $descriptor): array
{
    foreach ((array) ($descriptor['tools'] ?? []) as $tool) {
        if (!is_array($tool)) {
            return ['ok' => false, 'reason' => 'tool_descriptor_invalid'];
        }
        $toolName = trim((string) ($tool['tool_name'] ?? ''));
        $config = is_array($tool['config'] ?? null) ? (array) $tool['config'] : [];
        if ($toolName === '' || !\King\PipelineOrchestrator::registerTool($toolName, $config)) {
            return ['ok' => false, 'reason' => 'tool_registration_failed'];
        }
    }

    foreach ((array) ($descriptor['handlers'] ?? []) as $handler) {
        if (!is_array($handler)) {
            return ['ok' => false, 'reason' => 'handler_descriptor_invalid'];
        }
        $toolName = trim((string) ($handler['tool_name'] ?? ''));
        $callableName = trim((string) ($handler['handler'] ?? ''));
        if ($toolName === '' || $callableName === '' || !is_callable($callableName)) {
            return ['ok' => false, 'reason' => 'handler_not_ready'];
        }
        if (!\King\PipelineOrchestrator::registerHandler($toolName, $callableName)) {
            return ['ok' => false, 'reason' => 'handler_registration_failed'];
        }
    }

    return ['ok' => true];
}
