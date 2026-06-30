<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once __DIR__ . '/runtime-logging.php';
require_once __DIR__ . '/openai-router-models.php';
require_once __DIR__ . '/openai-router-coder.php';
require_once __DIR__ . '/openai-router-prompt.php';
require_once __DIR__ . '/openai-router-tools.php';
require_once __DIR__ . '/openai-router-stream.php';

$host = getenv('KING_OPENAI_HOST') ?: '127.0.0.1';
$port = (int) (getenv('KING_OPENAI_PORT') ?: '8080');
if ($port <= 0 || $port > 65535) {
    fwrite(STDERR, "KING_OPENAI_PORT must be between 1 and 65535.\n");
    exit(1);
}
$contextPolicy = getenv('KING_OPENAI_CONTEXT_POLICY') ?: 'full';
if (!in_array($contextPolicy, ['full', 'last_user'], true)) {
    $contextPolicy = 'full';
}
$defaultSystemPrompt = getenv('KING_OPENAI_DEFAULT_SYSTEM_PROMPT');
if (!is_string($defaultSystemPrompt) || trim($defaultSystemPrompt) === '') {
    $defaultSystemPrompt = '';
}
$defaultMaxTokens = max(1, (int) (getenv('KING_OPENAI_DEFAULT_MAX_TOKENS') ?: 128));
$maxCompletionTokens = max(1, (int) (getenv('KING_OPENAI_MAX_COMPLETION_TOKENS') ?: 512));
$coderInstructionWrapper = king_openai_router_env_bool('KING_OPENAI_CODER_INSTRUCTION_WRAPPER', true);
$internalMcpToolsEnabled = king_openai_router_env_bool('KING_OPENAI_INTERNAL_MCP_TOOLS', true);
$internalMcpToolTimeoutMs = max(1, min((int) (getenv('KING_OPENAI_INTERNAL_MCP_TOOL_TIMEOUT_MS') ?: 100), 5000));
$allowBufferedNativeStream = king_openai_router_env_bool('KING_OPENAI_ALLOW_BUFFERED_NATIVE_STREAM', false);

function king_openai_router_string(array $source, string $key, string $fallback = ''): string
{
    return is_string($source[$key] ?? null) && $source[$key] !== '' ? $source[$key] : $fallback;
}
function king_openai_router_int(array $source, string $key, int $fallback = 0): int
{
    return is_int($source[$key] ?? null) ? $source[$key] : $fallback;
}
function king_openai_router_bool(array $source, string $key, bool $fallback = false): bool
{
    return is_bool($source[$key] ?? null) ? $source[$key] : $fallback;
}
function king_openai_router_ini_bool(string $key, bool $fallback = false): bool
{
    $value = ini_get($key);
    if ($value === false || trim((string) $value) === '') {
        return $fallback;
    }
    return in_array(strtolower(trim((string) $value)), ['1', 'on', 'yes', 'true'], true);
}
function king_openai_router_array(array $source, string $key): array
{
    return is_array($source[$key] ?? null) ? $source[$key] : [];
}
function king_openai_router_artifact_path(array $config): string
{
    $artifact = king_openai_router_array($config, 'artifact');
    return king_openai_router_string($artifact, 'path', king_openai_router_string($config, 'artifact_path'));
}

