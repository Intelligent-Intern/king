<?php
declare(strict_types=1);

function king_ft_fail(string $message, int $code = 1): never
{
    fwrite(STDERR, "king-coder-fine-tune: {$message}\n");
    exit($code);
}

function king_ft_usage(): never
{
    fwrite(STDERR, "Usage: bin/king-coder-fine-tune prepare [--model=/path/model.gguf] [--out=var/fine-tuning/gemma3-1b-coder]\n");
    fwrite(STDERR, "       [--source=docs] [--max-tokens=2048] [--limit=N] [--trainable-base=/path/checkpoint-dir]\n");
    fwrite(STDERR, "       Defaults build a tokenizer-validated coder dataset from repository docs.\n");
    exit(64);
}

function king_ft_parse_cli(array $argv): array
{
    $root = dirname(__DIR__, 3);
    $options = [
        'command' => 'prepare',
        'model' => getenv('KING_FINE_TUNE_MODEL_PATH') ?: $root . '/var/inference-models/gemma3-1b.gguf',
        'out' => getenv('KING_FINE_TUNE_OUTPUT_DIR') ?: $root . '/var/fine-tuning/gemma3-1b-coder',
        'sources' => ['docs'],
        'max_tokens' => getenv('KING_FINE_TUNE_MAX_TOKENS') ?: '2048',
        'limit' => getenv('KING_FINE_TUNE_LIMIT') ?: '0',
        'trainable_base' => getenv('KING_FINE_TUNE_TRAINABLE_BASE') ?: '',
    ];

    if (count($argv) > 1 && !str_starts_with((string) $argv[1], '-')) {
        $options['command'] = (string) $argv[1];
        $start = 2;
    } else {
        $start = 1;
    }

    $options['sources'] = [];
    for ($i = $start; $i < count($argv); $i++) {
        $arg = (string) $argv[$i];
        $next = static function () use (&$i, $argv, $arg): string {
            if (!array_key_exists($i + 1, $argv)) {
                king_ft_fail("missing value for {$arg}", 64);
            }
            return (string) $argv[++$i];
        };

        if ($arg === '--help' || $arg === '-h') {
            king_ft_usage();
        } elseif (str_starts_with($arg, '--model=')) {
            $options['model'] = substr($arg, strlen('--model='));
        } elseif ($arg === '--model') {
            $options['model'] = $next();
        } elseif (str_starts_with($arg, '--out=')) {
            $options['out'] = substr($arg, strlen('--out='));
        } elseif ($arg === '--out') {
            $options['out'] = $next();
        } elseif (str_starts_with($arg, '--source=')) {
            $options['sources'][] = substr($arg, strlen('--source='));
        } elseif ($arg === '--source') {
            $options['sources'][] = $next();
        } elseif (str_starts_with($arg, '--max-tokens=')) {
            $options['max_tokens'] = substr($arg, strlen('--max-tokens='));
        } elseif ($arg === '--max-tokens') {
            $options['max_tokens'] = $next();
        } elseif (str_starts_with($arg, '--limit=')) {
            $options['limit'] = substr($arg, strlen('--limit='));
        } elseif ($arg === '--limit') {
            $options['limit'] = $next();
        } elseif (str_starts_with($arg, '--trainable-base=')) {
            $options['trainable_base'] = substr($arg, strlen('--trainable-base='));
        } elseif ($arg === '--trainable-base') {
            $options['trainable_base'] = $next();
        } else {
            king_ft_fail("unsupported argument {$arg}", 64);
        }
    }

    if ($options['command'] !== 'prepare') {
        king_ft_fail('only the prepare command is implemented; in-King training kernels are not implemented yet', 64);
    }
    if ($options['sources'] === []) {
        $options['sources'] = ['docs'];
    }
    if (!preg_match('/^[1-9][0-9]*$/', (string) $options['max_tokens'])) {
        king_ft_fail('--max-tokens must be a positive integer', 64);
    }
    $options['max_tokens'] = (int) $options['max_tokens'];
    if (!preg_match('/^[0-9]+$/', (string) $options['limit'])) {
        king_ft_fail('--limit must be a non-negative integer', 64);
    }
    $options['limit'] = (int) $options['limit'];

    return $options;
}

function king_ft_absolute_path(string $root, string $path): string
{
    if ($path === '') {
        king_ft_fail('path must not be empty', 64);
    }
    if (str_starts_with($path, '/')) {
        return $path;
    }
    return $root . '/' . $path;
}

