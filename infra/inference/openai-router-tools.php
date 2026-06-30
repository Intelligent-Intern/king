<?php
declare(strict_types=1);

require_once __DIR__ . '/openai-router-mini-language.php';

function king_openai_router_internal_tool_specs(): array
{
    $tools = [
        'king.inference.structured_mini_language' => [
            'kind' => 'king_inference_mini_language',
            'operations' => array_keys(king_openai_router_mini_language_specs()),
            'io' => 'none',
            'safe' => true,
            'pure' => true,
            'filesystem' => false,
            'cli' => false,
            'network' => false,
            'timeout_category' => 'timeout',
            'error_category' => 'runtime',
        ],
        'king.inference.count_occurrences' => [
            'kind' => 'king_inference_mini_op',
            'operation' => 'count_occurrences',
            'io' => 'none',
            'safe' => true,
            'timeout_category' => 'timeout',
            'error_category' => 'runtime',
        ],
    ];
    return $tools;
}

function king_openai_router_mcp_tools_enabled(array $routerOptions): bool
{
    return !array_key_exists('internal_mcp_tools_enabled', $routerOptions)
        || $routerOptions['internal_mcp_tools_enabled'] === true;
}

function king_openai_router_mcp_tool_timeout_ms(array $routerOptions): int
{
    $timeout = $routerOptions['internal_mcp_tool_timeout_ms'] ?? 100;
    return is_int($timeout) ? max(1, min($timeout, 5000)) : 100;
}

function king_openai_router_mcp_registered_tool_names(): array
{
    if (!function_exists('king_system_get_component_info')) {
        return [];
    }

    try {
        $info = king_system_get_component_info('pipeline_orchestrator');
    } catch (Throwable) {
        return [];
    }
    $registered = $info['configuration']['registered_tools'] ?? [];
    if (!is_array($registered)) {
        return [];
    }

    $names = [];
    foreach ($registered as $name) {
        if (is_string($name) && $name !== '') {
            $names[$name] = true;
        }
    }
    $result = array_keys($names);
    sort($result);
    return $result;
}

function king_openai_router_mcp_register_internal_tools(): array
{
    static $registration = null;
    if (is_array($registration)) {
        return $registration;
    }

    $registered = [];
    $errors = [];
    if (!function_exists('king_pipeline_orchestrator_register_tool')) {
        return $registration = [
            'registered' => [],
            'registry_names' => king_openai_router_mcp_registered_tool_names(),
            'errors' => ['king_pipeline_orchestrator_register_tool unavailable'],
        ];
    }

    foreach (king_openai_router_internal_tool_specs() as $name => $config) {
        try {
            $ok = king_pipeline_orchestrator_register_tool($name, $config);
            if ($ok === true) {
                $registered[] = $name;
            }
        } catch (Throwable $e) {
            $errors[] = $name . ':' . $e::class;
        }
    }

    return $registration = [
        'registered' => $registered,
        'registry_names' => king_openai_router_mcp_registered_tool_names(),
        'errors' => $errors,
    ];
}

function king_openai_router_mcp_tool_bridge_result(array $payload, array $routerOptions): array
{
    $startedNs = hrtime(true);
    $toolName = 'king.inference.structured_mini_language';
    $miniLanguageResult = king_openai_router_mini_language_result($payload);
    $result = [
        'executed' => false,
        'content' => null,
        'tool_name' => $toolName,
        'status' => 'not_applicable',
        'error_category' => 'none',
        'duration_ms' => 0.0,
        'registry_names' => [],
        'registered_now' => [],
        'registry_errors' => [],
    ];

    if (!is_array($miniLanguageResult)) {
        return $result;
    }
    if (!king_openai_router_mcp_tools_enabled($routerOptions)) {
        $result['status'] = 'disabled';
        $result['error_category'] = 'policy';
        return $result;
    }

    $registry = king_openai_router_mcp_register_internal_tools();
    $result['registry_names'] = is_array($registry['registry_names'] ?? null) ? $registry['registry_names'] : [];
    $result['registered_now'] = is_array($registry['registered'] ?? null) ? $registry['registered'] : [];
    $result['registry_errors'] = is_array($registry['errors'] ?? null) ? $registry['errors'] : [];
    if (!in_array($toolName, $result['registry_names'], true)) {
        $result['status'] = 'not_registered';
        $result['error_category'] = 'registry';
        return $result;
    }

    $content = is_array($miniLanguageResult) && is_string($miniLanguageResult['content'] ?? null)
        ? $miniLanguageResult['content']
        : null;

    $durationMs = (hrtime(true) - $startedNs) / 1_000_000;
    $result['duration_ms'] = round($durationMs, 3);
    if ($durationMs > king_openai_router_mcp_tool_timeout_ms($routerOptions)) {
        $result['status'] = 'timeout';
        $result['error_category'] = 'timeout';
        return $result;
    }
    if (!is_string($content) || $content === '') {
        $result['status'] = 'no_result';
        $result['error_category'] = 'validation';
        return $result;
    }

    $result['executed'] = true;
    $result['content'] = $content;
    $result['program'] = $miniLanguageResult['program'] ?? null;
    $result['typed_result'] = $miniLanguageResult['result'] ?? null;
    $result['status'] = 'executed';
    return $result;
}