$runtimeModelConfigs = king_openai_router_model_configs();
$runtimeModelConfig = reset($runtimeModelConfigs);
if (!is_array($runtimeModelConfig)) {
    fwrite(STDERR, "King runtime model registry did not provide a primary model config.\n");
    exit(1);
}
$modelName = king_openai_router_string($runtimeModelConfig, 'name', 'king-runtime');
$backend = king_openai_router_string($runtimeModelConfig, 'backend', 'unknown');
$runtimeProfile = king_openai_router_string($runtimeModelConfig, 'runtime_profile', 'unknown');
$requestedProfile = king_openai_router_string($runtimeModelConfig, 'runtime_requested_profile', $runtimeProfile);
$artifactPath = king_openai_router_artifact_path($runtimeModelConfig);
$withMemory = king_openai_router_bool($runtimeModelConfig, 'with_memory');
$contextTokens = king_openai_router_int($runtimeModelConfig, 'context_tokens', 2048);
$kvCache = king_openai_router_array($runtimeModelConfig, 'kv_cache');
$kvPageTokens = king_openai_router_int($kvCache, 'page_tokens', 16);
$kvElementBytes = king_openai_router_int($kvCache, 'element_bytes', 2);
$llmCache = king_openai_router_array($runtimeModelConfig, 'llm_cache');
$llmCacheEnabled = king_openai_router_bool($llmCache, 'enabled');
$gpuConfig = king_openai_router_array($runtimeModelConfig, 'gpu');
$gpuThermal = king_openai_router_array($gpuConfig, 'thermal');
$gpuRuntime = king_openai_router_array($runtimeModelConfig, 'gpu_runtime');
$gpuEnabled = $backend === 'king_native_gpu' && king_openai_router_bool($gpuConfig, 'enabled');
$gpuLayers = king_openai_router_int($gpuConfig, 'max_gpu_layers');
$gpuVramReserveMb = king_openai_router_int($gpuConfig, 'vram_reserve_mb');
$gpuMinFreeVramMb = king_openai_router_int($gpuConfig, 'min_free_vram_mb');
$gpuSensorPath = king_openai_router_string($gpuThermal, 'sensor_path');
$gpuSensorCommand = king_openai_router_string($gpuThermal, 'sensor_command');
$listenerOverrideAllowed = king_openai_router_ini_bool('king.security_allow_config_override');
$listenerConfig = $listenerOverrideAllowed ? [
    'tcp_connect_timeout_ms' => 1000,
    'tcp.persistent_listener' => true,
] : [];

king_inference_runtime_log_configured([
    'host' => $host,
    'port' => $port,
    'listener_config_override_allowed' => $listenerOverrideAllowed,
    'listener_persistent' => $listenerOverrideAllowed,
    'requested_profile' => $requestedProfile,
    'selected_profile' => $runtimeProfile,
    'backend' => $backend,
    'model' => $modelName,
    'artifact' => $artifactPath,
    'artifact_readable' => is_file($artifactPath) && is_readable($artifactPath),
    'gpu_profile_available' => king_openai_router_bool($runtimeModelConfig, 'runtime_gpu_profile_available'),
    'gpu_enabled' => $gpuEnabled,
    'gpu_layers' => $gpuLayers,
    'gpu_vram_reserve_mb' => $gpuVramReserveMb,
    'gpu_min_free_vram_mb' => $gpuMinFreeVramMb,
    'context_tokens' => $contextTokens,
    'kv_page_tokens' => $kvPageTokens,
    'kv_element_bytes' => $kvElementBytes,
    'with_memory' => $withMemory,
    'llm_cache_enable' => $llmCacheEnabled,
    'context_policy' => $contextPolicy,
    'default_max_tokens' => $defaultMaxTokens,
    'max_completion_tokens' => $maxCompletionTokens,
    'coder_instruction_wrapper' => $coderInstructionWrapper,
    'internal_mcp_tools_enabled' => $internalMcpToolsEnabled,
    'internal_mcp_tool_timeout_ms' => $internalMcpToolTimeoutMs,
    'allow_buffered_native_stream' => $allowBufferedNativeStream,
    'model_registry_count' => count($runtimeModelConfigs),
    'model_registry_ids' => array_keys($runtimeModelConfigs),
    'gpu_thermal_source' => $gpuSensorPath !== '' ? $gpuSensorPath : $gpuSensorCommand,
    'gpu_thermal_check_interval_sec' => king_openai_router_int($gpuThermal, 'check_interval_seconds'),
    'gpu_generation_ready' => king_openai_router_bool($gpuRuntime, 'generation_ready'),
    'gpu_admission_reason' => king_openai_router_string($gpuRuntime, 'reason'),
]);

foreach ($runtimeModelConfigs as $registryId => $registryConfig) {
    if (!is_array($registryConfig)) {
        fwrite(STDERR, "Configured model registry entry is invalid: {$registryId}\n");
        exit(1);
    }
    $registryArtifactPath = king_openai_router_artifact_path($registryConfig);
    if ($registryArtifactPath === '' || !is_file($registryArtifactPath) || !is_readable($registryArtifactPath)) {
        fwrite(STDERR, "Configured model artifact is not readable for {$registryId}: {$registryArtifactPath}\n");
        exit(1);
    }
}