function king_ft_json_encode(array $value): string
{
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function king_ft_docs_files(string $root, array $sources): array
{
    $files = [];
    foreach ($sources as $source) {
        $path = king_ft_absolute_path($root, $source);
        if (is_dir($path)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $item) {
                if (!$item instanceof SplFileInfo || !$item->isFile()) {
                    continue;
                }
                if (strtolower($item->getExtension()) !== 'md') {
                    continue;
                }
                $files[] = $item->getPathname();
            }
        } elseif (is_file($path) && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'md') {
            $files[] = $path;
        } else {
            king_ft_fail("source {$source} is not a markdown file or directory", 64);
        }
    }

    $files = array_values(array_unique($files));
    sort($files, SORT_STRING);
    return $files;
}

function king_ft_extract_code_blocks(string $root, string $file): array
{
    $relative = str_starts_with($file, $root . '/') ? substr($file, strlen($root) + 1) : $file;
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        king_ft_fail("cannot read {$relative}");
    }

    $allowed = [
        'bash' => true,
        'c' => true,
        'ini' => true,
        'json' => true,
        'php' => true,
        'sh' => true,
        'sql' => true,
        'xml' => true,
        'yaml' => true,
        'yml' => true,
    ];
    $blocks = [];
    $heading = basename($relative);
    $paragraphs = [];
    $inFence = false;
    $language = '';
    $code = [];
    $startLine = 0;

    foreach ($lines as $index => $line) {
        if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $matches) === 1 && !$inFence) {
            $heading = trim($matches[2]);
            $paragraphs = [];
            continue;
        }

        if (preg_match('/^```([A-Za-z0-9_+-]*)\s*$/', $line, $matches) === 1) {
            if (!$inFence) {
                $inFence = true;
                $language = strtolower((string) $matches[1]);
                $code = [];
                $startLine = $index + 1;
            } else {
                $inFence = false;
                $body = rtrim(implode("\n", $code));
                $normalizedLanguage = $language === '' ? 'text' : $language;
                if (isset($allowed[$normalizedLanguage]) && strlen($body) >= 80 && strlen($body) <= 8000) {
                    $context = trim(implode("\n\n", array_slice($paragraphs, -2)));
                    $blocks[] = [
                        'source_file' => $relative,
                        'start_line' => $startLine,
                        'heading' => $heading,
                        'language' => $normalizedLanguage,
                        'context' => $context,
                        'code' => $body,
                    ];
                }
                $language = '';
                $code = [];
            }
            continue;
        }

        if ($inFence) {
            $code[] = $line;
            continue;
        }

        $trimmed = trim($line);
        if ($trimmed !== '' && !str_starts_with($trimmed, '<') && !str_starts_with($trimmed, '- [')) {
            $paragraphs[] = $trimmed;
            if (count($paragraphs) > 8) {
                array_shift($paragraphs);
            }
        }
    }

    return $blocks;
}

function king_ft_training_example(array $block): array
{
    $language = (string) $block['language'];
    $context = (string) $block['context'];
    $user = "Generate a production-grade {$language} example for the King primitive \"{$block['heading']}\".";
    if ($context !== '') {
        $user .= "\n\nSource context:\n{$context}";
    }
    $user .= "\n\nReturn the implementation only, with no marketing text.";

    return [
        'messages' => [
            [
                'role' => 'system',
                'content' => 'You are King Coder. Produce precise, production-grade code for King PHP runtime work. Prefer direct PHP and king_* APIs. Do not invent missing APIs.',
            ],
            [
                'role' => 'user',
                'content' => $user,
            ],
            [
                'role' => 'assistant',
                'content' => "```{$language}\n{$block['code']}\n```",
            ],
        ],
        'metadata' => [
            'source_file' => $block['source_file'],
            'start_line' => $block['start_line'],
            'heading' => $block['heading'],
            'language' => $language,
            'source_sha256' => hash('sha256', (string) $block['code']),
        ],
    ];
}

function king_ft_example_text(array $example): string
{
    $parts = [];
    foreach ($example['messages'] as $message) {
        $parts[] = strtoupper((string) $message['role']) . ":\n" . (string) $message['content'];
    }
    return implode("\n\n", $parts);
}

function king_ft_token_count(object $model, array $example): int
{
    $encoded = king_inference_tokenize($model, king_ft_example_text($example));
    $tokens = $encoded['tokens'] ?? null;
    if (!is_array($tokens)) {
        king_ft_fail('king_inference_tokenize returned no token list');
    }
    return count($tokens);
}

