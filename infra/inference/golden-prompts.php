<?php
declare(strict_types=1);

function king_golden_usage(): void
{
    fwrite(STDERR, "Usage: php infra/inference/golden-prompts.php [--url=http://127.0.0.1:8080/v1] [--model=name] [--artifact=/path/model.gguf] [--case=name] [--strict] [--json]\n");
}

function king_golden_env_bool(string $name, bool $fallback = false): bool
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $fallback;
    }
    return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
}

function king_golden_parse_args(array $argv): array
{
    $options = [
        'url' => getenv('KING_INFERENCE_GOLDEN_BASE_URL') ?: 'http://127.0.0.1:8080/v1',
        'model' => getenv('KING_INFERENCE_GOLDEN_MODEL') ?: '',
        'artifact' => getenv('KING_INFERENCE_GOLDEN_MODEL_PATH') ?: '',
        'case' => getenv('KING_INFERENCE_GOLDEN_CASE') ?: '',
        'strict' => king_golden_env_bool('KING_INFERENCE_GOLDEN_STRICT', false),
        'json' => king_golden_env_bool('KING_INFERENCE_GOLDEN_JSON', false),
        'timeout' => max(1, (int) (getenv('KING_INFERENCE_GOLDEN_TIMEOUT_SEC') ?: 45)),
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--strict' || $arg === '--fail-on-mismatch') {
            $options['strict'] = true;
            continue;
        }
        if ($arg === '--json') {
            $options['json'] = true;
            continue;
        }
        if ($arg === '-h' || $arg === '--help') {
            king_golden_usage();
            exit(0);
        }
        foreach (['url', 'model', 'artifact', 'case', 'timeout'] as $key) {
            $prefix = '--' . $key . '=';
            if (str_starts_with($arg, $prefix)) {
                $options[$key] = substr($arg, strlen($prefix));
                continue 2;
            }
        }
        fwrite(STDERR, "golden-prompts: unknown argument: {$arg}\n");
        king_golden_usage();
        exit(2);
    }

    $options['url'] = rtrim((string) $options['url'], '/');
    $options['timeout'] = max(1, (int) $options['timeout']);
    return $options;
}

function king_golden_cases(): array
{
    $system = 'You are a deterministic King inference contract runner. Follow the requested output format exactly.';

    return [
        [
            'name' => 'exact_output',
            'coverage' => 'exact output',
            'sampler' => ['temperature' => 0.0, 'top_p' => 1.0, 'seed' => 1001],
            'max_tokens' => 8,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => 'Return exactly this two-letter answer and nothing else: OK'],
            ],
            'expected' => [
                'type' => 'exact',
                'tokens' => ['OK'],
            ],
        ],
        [
            'name' => 'counting',
            'coverage' => 'counting',
            'sampler' => ['temperature' => 0.0, 'top_p' => 1.0, 'seed' => 1002],
            'max_tokens' => 8,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => 'How many letters r are in the word strawberry? Answer with only the number.'],
            ],
            'expected' => [
                'type' => 'exact',
                'tokens' => ['3'],
            ],
        ],
        [
            'name' => 'german_instruction',
            'coverage' => 'German instruction following',
            'sampler' => ['temperature' => 0.0, 'top_p' => 1.0, 'seed' => 1003],
            'max_tokens' => 32,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => 'Antworte auf Deutsch in einem kurzen Satz: Was ist ein Token?'],
            ],
            'expected' => [
                'type' => 'regex',
                'pattern' => '/\\bToken\\b/u',
                'description' => 'contains the German term Token',
            ],
        ],
        [
            'name' => 'json_only',
            'coverage' => 'JSON-only response',
            'sampler' => ['temperature' => 0.0, 'top_p' => 1.0, 'seed' => 1004],
            'max_tokens' => 32,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => 'Return only a JSON object with exactly one key named status and the value ok.'],
            ],
            'expected' => [
                'type' => 'json_object',
                'object' => ['status' => 'ok'],
            ],
        ],
        [
            'name' => 'stop_boundary',
            'coverage' => 'stop token behavior',
            'sampler' => ['temperature' => 0.0, 'top_p' => 1.0, 'seed' => 1005],
            'max_tokens' => 32,
            'stop' => ['STOP_HERE'],
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => 'Write alpha STOP_HERE beta.'],
            ],
            'expected' => [
                'type' => 'not_contains',
                'text' => 'STOP_HERE',
            ],
        ],
        [
            'name' => 'php_generation',
            'coverage' => 'simple PHP generation',
            'sampler' => ['temperature' => 0.0, 'top_p' => 1.0, 'seed' => 1006],
            'max_tokens' => 96,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => 'Return only a PHP code fence containing a function named king_example that returns the string ok.'],
            ],
            'expected' => [
                'type' => 'contains_all',
                'texts' => ['```php', 'function king_example', 'return', 'ok'],
            ],
        ],
    ];
}