[$models, $modelAliases] = king_openai_router_load_model_registry($runtimeModelConfigs);
foreach ($models as $registryName => $model) {
    king_inference_runtime_log_model_admitted($registryName, $model);
}

fwrite(STDERR, "King OpenAI router listening on http://{$host}:{$port}/v1\n");
fwrite(STDERR, "Configured models: " . implode(',', array_keys($models)) . "\n");
fwrite(STDERR, "Configured backend: {$backend}\n");
fwrite(STDERR, "Configured artifact: {$artifactPath}\n");
if ($gpuEnabled) {
    fwrite(STDERR, "GPU layers: {$gpuLayers}\n");
    fwrite(STDERR, "GPU VRAM reserve: {$gpuVramReserveMb} MB\n");
    fwrite(STDERR, "GPU minimum free VRAM: {$gpuMinFreeVramMb} MB\n");
    fwrite(STDERR, 'GPU thermal source: ' . ($gpuSensorPath !== '' ? $gpuSensorPath : $gpuSensorCommand) . "\n");
}

function king_openai_router_path_is(array $request, string $path): bool
{
    $requestPath = $request['path'] ?? $request['uri'] ?? '';
    if (!is_string($requestPath) || $requestPath === '') {
        return false;
    }
    $queryOffset = strpos($requestPath, '?');
    if ($queryOffset !== false) {
        $requestPath = substr($requestPath, 0, $queryOffset);
    }
    return $requestPath === $path;
}

function king_openai_router_decode_chat_payload(array $request): ?array
{
    if (strcasecmp((string) ($request['method'] ?? ''), 'POST') !== 0) {
        return null;
    }
    if (!king_openai_router_path_is($request, '/v1/chat/completions')) {
        return null;
    }
    $body = $request['body'] ?? null;
    if (!is_string($body) || $body === '') {
        return null;
    }
    try {
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    if (!is_array($payload)) {
        return null;
    }
    return $payload;
}

function king_openai_router_int_payload_value(array $payload, string $key): ?int
{
    $value = $payload[$key] ?? null;
    if (is_int($value)) {
        return $value;
    }
    if (is_float($value)) {
        return (int) $value;
    }
    if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
        return (int) $value;
    }
    return null;
}

function king_openai_router_prepare_chat_payload(
    array $payload,
    bool $withMemory,
    string $contextPolicy,
    string $defaultSystemPrompt,
    bool $coderInstructionWrapper,
    array $modelAliases,
    int $defaultMaxTokens,
    int $maxCompletionTokens
): array
{
    $prepared = king_openai_router_assemble_chat_messages(
        $payload,
        $contextPolicy,
        $defaultSystemPrompt,
        $coderInstructionWrapper
    );
    if (!$withMemory) {
        $prepared['with_memory'] = false;
        $prepared['graph_options'] = [
            ...((isset($prepared['graph_options']) && is_array($prepared['graph_options'])) ? $prepared['graph_options'] : []),
            'with_memory' => false,
        ];
    }
    $requestedMaxTokens = king_openai_router_int_payload_value($prepared, 'max_tokens')
        ?? king_openai_router_int_payload_value($prepared, 'max_completion_tokens')
        ?? $defaultMaxTokens;
    $prepared['max_tokens'] = max(1, min($requestedMaxTokens, $maxCompletionTokens));
    unset($prepared['max_completion_tokens']);
    if (!array_key_exists('temperature', $prepared)) {
        $prepared['temperature'] = 0.0;
    }
    if (!array_key_exists('top_p', $prepared)) {
        $prepared['top_p'] = 1.0;
    }
    $requestedModel = $prepared['model'] ?? null;
    if (is_string($requestedModel)
        && $requestedModel !== ''
        && isset($modelAliases[$requestedModel])
        && is_string($modelAliases[$requestedModel])
        && $modelAliases[$requestedModel] !== ''
    ) {
        $prepared['model'] = $modelAliases[$requestedModel];
    }
    return $prepared;
}

