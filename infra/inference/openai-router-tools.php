<?php
declare(strict_types=1);

function king_openai_router_internal_tool_specs(): array
{
    return [
        'king.inference.count_occurrences' => [
            'kind' => 'king_inference_mini_op',
            'operation' => 'count_occurrences',
            'io' => 'none',
            'safe' => true,
            'timeout_category' => 'timeout',
            'error_category' => 'runtime',
        ],
    ];
}

function king_openai_router_last_user_text_for_tool(array $payload): string
{
    $messages = is_array($payload['messages'] ?? null) ? $payload['messages'] : [];
    for ($index = count($messages) - 1; $index >= 0; $index--) {
        $message = $messages[$index] ?? null;
        if (!is_array($message) || (($message['role'] ?? null) !== 'user')) {
            continue;
        }
        $content = king_openai_router_message_content_text($message['content'] ?? '');
        if ($content !== '') {
            return $content;
        }
    }
    return '';
}

function king_openai_router_count_tool_candidate(array $payload): bool
{
    $text = strtolower(king_openai_router_last_user_text_for_tool($payload));
    if ($text === '') {
        return false;
    }

    $questionIntent = str_contains($text, 'how many')
        || str_contains($text, 'how often')
        || str_contains($text, 'wie viele')
        || str_contains($text, 'wieviele')
        || str_contains($text, 'wie oft')
        || str_contains($text, 'ho man');
    $countVocabulary = str_contains($text, 'letter')
        || str_contains($text, 'character')
        || str_contains($text, 'ltter')
        || str_contains($text, 'buchstaben')
        || str_contains($text, 'zeichen');

    return $questionIntent && $countVocabulary;
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
    $toolName = 'king.inference.count_occurrences';
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

    if (!king_openai_router_mcp_tools_enabled($routerOptions)) {
        $result['status'] = 'disabled';
        $result['error_category'] = 'policy';
        return $result;
    }
    if (!king_openai_router_count_tool_candidate($payload)) {
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

    try {
        $content = king_inference_runtime_mini_op_content($payload);
    } catch (Throwable $e) {
        $result['status'] = 'runtime_error';
        $result['error_category'] = 'runtime';
        $result['registry_errors'][] = $e::class;
        return $result;
    }

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
    $result['status'] = 'executed';
    return $result;
}

function king_openai_router_deterministic_result(array $payload, array $routerOptions = []): ?array
{
    $toolResult = king_openai_router_mcp_tool_bridge_result($payload, $routerOptions);
    if (($toolResult['status'] ?? 'not_applicable') !== 'not_applicable') {
        king_inference_runtime_log_line('tool_bridge', [
            'tool_name' => $toolResult['tool_name'] ?? '',
            'status' => $toolResult['status'] ?? '',
            'error_category' => $toolResult['error_category'] ?? '',
            'duration_ms' => $toolResult['duration_ms'] ?? 0,
            'registered_now' => $toolResult['registered_now'] ?? [],
            'registry_names' => $toolResult['registry_names'] ?? [],
            'registry_errors' => $toolResult['registry_errors'] ?? [],
            'executed' => !empty($toolResult['executed']),
        ]);
    }
    if (!empty($toolResult['executed']) && is_string($toolResult['content'] ?? null)) {
        return [
            'content' => $toolResult['content'],
            'source' => 'mcp_internal_tool',
            'tool' => $toolResult,
        ];
    }

    $content = king_inference_runtime_mini_op_content($payload);
    if (!is_string($content) || $content === '') {
        return null;
    }
    return [
        'content' => $content,
        'source' => 'runtime_mini_op',
        'tool' => null,
    ];
}
