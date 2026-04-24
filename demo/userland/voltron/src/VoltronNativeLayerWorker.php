<?php
declare(strict_types=1);

namespace King\Voltron;

final class VoltronNativeLayerWorker
{
    public static function isAvailable(): bool
    {
        return function_exists('king_native_voltron_layer_worker');
    }

    /**
     * @param array<string,mixed> $runtime
     * @param array<int,float|int> $hidden
     * @param array<string,mixed> $kvCache
     * @return array{hidden:array<int,float>,kv_cache:array<string,mixed>}|null
     */
    public static function forwardAttention(
        GgufTensorLoader $loader,
        array $runtime,
        array $hidden,
        array $kvCache,
        int $position,
        int $layerStart,
        int $layerEnd
    ): ?array {
        return self::run($loader, $runtime, 'attention', $hidden, $kvCache, $position, $layerStart, $layerEnd);
    }

    /**
     * @param array<string,mixed> $runtime
     * @param array<int,float|int> $hidden
     * @return array<int,float>|null
     */
    public static function forwardFfn(
        GgufTensorLoader $loader,
        array $runtime,
        array $hidden,
        int $layerStart,
        int $layerEnd
    ): ?array {
        $result = self::run($loader, $runtime, 'ffn', $hidden, [], 0, $layerStart, $layerEnd);
        return is_array($result['hidden'] ?? null) ? $result['hidden'] : null;
    }

    /**
     * @param array<string,mixed> $runtime
     * @param array<int,float|int> $hidden
     * @param array<string,mixed> $kvCache
     * @return array{hidden:array<int,float>,kv_cache:array<string,mixed>}|null
     */
    public static function forwardLayers(
        GgufTensorLoader $loader,
        array $runtime,
        array $hidden,
        array $kvCache,
        int $position,
        int $layerStart,
        int $layerEnd
    ): ?array {
        return self::run($loader, $runtime, 'layer', $hidden, $kvCache, $position, $layerStart, $layerEnd);
    }

    /**
     * @param array<string,mixed> $runtime
     * @param array<int,float|int> $hidden
     * @param array<string,mixed> $kvCache
     * @return array{hidden:array<int,float>,kv_cache:array<string,mixed>}|null
     */
    private static function run(
        GgufTensorLoader $loader,
        array $runtime,
        string $mode,
        array $hidden,
        array $kvCache,
        int $position,
        int $layerStart,
        int $layerEnd
    ): ?array {
        if (!self::isAvailable()) {
            return null;
        }

        $headCount = max(1, (int) ($runtime['head_count'] ?? 1));
        $headCountKv = max(1, (int) ($runtime['head_count_kv'] ?? $headCount));
        $headDim = max(1, (int) ($runtime['head_dim'] ?? max(1, intdiv((int) ($runtime['hidden_dim'] ?? 1), $headCount))));

        $request = [
            'mode' => $mode,
            'hidden' => self::normalizeVector($hidden, (int) ($runtime['hidden_dim'] ?? count($hidden))),
            'rms_eps' => (float) ($runtime['rms_eps'] ?? 1e-5),
            'layers' => [],
        ];

        if ($mode !== 'ffn') {
            $request['position'] = $position;
            $request['head_count'] = $headCount;
            $request['head_count_kv'] = $headCountKv;
            $request['head_dim'] = $headDim;
            $request['rope_freq_base'] = (float) ($runtime['rope_freq_base'] ?? 10000.0);
            $request['rope_type'] = (int) ($runtime['rope_type'] ?? 0);
        }

        for ($layer = $layerStart; $layer <= $layerEnd; $layer++) {
            $plan = ['layer' => $layer];
            if ($mode !== 'ffn') {
                $plan += self::buildAttentionLayerPlan($loader, $layer);
                $flattened = self::flattenLayerCache($kvCache[(string) $layer] ?? [], $headCountKv, $headDim);
                $plan['cache_tokens'] = $flattened['cache_tokens'];
                $plan['cache_k'] = $flattened['cache_k'];
                $plan['cache_v'] = $flattened['cache_v'];
            }
            if ($mode !== 'attention') {
                $plan += self::buildFfnLayerPlan($loader, $layer);
            }
            $request['layers'][] = $plan;
        }

        try {
            $result = king_native_voltron_layer_worker($loader->path(), $request);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($result)) {
            return null;
        }

        $normalizedHidden = self::normalizeVector(
            is_array($result['hidden'] ?? null) ? $result['hidden'] : [],
            (int) ($runtime['hidden_dim'] ?? count($hidden))
        );

        $nextKvCache = $kvCache;
        if ($mode !== 'ffn') {
            $layerResults = is_array($result['layers'] ?? null) ? $result['layers'] : [];
            foreach ($layerResults as $layerResult) {
                if (!is_array($layerResult)) {
                    continue;
                }
                $layer = (string) ((int) ($layerResult['layer'] ?? 0));
                $tokens = max(0, (int) ($layerResult['cache_tokens'] ?? 0));
                $flatK = is_array($layerResult['cache_k'] ?? null) ? $layerResult['cache_k'] : [];
                $flatV = is_array($layerResult['cache_v'] ?? null) ? $layerResult['cache_v'] : [];
                $nextKvCache[$layer] = [
                    'k' => self::expandFlatCache($flatK, $tokens, $headCountKv, $headDim),
                    'v' => self::expandFlatCache($flatV, $tokens, $headCountKv, $headDim),
                ];
            }
        }

        return [
            'hidden' => $normalizedHidden,
            'kv_cache' => $nextKvCache,
        ];
    }