function king_golden_http_json(string $method, string $url, ?array $payload, int $timeout): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('golden-prompts requires the PHP curl extension.');
    }

    $ch = curl_init($url);
    $headers = ['accept: application/json'];
    $body = null;
    if ($payload !== null) {
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers[] = 'content-type: application/json';
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $timeout,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $started = hrtime(true);
    $raw = curl_exec($ch);
    $durationMs = (hrtime(true) - $started) / 1_000_000.0;
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($raw === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException($error);
    }
    curl_close($ch);

    $decoded = json_decode((string) $raw, true);
    return [
        'status' => $code,
        'duration_ms' => round($durationMs, 3),
        'raw' => (string) $raw,
        'json' => is_array($decoded) ? $decoded : null,
    ];
}

function king_golden_validate_artifact(string $path): array
{
    if ($path === '') {
        throw new RuntimeException('KING_INFERENCE_GOLDEN_MODEL_PATH or --artifact must point to the fixed local GGUF artifact for this contract run.');
    }
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException("Golden model artifact is not readable: {$path}");
    }

    $real = realpath($path);
    return [
        'path' => $path,
        'realpath' => $real !== false ? $real : $path,
        'bytes' => filesize($path),
        'mtime' => filemtime($path),
        'sha256' => getenv('KING_INFERENCE_GOLDEN_MODEL_SHA256') ?: null,
    ];
}

function king_golden_model_id(array $options, array $models): string
{
    if ((string) $options['model'] !== '') {
        return (string) $options['model'];
    }
    $first = $models['data'][0]['id'] ?? null;
    if (!is_string($first) || $first === '') {
        throw new RuntimeException('/v1/models did not expose a selectable model id.');
    }
    return $first;
}

function king_golden_model_summary(array $entry): array
{
    $king = is_array($entry['x_king'] ?? null) ? $entry['x_king'] : [];
    $gpuRuntime = is_array($king['gpu_runtime'] ?? null) ? $king['gpu_runtime'] : [];
    $clientCapabilities = is_array($king['client_capabilities'] ?? null) ? $king['client_capabilities'] : [];
    $capabilities = is_array($king['capabilities'] ?? null) ? $king['capabilities'] : [];
    $route = is_array($king['openai_route'] ?? null) ? $king['openai_route'] : [];

    return [
        'id' => $entry['id'] ?? null,
        'owned_by' => $entry['owned_by'] ?? null,
        'backend' => $king['backend'] ?? null,
        'openai_generation' => $king['openai_generation'] ?? null,
        'gpu_enabled' => $king['gpu_enabled'] ?? null,
        'gpu_generation_ready' => $gpuRuntime['generation_ready'] ?? null,
        'gpu_admission_reason' => $gpuRuntime['reason'] ?? null,
        'client_generation_ready' => $clientCapabilities['generation_ready'] ?? null,
        'prompt_to_logits_generation' => $clientCapabilities['prompt_to_logits_generation'] ?? null,
        'synthetic_token_vector_graph' => $clientCapabilities['synthetic_token_vector_graph'] ?? null,
        'capability_prompt_to_logits_generation' => $capabilities['prompt_to_logits_generation'] ?? null,
        'capability_synthetic_token_vector_graph' => $capabilities['synthetic_token_vector_graph'] ?? null,
        'active_hot_path' => $route['active_hot_path'] ?? null,
        'batch_prefill_admitted' => $route['batch_prefill_admitted'] ?? null,
    ];
}