function king_ft_write_jsonl(string $path, array $examples): void
{
    $handle = fopen($path, 'wb');
    if ($handle === false) {
        king_ft_fail("cannot write {$path}");
    }
    foreach ($examples as $example) {
        fwrite($handle, king_ft_json_encode($example) . "\n");
    }
    fclose($handle);
}

function king_ft_run(array $argv): void
{
    $root = dirname(__DIR__, 3);
    $options = king_ft_parse_cli($argv);
    $modelPath = king_ft_absolute_path($root, (string) $options['model']);
    $outDir = king_ft_absolute_path($root, (string) $options['out']);

    if (!is_readable($modelPath)) {
        king_ft_fail("model artifact is not readable: {$modelPath}");
    }

    if (!function_exists('king_inference_model_load')) {
        king_ft_fail('King extension is not loaded');
    }

    $model = king_inference_model_load([
        'name' => 'king-coder-fine-tune-tokenizer',
        'artifact' => ['path' => $modelPath],
        'backend' => 'king_native_cpu',
        'with_memory' => false,
    ]);

    $blocks = [];
    foreach (king_ft_docs_files($root, $options['sources']) as $file) {
        array_push($blocks, ...king_ft_extract_code_blocks($root, $file));
    }

    $examples = [];
    $skippedTooLarge = 0;
    foreach ($blocks as $block) {
        $example = king_ft_training_example($block);
        $tokenCount = king_ft_token_count($model, $example);
        if ($tokenCount > $options['max_tokens']) {
            $skippedTooLarge++;
            continue;
        }
        $example['metadata']['token_count'] = $tokenCount;
        $examples[] = $example;
        if ($options['limit'] > 0 && count($examples) >= $options['limit']) {
            break;
        }
    }

    if ($examples === []) {
        king_ft_fail('no tokenizer-valid examples were produced');
    }

    $train = [];
    $validation = [];
    foreach ($examples as $example) {
        $hash = hexdec(substr((string) $example['metadata']['source_sha256'], 0, 8));
        if ($hash % 10 === 0) {
            $validation[] = $example;
        } else {
            $train[] = $example;
        }
    }
    if ($validation === [] && count($train) > 1) {
        $validation[] = array_pop($train);
    }

    if (!is_dir($outDir) && !mkdir($outDir, 0775, true)) {
        king_ft_fail("cannot create output directory {$outDir}");
    }

    king_ft_write_jsonl($outDir . '/train.jsonl', $train);
    king_ft_write_jsonl($outDir . '/validation.jsonl', $validation);

    $manifest = [
        'kind' => 'king.coder_fine_tune.dataset.v1',
        'created_at' => gmdate(DATE_ATOM),
        'model_tokenizer_artifact' => $modelPath,
        'trainable_base_checkpoint' => (string) $options['trainable_base'],
        'output_dir' => $outDir,
        'sources' => array_values($options['sources']),
        'max_tokens' => $options['max_tokens'],
        'examples_total' => count($examples),
        'train_examples' => count($train),
        'validation_examples' => count($validation),
        'skipped_too_large' => $skippedTooLarge,
        'status' => (string) $options['trainable_base'] === ''
            ? 'dataset_prepared_trainable_base_missing'
            : 'dataset_prepared_ready_for_lora_training',
        'notes' => [
            'GGUF is used here only as the King tokenizer and runtime reference artifact.',
            'Actual adapter training needs a trainable base checkpoint; this script does not fake that step.',
            'The produced dataset is source-grounded from repository documentation examples.',
        ],
    ];
    file_put_contents($outDir . '/manifest.json', king_ft_json_encode($manifest) . "\n");

    $runBook = "# King Coder Fine-Tune Run\n\n"
        . "Dataset status: `{$manifest['status']}`\n\n"
        . "Files:\n\n"
        . "- `train.jsonl`: supervised chat examples for adapter training\n"
        . "- `validation.jsonl`: held-out examples for adapter evaluation\n"
        . "- `manifest.json`: reproducibility metadata\n\n"
        . "Important: the configured GGUF file is not the trainable checkpoint. It is used for King tokenizer validation and later runtime export compatibility.\n";
    file_put_contents($outDir . '/run.md', $runBook);

    fwrite(STDOUT, king_ft_json_encode([
        'status' => 'ok',
        'out' => $outDir,
        'train_examples' => count($train),
        'validation_examples' => count($validation),
        'skipped_too_large' => $skippedTooLarge,
    ]) . "\n");
}

king_ft_run($argv);
