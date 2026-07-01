<?php
declare(strict_types=1);

function king_inference_runtime_log_value(mixed $value): string
{
    if (is_bool($value)) {
        return $value ? 'yes' : 'no';
    }
    if ($value === null) {
        return 'null';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    if (is_array($value)) {
        $items = array_map(static fn (mixed $item): string => king_inference_runtime_log_value($item), $value);
        return $items === [] ? '[]' : implode(',', $items);
    }

    $string = (string) $value;
    if ($string === '') {
        return '""';
    }
    if (preg_match('/^[A-Za-z0-9_.:\\/@+=,-]+$/', $string) === 1) {
        return $string;
    }
    return (string) json_encode($string, JSON_UNESCAPED_SLASHES);
}

function king_inference_runtime_log_line(string $state, array $fields, mixed $stream = null): void
{
    $line = '[' . date('c') . '] king-inference state=' . king_inference_runtime_log_value($state);
    foreach ($fields as $key => $value) {
        $line .= ' ' . $key . '=' . king_inference_runtime_log_value($value);
    }
    fwrite($stream ?? STDERR, $line . "\n");
}

function king_inference_runtime_backend_label(mixed $backend): string
{
    if (is_array($backend)) {
        $name = isset($backend['name']) && is_string($backend['name']) ? $backend['name'] : 'array';
        $runner = isset($backend['runner_path']) && is_string($backend['runner_path']) ? $backend['runner_path'] : '';
        return $runner === '' ? $name : $name . ':' . $runner;
    }
    return is_string($backend) && $backend !== '' ? $backend : 'unknown';
}

function king_inference_runtime_log_configured(array $config, mixed $stream = null): void
{
    king_inference_runtime_log_line('configured', $config, $stream);
}

function king_inference_runtime_decoder_truth_fields(array $info): array
{
    $truth = is_array($info['decoder_truth'] ?? null) ? $info['decoder_truth'] : [];

    return [
        'decoder_backend' => is_string($truth['backend'] ?? null) ? $truth['backend'] : '',
        'decoder_active_device' => is_string($truth['active_device'] ?? null) ? $truth['active_device'] : '',
        'prompt_graph_path' => is_string($truth['prompt_graph_path'] ?? null) ? $truth['prompt_graph_path'] : '',
        'synthetic_graph_path' => is_string($truth['synthetic_graph_path'] ?? null) ? $truth['synthetic_graph_path'] : '',
        'sampler_path' => is_string($truth['sampler_path'] ?? null) ? $truth['sampler_path'] : '',
        'kv_cache_path' => is_string($truth['kv_cache_path'] ?? null) ? $truth['kv_cache_path'] : '',
        'prompt_to_logits_inference' => !empty($truth['prompt_to_logits_inference']),
        'synthetic_token_vector_graph' => !empty($truth['synthetic_token_vector_graph']),
        'decoder_silent_cpu_fallback' => !empty($truth['silent_cpu_fallback']),
        'fallback_policy' => is_string($truth['fallback_policy'] ?? null) ? $truth['fallback_policy'] : '',
        'fallback_error' => is_string($truth['fallback_error'] ?? null) ? $truth['fallback_error'] : '',
    ];
}

function king_inference_runtime_log_model_admitted(string $registryName, object $model, mixed $stream = null): void
{
    $info = king_inference_model_info($model);
    $backend = is_string($info['backend'] ?? null) ? $info['backend'] : 'unknown';
    $truth = is_array($info['runtime_truth'] ?? null) ? $info['runtime_truth'] : [];
    $timing = is_array($truth['measured_token_timing'] ?? null) ? $truth['measured_token_timing'] : [];
    $fields = [
        'registry' => $registryName,
        'model' => is_string($info['name'] ?? null) ? $info['name'] : $registryName,
        'backend' => $backend,
        'admitted' => true,
        'artifact' => is_string($info['artifact_path'] ?? null) ? $info['artifact_path'] : '',
        'active_device' => is_string($truth['active_device'] ?? null) ? $truth['active_device'] : '',
        'model_resident' => !empty($truth['model_resident']),
        'fallback_mode' => is_string($truth['fallback_mode'] ?? null) ? $truth['fallback_mode'] : '',
        'gpu_admission_reason' => is_string($truth['gpu_admission_reason'] ?? null) ? $truth['gpu_admission_reason'] : '',
        'silent_cpu_fallback' => !empty($info['silent_cpu_fallback']),
        'last_timing_available' => !empty($timing['available']),
        'last_generated_tokens' => is_int($timing['generated_tokens'] ?? null) ? $timing['generated_tokens'] : 0,
        'last_ttfb_ms' => is_int($timing['time_to_first_token_ms'] ?? null) ? $timing['time_to_first_token_ms'] : '',
        'last_tokens_per_second' => is_float($timing['tokens_per_second'] ?? null) || is_int($timing['tokens_per_second'] ?? null)
            ? $timing['tokens_per_second']
            : '',
    ];
    $fields += king_inference_runtime_decoder_truth_fields($info);

    if ($backend === 'king_native_gpu') {
        $runtime = is_array($info['gpu_runtime'] ?? null) ? $info['gpu_runtime'] : [];
        $fields['config_ready'] = !empty($runtime['config_ready']);
        $fields['generation_ready'] = !empty($runtime['generation_ready']);
        $fields['reason'] = is_string($runtime['reason'] ?? null) ? $runtime['reason'] : '';
        $fields['refusal_reasons'] = is_array($runtime['refusal_reasons'] ?? null)
            ? array_values($runtime['refusal_reasons'])
            : [];
    } else {
        $fields['generation_ready'] = !empty($info['openai_generation'] ?? ($info['token_generation_ready'] ?? false));
    }

    king_inference_runtime_log_line('admitted', $fields, $stream);
}

function king_inference_runtime_selected_model(array $payload, array $models): ?object
{
    $requested = king_inference_runtime_request_model($payload);
    if ($requested !== '' && isset($models[$requested]) && is_object($models[$requested])) {
        return $models[$requested];
    }
    if ($requested !== '') {
        foreach ($models as $model) {
            if (!is_object($model)) {
                continue;
            }
            $info = king_inference_model_info($model);
            if (($info['name'] ?? null) === $requested) {
                return $model;
            }
        }
        return null;
    }
    $first = reset($models);
    return is_object($first) ? $first : null;
}

function king_inference_runtime_log_request_completed(
    array $request,
    array $models,
    int $startedNs,
    array $response,
    mixed $stream = null
): void {
    $payload = king_inference_runtime_request_payload($request);
    $model = king_inference_runtime_selected_model($payload, $models);
    $status = is_int($response['status'] ?? null) ? $response['status'] : 0;
    $durationMs = (hrtime(true) - $startedNs) / 1_000_000;
    $fields = [
        'method' => is_string($request['method'] ?? null) ? $request['method'] : '',
        'path' => is_string($request['path'] ?? null) ? $request['path'] : ($request['uri'] ?? ''),
        'requested_model' => king_inference_runtime_request_model($payload),
        'status' => $status,
        'duration_ms' => round($durationMs, 3),
    ];

    if ($model !== null) {
        $info = king_inference_model_info($model);
        $truth = is_array($info['runtime_truth'] ?? null) ? $info['runtime_truth'] : [];
        $timing = is_array($truth['measured_token_timing'] ?? null) ? $truth['measured_token_timing'] : [];
        $fields += [
            'model' => is_string($info['name'] ?? null) ? $info['name'] : '',
            'backend' => is_string($info['backend'] ?? null) ? $info['backend'] : '',
            'active_device' => is_string($truth['active_device'] ?? null) ? $truth['active_device'] : '',
            'model_resident' => !empty($truth['model_resident']),
            'fallback_mode' => is_string($truth['fallback_mode'] ?? null) ? $truth['fallback_mode'] : '',
            'gpu_admission_reason' => is_string($truth['gpu_admission_reason'] ?? null) ? $truth['gpu_admission_reason'] : '',
            'silent_cpu_fallback' => !empty($truth['silent_cpu_fallback']),
            'timing_available' => !empty($timing['available']),
            'generated_tokens' => is_int($timing['generated_tokens'] ?? null) ? $timing['generated_tokens'] : 0,
            'ttfb_ms' => is_int($timing['time_to_first_token_ms'] ?? null) ? $timing['time_to_first_token_ms'] : '',
            'tokens_per_second' => is_float($timing['tokens_per_second'] ?? null) || is_int($timing['tokens_per_second'] ?? null)
                ? $timing['tokens_per_second']
                : '',
        ];
        $fields += king_inference_runtime_decoder_truth_fields($info);
    }

    king_inference_runtime_log_line('completed', $fields, $stream);
}

function king_inference_runtime_request_payload(array $request): array
{
    $body = $request['body'] ?? null;
    if (!is_string($body) || $body === '') {
        return [];
    }
    try {
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return [];
    }
    return is_array($payload) ? $payload : [];
}

function king_inference_runtime_request_model(array $payload): string
{
    return is_string($payload['model'] ?? null) ? $payload['model'] : '';
}

function king_inference_runtime_request_has_assistant_tool_calls(array $payload): bool
{
    $messages = $payload['messages'] ?? null;
    if (!is_array($messages)) {
        return false;
    }
    foreach ($messages as $message) {
        if (!is_array($message)) {
            continue;
        }
        if (($message['role'] ?? null) === 'assistant'
            && array_key_exists('tool_calls', $message)
            && $message['tool_calls'] !== null
            && $message['tool_calls'] !== []
        ) {
            return true;
        }
    }
    return false;
}

function king_inference_runtime_message_content_chars(mixed $content): int
{
    if (is_string($content)) {
        return strlen($content);
    }
    if (!is_array($content)) {
        return 0;
    }

    $chars = 0;
    foreach ($content as $part) {
        if (is_string($part)) {
            $chars += strlen($part);
        } else if (is_array($part) && is_string($part['text'] ?? null)) {
            $chars += strlen($part['text']);
        }
    }
    return $chars;
}

function king_inference_runtime_payload_message_stats(array $payload): array
{
    $messages = $payload['messages'] ?? null;
    $count = is_array($messages) ? count($messages) : 0;
    $textChars = 0;
    $lastUserChars = 0;

    if (is_array($messages)) {
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $chars = king_inference_runtime_message_content_chars($message['content'] ?? null);
            $textChars += $chars;
            if (($message['role'] ?? null) === 'user') {
                $lastUserChars = $chars;
            }
        }
    }

    return [$count, $textChars, $lastUserChars];
}

