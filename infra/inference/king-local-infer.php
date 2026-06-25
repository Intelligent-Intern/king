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
                $next();
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

function inv_freqs(int $headDim, float $base): array
{
    $freqs = [];
    for ($i = 0; $i < intdiv($headDim, 2); $i++) {
        $freqs[] = 1.0 / ($base ** (($i * 2.0) / $headDim));
    }
    return $freqs;
}

function op(array &$ops, string $id, string $name, array $fields): string
{
    $ops[] = ['id' => $id, 'op' => $name] + $fields;
    return $id;
}

function build_step_graph(
    int $tokenId,
    int $position,
    ?array $state,
    array $gguf,
    array $sample,
    bool $emitToken
): array {
    $layers = (int) ($gguf['block_count'] ?? 0);
    $heads = (int) ($gguf['attention_head_count'] ?? 0);
    $headDim = (int) ($gguf['attention_key_length'] ?? 0);
    $slidingWindow = (int) ($gguf['attention_sliding_window'] ?? 0);
    $vocab = (int) ($gguf['tokenizer_token_count'] ?? 0);

    if (($gguf['architecture'] ?? '') !== 'gemma3') {
        fail('only gemma3 GGUF artifacts are supported by this native runner right now');
    }
    if ($layers <= 0 || $heads <= 0 || $headDim <= 0 || $vocab <= 0) {
        fail('model is missing required Gemma3 architecture metadata');
    }

    $ops = [];
    $hidden = op($ops, 'x', 'embedding', [
        'tensor' => 'token_embd.weight',
        'token_id' => $tokenId,
    ]);
    $rope = inv_freqs($headDim, 10000.0);
    $slotStart = $slidingWindow > 0 ? max(0, $position - $slidingWindow + 1) : 0;
    $slotCount = $position - $slotStart + 1;
    $attentionScale = 1.0 / sqrt((float) $headDim);

    for ($layer = 0; $layer < $layers; $layer++) {
        $prefix = "blk.{$layer}";
        $attnNorm = op($ops, "l{$layer}_attn_norm", 'rms_norm', [
            'input' => $hidden,
            'weight' => "{$prefix}.attn_norm.weight",
            'epsilon' => 1e-6,
        ]);
        $query = op($ops, "l{$layer}_q", 'linear', [
            'input' => $attnNorm,
            'weight' => "{$prefix}.attn_q.weight",
        ]);
        $key = op($ops, "l{$layer}_k", 'linear', [
            'input' => $attnNorm,
            'weight' => "{$prefix}.attn_k.weight",
        ]);
        $value = op($ops, "l{$layer}_v", 'linear', [
            'input' => $attnNorm,
            'weight' => "{$prefix}.attn_v.weight",
        ]);
        $keyNorm = op($ops, "l{$layer}_k_norm", 'rms_norm', [
            'input' => $key,
            'weight' => "{$prefix}.attn_k_norm.weight",
            'epsilon' => 1e-6,
        ]);
        $keyRope = op($ops, "l{$layer}_k_rope", 'rope', [
            'input' => $keyNorm,
            'position' => $position,
            'head_dim' => $headDim,
            'inv_freqs' => $rope,
        ]);

        $contexts = [];
        for ($head = 0; $head < $heads; $head++) {
            $qSlice = op($ops, "l{$layer}_h{$head}_q_slice", 'slice', [
                'input' => $query,
                'offset' => $head * $headDim,
                'count' => $headDim,
            ]);
            $qNorm = op($ops, "l{$layer}_h{$head}_q_norm", 'rms_norm', [
                'input' => $qSlice,
                'weight' => "{$prefix}.attn_q_norm.weight",
                'epsilon' => 1e-6,
            ]);
            $qRope = op($ops, "l{$layer}_h{$head}_q_rope", 'rope', [
                'input' => $qNorm,
                'position' => $position,
                'head_dim' => $headDim,
                'inv_freqs' => $rope,
            ]);
            op($ops, "l{$layer}_h{$head}_kv_write", 'kv_write', [
                'cache' => "l{$layer}.h{$head}",
                'slot' => $position,
                'key' => $keyRope,
                'value' => $value,
            ]);
            $contexts[] = op($ops, "l{$layer}_h{$head}_ctx", 'kv_attention', [
                'cache' => "l{$layer}.h{$head}",
                'query' => $qRope,
                'slot_start' => $slotStart,
                'slot_count' => $slotCount,
                'scale' => $attentionScale,
            ]);
        }

        $context = op($ops, "l{$layer}_context", 'stack', ['inputs' => $contexts]);
        $attnOut = op($ops, "l{$layer}_attn_out", 'linear', [
            'input' => $context,
            'weight' => "{$prefix}.attn_output.weight",
        ]);
        $attnPost = op($ops, "l{$layer}_attn_post", 'rms_norm', [
            'input' => $attnOut,
            'weight' => "{$prefix}.post_attention_norm.weight",
            'epsilon' => 1e-6,
        ]);
        $attnResidual = op($ops, "l{$layer}_attn_residual", 'add', [
            'left' => $hidden,
            'right' => $attnPost,
        ]);
        $ffnNorm = op($ops, "l{$layer}_ffn_norm", 'rms_norm', [
            'input' => $attnResidual,
            'weight' => "{$prefix}.ffn_norm.weight",
            'epsilon' => 1e-6,
        ]);
        $gate = op($ops, "l{$layer}_ffn_gate", 'linear', [
            'input' => $ffnNorm,
            'weight' => "{$prefix}.ffn_gate.weight",
        ]);
        $up = op($ops, "l{$layer}_ffn_up", 'linear', [
            'input' => $ffnNorm,
            'weight' => "{$prefix}.ffn_up.weight",
        ]);
        $gateAct = op($ops, "l{$layer}_ffn_gate_silu", 'silu', ['input' => $gate]);
        $gated = op($ops, "l{$layer}_ffn_gated", 'mul', [
            'left' => $gateAct,
            'right' => $up,
        ]);
        $down = op($ops, "l{$layer}_ffn_down", 'linear', [
            'input' => $gated,
            'weight' => "{$prefix}.ffn_down.weight",
        ]);
        $ffnPost = op($ops, "l{$layer}_ffn_post", 'rms_norm', [
            'input' => $down,
            'weight' => "{$prefix}.post_ffw_norm.weight",
            'epsilon' => 1e-6,
        ]);
        $hidden = op($ops, "l{$layer}_output", 'add', [
            'left' => $attnResidual,
            'right' => $ffnPost,
        ]);
    }

    $final = op($ops, 'final_norm', 'rms_norm', [
        'input' => $hidden,
        'weight' => 'output_norm.weight',
        'epsilon' => 1e-6,
    ]);
    if ($emitToken) {
        $logits = op($ops, 'logits', 'linear', [
            'input' => $final,
            'weight' => 'token_embd.weight',
            'row_limit' => $vocab,
        ]);
        op($ops, 'next_token', 'sample_token', [
            'logits' => $logits,
            'temperature' => (float) $sample['temperature'],
            'top_k' => (int) $sample['top_k'],
            'top_p' => (float) $sample['top_p'],
            'seed' => (int) $sample['seed'],
            'sample_index' => $position,
        ]);
    }

    $graph = [
        'ops' => $ops,
        'output' => $emitToken ? 'next_token' : $hidden,
    ];
    if ($state !== null) {
        $graph['state'] = $state;
    }

    return $graph;
}

function run_step(
    King\Inference\Model $model,
    int $tokenId,
    int $position,
    ?array $state,
    array $gguf,
    array $sample,
    bool $emitToken,
    array $options
): array {
    $graph = build_step_graph($tokenId, $position, $state, $gguf, $sample, $emitToken);
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
$sample = [
    'temperature' => (float) $args['temperature'],
    'top_k' => (int) $args['top_k'],
    'top_p' => (float) $args['top_p'],
    'seed' => (int) $args['seed'],
];

foreach ($tokens as $index => $token) {
    [$nextToken, $state] = run_step(
        $model,
        (int) $token,
        $index,
        $state,
        $gguf,
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
        $gguf,
        $sample,
        true,
        $graphOptions
    );
    $position++;
}
