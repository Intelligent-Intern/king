<?php
declare(strict_types=1);

echo "=== Voltron Native Layer Worker Contract Test ===\n\n";

if (!function_exists('king_native_voltron_layer_worker')) {
    echo "[SKIP] King extension is not loaded; run with -d extension=extension/modules/king.so\n";
    exit(0);
}

$passed = 0;
$failed = 0;

function native_layer_test(string $name, callable $fn): void
{
    global $passed, $failed;

    try {
        $fn();
        echo "[PASS] {$name}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "[FAIL] {$name}: " . $e->getMessage() . "\n";
        $failed++;
    }
}

function native_layer_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function native_layer_assert_close(float $actual, float $expected, string $label): void
{
    if (abs($actual - $expected) > 1e-6) {
        throw new RuntimeException("{$label}: expected {$expected}, got {$actual}");
    }
}

/**
 * @param array<int,float> $input
 * @param array<int,float> $weights
 * @return array<int,float>
 */
function native_layer_rms_norm(array $input, array $weights, float $eps): array
{
    $mean = 0.0;
    foreach ($input as $value) {
        $mean += $value * $value;
    }
    $mean /= max(1, count($input));
    $inv = 1.0 / sqrt($mean + $eps);

    $out = [];
    foreach ($input as $i => $value) {
        $out[] = $value * $inv * ($weights[$i] ?? 1.0);
    }

    return $out;
}

/**
 * @param array<int,array<int,float>> $rows
 * @param array<int,float> $input
 * @return array<int,float>
 */
function native_layer_matvec(array $rows, array $input): array
{
    $out = [];
    foreach ($rows as $row) {
        $sum = 0.0;
        foreach ($row as $i => $weight) {
            $sum += $weight * ($input[$i] ?? 0.0);
        }
        $out[] = $sum;
    }

    return $out;
}

/**
 * @param array<int,float> $values
 * @return array<int,float>
 */
function native_layer_apply_rope(array $values, int $headCount, int $headDim, int $position, float $freqBase): array
{
    for ($head = 0; $head < $headCount; $head++) {
        $offset = $head * $headDim;
        for ($lane = 0; $lane + 1 < $headDim; $lane += 2) {
            $theta = $position / ($freqBase ** ($lane / max(1, $headDim)));
            $c = cos($theta);
            $s = sin($theta);
            $x0 = $values[$offset + $lane];
            $x1 = $values[$offset + $lane + 1];
            $values[$offset + $lane] = ($x0 * $c) - ($x1 * $s);
            $values[$offset + $lane + 1] = ($x0 * $s) + ($x1 * $c);
        }
    }

    return $values;
}

/**
 * @param array<int,float> $left
 * @param array<int,float> $right
 */
function native_layer_dot(array $left, array $right): float
{
    $sum = 0.0;
    $count = min(count($left), count($right));
    for ($i = 0; $i < $count; $i++) {
        $sum += $left[$i] * $right[$i];
    }
    return $sum;
}

/**
 * @param array<int,float> $hidden
 * @param array<string,mixed> $weights
 * @param array<int,float> $cacheK
 * @param array<int,float> $cacheV
 * @return array{hidden:array<int,float>,cache_k:array<int,float>,cache_v:array<int,float>,cache_tokens:int}
 */
function native_layer_attention_reference(
    array $hidden,
    array $weights,
    array $cacheK,
    array $cacheV,
    int $cacheTokens,
    int $position,
    int $headCount,
    int $headCountKv,
    int $headDim,
    float $ropeFreqBase,
    float $rmsEps
): array {
    $x = native_layer_rms_norm($hidden, $weights['attn_norm'], $rmsEps);
    $q = native_layer_apply_rope(native_layer_matvec($weights['attn_q'], $x), $headCount, $headDim, $position, $ropeFreqBase);
    $k = native_layer_apply_rope(native_layer_matvec($weights['attn_k'], $x), $headCountKv, $headDim, $position, $ropeFreqBase);
    $v = native_layer_matvec($weights['attn_v'], $x);

    $cacheWidth = $headCountKv * $headDim;
    $cacheK = array_merge($cacheK, $k);
    $cacheV = array_merge($cacheV, $v);
    $cacheTokens++;

    $ctx = [];
    $scale = 1.0 / sqrt($headDim);
    $qPerKv = max(1, intdiv($headCount, $headCountKv));

    for ($head = 0; $head < $headCount; $head++) {
        $qHead = array_slice($q, $head * $headDim, $headDim);
        $kvHead = min($headCountKv - 1, intdiv($head, $qPerKv));
        $scores = [];

        for ($token = 0; $token < $cacheTokens; $token++) {
            $offset = ($token * $cacheWidth) + ($kvHead * $headDim);
            $scores[] = native_layer_dot($qHead, array_slice($cacheK, $offset, $headDim)) * $scale;
        }

        $maxScore = max($scores);
        $expSum = 0.0;
        $weightsSoftmax = [];
        foreach ($scores as $score) {
            $value = exp($score - $maxScore);
            $weightsSoftmax[] = $value;
            $expSum += $value;
        }

        $headCtx = array_fill(0, $headDim, 0.0);
        foreach ($weightsSoftmax as $token => $value) {
            $offset = ($token * $cacheWidth) + ($kvHead * $headDim);
            $vHead = array_slice($cacheV, $offset, $headDim);
            $weight = $value / max($expSum, 1e-12);
            foreach ($headCtx as $lane => $laneValue) {
                $headCtx[$lane] = $laneValue + ($weight * $vHead[$lane]);
            }
        }

        foreach ($headCtx as $value) {
            $ctx[] = $value;
        }
    }

    $proj = native_layer_matvec($weights['attn_output'], $ctx);
    foreach ($proj as $i => $value) {
        $hidden[$i] += $value;
    }

    return [
        'hidden' => $hidden,
        'cache_k' => $cacheK,
        'cache_v' => $cacheV,
        'cache_tokens' => $cacheTokens,
    ];
}