function king_openai_router_log_prepared_payload(array $original, array $prepared): void
{
    $encoded = json_encode($prepared, JSON_UNESCAPED_SLASHES);
    $originalTools = $original['tools'] ?? null;
    $originalFunctions = $original['functions'] ?? null;
    $preparedTools = $prepared['tools'] ?? null;
    $preparedFunctions = $prepared['functions'] ?? null;
    $toolStatus = king_openai_router_tool_status($original);
    [$originalMessageCount, $originalTextChars, $originalLastUserChars] =
        king_inference_runtime_payload_message_stats($original);
    [$preparedMessageCount, $preparedTextChars, $preparedLastUserChars] =
        king_inference_runtime_payload_message_stats($prepared);

    king_inference_runtime_log_line('prepared', [
        'normalized' => $original !== $prepared,
        'requested_model' => king_inference_runtime_request_model($original),
        'prepared_model' => king_inference_runtime_request_model($prepared),
        'prepared_body_bytes' => is_string($encoded) ? strlen($encoded) : 0,
        'original_message_count' => $originalMessageCount,
        'prepared_message_count' => $preparedMessageCount,
        'original_message_text_chars' => $originalTextChars,
        'prepared_message_text_chars' => $preparedTextChars,
        'original_last_user_chars' => $originalLastUserChars,
        'prepared_last_user_chars' => $preparedLastUserChars,
        'prepared_stream' => ($prepared['stream'] ?? null) === true,
        'prepared_max_tokens' => king_openai_router_int_payload_value($prepared, 'max_tokens') ?? '',
        'original_tool_schema_count' => is_array($originalTools) ? count($originalTools) : 0,
        'prepared_tool_schema_count' => is_array($preparedTools) ? count($preparedTools) : 0,
        'original_legacy_function_count' => is_array($originalFunctions) ? count($originalFunctions) : 0,
        'prepared_legacy_function_count' => is_array($preparedFunctions) ? count($preparedFunctions) : 0,
        'tool_choice_removed' => array_key_exists('tool_choice', $original) && !array_key_exists('tool_choice', $prepared),
        'parallel_tool_calls_removed' => array_key_exists('parallel_tool_calls', $original) && !array_key_exists('parallel_tool_calls', $prepared),
        'tool_execution' => !empty($toolStatus['context_only']) ? 'context_only' : 'none',
        'tool_fields_sanitized' => !empty($toolStatus['context_only'])
            && !array_key_exists('tools', $prepared)
            && !array_key_exists('functions', $prepared)
            && !array_key_exists('tool_choice', $prepared)
            && !array_key_exists('function_call', $prepared)
            && !array_key_exists('parallel_tool_calls', $prepared),
        'tool_field_names' => is_array($toolStatus['present_fields'] ?? null) ? $toolStatus['present_fields'] : [],
        'available_tool_names' => is_array($toolStatus['available_tool_names'] ?? null) ? $toolStatus['available_tool_names'] : [],
        'forced_tool_names' => is_array($toolStatus['forced_tool_names'] ?? null) ? $toolStatus['forced_tool_names'] : [],
        'assistant_tool_call_names' => is_array($toolStatus['assistant_tool_call_names'] ?? null) ? $toolStatus['assistant_tool_call_names'] : [],
        'unknown_tool_names' => is_array($toolStatus['unknown_tool_names'] ?? null) ? $toolStatus['unknown_tool_names'] : [],
        'invalid_tool_schema_count' => is_int($toolStatus['invalid_schema_count'] ?? null)
            ? $toolStatus['invalid_schema_count']
            : 0,
    ]);
}

function king_openai_router_stream_terminal(array $event): bool
{
    $choice = $event['choices'][0] ?? null;
    return is_array($choice)
        && array_key_exists('finish_reason', $choice)
        && $choice['finish_reason'] !== null;
}

function king_openai_router_sse(array $event): string
{
    return 'data: ' . json_encode($event, JSON_UNESCAPED_SLASHES) . "\n\n";
}

function king_openai_router_chat_completion_response(string $id, int $created, string $model, string $content): array
{
    return [
        'id' => $id,
        'object' => 'chat.completion',
        'created' => $created,
        'model' => $model,
        'choices' => [
            [
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => $content,
                ],
                'finish_reason' => 'stop',
            ],
        ],
    ];
}