function king_inference_runtime_log_request_executing(array $request, array $models, mixed $stream = null): void
{
    $payload = king_inference_runtime_request_payload($request);
    $tools = $payload['tools'] ?? null;
    $functions = $payload['functions'] ?? null;
    $body = $request['body'] ?? '';
    [$messageCount, $messageTextChars, $lastUserChars] = king_inference_runtime_payload_message_stats($payload);

    king_inference_runtime_log_line('executing', [
        'method' => is_string($request['method'] ?? null) ? $request['method'] : '',
        'path' => is_string($request['path'] ?? null) ? $request['path'] : ($request['uri'] ?? ''),
        'requested_model' => king_inference_runtime_request_model($payload),
        'registered_models' => count($models),
        'body_bytes' => is_string($body) ? strlen($body) : 0,
        'message_count' => $messageCount,
        'message_text_chars' => $messageTextChars,
        'last_user_chars' => $lastUserChars,
        'stream' => ($payload['stream'] ?? null) === true,
        'max_tokens' => is_int($payload['max_tokens'] ?? null) ? $payload['max_tokens'] : '',
        'max_completion_tokens' => is_int($payload['max_completion_tokens'] ?? null) ? $payload['max_completion_tokens'] : '',
        'tool_schema_count' => is_array($tools) ? count($tools) : 0,
        'legacy_function_count' => is_array($functions) ? count($functions) : 0,
        'tool_choice_present' => array_key_exists('tool_choice', $payload),
        'function_call_present' => array_key_exists('function_call', $payload),
        'parallel_tool_calls_present' => array_key_exists('parallel_tool_calls', $payload),
        'assistant_tool_calls_present' => king_inference_runtime_request_has_assistant_tool_calls($payload),
        'tool_execution' => 'not_dispatched',
    ], $stream);
}
