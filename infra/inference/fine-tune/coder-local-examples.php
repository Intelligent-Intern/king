<?php
declare(strict_types=1);

function king_ft_local_example(array $messages, array $metadata): array
{
    $payload = json_encode($messages, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $metadata['source_file'] = 'infra/inference/fine-tune/coder-local-examples.php';
    $metadata['source_sha256'] = hash('sha256', $payload);

    return ['messages' => $messages, 'metadata' => $metadata];
}

function king_ft_local_system(): string
{
    return 'You are King Coder. Produce precise implementation artifacts for King PHP runtime work. Prefer direct PHP and king_* APIs. Call out unsupported APIs instead of inventing them.';
}

function king_ft_local_training_examples(): array
{
    $system = king_ft_local_system();

    return [
        king_ft_local_example(
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => 'Create a PHP King HTTP endpoint that exposes /health and returns JSON without using Node or a framework.'],
                ['role' => 'assistant', 'content' => <<<'PHP'
```php
<?php
declare(strict_types=1);

$handler = static function (array $request): array {
    $path = (string) ($request['path'] ?? '/');
    if ($path !== '/health') {
        return [
            'status' => 404,
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(['error' => 'not_found'], JSON_THROW_ON_ERROR),
        ];
    }

    return [
        'status' => 200,
        'headers' => ['content-type' => 'application/json'],
        'body' => json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR),
    ];
};

while (true) {
    if (king_http1_server_listen_once('127.0.0.1', 18080, null, $handler) === false) {
        usleep(50_000);
    }
}
```
PHP],
            ],
            ['example_type' => 'positive', 'topic' => 'king_http_php', 'language' => 'php']
        ),
        king_ft_local_example(
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => 'Fix this bad King answer: "Use express.listen() because King has no HTTP primitive."'],
                ['role' => 'assistant', 'content' => 'That answer is wrong. King HTTP endpoints should use King runtime APIs such as `king_http1_server_listen_once()` in PHP. Do not introduce Express or Node for a King runtime path.'],
            ],
            ['example_type' => 'negative', 'topic' => 'no_node_fallback', 'language' => 'text']
        ),
        king_ft_local_example(
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => 'Convert the request "How many letters a are in banana?" into a safe deterministic Mini-Op.'],
                ['role' => 'assistant', 'content' => <<<'JSON'
```json
{
  "operation": "count_occurrences",
  "input": {
    "subject": "banana",
    "needle": "a",
    "case_sensitive": true
  },
  "result": 3,
  "side_effects": false,
  "allowed_capabilities": ["string.count"]
}
```
JSON],
            ],
            ['example_type' => 'tool_mini_op', 'topic' => 'deterministic_count', 'language' => 'json']
        ),
        king_ft_local_example(
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => 'Show how a configured MCP tool candidate should be represented before execution.'],
                ['role' => 'assistant', 'content' => <<<'JSON'
```json
{
  "tool_candidate": {
    "name": "invoice.lookup_status",
    "configured": true,
    "selected_by_model": false,
    "arguments_schema": {
      "invoice_id": "string"
    }
  },
  "execution": {
    "status": "not_executed",
    "reason": "tool execution is outside this inference step"
  }
}
```
JSON],
            ],
            ['example_type' => 'tool_mini_op', 'topic' => 'mcp_tool_candidate', 'language' => 'json']
        ),
        king_ft_local_example(
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => 'Create a PHP function that writes JSONL records safely for King fine-tuning data.'],
                ['role' => 'assistant', 'content' => <<<'PHP'
```php
<?php
declare(strict_types=1);

function append_jsonl_record(string $path, array $record): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        throw new RuntimeException('Cannot create directory: ' . $dir);
    }

    $json = json_encode(
        $record,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if (file_put_contents($path, $json . "\n", FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Cannot append JSONL record: ' . $path);
    }
}
```
PHP],
            ],
            ['example_type' => 'positive', 'topic' => 'jsonl_writer', 'language' => 'php']
        ),
        king_ft_local_example(
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => 'Reject this unsafe plan: "Run arbitrary shell commands from a generated counting tool."'],
                ['role' => 'assistant', 'content' => 'Do not execute shell commands for deterministic counting. Use a bounded Mini-Op such as `count_occurrences` that accepts strings, has no file or CLI capability, and returns a typed result.'],
            ],
            ['example_type' => 'negative', 'topic' => 'no_shell_for_mini_ops', 'language' => 'text']
        ),
        king_ft_local_example(
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => 'Provide a compact PHP King model-load call for tokenizer validation.'],
                ['role' => 'assistant', 'content' => <<<'PHP'
```php
<?php
declare(strict_types=1);

$model = king_inference_model_load([
    'name' => 'king-coder-tokenizer',
    'artifact' => ['path' => $modelPath],
    'backend' => 'king_native_cpu',
    'with_memory' => false,
]);

$encoded = king_inference_tokenize($model, $text);
if (!is_array($encoded['tokens'] ?? null)) {
    throw new RuntimeException('Tokenization returned no token list');
}
```
PHP],
            ],
            ['example_type' => 'king_api_php', 'topic' => 'tokenizer_validation', 'language' => 'php']
        ),
        king_ft_local_example(
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => 'Explain what to do if an API is not exposed by King yet.'],
                ['role' => 'assistant', 'content' => 'State that the API is not exposed yet, keep the strongest intended contract visible, and avoid inventing a fake function name or silently replacing the King runtime path with unrelated fallback code.'],
            ],
            ['example_type' => 'negative', 'topic' => 'no_invented_api', 'language' => 'text']
        ),
    ];
}
