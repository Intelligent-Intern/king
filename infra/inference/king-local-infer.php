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
        'stops' => [],
    ];

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
                $options['temperature'] = max(0.0, (float) $next());
                break;
            case '--top-k':
                $options['top_k'] = max(0, (int) $next());
                break;
            case '--top-p':
                $options['top_p'] = min(1.0, max(0.001, (float) $next()));
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
    $nextState = isset($result['state']) && is_array($result['state']) ? $result['state'] : null;

    if (!$emitToken) {
        return [null, $nextState];
    }

    $payload = $result['final']['next_token']['values'] ?? null;
    if (!is_array($payload) || !isset($payload[0])) {
        fail('native graph produced no next token');
    }

    return [(int) $payload[0], $nextState];
}

function should_stop(string $buffer, array $stops): bool
{
    foreach ($stops as $stop) {
        if ($stop !== '' && str_contains($buffer, $stop)) {
            return true;
        }
    }
    return false;
}

$args = parse_args($argv);
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
$tokens = array_values(array_map('intval', $tokens));
$bos = (int) ($gguf['tokenizer_bos_id'] ?? -1);
$eos = (int) ($gguf['tokenizer_eos_id'] ?? -1);
if ($bos >= 0 && ($tokens === [] || $tokens[0] !== $bos)) {
    array_unshift($tokens, $bos);
}
if ($tokens === []) {
    fail('prompt produced no tokens');
}
if ((int) $args['context'] > 0 && count($tokens) > (int) $args['context']) {
    $tokens = array_slice($tokens, -((int) $args['context']));
}

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

$buffer = '';
$generated = 0;
$position = count($tokens);
while ($nextToken !== null && $generated < (int) $args['tokens']) {
    if ($nextToken === $eos) {
        break;
    }

    $piece = king_inference_token_decode($model, $nextToken);
    echo $piece;
    flush();
    $buffer .= $piece;
    $generated++;

    if (should_stop($buffer, $args['stops']) || $generated >= (int) $args['tokens']) {
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
