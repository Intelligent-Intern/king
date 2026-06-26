<?php
declare(strict_types=1);

/**
 * King local text-generation runner for the `local` inference backend.
 *
 * This entrypoint intentionally talks only to the King extension. It does not
 * proxy an external model server.
 */

function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, "king-local-infer: {$message}\n");
    exit($code);
}

function parse_args(array $argv): array
{
    $options = [
        'model' => null,
        'prompt' => '',
        'tokens' => 128,
        'temperature' => 0.8,
        'top_k' => 40,
        'top_p' => 0.95,
        'seed' => 0,
        'context' => 0,
        'gpu_layers' => 0,
        'sampler' => null,
        'stops' => [],
    ];

    $float = static function (string $value, string $arg): float {
        if (!is_numeric($value)) {
            fail("{$arg} must be a finite number");
        }
        $number = (float) $value;
        if (!is_finite($number)) {
            fail("{$arg} must be a finite number");
        }
        return $number;
    };
    $int = static function (string $value, string $arg): int {
        if (!preg_match('/^-?[0-9]+$/', $value)) {
            fail("{$arg} must be an integer");
        }
        return (int) $value;
    };

    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        $next = static function () use ($argv, &$i, $arg): string {
            if (!array_key_exists($i + 1, $argv)) {
                fail("missing value for {$arg}");
            }
            return (string) $argv[++$i];
        };

        switch ($arg) {
            case '-m':
            case '--model':
                $options['model'] = $next();
                break;
            case '-p':
            case '--prompt':
                $options['prompt'] = $next();
                break;
            case '-n':
            case '--tokens':
                $options['tokens'] = max(1, (int) $next());
                break;
            case '--temp':
            case '--temperature':
                $options['temperature'] = $float($next(), $arg);
                if ($options['temperature'] < 0.0) {
                    fail("{$arg} must be non-negative");
                }
                break;
            case '--top-k':
                $options['top_k'] = $int($next(), $arg);
                if ($options['top_k'] < 0) {
                    fail("{$arg} must be non-negative");
                }
                break;
            case '--top-p':
                $options['top_p'] = $float($next(), $arg);
                if ($options['top_p'] <= 0.0 || $options['top_p'] > 1.0) {
                    fail("{$arg} must be greater than zero and at most one");
                }
                break;
            case '--sampler':
                $options['sampler'] = $next();
                break;
            case '--seed':
                $options['seed'] = (int) $next();
                break;
            case '-c':
            case '--ctx-size':
                $options['context'] = max(0, (int) $next());
                break;
            case '--reverse-prompt':
            case '--stop':
                $options['stops'][] = $next();
                break;
            case '-ngl':
            case '--gpu-layers':
                $options['gpu_layers'] = max(0, (int) $next());
                break;
            case '--no-display-prompt':
            case '--log-disable':
                break;
            default:
                fail("unsupported argument {$arg}");
        }
    }

    if (!is_string($options['model']) || $options['model'] === '') {
        fail('model path is required via -m');
    }
    if (!is_file($options['model'])) {
        fail("model path does not exist: {$options['model']}");
    }

    return $options;
}

function require_supported_execution(array $args): void
{
    if ((int) $args['gpu_layers'] > 0) {
        fail(
            'GPU offload was requested, but this King local PHP graph runner has no native GPU decoder kernel yet. Refusing CPU fallback.'
        );
    }
}

function model_load(string $path): King\Inference\Model
{
    return king_inference_model_load([
        'name' => basename($path),
        'artifact' => $path,
        'backend' => 'king_native_cpu',
        'with_memory' => false,
    ]);
}

function graph_options(array $gguf): array
{
    $vocab = max(1, (int) ($gguf['tokenizer_token_count'] ?? 262144));
    $hidden = max(1, (int) ($gguf['embedding_length'] ?? 1152));

    return [
        'max_vector_values' => max($vocab, 262144),
        'max_operations' => max($vocab * $hidden + 1024, 400000000),
        'return_outputs' => false,
    ];
}

function require_kv_state(array $result): array
{
    $state = $result['state'] ?? null;
    if (!is_array($state)) {
        fail('native graph produced no state for KV-cache continuity');
    }

    $kvCache = $state['kv_cache'] ?? null;
    if (!is_array($kvCache) || $kvCache === []) {
        fail('native graph produced no kv_cache entries for KV-cache continuity');
    }

    return $state;
}

function metadata_token_id(array $gguf, string $key): ?int
{
    if (!array_key_exists($key, $gguf)) {
        return null;
    }

    $id = (int) $gguf[$key];
    return $id >= 0 ? $id : null;
}

function prepare_prompt_tokens(array $tokens, ?int $bosTokenId, int $context): array
{
    $tokens = array_values(array_map('intval', $tokens));
    if ($bosTokenId !== null && ($tokens === [] || $tokens[0] !== $bosTokenId)) {
        array_unshift($tokens, $bosTokenId);
    }
    if ($tokens === []) {
        fail('prompt produced no tokens');
    }
    if ($context > 0 && count($tokens) > $context) {
        if ($bosTokenId !== null && $context > 1 && $tokens[0] === $bosTokenId) {
            $tokens = array_merge([$bosTokenId], array_slice($tokens, -($context - 1)));
        } else {
            $tokens = array_slice($tokens, -$context);
        }
    }

    return array_values($tokens);
}