/**
 * @param array<int,float> $hidden
 * @param array<string,mixed> $weights
 * @return array<int,float>
 */
function native_layer_ffn_reference(array $hidden, array $weights, float $rmsEps): array
{
    $x = native_layer_rms_norm($hidden, $weights['ffn_norm'], $rmsEps);
    $gate = native_layer_matvec($weights['ffn_gate'], $x);
    $up = native_layer_matvec($weights['ffn_up'], $x);
    $act = [];

    foreach ($gate as $i => $g) {
        $act[] = ($g / (1.0 + exp(-$g))) * ($up[$i] ?? 0.0);
    }

    $down = native_layer_matvec($weights['ffn_down'], $act);
    foreach ($down as $i => $value) {
        $hidden[$i] += $value;
    }

    return $hidden;
}

/**
 * @param array<string,array<int,array<int,float>>|array<int,float>> $tensors
 * @return array<string,array<string,int>>
 */
function native_layer_materialize_tensors(string $path, array $tensors): array
{
    $offset = 0;
    $meta = [];
    $fh = fopen($path, 'wb');
    if (!is_resource($fh)) {
        throw new RuntimeException('Failed to open temp tensor file.');
    }

    try {
        foreach ($tensors as $name => $rows) {
            native_layer_assert(is_array($rows) && $rows !== [], "Tensor {$name} must have rows.");
            $rowCount = count($rows);
            $ne0 = count($rows[0]);
            foreach ($rows as $row) {
                native_layer_assert(is_array($row) && count($row) === $ne0, "Tensor {$name} row width mismatch.");
                fwrite($fh, pack('g*', ...$row));
            }

            $meta[$name] = [
                'absolute_offset' => $offset,
                'row_count' => $rowCount,
                'row_size' => $ne0 * 4,
                'ne0' => $ne0,
                'type' => 0,
            ];

            $offset += $rowCount * $ne0 * 4;
        }
    } finally {
        fclose($fh);
    }

    return $meta;
}