function king_golden_expected_text(array $expected): ?string
{
    if (($expected['type'] ?? '') !== 'exact') {
        return null;
    }
    $tokens = $expected['tokens'] ?? null;
    if (!is_array($tokens)) {
        return null;
    }
    return implode('', array_map(static fn($value): string => (string) $value, $tokens));
}

function king_golden_evaluate(string $content, array $expected): array
{
    $type = (string) ($expected['type'] ?? '');
    $trimmed = trim($content);

    if ($type === 'exact') {
        $expectedText = king_golden_expected_text($expected);
        $ok = $trimmed === $expectedText;
        return [
            'ok' => $ok,
            'expected' => $expectedText,
            'actual' => $trimmed,
            'reason' => $ok ? 'exact_match' : 'exact_mismatch',
        ];
    }

    if ($type === 'regex') {
        $pattern = (string) ($expected['pattern'] ?? '//');
        $ok = preg_match($pattern, $trimmed) === 1;
        return [
            'ok' => $ok,
            'expected' => $expected['description'] ?? $pattern,
            'actual' => $trimmed,
            'reason' => $ok ? 'regex_match' : 'regex_mismatch',
        ];
    }

    if ($type === 'json_object') {
        try {
            $decoded = json_decode($trimmed, true, 64, JSON_THROW_ON_ERROR);
            $expectedObject = $expected['object'] ?? [];
            $ok = is_array($decoded) && $decoded === $expectedObject;
            return [
                'ok' => $ok,
                'expected' => $expectedObject,
                'actual' => $decoded,
                'reason' => $ok ? 'json_object_match' : 'json_object_mismatch',
            ];
        } catch (JsonException $exception) {
            return [
                'ok' => false,
                'expected' => $expected['object'] ?? [],
                'actual' => $trimmed,
                'reason' => 'json_parse_failed: ' . $exception->getMessage(),
            ];
        }
    }

    if ($type === 'not_contains') {
        $needle = (string) ($expected['text'] ?? '');
        $ok = $needle !== '' && !str_contains($content, $needle);
        return [
            'ok' => $ok,
            'expected' => 'content does not contain ' . $needle,
            'actual' => $trimmed,
            'reason' => $ok ? 'needle_absent' : 'needle_present',
        ];
    }

    if ($type === 'contains_all') {
        $missing = [];
        foreach (($expected['texts'] ?? []) as $needle) {
            if (!is_string($needle) || $needle === '' || !str_contains($content, $needle)) {
                $missing[] = $needle;
            }
        }
        return [
            'ok' => $missing === [],
            'expected' => $expected['texts'] ?? [],
            'actual' => $trimmed,
            'reason' => $missing === [] ? 'all_needles_present' : 'missing: ' . implode(', ', $missing),
        ];
    }

    return [
        'ok' => false,
        'expected' => $expected,
        'actual' => $trimmed,
        'reason' => 'unknown_expectation_type',
    ];
}

function king_golden_request_payload(string $model, array $case): array
{
    $sampler = is_array($case['sampler'] ?? null) ? $case['sampler'] : [];
    $payload = [
        'model' => $model,
        'stream' => false,
        'messages' => $case['messages'],
        'max_tokens' => (int) $case['max_tokens'],
        'temperature' => (float) ($sampler['temperature'] ?? 0.0),
        'top_p' => (float) ($sampler['top_p'] ?? 1.0),
        'seed' => (int) ($sampler['seed'] ?? 0),
    ];
    if (isset($case['stop'])) {
        $payload['stop'] = $case['stop'];
    }
    return $payload;
}

