<?php
declare(strict_types=1);

function king_openai_engine_log(string $state, array $fields): void
{
    if (function_exists('king_inference_runtime_log_line')) {
        king_inference_runtime_log_line($state, $fields);
    }
}

function king_openai_engine_deterministic_result(array $payload, array $executionOptions = []): ?array
{
    $toolResult = function_exists('king_openai_router_mcp_tool_bridge_result')
        ? king_openai_router_mcp_tool_bridge_result($payload, $executionOptions)
        : ['status' => 'not_applicable'];

    if (($toolResult['status'] ?? 'not_applicable') !== 'not_applicable') {
        king_openai_engine_log('tool_bridge', [
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

    $miniLanguageResult = function_exists('king_openai_router_mini_language_result')
        ? king_openai_router_mini_language_result($payload)
        : null;
    if (is_array($miniLanguageResult) && is_string($miniLanguageResult['content'] ?? null) && $miniLanguageResult['content'] !== '') {
        king_openai_engine_log('mini_language', [
            'operation' => $miniLanguageResult['program']['operation'] ?? '',
            'typed_result' => $miniLanguageResult['result'] ?? null,
            'source' => 'structured_mini_language',
        ]);
        return [
            'content' => $miniLanguageResult['content'],
            'source' => 'structured_mini_language',
            'tool' => $miniLanguageResult,
        ];
    }

    if (!function_exists('king_inference_runtime_mini_op_content')) {
        return null;
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

function king_openai_router_deterministic_result(array $payload, array $routerOptions = []): ?array
{
    return king_openai_engine_deterministic_result($payload, $routerOptions);
}