function king_openai_router_stream_id(): string
{
    try {
        return 'chatcmpl-king-' . bin2hex(random_bytes(8));
    } catch (Throwable) {
        return 'chatcmpl-king-' . (string) getmypid() . '-' . (string) time();
    }
}

function king_openai_router_content_chunk(string $id, int $created, string $model, string $content): array
{
    return [
        'id' => $id,
        'object' => 'chat.completion.chunk',
        'created' => $created,
        'model' => $model,
        'choices' => [
            [
                'index' => 0,
                'delta' => ['content' => $content],
                'finish_reason' => null,
            ],
        ],
    ];
}

function king_openai_router_deterministic_response(
    array $payload,
    string $content,
    string $model,
    int $startedNs
): array {
    $id = king_openai_router_stream_id();
    $created = time();

    if (($payload['stream'] ?? false) === true) {
        $nowNs = hrtime(true);
        $initial = king_openai_router_attach_stream_timing(
            king_openai_router_initial_chunk($id, $created, $model),
            king_openai_router_timing_payload($startedNs, $nowNs, 1, 0, null, null, null, false, false, null, [])
        );
        $contentEvent = king_openai_router_attach_stream_timing(
            king_openai_router_content_chunk($id, $created, $model, $content),
            king_openai_router_timing_payload($startedNs, $nowNs, 2, 1, $nowNs, null, $nowNs, false, false, null, [])
        );
        $terminal = king_openai_router_attach_stream_timing(
            king_openai_router_terminal_chunk($id, $created, $model),
            king_openai_router_timing_payload($startedNs, $nowNs, 3, 1, $nowNs, $nowNs, $nowNs, false, true, 'stop', [])
        );

        return [
            'status' => 200,
            'headers' => [
                'content-type' => 'text/event-stream',
                'cache-control' => 'no-cache',
                'x-accel-buffering' => 'no',
                'x-king-openai-router-path' => 'deterministic_task',
            ],
            'body' => king_openai_router_sse($initial)
                . king_openai_router_sse($contentEvent)
                . king_openai_router_sse($terminal)
                . "data: [DONE]\n\n",
        ];
    }

    return [
        'status' => 200,
        'headers' => ['content-type' => 'application/json'],
        'body' => (string) json_encode(
            king_openai_router_chat_completion_response($id, $created, $model, $content),
            JSON_UNESCAPED_SLASHES
        ),
    ];
}

function king_openai_router_terminal_chunk(string $id, int $created, string $model): array
{
    return [
        'id' => $id,
        'object' => 'chat.completion.chunk',
        'created' => $created,
        'model' => $model,
        'choices' => [
            [
                'index' => 0,
                'delta' => ['content' => ''],
                'finish_reason' => 'stop',
            ],
        ],
    ];
}

function king_openai_router_initial_chunk(string $id, int $created, string $model): array
{
    return [
        'id' => $id,
        'object' => 'chat.completion.chunk',
        'created' => $created,
        'model' => $model,
        'choices' => [
            [
                'index' => 0,
                'delta' => ['role' => 'assistant'],
                'finish_reason' => null,
            ],
        ],
    ];
}

function king_openai_router_normalize_chunk(array $event, string $id, int $created, string $model): array
{
    if (($event['object'] ?? null) === 'chat.completion.chunk') {
        $event['id'] = $id;
        $event['created'] = $created;
        $event['model'] = $model;
    }
    return $event;
}

function king_openai_router_role_only_chunk(array $event): bool
{
    $choice = $event['choices'][0] ?? null;
    $delta = is_array($choice) ? ($choice['delta'] ?? null) : null;

    return is_array($delta)
        && ($delta['role'] ?? null) === 'assistant'
        && count($delta) === 1
        && (($choice['finish_reason'] ?? null) === null);
}