native_layer_test('native attention, ffn, and layer modes match reference math', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'king-voltron-layer-');
    if ($tmp === false) {
        throw new RuntimeException('Failed to allocate temp file.');
    }

    $weights = [
        'attn_norm' => [[1.0, 0.5, 1.5, -1.0]],
        'attn_q' => [
            [0.6, 0.1, -0.2, 0.0],
            [0.0, 0.4, 0.3, -0.1],
            [0.2, -0.5, 0.7, 0.3],
            [0.1, 0.2, -0.4, 0.8],
        ],
        'attn_k' => [
            [0.3, -0.2, 0.5, 0.1],
            [-0.4, 0.6, 0.2, -0.3],
        ],
        'attn_v' => [
            [0.2, 0.7, -0.1, 0.5],
            [-0.3, 0.4, 0.6, -0.2],
        ],
        'attn_output' => [
            [0.5, -0.2, 0.1, 0.0],
            [0.0, 0.3, -0.4, 0.6],
            [0.2, 0.1, 0.5, -0.3],
            [-0.6, 0.4, 0.2, 0.1],
        ],
        'ffn_norm' => [[0.7, 1.1, -0.9, 0.3]],
        'ffn_gate' => [
            [0.2, -0.1, 0.4, 0.6],
            [-0.3, 0.8, 0.2, -0.5],
            [0.9, 0.1, -0.7, 0.3],
        ],
        'ffn_up' => [
            [0.5, 0.2, -0.4, 0.1],
            [0.7, -0.6, 0.3, 0.2],
            [-0.2, 0.4, 0.8, -0.1],
        ],
        'ffn_down' => [
            [0.2, -0.3, 0.5],
            [0.1, 0.4, -0.2],
            [-0.6, 0.2, 0.3],
            [0.7, -0.5, 0.1],
        ],
    ];

    try {
        $meta = native_layer_materialize_tensors($tmp, $weights);

        $hidden = [1.0, -2.0, 0.5, 3.0];
        $cacheK = [0.2, -0.1];
        $cacheV = [1.5, -0.5];
        $cacheTokens = 1;
        $position = 2;
        $headCount = 2;
        $headCountKv = 1;
        $headDim = 2;
        $ropeFreqBase = 1000.0;
        $rmsEps = 1e-5;

        $attentionRequest = [
            'mode' => 'attention',
            'hidden' => $hidden,
            'position' => $position,
            'head_count' => $headCount,
            'head_count_kv' => $headCountKv,
            'head_dim' => $headDim,
            'rope_freq_base' => $ropeFreqBase,
            'rms_eps' => $rmsEps,
            'layers' => [[
                'layer' => 7,
                'attn_norm' => $meta['attn_norm'],
                'attn_q' => $meta['attn_q'],
                'attn_k' => $meta['attn_k'],
                'attn_v' => $meta['attn_v'],
                'attn_output' => $meta['attn_output'],
                'cache_tokens' => $cacheTokens,
                'cache_k' => $cacheK,
                'cache_v' => $cacheV,
            ]],
        ];

        $attentionExpected = native_layer_attention_reference(
            $hidden,
            $weights,
            $cacheK,
            $cacheV,
            $cacheTokens,
            $position,
            $headCount,
            $headCountKv,
            $headDim,
            $ropeFreqBase,
            $rmsEps
        );
        $attentionActual = king_native_voltron_layer_worker($tmp, $attentionRequest);

        native_layer_assert(is_array($attentionActual), 'Expected attention response array.');
        foreach ($attentionExpected['hidden'] as $i => $expectedValue) {
            native_layer_assert_close((float) ($attentionActual['hidden'][$i] ?? 0.0), $expectedValue, "attention hidden[$i]");
        }
        native_layer_assert((int) ($attentionActual['layers'][0]['cache_tokens'] ?? -1) === 2, 'attention cache_tokens mismatch');
        foreach ($attentionExpected['cache_k'] as $i => $expectedValue) {
            native_layer_assert_close((float) ($attentionActual['layers'][0]['cache_k'][$i] ?? 0.0), $expectedValue, "attention cache_k[$i]");
            native_layer_assert_close((float) ($attentionActual['layers'][0]['cache_v'][$i] ?? 0.0), $attentionExpected['cache_v'][$i], "attention cache_v[$i]");
        }

        $ffnRequest = [
            'mode' => 'ffn',
            'hidden' => $attentionExpected['hidden'],
            'rms_eps' => $rmsEps,
            'layers' => [[
                'layer' => 7,
                'ffn_norm' => $meta['ffn_norm'],
                'ffn_gate' => $meta['ffn_gate'],
                'ffn_up' => $meta['ffn_up'],
                'ffn_down' => $meta['ffn_down'],
            ]],
        ];

        $ffnExpected = native_layer_ffn_reference($attentionExpected['hidden'], $weights, $rmsEps);
        $ffnActual = king_native_voltron_layer_worker($tmp, $ffnRequest);
        native_layer_assert(is_array($ffnActual), 'Expected FFN response array.');
        foreach ($ffnExpected as $i => $expectedValue) {
            native_layer_assert_close((float) ($ffnActual['hidden'][$i] ?? 0.0), $expectedValue, "ffn hidden[$i]");
        }

        $layerRequest = [
            'mode' => 'layer',
            'hidden' => $hidden,
            'position' => $position,
            'head_count' => $headCount,
            'head_count_kv' => $headCountKv,
            'head_dim' => $headDim,
            'rope_freq_base' => $ropeFreqBase,
            'rms_eps' => $rmsEps,
            'layers' => [[
                'layer' => 7,
                'attn_norm' => $meta['attn_norm'],
                'attn_q' => $meta['attn_q'],
                'attn_k' => $meta['attn_k'],
                'attn_v' => $meta['attn_v'],
                'attn_output' => $meta['attn_output'],
                'cache_tokens' => $cacheTokens,
                'cache_k' => $cacheK,
                'cache_v' => $cacheV,
                'ffn_norm' => $meta['ffn_norm'],
                'ffn_gate' => $meta['ffn_gate'],
                'ffn_up' => $meta['ffn_up'],
                'ffn_down' => $meta['ffn_down'],
            ]],
        ];

        $layerActual = king_native_voltron_layer_worker($tmp, $layerRequest);
        native_layer_assert(is_array($layerActual), 'Expected layer response array.');
        foreach ($ffnExpected as $i => $expectedValue) {
            native_layer_assert_close((float) ($layerActual['hidden'][$i] ?? 0.0), $expectedValue, "layer hidden[$i]");
        }
        foreach ($attentionExpected['cache_k'] as $i => $expectedValue) {
            native_layer_assert_close((float) ($layerActual['layers'][0]['cache_k'][$i] ?? 0.0), $expectedValue, "layer cache_k[$i]");
            native_layer_assert_close((float) ($layerActual['layers'][0]['cache_v'][$i] ?? 0.0), $attentionExpected['cache_v'][$i], "layer cache_v[$i]");
        }
    } finally {
        @unlink($tmp);
    }
});

echo "\n=== Results ===\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";

exit($failed > 0 ? 1 : 0);