function king_golden_run_case(string $url, string $model, array $case, int $timeout): array
{
    $payload = king_golden_request_payload($model, $case);
    $response = king_golden_http_json('POST', $url . '/chat/completions', $payload, $timeout);
    $content = '';
    if (is_array($response['json'])) {
        $content = (string) ($response['json']['choices'][0]['message']['content'] ?? '');
    }
    $evaluation = $response['status'] >= 200 && $response['status'] < 300
        ? king_golden_evaluate($content, $case['expected'])
        : [
            'ok' => false,
            'expected' => $case['expected'],
            'actual' => $response['raw'],
            'reason' => 'http_' . $response['status'],
        ];

    return [
        'name' => $case['name'],
        'coverage' => $case['coverage'],
        'model' => $model,
        'sampler' => $case['sampler'],
        'max_tokens' => $case['max_tokens'],
        'stop' => $case['stop'] ?? null,
        'expected' => $case['expected'],
        'http_status' => $response['status'],
        'duration_ms' => $response['duration_ms'],
        'content' => trim($content),
        'ok' => $evaluation['ok'],
        'reason' => $evaluation['reason'],
        'evaluation' => $evaluation,
    ];
}

$options = king_golden_parse_args($argv);

try {
    $artifact = king_golden_validate_artifact((string) $options['artifact']);
    $modelsResponse = king_golden_http_json('GET', (string) $options['url'] . '/models', null, (int) $options['timeout']);
    if ($modelsResponse['status'] < 200 || $modelsResponse['status'] >= 300 || !is_array($modelsResponse['json'])) {
        throw new RuntimeException('/v1/models is not reachable or returned invalid JSON.');
    }
    $model = king_golden_model_id($options, $modelsResponse['json']);
    $selected = [];
    foreach (($modelsResponse['json']['data'] ?? []) as $entry) {
        if (is_array($entry) && ($entry['id'] ?? null) === $model) {
            $selected = $entry;
            break;
        }
    }

    $cases = king_golden_cases();
    if ((string) $options['case'] !== '') {
        $cases = array_values(array_filter(
            $cases,
            static fn(array $case): bool => $case['name'] === (string) $options['case']
        ));
        if ($cases === []) {
            throw new RuntimeException('Unknown golden case: ' . $options['case']);
        }
    }

    $results = [];
    foreach ($cases as $case) {
        $results[] = king_golden_run_case((string) $options['url'], $model, $case, (int) $options['timeout']);
    }

    $failed = array_values(array_filter($results, static fn(array $result): bool => !$result['ok']));
    $report = [
        'ok' => $failed === [],
        'strict' => (bool) $options['strict'],
        'url' => $options['url'],
        'model' => $model,
        'artifact' => $artifact,
        'model_entry' => king_golden_model_summary($selected),
        'case_count' => count($results),
        'passed' => count($results) - count($failed),
        'failed' => count($failed),
        'results' => $results,
    ];

    if ($options['json']) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "King golden prompt contract\n";
        echo "url={$report['url']}\n";
        echo "model={$report['model']}\n";
        echo "artifact={$artifact['path']}\n";
        echo "mode=" . ($report['strict'] ? 'strict' : 'report') . "\n";
        foreach ($results as $result) {
            echo sprintf(
                "[%s] %s (%s, %.1fms): %s\n",
                $result['ok'] ? 'PASS' : 'FAIL',
                $result['name'],
                $result['coverage'],
                (float) $result['duration_ms'],
                $result['reason']
            );
            if (!$result['ok']) {
                echo "  actual: " . str_replace("\n", "\\n", $result['content']) . "\n";
            }
        }
        echo "summary={$report['passed']}/{$report['case_count']} passed\n";
    }

    exit($failed !== [] && $options['strict'] ? 1 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'golden-prompts: ' . $exception->getMessage() . "\n");
    exit(2);
}