function normalize_stop_sequences(array $stops): array
{
    if (count($stops) > 4) {
        fail('at most four stop sequences are supported');
    }

    $unique = [];
    foreach ($stops as $stop) {
        $stop = (string) $stop;
        if ($stop === '') {
            fail('stop sequence must not be empty');
        }
        $unique[$stop] = $stop;
    }

    return array_values($unique);
}

function run_step(
    King\Inference\Model $model,
    int|array $token,
    int $position,
    ?array $state,
    array $sample,
    bool $emitToken,
    array $options
): array {
    $decodeOptions = $sample;
    $decodeOptions['emit_token'] = $emitToken;
    if ($state !== null) {
        $decodeOptions['state'] = $state;
    }

    $graph = king_inference_token_decode_graph($model, $token, $position, $decodeOptions);
    $result = king_inference_graph_run($model, $graph, $options);
    $nextState = require_kv_state($result);

    if (!$emitToken) {
        return [null, $nextState];
    }

    $payload = $result['final']['next_token']['values'] ?? null;
    if (!is_array($payload) || !isset($payload[0])) {
        fail('native graph produced no next token');
    }

    return [(int) $payload[0], $nextState];
}

function should_stop_on_token(int $token, ?int $bosTokenId, ?int $eosTokenId): bool
{
    if ($eosTokenId !== null && $token === $eosTokenId) {
        return true;
    }
    if ($bosTokenId !== null && $token === $bosTokenId) {
        return true;
    }

    return false;
}

function find_stop_offset(string $text, array $stops): ?int
{
    $offset = null;
    foreach ($stops as $stop) {
        $position = strpos($text, $stop);
        if ($position !== false && ($offset === null || $position < $offset)) {
            $offset = $position;
        }
    }

    return $offset;
}

function pending_stop_prefix_length(string $text, array $stops): int
{
    $keep = 0;
    foreach ($stops as $stop) {
        $max = min(strlen($stop) - 1, strlen($text));
        for ($length = $max; $length > 0; $length--) {
            if (substr($text, -$length) === substr($stop, 0, $length)) {
                $keep = max($keep, $length);
                break;
            }
        }
    }

    return $keep;
}

function write_output(string $text): void
{
    if ($text === '') {
        return;
    }

    echo $text;
    flush();
}

function emit_generated_text(string &$pending, string $piece, array $stops): bool
{
    if ($stops === []) {
        write_output($piece);
        return false;
    }

    $pending .= $piece;
    $stopOffset = find_stop_offset($pending, $stops);
    if ($stopOffset !== null) {
        write_output(substr($pending, 0, $stopOffset));
        $pending = '';
        return true;
    }

    $keep = pending_stop_prefix_length($pending, $stops);
    $emitLength = strlen($pending) - $keep;
    if ($emitLength > 0) {
        write_output(substr($pending, 0, $emitLength));
        $pending = $keep > 0 ? substr($pending, -$keep) : '';
    }

    return false;
}

$args = parse_args($argv);
$stopSequences = normalize_stop_sequences($args['stops']);
require_supported_execution($args);
$model = model_load($args['model']);
$info = king_inference_model_info($model);
$gguf = $info['gguf'] ?? [];
if (!is_array($gguf)) {
    fail('model does not expose GGUF metadata');
}

$encoded = king_inference_tokenize($model, (string) $args['prompt']);
$tokens = $encoded['tokens'] ?? [];
if (!is_array($tokens)) {
    fail('tokenizer did not return token ids');
}
$bosTokenId = metadata_token_id($gguf, 'tokenizer_bos_id');
$eosTokenId = metadata_token_id($gguf, 'tokenizer_eos_id');
$tokens = prepare_prompt_tokens($tokens, $bosTokenId, (int) $args['context']);

$graphOptions = graph_options($gguf);
$state = null;
$nextToken = null;
$tokenizedPrompt = ['tokens' => $tokens];
$sample = [
    'temperature' => (float) $args['temperature'],
    'top_k' => (int) $args['top_k'],
    'top_p' => (float) $args['top_p'],
    'seed' => (int) $args['seed'],
];
if (is_string($args['sampler']) && $args['sampler'] !== '') {
    $sample['sampler'] = $args['sampler'];
}

foreach (array_keys($tokens) as $index) {
    [$nextToken, $state] = run_step(
        $model,
        $tokenizedPrompt,
        $index,
        $state,
        $sample,
        $index === count($tokens) - 1,
        $graphOptions
    );
}

$pendingText = '';
$stoppedByText = false;
$generated = 0;
$position = count($tokens);
while ($nextToken !== null && $generated < (int) $args['tokens']) {
    if (should_stop_on_token($nextToken, $bosTokenId, $eosTokenId)) {
        break;
    }

    $piece = king_inference_token_decode($model, $nextToken);
    $generated++;
    if (emit_generated_text($pendingText, $piece, $stopSequences)) {
        $stoppedByText = true;
        break;
    }
    if ($generated >= (int) $args['tokens']) {
        break;
    }

    [$nextToken, $state] = run_step(
        $model,
        $nextToken,
        $position,
        $state,
        $sample,
        true,
        $graphOptions
    );
    $position++;
}

if (!$stoppedByText) {
    write_output($pendingText);
}