function king_openai_router_plain_artifact_requested(array $payload): bool
{
    $responseFormat = $payload['response_format'] ?? null;
    if (is_array($responseFormat)) {
        $type = $responseFormat['type'] ?? null;
        if ($type === 'json_object' || $type === 'json_schema') {
            return true;
        }
    }

    $texts = [];
    $messages = $payload['messages'] ?? null;
    if (is_array($messages)) {
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            if (($message['role'] ?? null) !== 'user') {
                continue;
            }
            $content = king_openai_router_message_content_text($message['content'] ?? '');
            if ($content !== '') {
                $texts[] = $content;
            }
        }
    }
    foreach (['prompt', 'native_prompt_text'] as $key) {
        if (is_string($payload[$key] ?? null) && trim($payload[$key]) !== '') {
            $texts[] = $payload[$key];
        }
    }

    $text = strtolower(implode("\n", $texts));
    if ($text === '') {
        return false;
    }

    foreach ([
        'no markdown',
        'without markdown',
        'do not use markdown',
        'return only json',
        'output only json',
        'respond only json',
        'valid json',
        'return only php',
        'output only php',
        'return only code',
        'output only code',
        'only file contents',
        'exact text',
    ] as $needle) {
        if (str_contains($text, $needle)) {
            return true;
        }
    }

    return false;
}

function king_openai_router_strip_artifact_markdown_fence(string $content): string
{
    $trimmed = trim($content);
    if (preg_match('/^```[A-Za-z0-9_.+-]*[ \t]*\R(.*)\R```[ \t]*$/s', $trimmed, $match) === 1) {
        return trim($match[1]);
    }
    if (preg_match('/^```[A-Za-z0-9_.+-]*[ \t]*(.*?)```[ \t]*$/s', $trimmed, $match) === 1) {
        return trim($match[1]);
    }
    return $content;
}

function king_openai_router_event_delta_content(array $event): string
{
    $choice = $event['choices'][0] ?? null;
    if (!is_array($choice)) {
        return '';
    }
    $delta = $choice['delta'] ?? null;
    if (!is_array($delta) || !is_string($delta['content'] ?? null)) {
        return '';
    }
    return $delta['content'];
}

function king_openai_router_clear_event_delta_content(array $event): array
{
    if (isset($event['choices'][0]['delta']['content'])) {
        $event['choices'][0]['delta']['content'] = '';
    }
    return $event;
}

function king_openai_router_normalize_plain_artifact_response(array $response, array $payload): array
{
    if (!king_openai_router_plain_artifact_requested($payload)) {
        return $response;
    }
    if (!is_string($response['body'] ?? null) || trim($response['body']) === '') {
        return $response;
    }

    try {
        $body = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return $response;
    }
    if (!is_array($body)) {
        return $response;
    }

    $changed = false;
    if (isset($body['choices'][0]['message']['content'])
        && is_string($body['choices'][0]['message']['content'])) {
        $before = $body['choices'][0]['message']['content'];
        $after = king_openai_router_strip_artifact_markdown_fence($before);
        if ($after !== $before) {
            $body['choices'][0]['message']['content'] = $after;
            $changed = true;
        }
    }
    if (isset($body['choices'][0]['text']) && is_string($body['choices'][0]['text'])) {
        $before = $body['choices'][0]['text'];
        $after = king_openai_router_strip_artifact_markdown_fence($before);
        if ($after !== $before) {
            $body['choices'][0]['text'] = $after;
            $changed = true;
        }
    }
    if (!$changed) {
        return $response;
    }

    $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded)) {
        return $response;
    }
    $response['body'] = $encoded;
    if (isset($response['headers']) && is_array($response['headers'])) {
        $response['headers']['content-length'] = (string) strlen($encoded);
    }
    return $response;
}

$routerOptions = [
    'owned_by' => 'local-king',
    'with_memory' => $withMemory,
    'context_policy' => $contextPolicy,
    'default_system_prompt' => $defaultSystemPrompt,
    'coder_instruction_wrapper' => $coderInstructionWrapper,
    'model_aliases' => $modelAliases,
    'default_max_tokens' => $defaultMaxTokens,
    'max_completion_tokens' => $maxCompletionTokens,
    'internal_mcp_tools_enabled' => $internalMcpToolsEnabled,
    'internal_mcp_tool_timeout_ms' => $internalMcpToolTimeoutMs,
    'allow_buffered_native_stream' => $allowBufferedNativeStream,
    'read_timeout_ms' => 250,
    'max_events' => 4096,
    'max_idle_events' => 4800,
    'max_chat_messages' => 256,
    'max_response_input_items' => 256,
    'max_completion_prompts' => 128,
    'max_embedding_inputs' => 512,
    'max_embedding_tokens' => 2048,
    'max_embedding_dimensions' => 8192,
];

