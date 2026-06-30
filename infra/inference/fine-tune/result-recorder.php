<?php
declare(strict_types=1);

function king_ir_env_bool(string $name, bool $fallback = false): bool
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $fallback;
    }
    return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
}

function king_ir_config_from_env(): array
{
    $root = dirname(__DIR__, 3);
    $enabled = king_ir_env_bool('KING_INFERENCE_RECORD_RESULTS')
        || king_ir_env_bool('KING_INFERENCE_RESULT_RECORD');
    $path = getenv('KING_INFERENCE_RESULT_RECORD_PATH') ?: $root . '/var/fine-tuning/inference-results/results.jsonl';
    $maxChars = max(80, (int) (getenv('KING_INFERENCE_RESULT_RECORD_MAX_FIELD_CHARS') ?: 4096));

    return [
        'enabled' => $enabled,
        'path' => $path,
        'max_field_chars' => $maxChars,
        'run_id' => getenv('KING_INFERENCE_RESULT_RECORD_RUN_ID') ?: gmdate('Ymd\THis\Z'),
    ];
}

function king_ir_redact_text(string $value, int $maxChars): string
{
    $redacted = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu', '[redacted-email]', $value) ?? $value;
    $redacted = preg_replace('/https?:\/\/[^\s<>"\']+/iu', '[redacted-url]', $redacted) ?? $redacted;
    $redacted = preg_replace('/-----BEGIN [^-]+ PRIVATE KEY-----.*?-----END [^-]+ PRIVATE KEY-----/is', '[redacted-private-key]', $redacted) ?? $redacted;
    $redacted = preg_replace('/\b(?:bearer|api[_ -]?key|token|secret|password)\s*[:=]\s*[^\s,"\']+/iu', '[redacted-secret]', $redacted) ?? $redacted;
    $redacted = preg_replace('/\b(?:sk-[A-Za-z0-9_-]{20,}|[A-Za-z0-9_-]{32,}\.[A-Za-z0-9_-]{16,}\.[A-Za-z0-9_-]{16,})\b/u', '[redacted-token]', $redacted) ?? $redacted;

    if (strlen($redacted) <= $maxChars) {
        return $redacted;
    }
    return substr($redacted, 0, $maxChars) . "\n[truncated sha256=" . hash('sha256', $redacted) . ']';
}

function king_ir_redact_value(mixed $value, int $maxChars): mixed
{
    if (is_string($value)) {
        return king_ir_redact_text($value, $maxChars);
    }
    if (!is_array($value)) {
        return $value;
    }

    $result = [];
    foreach ($value as $key => $item) {
        $normalizedKey = is_string($key) ? strtolower($key) : $key;
        if (is_string($normalizedKey) && preg_match('/password|secret|token|api[_-]?key|authorization/i', $normalizedKey) === 1) {
            $result[$key] = '[redacted-secret]';
            continue;
        }
        $result[$key] = king_ir_redact_value($item, $maxChars);
    }
    return $result;
}

function king_ir_write_jsonl(string $path, array $record): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        throw new RuntimeException('Cannot create inference result record directory: ' . $dir);
    }
    $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    if (file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Cannot append inference result record: ' . $path);
    }
}

function king_ir_record_case(array $config, array $case, array $result, string $model, array $artifact, array $modelSummary): ?array
{
    if (empty($config['enabled'])) {
        return null;
    }

    $maxChars = (int) $config['max_field_chars'];
    $record = [
        'kind' => 'king.inference.result_record.v1',
        'created_at' => gmdate(DATE_ATOM),
        'run_id' => (string) $config['run_id'],
        'case' => [
            'name' => $case['name'] ?? null,
            'category' => $case['category'] ?? $case['coverage'] ?? null,
            'scope' => $case['scope'] ?? null,
            'mode' => $case['mode'] ?? 'deterministic',
        ],
        'model' => [
            'id' => $model,
            'backend' => $modelSummary['backend'] ?? null,
            'artifact_basename' => isset($artifact['path']) ? basename((string) $artifact['path']) : null,
            'artifact_bytes' => $artifact['bytes'] ?? null,
            'artifact_sha256' => $artifact['sha256'] ?? null,
        ],
        'request' => [
            'messages' => king_ir_redact_value($case['messages'] ?? [], $maxChars),
            'max_tokens' => $case['max_tokens'] ?? null,
            'sampler' => king_ir_redact_value($case['sampler'] ?? [], $maxChars),
            'stop' => king_ir_redact_value($case['stop'] ?? null, $maxChars),
        ],
        'expected' => king_ir_redact_value($case['expected'] ?? null, $maxChars),
        'actual' => [
            'content' => king_ir_redact_value($result['content'] ?? '', $maxChars),
            'sha256' => hash('sha256', (string) ($result['content'] ?? '')),
        ],
        'evaluation' => [
            'ok' => (bool) ($result['ok'] ?? false),
            'reason' => $result['reason'] ?? null,
            'details' => king_ir_redact_value($result['evaluation'] ?? [], $maxChars),
        ],
        'transport' => [
            'http_status' => $result['http_status'] ?? null,
            'duration_ms' => $result['duration_ms'] ?? null,
        ],
    ];

    king_ir_write_jsonl((string) $config['path'], $record);
    return ['path' => (string) $config['path'], 'kind' => $record['kind']];
}