    /**
     * @return array<string,array<string,int|string|array<int,int>>>
     */
    private static function buildAttentionLayerPlan(GgufTensorLoader $loader, int $layer): array
    {
        $prefixes = ["blk.{$layer}.", "layers.{$layer}.", "model.layers.{$layer}."];

        return [
            'attn_norm' => $loader->nativeTensorMeta(self::resolveLayerTensor($loader, $prefixes, [
                'attn_norm.weight',
                'attention_norm.weight',
                'ln1.weight',
                'input_layernorm.weight',
            ])),
            'attn_q' => $loader->nativeTensorMeta(self::resolveLayerTensor($loader, $prefixes, [
                'attn_q.weight',
                'self_attn.q_proj.weight',
                'attention.wq.weight',
            ])),
            'attn_k' => $loader->nativeTensorMeta(self::resolveLayerTensor($loader, $prefixes, [
                'attn_k.weight',
                'self_attn.k_proj.weight',
                'attention.wk.weight',
            ])),
            'attn_v' => $loader->nativeTensorMeta(self::resolveLayerTensor($loader, $prefixes, [
                'attn_v.weight',
                'self_attn.v_proj.weight',
                'attention.wv.weight',
            ])),
            'attn_output' => $loader->nativeTensorMeta(self::resolveLayerTensor($loader, $prefixes, [
                'attn_output.weight',
                'self_attn.o_proj.weight',
                'attention.wo.weight',
            ])),
        ];
    }

    /**
     * @return array<string,array<string,int|string|array<int,int>>>
     */
    private static function buildFfnLayerPlan(GgufTensorLoader $loader, int $layer): array
    {
        $prefixes = ["blk.{$layer}.", "layers.{$layer}.", "model.layers.{$layer}."];

        return [
            'ffn_norm' => $loader->nativeTensorMeta(self::resolveLayerTensor($loader, $prefixes, [
                'ffn_norm.weight',
                'post_attention_layernorm.weight',
                'ln2.weight',
            ])),
            'ffn_gate' => $loader->nativeTensorMeta(self::resolveLayerTensor($loader, $prefixes, [
                'ffn_gate.weight',
                'mlp.gate_proj.weight',
                'feed_forward.w1.weight',
            ])),
            'ffn_up' => $loader->nativeTensorMeta(self::resolveLayerTensor($loader, $prefixes, [
                'ffn_up.weight',
                'mlp.up_proj.weight',
                'feed_forward.w3.weight',
            ])),
            'ffn_down' => $loader->nativeTensorMeta(self::resolveLayerTensor($loader, $prefixes, [
                'ffn_down.weight',
                'mlp.down_proj.weight',
                'feed_forward.w2.weight',
            ])),
        ];
    }

    /**
     * @param array<int,string> $prefixes
     * @param array<int,string> $suffixes
     */
    private static function resolveLayerTensor(GgufTensorLoader $loader, array $prefixes, array $suffixes): string
    {
        $candidates = [];
        foreach ($prefixes as $prefix) {
            foreach ($suffixes as $suffix) {
                $candidates[] = $prefix . $suffix;
            }
        }

        $name = $loader->findTensor($candidates);
        if (!is_string($name) || $name === '') {
            throw new \RuntimeException('Missing GGUF tensor. Tried: ' . implode(', ', $candidates));
        }

        return $name;
    }

    /**
     * @param array<string,mixed> $layerCache
     * @return array{cache_tokens:int,cache_k:array<int,float>,cache_v:array<int,float>}
     */
    private static function flattenLayerCache(array $layerCache, int $headCountKv, int $headDim): array
    {
        $flatK = [];
        $flatV = [];
        $cacheK = is_array($layerCache['k'] ?? null) ? $layerCache['k'] : [];
        $cacheV = is_array($layerCache['v'] ?? null) ? $layerCache['v'] : [];
        $tokens = min(count($cacheK), count($cacheV));

        for ($token = 0; $token < $tokens; $token++) {
            $kHeads = is_array($cacheK[$token] ?? null) ? $cacheK[$token] : [];
            $vHeads = is_array($cacheV[$token] ?? null) ? $cacheV[$token] : [];

            for ($head = 0; $head < $headCountKv; $head++) {
                $kHead = is_array($kHeads[$head] ?? null) ? $kHeads[$head] : [];
                $vHead = is_array($vHeads[$head] ?? null) ? $vHeads[$head] : [];

                for ($lane = 0; $lane < $headDim; $lane++) {
                    $flatK[] = (float) ($kHead[$lane] ?? 0.0);
                    $flatV[] = (float) ($vHead[$lane] ?? 0.0);
                }
            }
        }

        return [
            'cache_tokens' => $tokens,
            'cache_k' => $flatK,
            'cache_v' => $flatV,
        ];
    }

    /**
     * @param array<int,mixed> $flat
     * @return array<int,array<int,array<int,float>>>
     */
    private static function expandFlatCache(array $flat, int $tokens, int $headCountKv, int $headDim): array
    {
        $out = [];
        $cursor = 0;

        for ($token = 0; $token < $tokens; $token++) {
            $heads = [];
            for ($head = 0; $head < $headCountKv; $head++) {
                $values = [];
                for ($lane = 0; $lane < $headDim; $lane++) {
                    $values[] = (float) ($flat[$cursor] ?? 0.0);
                    $cursor++;
                }
                $heads[] = $values;
            }
            $out[] = $heads;
        }

        return $out;
    }

    /**
     * @param array<int,mixed> $values
     * @return array<int,float>
     */
    private static function normalizeVector(array $values, int $length): array
    {
        $length = max(0, $length);
        $out = [];

        for ($i = 0; $i < $length; $i++) {
            $out[] = (float) ($values[$i] ?? 0.0);
        }

        return $out;
    }
}