while (true) {
    try {
        king_http1_server_listen_once(
            $host,
            $port,
            $listenerConfig,
            static function (array $request) use ($models, $routerOptions): array {
                $startedNs = hrtime(true);
                king_inference_runtime_log_request_executing($request, $models);
                $chatPayload = king_openai_router_decode_chat_payload($request);
                if ($chatPayload !== null) {
                    $withMemory = isset($routerOptions['with_memory']) && is_bool($routerOptions['with_memory'])
                        ? $routerOptions['with_memory']
                        : false;
                    $contextPolicy = isset($routerOptions['context_policy']) && is_string($routerOptions['context_policy'])
                        ? $routerOptions['context_policy']
                        : 'full';
                    $defaultSystemPrompt = isset($routerOptions['default_system_prompt']) && is_string($routerOptions['default_system_prompt'])
                        ? $routerOptions['default_system_prompt']
                        : '';
                    $coderInstructionWrapper = isset($routerOptions['coder_instruction_wrapper']) && is_bool($routerOptions['coder_instruction_wrapper'])
                        ? $routerOptions['coder_instruction_wrapper']
                        : true;
                    $modelAliases = isset($routerOptions['model_aliases']) && is_array($routerOptions['model_aliases'])
                        ? $routerOptions['model_aliases']
                        : [];
                    $defaultMaxTokens = isset($routerOptions['default_max_tokens']) && is_int($routerOptions['default_max_tokens'])
                        ? max(1, $routerOptions['default_max_tokens'])
                        : 32;
                    $maxCompletionTokens = isset($routerOptions['max_completion_tokens']) && is_int($routerOptions['max_completion_tokens'])
                        ? max(1, $routerOptions['max_completion_tokens'])
                        : 64;
                    $preparedPayload = king_openai_router_prepare_chat_payload(
                        $chatPayload,
                        $withMemory,
                        $contextPolicy,
                        $defaultSystemPrompt,
                        $coderInstructionWrapper,
                        $modelAliases,
                        $defaultMaxTokens,
                        $maxCompletionTokens
                    );
                    king_openai_router_log_prepared_payload($chatPayload, $preparedPayload);
                    $preparedRequest = $request;
                    $preparedRequest['body'] = (string) json_encode($preparedPayload, JSON_UNESCAPED_SLASHES);
                    if (!isset($preparedRequest['headers']) || !is_array($preparedRequest['headers'])) {
                        $preparedRequest['headers'] = [];
                    }
                    $preparedRequest['headers']['content-length'] = (string) strlen($preparedRequest['body']);
                    $deterministicResult = king_openai_router_deterministic_result($preparedPayload, $routerOptions);
                    if ($deterministicResult !== null && is_string($deterministicResult['content'] ?? null)) {
                        $responseModel = is_string($preparedPayload['model'] ?? null) && $preparedPayload['model'] !== ''
                            ? $preparedPayload['model']
                            : array_key_first($models);
                        $response = king_openai_router_deterministic_response(
                            $preparedPayload,
                            $deterministicResult['content'],
                            is_string($responseModel) && $responseModel !== '' ? $responseModel : 'king-local',
                            $startedNs
                        );
                        king_inference_runtime_log_request_completed($preparedRequest, $models, $startedNs, $response);
                        return $response;
                    }
                    if (($preparedPayload['stream'] ?? false) === true) {
                        $streamResponse = king_openai_router_stream_response(
                            $models,
                            $preparedPayload,
                            $routerOptions,
                            $preparedRequest,
                            $startedNs
                        );
                        if ($streamResponse !== null) {
                            return $streamResponse;
                        }
                    }

                    $request = $preparedRequest;
                }

                $response = king_inference_openai_http_response($models, $request, $routerOptions);
                if ($chatPayload !== null && isset($preparedPayload) && is_array($preparedPayload)) {
                    $response = king_openai_router_normalize_plain_artifact_response($response, $preparedPayload);
                }
                king_inference_runtime_log_request_completed($request, $models, $startedNs, $response);
                return $response;
            }
        );
    } catch (Throwable $e) {
        fwrite(STDERR, '[' . date('c') . '] ' . $e::class . ': ' . $e->getMessage() . "\n");
        usleep(250000);
    }
}
