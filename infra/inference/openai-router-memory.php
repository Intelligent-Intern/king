<?php
declare(strict_types=1);

function king_openai_router_memory_policy(array $runtimeModelConfig): array
{
    $withMemory = king_openai_router_bool($runtimeModelConfig, 'with_memory');
    $llmCache = king_openai_router_array($runtimeModelConfig, 'llm_cache');
    $llmCacheConfigured = king_openai_router_bool($llmCache, 'enabled');
    $status = [];

    if (function_exists('king_inference_llm_cache_status')) {
        try {
            $status = king_inference_llm_cache_status(null, ['with_memory' => $withMemory]);
        } catch (Throwable $e) {
            $status = [
                'ok' => false,
                'active' => false,
                'reason' => 'status_error:' . $e::class,
            ];
        }
    }
    $statusOk = ($status === []) || !array_key_exists('ok', $status) || $status['ok'] === true;
    $statusAction = is_string($status['action'] ?? null) ? $status['action'] : '';
    $llmCacheActive = $withMemory && $llmCacheConfigured && $statusOk;
    $disabledReason = '';
    if (!$withMemory) {
        $disabledReason = 'memory_disabled';
    } else if (!$llmCacheConfigured) {
        $disabledReason = 'llm_cache_not_configured';
    } else if (!$statusOk) {
        $disabledReason = $statusAction !== '' ? $statusAction : 'disk_floor_failed';
    }

    return [
        'with_memory' => $withMemory,
        'mode' => $withMemory ? 'stateful_graph_memory' : 'stateless',
        'llm_cache_configured' => $llmCacheConfigured,
        'llm_cache_active' => $llmCacheActive,
        'llm_cache' => [
            ...$llmCache,
            'enabled' => $llmCacheActive,
            'configured_enabled' => $llmCacheConfigured,
            'status_ok' => $statusOk,
            'status_action' => $statusAction,
            'disabled_reason' => $llmCacheActive ? '' : $disabledReason,
        ],
        'llm_cache_status' => is_array($status) ? $status : [],
    ];
}

function king_openai_router_memory_status_log_fields(array $policy): array
{
    $status = is_array($policy['llm_cache_status'] ?? null) ? $policy['llm_cache_status'] : [];
    return [
        'llm_cache_status_action' => is_string($status['action'] ?? null) ? $status['action'] : '',
        'llm_cache_status_degraded' => !empty($status['degraded']),
        'llm_cache_min_free_mb' => is_int($status['min_free_mb'] ?? null) ? $status['min_free_mb'] : '',
        'llm_cache_free_mb' => is_int($status['free_mb'] ?? null) ? $status['free_mb'] : '',
        'llm_cache_alert_requested' => !empty($status['alert']['requested']),
        'llm_cache_webhook_configured' => !empty($status['alert']['webhook_configured']),
        'llm_cache_mcp_configured' => !empty($status['alert']['mcp_configured']),
    ];
}

function king_openai_router_payload_bool(array $payload, string $key): ?bool
{
    return is_bool($payload[$key] ?? null) ? $payload[$key] : null;
}

function king_openai_router_payload_llm_cache_enabled(array $payload): ?bool
{
    $cache = $payload['llm_cache'] ?? null;
    if (!is_array($cache)) {
        return null;
    }
    return king_openai_router_payload_bool($cache, 'enabled');
}

function king_openai_router_apply_memory_policy_to_payload(array $payload, array $policy): array
{
    $withMemory = !empty($policy['with_memory']);
    $llmCache = is_array($policy['llm_cache'] ?? null) ? $policy['llm_cache'] : ['enabled' => false];

    $payload['with_memory'] = $withMemory;
    $payload['llm_cache'] = $llmCache;

    $graphOptions = is_array($payload['graph_options'] ?? null) ? $payload['graph_options'] : [];
    $graphOptions['with_memory'] = $withMemory;
    $graphOptions['llm_cache'] = $llmCache;
    $payload['graph_options'] = $graphOptions;

    return $payload;
}

function king_openai_router_memory_log_fields(array $payload): array
{
    $graphOptions = is_array($payload['graph_options'] ?? null) ? $payload['graph_options'] : [];
    $graphCache = is_array($graphOptions['llm_cache'] ?? null) ? $graphOptions['llm_cache'] : [];

    return [
        'prepared_with_memory' => king_openai_router_payload_bool($payload, 'with_memory') === true,
        'prepared_llm_cache_enabled' => king_openai_router_payload_llm_cache_enabled($payload) === true,
        'prepared_graph_with_memory' => king_openai_router_payload_bool($graphOptions, 'with_memory') === true,
        'prepared_graph_llm_cache_enabled' => king_openai_router_payload_llm_cache_enabled(['llm_cache' => $graphCache]) === true,
    ];
}
