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

function king_inference_runtime_log_model_admitted(string $registryName, object $model, mixed $stream = null): void
{
    $info = king_inference_model_info($model);
    $backend = is_string($info['backend'] ?? null) ? $info['backend'] : 'unknown';
    $fields = [
        'registry' => $registryName,
        'model' => is_string($info['name'] ?? null) ? $info['name'] : $registryName,
        'backend' => $backend,
        'admitted' => true,
        'artifact' => is_string($info['artifact_path'] ?? null) ? $info['artifact_path'] : '',
        'silent_cpu_fallback' => !empty($info['silent_cpu_fallback']),
    ];

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

function king_inference_runtime_request_model(array $request): string
{
    $body = $request['body'] ?? null;
    if (!is_string($body) || $body === '') {
        return '';
    }
    try {
        $payload = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return '';
    }
    return is_array($payload) && is_string($payload['model'] ?? null) ? $payload['model'] : '';
}

function king_inference_runtime_log_request_executing(array $request, array $models, mixed $stream = null): void
{
    king_inference_runtime_log_line('executing', [
        'method' => is_string($request['method'] ?? null) ? $request['method'] : '',
        'path' => is_string($request['path'] ?? null) ? $request['path'] : ($request['uri'] ?? ''),
        'requested_model' => king_inference_runtime_request_model($request),
        'registered_models' => count($models),
    ], $stream);
}
