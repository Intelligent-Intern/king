<?php
declare(strict_types=1);

namespace King\Voltron;

use RuntimeException;

final class VoltronKernels
{
    /** @var array<string,array<string,mixed>> */
    private static array $runtimeCache = [];

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public static function initialState(string $prompt, array $params): array
    {
        $runtime = self::runtimeForParams($params);
        /** @var VoltronTokenizer $tokenizer */
        $tokenizer = $runtime['tokenizer'];

        $formattedPrompt = $tokenizer->formatPrompt($prompt);
        $tokenIds = $tokenizer->encode($formattedPrompt, false);
        if ($tokenIds === []) {
            $fallback = $tokenizer->bosId();
            $tokenIds[] = is_int($fallback) ? $fallback : 0;
        }

        $maxTokens = is_int($params['inference_max_tokens'] ?? null) && (int) $params['inference_max_tokens'] > 0
            ? (int) $params['inference_max_tokens']
            : 64;

        return [
            'prompt' => $prompt,
            'formatted_prompt' => $formattedPrompt,
            'token_ids' => $tokenIds,
            'generated_token_ids' => [],
            'generated_text' => '',
            'position' => max(0, count($tokenIds) - 1),
            'stop' => false,
            'max_tokens' => $maxTokens,
            'latent_dim' => $runtime['hidden_dim'],
            'kv_cache' => [],
            'activations' => [],
            'model_runtime_key' => $runtime['key'],
            'last_token_id' => $tokenIds[count($tokenIds) - 1],
            'finished_reason' => null,
        ];
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public static function executeBlock(string $blockType, array $state, array $params): array
    {
        if (($state['stop'] ?? false) === true && $blockType !== 'output_head') {
            return $state;
        }

        return match ($blockType) {
            'embed' => self::executeEmbed($state, $params),
            'attention' => self::executeAttention($state, $params),
            'ffn' => self::executeFfn($state, $params),
            'output_head' => self::executeOutputHead($state, $params),
            default => throw new RuntimeException("Unsupported Voltron block type: {$blockType}"),
        };
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private static function executeEmbed(array $state, array $params): array
    {
        $runtime = self::runtimeForParams($params);
        /** @var GgufTensorLoader $loader */
        $loader = $runtime['loader'];

        $tokenIds = is_array($state['token_ids'] ?? null) ? $state['token_ids'] : [];
        if ($tokenIds === []) {
            throw new RuntimeException('Kernel state missing token_ids at embed block.');
        }
        $lastTokenId = (int) $tokenIds[count($tokenIds) - 1];

        $embedTensor = (string) $runtime['embed_tensor'];
        if ($lastTokenId < 0 || $lastTokenId >= $loader->rowCount($embedTensor)) {
            $lastTokenId = max(0, min($loader->rowCount($embedTensor) - 1, $lastTokenId));
        }

        $hidden = $loader->readRow($embedTensor, $lastTokenId);

        $state['position'] = max(0, count($tokenIds) - 1);
        $state['last_token_id'] = $lastTokenId;
        $state['activations']['hidden'] = $hidden;
        $state['activations']['embed_token_id'] = $lastTokenId;

        return $state;
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private static function executeAttention(array $state, array $params): array
    {
        $runtime = self::runtimeForParams($params);
        [$layerStart, $layerEnd] = self::layerRangeFromParams($params);

        /** @var GgufTensorLoader $loader */
        $loader = $runtime['loader'];
        $hidden = self::hiddenFromState($state, (int) $runtime['hidden_dim']);
        $kvCache = is_array($state['kv_cache'] ?? null) ? $state['kv_cache'] : [];
        $pos = is_int($state['position'] ?? null) ? (int) $state['position'] : 0;

        for ($layer = $layerStart; $layer <= $layerEnd; $layer++) {
            $prefixes = ["blk.{$layer}.", "layers.{$layer}.", "model.layers.{$layer}."];
            $normTensor = self::resolveLayerTensor($loader, $prefixes, ['attn_norm.weight', 'attention_norm.weight', 'ln1.weight', 'input_layernorm.weight'], 'attention norm');
            $qTensor = self::resolveLayerTensor($loader, $prefixes, ['attn_q.weight', 'self_attn.q_proj.weight', 'attention.wq.weight'], 'attention q');
            $kTensor = self::resolveLayerTensor($loader, $prefixes, ['attn_k.weight', 'self_attn.k_proj.weight', 'attention.wk.weight'], 'attention k');
            $vTensor = self::resolveLayerTensor($loader, $prefixes, ['attn_v.weight', 'self_attn.v_proj.weight', 'attention.wv.weight'], 'attention v');
            $oTensor = self::resolveLayerTensor($loader, $prefixes, ['attn_output.weight', 'self_attn.o_proj.weight', 'attention.wo.weight'], 'attention o');

            $norm = $loader->readRow($normTensor, 0);
            $x = self::rmsNorm($hidden, $norm, (float) $runtime['rms_eps']);
            $q = self::projectTensorRows($loader, $qTensor, $x);
            $k = self::projectTensorRows($loader, $kTensor, $x);
            $v = self::projectTensorRows($loader, $vTensor, $x);

            $qHeads = self::splitHeads($q, (int) $runtime['head_count'], (int) $runtime['head_dim']);
            $kHeads = self::splitHeads($k, (int) $runtime['head_count_kv'], (int) $runtime['head_dim']);
            $vHeads = self::splitHeads($v, (int) $runtime['head_count_kv'], (int) $runtime['head_dim']);

            foreach ($qHeads as &$qHead) {
                self::applyRopeToHead($qHead, $pos, (float) $runtime['rope_freq_base']);
            }
            unset($qHead);
            foreach ($kHeads as &$kHead) {
                self::applyRopeToHead($kHead, $pos, (float) $runtime['rope_freq_base']);
            }
            unset($kHead);

            $layerKey = (string) $layer;
            $layerCache = is_array($kvCache[$layerKey] ?? null) ? $kvCache[$layerKey] : ['k' => [], 'v' => []];
            $layerCacheK = is_array($layerCache['k'] ?? null) ? $layerCache['k'] : [];
            $layerCacheV = is_array($layerCache['v'] ?? null) ? $layerCache['v'] : [];

            $layerCacheK[] = $kHeads;
            $layerCacheV[] = $vHeads;

            $scale = 1.0 / sqrt(max(1, (int) $runtime['head_dim']));
            $ctx = [];
            $qPerKv = max(1, intdiv((int) $runtime['head_count'], max(1, (int) $runtime['head_count_kv'])));
            foreach ($qHeads as $headIndex => $qHeadValues) {
                $kvHeadIndex = min((int) $runtime['head_count_kv'] - 1, intdiv($headIndex, $qPerKv));
                $scores = [];
                foreach ($layerCacheK as $cachedHeads) {
                    $cachedHead = is_array($cachedHeads[$kvHeadIndex] ?? null) ? $cachedHeads[$kvHeadIndex] : [];
                    $scores[] = self::dot($qHeadValues, $cachedHead) * $scale;
                }

                $weights = self::softmax($scores);
                $headCtx = array_fill(0, (int) $runtime['head_dim'], 0.0);
                foreach ($weights as $idx => $weight) {
                    $cachedValueHeads = $layerCacheV[$idx] ?? null;
                    $cachedValueHead = is_array($cachedValueHeads[$kvHeadIndex] ?? null) ? $cachedValueHeads[$kvHeadIndex] : [];
                    foreach ($headCtx as $i => $acc) {
                        $headCtx[$i] = $acc + $weight * (float) ($cachedValueHead[$i] ?? 0.0);
                    }
                }

                foreach ($headCtx as $value) {
                    $ctx[] = $value;
                }
            }

            $proj = self::projectTensorRows($loader, $oTensor, $ctx);
            $hidden = self::vecAdd($hidden, $proj);

            $kvCache[$layerKey] = ['k' => $layerCacheK, 'v' => $layerCacheV];
        }

        $state['kv_cache'] = $kvCache;
        $state['activations']['hidden'] = $hidden;

        return $state;
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private static function executeFfn(array $state, array $params): array
    {
        $runtime = self::runtimeForParams($params);
        [$layerStart, $layerEnd] = self::layerRangeFromParams($params);
        /** @var GgufTensorLoader $loader */
        $loader = $runtime['loader'];
        $hidden = self::hiddenFromState($state, (int) $runtime['hidden_dim']);

        for ($layer = $layerStart; $layer <= $layerEnd; $layer++) {
            $prefixes = ["blk.{$layer}.", "layers.{$layer}.", "model.layers.{$layer}."];
            $normTensor = self::resolveLayerTensor($loader, $prefixes, ['ffn_norm.weight', 'post_attention_layernorm.weight', 'ln2.weight'], 'ffn norm');
            $gateTensor = self::resolveLayerTensor($loader, $prefixes, ['ffn_gate.weight', 'mlp.gate_proj.weight', 'feed_forward.w1.weight'], 'ffn gate');
            $upTensor = self::resolveLayerTensor($loader, $prefixes, ['ffn_up.weight', 'mlp.up_proj.weight', 'feed_forward.w3.weight'], 'ffn up');
            $downTensor = self::resolveLayerTensor($loader, $prefixes, ['ffn_down.weight', 'mlp.down_proj.weight', 'feed_forward.w2.weight'], 'ffn down');

            $norm = $loader->readRow($normTensor, 0);
            $x = self::rmsNorm($hidden, $norm, (float) $runtime['rms_eps']);
            $gate = self::projectTensorRows($loader, $gateTensor, $x);
            $up = self::projectTensorRows($loader, $upTensor, $x);

            $act = [];
            $count = min(count($gate), count($up));
            for ($i = 0; $i < $count; $i++) {
                $g = (float) $gate[$i];
                $u = (float) $up[$i];
                $act[] = self::silu($g) * $u;
            }

            $down = self::projectTensorRows($loader, $downTensor, $act);
            $hidden = self::vecAdd($hidden, $down);
        }

        $state['activations']['hidden'] = $hidden;
        return $state;
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private static function executeOutputHead(array $state, array $params): array
    {
        $runtime = self::runtimeForParams($params);
        /** @var VoltronTokenizer $tokenizer */
        $tokenizer = $runtime['tokenizer'];
        /** @var GgufTensorLoader $loader */
        $loader = $runtime['loader'];

        $hidden = self::hiddenFromState($state, (int) $runtime['hidden_dim']);

        if (is_string($runtime['output_norm_tensor'] ?? null) && $runtime['output_norm_tensor'] !== '') {
            $norm = $loader->readRow((string) $runtime['output_norm_tensor'], 0);
            $hidden = self::rmsNorm($hidden, $norm, (float) $runtime['rms_eps']);
        }

        $repeatPenalty = is_float($params['inference_repeat_penalty'] ?? null)
            ? (float) $params['inference_repeat_penalty']
            : 1.15;

        $temperature = is_float($params['inference_temperature'] ?? null)
            ? max(0.01, (float) $params['inference_temperature'])
            : 0.2;
        $topK = is_int($params['inference_top_k'] ?? null) ? max(0, (int) $params['inference_top_k']) : 40;
        $topP = is_float($params['inference_top_p'] ?? null) ? (float) $params['inference_top_p'] : 0.95;

        $candidateLimit = $topK > 0 ? $topK : 256;
        $candidateLimit = max(32, min(512, $candidateLimit));

        $logits = self::topTensorLogits($loader, (string) $runtime['output_tensor'], $hidden, $candidateLimit);
        $eos = $tokenizer->eosId();
        if (is_int($eos) && !isset($logits[$eos])) {
            $logits[$eos] = self::tensorRowDot($loader, (string) $runtime['output_tensor'], $eos, $hidden);
        }

        if ($repeatPenalty > 1.0) {
            $history = is_array($state['generated_token_ids'] ?? null) ? $state['generated_token_ids'] : [];
            $freq = [];
            foreach ($history as $tid) {
                $k = (int) $tid;
                $freq[$k] = ($freq[$k] ?? 0) + 1;
            }
            foreach ($freq as $tid => $count) {
                if (!isset($logits[$tid])) {
                    continue;
                }
                $penalty = $repeatPenalty ** max(1, (int) $count);
                if ($logits[$tid] >= 0.0) {
                    $logits[$tid] /= $penalty;
                } else {
                    $logits[$tid] *= $penalty;
                }
            }
        }

        $nextTokenId = self::sampleFromLogits($logits, $temperature, $topK, $topP);

        $tokenIds = is_array($state['token_ids'] ?? null) ? $state['token_ids'] : [];
        $tokenIds[] = $nextTokenId;
        $state['token_ids'] = $tokenIds;

        $generated = is_array($state['generated_token_ids'] ?? null) ? $state['generated_token_ids'] : [];
        $generated[] = $nextTokenId;
        $state['generated_token_ids'] = $generated;

        $piece = $tokenizer->decodeId($nextTokenId);
        $state['generated_text'] = (string) ($state['generated_text'] ?? '') . $piece;

        $state['position'] = max(0, count($tokenIds) - 1);
        $state['last_token_id'] = $nextTokenId;

        $stop = false;
        $reason = null;
        if (is_int($eos) && $nextTokenId === $eos) {
            $stop = true;
            $reason = 'eos';
        }

        $maxTokens = is_int($state['max_tokens'] ?? null) ? (int) $state['max_tokens'] : 64;
        if (count($generated) >= $maxTokens) {
            $stop = true;
            $reason = 'max_tokens';
        }

        $state['stop'] = $stop;
        $state['finished_reason'] = $reason;

        return $state;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private static function runtimeForParams(array $params): array
    {
        $loader = GgufTensorLoader::fromParams($params);

        $hiddenDim = self::metadataInt($loader, ['qwen2.embedding_length', 'llama.embedding_length']);
        if (!is_int($hiddenDim) || $hiddenDim <= 0) {
            $hiddenDim = $loader->ne0(self::resolveTensor(
                $loader,
                ['token_embd.weight', 'tok_embeddings.weight', 'model.embed_tokens.weight', 'transformer.wte.weight'],
                'embedding'
            ));
        }
        $ffnDim = self::metadataInt($loader, ['qwen2.feed_forward_length', 'llama.feed_forward_length']);
        if (!is_int($ffnDim) || $ffnDim <= 0) {
            $ffnDim = $hiddenDim * 4;
        }
        $headCount = self::metadataInt($loader, ['qwen2.attention.head_count', 'llama.attention.head_count']);
        $headCountKv = self::metadataInt($loader, ['qwen2.attention.head_count_kv', 'llama.attention.head_count_kv']);
        $headCount = is_int($headCount) && $headCount > 0 ? $headCount : 1;
        $headCountKv = is_int($headCountKv) && $headCountKv > 0 ? $headCountKv : $headCount;
        $headDim = max(1, intdiv($hiddenDim, $headCount));
        $ropeFreqBase = self::metadataFloat($loader, ['qwen2.rope.freq_base', 'llama.rope.freq_base'], 10000.0);
        $rmsEps = self::metadataFloat($loader, ['qwen2.attention.layer_norm_rms_epsilon', 'llama.attention.layer_norm_rms_epsilon'], 1e-5);

        $key = sha1($loader->path() . '|' . $hiddenDim . '|' . $ffnDim . '|' . $headCount . '|' . $headCountKv);
        if (isset(self::$runtimeCache[$key])) {
            return self::$runtimeCache[$key];
        }

        $embedTensor = self::resolveTensor(
            $loader,
            ['token_embd.weight', 'tok_embeddings.weight', 'model.embed_tokens.weight', 'transformer.wte.weight'],
            'embedding'
        );

        $outTensor = self::resolveTensor(
            $loader,
            ['output.weight', 'lm_head.weight', 'token_embd.weight'],
            'output_head'
        );

        $outNorm = $loader->findTensor(['output_norm.weight', 'model.norm.weight', 'norm.weight']);

        $bos = self::metadataInt($loader, ['tokenizer.ggml.bos_token_id', 'tokenizer.ggml.bos_id']);
        $eos = self::metadataInt($loader, ['tokenizer.ggml.eos_token_id', 'tokenizer.ggml.eos_id']);
        $unk = self::metadataInt($loader, ['tokenizer.ggml.unknown_token_id', 'tokenizer.ggml.unk_token_id', 'tokenizer.ggml.unk_id']);

        $tokenizer = new VoltronTokenizer(
            $loader->tokenizerTokens(),
            $loader->tokenizerTypes(),
            $loader->tokenizerScores(),
            $loader->tokenizerMerges(),
            $loader->tokenizerModel(),
            $loader->tokenizerPre(),
            $bos,
            $eos,
            $unk
        );

        $tokenCount = count($loader->tokenizerTokens());
        if ($tokenCount < 10000) {
            throw new RuntimeException(
                "GGUF tokenizer vocabulary is too small ({$tokenCount}) for real Qwen inference. "
                . 'Provide a real Qwen GGUF, not a fixture/tiny model.'
            );
        }

        $hiddenIndices = [];
        for ($i = 0; $i < $hiddenDim; $i++) {
            $hiddenIndices[] = $i;
        }

        $runtime = [
            'key' => $key,
            'loader' => $loader,
            'hidden_dim' => $hiddenDim,
            'ffn_dim' => $ffnDim,
            'head_count' => $headCount,
            'head_count_kv' => $headCountKv,
            'head_dim' => $headDim,
            'rope_freq_base' => $ropeFreqBase,
            'rms_eps' => $rmsEps,
            'embed_tensor' => $embedTensor,
            'output_tensor' => $outTensor,
            'output_norm_tensor' => $outNorm,
            'tokenizer' => $tokenizer,
            'hidden_indices' => $hiddenIndices,
            'attention' => [],
            'ffn' => [],
            'lm_head' => [],
        ];

        self::$runtimeCache[$key] = $runtime;
        return self::$runtimeCache[$key];
    }

    /**
     * @param array<string,mixed> $runtime
     * @return array{norm:array<int,float>,wq:array<int,array<int,float>>,wk:array<int,array<int,float>>,wv:array<int,array<int,float>>,wo:array<int,array<int,float>>}
     */
    private static function attentionWeights(array &$runtime, int $layer): array
    {
        $cacheKey = (string) $layer;
        if (self::weightCacheEnabled() && isset($runtime['attention'][$cacheKey])) {
            return $runtime['attention'][$cacheKey];
        }

        /** @var GgufTensorLoader $loader */
        $loader = $runtime['loader'];
        $hiddenIdx = $runtime['hidden_indices'];
        $latent = (int) $runtime['latent_dim'];

        $prefixes = ["blk.{$layer}.", "layers.{$layer}.", "model.layers.{$layer}."];

        $norm = self::resolveLayerTensor($loader, $prefixes, ['attn_norm.weight', 'attention_norm.weight', 'ln1.weight', 'input_layernorm.weight'], 'attention norm');
        $q = self::resolveLayerTensor($loader, $prefixes, ['attn_q.weight', 'self_attn.q_proj.weight', 'attention.wq.weight'], 'attention q');
        $k = self::resolveLayerTensor($loader, $prefixes, ['attn_k.weight', 'self_attn.k_proj.weight', 'attention.wk.weight'], 'attention k');
        $v = self::resolveLayerTensor($loader, $prefixes, ['attn_v.weight', 'self_attn.v_proj.weight', 'attention.wv.weight'], 'attention v');
        $o = self::resolveLayerTensor($loader, $prefixes, ['attn_output.weight', 'self_attn.o_proj.weight', 'attention.wo.weight'], 'attention o');

        $qRows = self::sampleIndices($loader->rowCount($q), $latent);
        $kRows = self::sampleIndices($loader->rowCount($k), $latent);
        $vRows = self::sampleIndices($loader->rowCount($v), $latent);
        $oRows = self::sampleIndices($loader->rowCount($o), $latent);

        $weights = [
            'norm' => self::sampleVector($loader->readRow($norm, 0), $hiddenIdx),
            'wq' => self::sampledMatrix($loader, $q, $qRows, $hiddenIdx),
            'wk' => self::sampledMatrix($loader, $k, $kRows, $hiddenIdx),
            'wv' => self::sampledMatrix($loader, $v, $vRows, $hiddenIdx),
            'wo' => self::sampledMatrix($loader, $o, $oRows, $hiddenIdx),
        ];

        if (self::weightCacheEnabled()) {
            $runtime['attention'][$cacheKey] = $weights;
            self::$runtimeCache[(string) $runtime['key']] = $runtime;
        }

        return $weights;
    }

    /**
     * @param array<string,mixed> $runtime
     * @return array{norm:array<int,float>,wg:array<int,array<int,float>>,wu:array<int,array<int,float>>,wd:array<int,array<int,float>>}
     */
    private static function ffnWeights(array &$runtime, int $layer): array
    {
        $cacheKey = (string) $layer;
        if (self::weightCacheEnabled() && isset($runtime['ffn'][$cacheKey])) {
            return $runtime['ffn'][$cacheKey];
        }

        /** @var GgufTensorLoader $loader */
        $loader = $runtime['loader'];
        $hiddenIdx = $runtime['hidden_indices'];
        $latent = (int) $runtime['latent_dim'];

        $prefixes = ["blk.{$layer}.", "layers.{$layer}.", "model.layers.{$layer}."];

        $norm = self::resolveLayerTensor($loader, $prefixes, ['ffn_norm.weight', 'post_attention_layernorm.weight', 'ln2.weight'], 'ffn norm');
        $gate = self::resolveLayerTensor($loader, $prefixes, ['ffn_gate.weight', 'mlp.gate_proj.weight', 'feed_forward.w1.weight'], 'ffn gate');
        $up = self::resolveLayerTensor($loader, $prefixes, ['ffn_up.weight', 'mlp.up_proj.weight', 'feed_forward.w3.weight'], 'ffn up');
        $down = self::resolveLayerTensor($loader, $prefixes, ['ffn_down.weight', 'mlp.down_proj.weight', 'feed_forward.w2.weight'], 'ffn down');

        $ffnWidth = min($latent, $loader->rowCount($gate));
        $ffnRows = self::sampleIndices($loader->rowCount($gate), $ffnWidth);
        $hiddenRows = self::sampleIndices($loader->rowCount($down), $latent);

        $weights = [
            'norm' => self::sampleVector($loader->readRow($norm, 0), $hiddenIdx),
            'wg' => self::sampledMatrix($loader, $gate, $ffnRows, $hiddenIdx),
            'wu' => self::sampledMatrix($loader, $up, $ffnRows, $hiddenIdx),
            'wd' => self::sampledMatrix($loader, $down, $hiddenRows, $ffnRows),
        ];

        if (self::weightCacheEnabled()) {
            $runtime['ffn'][$cacheKey] = $weights;
            self::$runtimeCache[(string) $runtime['key']] = $runtime;
        }

        return $weights;
    }

    /**
     * @param array<string,mixed> $runtime
     * @return array<int,float>
     */
    private static function lmHeadVector(array &$runtime, int $tokenId): array
    {
        if (self::weightCacheEnabled() && isset($runtime['lm_head'][$tokenId])) {
            return $runtime['lm_head'][$tokenId];
        }

        /** @var GgufTensorLoader $loader */
        $loader = $runtime['loader'];
        $outTensor = (string) $runtime['output_tensor'];
        $rowCount = $loader->rowCount($outTensor);

        if ($tokenId < 0 || $tokenId >= $rowCount) {
            $tokenId = max(0, min($rowCount - 1, $tokenId));
        }

        $row = $loader->readRow($outTensor, $tokenId);
        $vec = self::sampleVector($row, $runtime['hidden_indices']);

        if (self::weightCacheEnabled()) {
            $runtime['lm_head'][$tokenId] = $vec;
            self::$runtimeCache[(string) $runtime['key']] = $runtime;
        }

        return $vec;
    }

    /**
     * @param array<string,mixed> $runtime
     * @return array<int,float>
     */
    private static function lmHeadVectorFull(array &$runtime, int $tokenId): array
    {
        /** @var GgufTensorLoader $loader */
        $loader = $runtime['loader'];
        $outTensor = (string) $runtime['output_tensor'];
        $rowCount = $loader->rowCount($outTensor);

        if ($tokenId < 0 || $tokenId >= $rowCount) {
            $tokenId = max(0, min($rowCount - 1, $tokenId));
        }

        return $loader->readRow($outTensor, $tokenId);
    }

    private static function weightCacheEnabled(): bool
    {
        static $enabled = null;
        if (is_bool($enabled)) {
            return $enabled;
        }

        $raw = getenv('VOLTRON_KERNEL_WEIGHT_CACHE');
        if (!is_string($raw)) {
            $enabled = false;
            return $enabled;
        }

        $normalized = strtolower(trim($raw));
        $enabled = in_array($normalized, ['1', 'true', 'yes', 'on'], true);
        return $enabled;
    }

    /**
     * @param array<int,string> $prefixes
     * @param array<int,string> $suffixes
     */
    private static function resolveLayerTensor(
        GgufTensorLoader $loader,
        array $prefixes,
        array $suffixes,
        string $label
    ): string {
        $candidates = [];
        foreach ($prefixes as $prefix) {
            foreach ($suffixes as $suffix) {
                $candidates[] = $prefix . $suffix;
            }
        }

        $name = $loader->findTensor($candidates);
        if (!is_string($name) || $name === '') {
            throw new RuntimeException("Missing {$label} tensor in GGUF. Tried: " . implode(', ', $candidates));
        }

        return $name;
    }

    /** @param array<int,string> $candidates */
    private static function resolveTensor(GgufTensorLoader $loader, array $candidates, string $label): string
    {
        $name = $loader->findTensor($candidates);
        if (!is_string($name) || $name === '') {
            throw new RuntimeException("Missing {$label} tensor in GGUF. Tried: " . implode(', ', $candidates));
        }

        return $name;
    }

    /**
     * @param array<string,mixed> $state
     * @return array<int,float>
     */
    private static function hiddenFromState(array $state, int $latentDim): array
    {
        $hidden = is_array($state['activations']['hidden'] ?? null) ? $state['activations']['hidden'] : [];
        if ($hidden === []) {
            $hidden = array_fill(0, $latentDim, 0.0);
        }
        if (count($hidden) < $latentDim) {
            $hidden = array_merge($hidden, array_fill(0, $latentDim - count($hidden), 0.0));
        } elseif (count($hidden) > $latentDim) {
            $hidden = array_slice($hidden, 0, $latentDim);
        }

        return array_map(static fn($v): float => (float) $v, $hidden);
    }

    /**
     * @return array{0:int,1:int}
     */
    private static function layerRangeFromParams(array $params): array
    {
        $start = null;
        $end = null;

        if (is_int($params['layer_start'] ?? null)) {
            $start = max(0, (int) $params['layer_start']);
        }

        if (is_int($params['layer_end'] ?? null)) {
            $end = max(0, (int) $params['layer_end']);
        }

        if (is_array($params['layers'] ?? null)) {
            if ($start === null && isset($params['layers'][0])) {
                $start = max(0, (int) $params['layers'][0]);
            }
            if ($end === null && isset($params['layers'][1])) {
                $end = max(0, (int) $params['layers'][1]);
            }
        }

        if ($start === null) {
            $start = 0;
        }
        if ($end === null) {
            $end = $start;
        }
        if ($end < $start) {
            $end = $start;
        }

        return [$start, $end];
    }

    /**
     * @param array<int,float> $source
     * @param array<int,int> $indices
     * @return array<int,float>
     */
    private static function sampleVector(array $source, array $indices): array
    {
        $out = [];
        foreach ($indices as $idx) {
            $out[] = (float) ($source[$idx] ?? 0.0);
        }

        return $out;
    }

    /**
     * @param array<int,int> $rowIndices
     * @param array<int,int> $colIndices
     * @return array<int,array<int,float>>
     */
    private static function sampledMatrix(
        GgufTensorLoader $loader,
        string $tensor,
        array $rowIndices,
        array $colIndices
    ): array {
        $mat = [];
        foreach ($rowIndices as $rowIdx) {
            $row = $loader->readRow($tensor, $rowIdx);
            $mat[] = self::sampleVector($row, $colIndices);
        }

        return $mat;
    }

    /**
     * @param array<int,int> $indices
     * @return array<int,float>
     */
    private static function rmsNorm(array $x, array $weights, float $eps): array
    {
        $n = max(1, count($x));
        $mean = 0.0;
        foreach ($x as $v) {
            $mean += $v * $v;
        }
        $mean /= $n;

        $inv = 1.0 / sqrt($mean + $eps);
        $out = [];
        foreach ($x as $i => $v) {
            $w = (float) ($weights[$i] ?? 1.0);
            $out[] = $v * $inv * $w;
        }

        return $out;
    }

    /**
     * @param array<int,float> $q
     * @param array<int,float> $k
     */
    private static function applyRope(array &$q, array &$k, int $pos): void
    {
        $dim = min(count($q), count($k));
        if ($dim < 2) {
            return;
        }

        for ($i = 0; $i + 1 < $dim; $i += 2) {
            $theta = $pos / (10000.0 ** (($i / max(2, $dim))));
            $c = cos($theta);
            $s = sin($theta);

            $q0 = $q[$i];
            $q1 = $q[$i + 1];
            $k0 = $k[$i];
            $k1 = $k[$i + 1];

            $q[$i] = $q0 * $c - $q1 * $s;
            $q[$i + 1] = $q0 * $s + $q1 * $c;
            $k[$i] = $k0 * $c - $k1 * $s;
            $k[$i + 1] = $k0 * $s + $k1 * $c;
        }
    }

    /** @param array<int,float> $scores */
    private static function softmax(array $scores): array
    {
        if ($scores === []) {
            return [];
        }

        $max = max($scores);
        $exps = [];
        $sum = 0.0;
        foreach ($scores as $score) {
            $e = exp($score - $max);
            $exps[] = $e;
            $sum += $e;
        }

        if ($sum <= 0.0) {
            $uniform = 1.0 / count($scores);
            return array_fill(0, count($scores), $uniform);
        }

        return array_map(static fn($e): float => $e / $sum, $exps);
    }

    /** @param array<int,float> $a @param array<int,float> $b */
    private static function dot(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        $sum = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sum += $a[$i] * $b[$i];
        }
        return $sum;
    }

    /** @param array<int,array<int,float>> $mat @param array<int,float> $vec @return array<int,float> */
    private static function matVec(array $mat, array $vec): array
    {
        $out = [];
        foreach ($mat as $row) {
            $out[] = self::dot($row, $vec);
        }
        return $out;
    }

    /** @param array<int,float> $a @param array<int,float> $b @return array<int,float> */
    private static function vecAdd(array $a, array $b): array
    {
        $n = max(count($a), count($b));
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = (float) ($a[$i] ?? 0.0) + (float) ($b[$i] ?? 0.0);
        }
        return $out;
    }

    private static function silu(float $x): float
    {
        return $x / (1.0 + exp(-$x));
    }

    /**
     * @return array<int,float>
     */
    private static function projectTensorRows(GgufTensorLoader $loader, string $tensor, array $input): array
    {
        $native = self::nativeTensorScan($loader, $tensor, $input);
        if (is_array($native)) {
            /** @var array<int,float> $native */
            return $native;
        }

        $rows = $loader->rowCount($tensor);
        $out = [];
        for ($row = 0; $row < $rows; $row++) {
            $out[] = self::tensorRowDot($loader, $tensor, $row, $input);
        }

        return $out;
    }

    private static function tensorRowDot(GgufTensorLoader $loader, string $tensor, int $row, array $input): float
    {
        $native = self::nativeTensorScan($loader, $tensor, $input, [
            'row_start' => $row,
            'row_limit' => 1,
        ]);
        if (is_array($native) && isset($native[0]) && is_numeric($native[0])) {
            return (float) $native[0];
        }

        $weights = $loader->readRow($tensor, $row);
        return self::dot($weights, $input);
    }

    /**
     * @return array<int,array<int,float>>
     */
    private static function splitHeads(array $values, int $headCount, int $headDim): array
    {
        $heads = [];
        for ($head = 0; $head < $headCount; $head++) {
            $offset = $head * $headDim;
            $heads[] = array_slice($values, $offset, $headDim);
        }

        return $heads;
    }

    /**
     * @param array<int,float> $head
     */
    private static function applyRopeToHead(array &$head, int $pos, float $freqBase): void
    {
        $dim = count($head);
        if ($dim < 2) {
            return;
        }

        for ($i = 0; $i + 1 < $dim; $i += 2) {
            $theta = $pos / ($freqBase ** ($i / max(2, $dim)));
            $c = cos($theta);
            $s = sin($theta);

            $x0 = $head[$i];
            $x1 = $head[$i + 1];
            $head[$i] = $x0 * $c - $x1 * $s;
            $head[$i + 1] = $x0 * $s + $x1 * $c;
        }
    }

    /**
     * @return array<int,float>
     */
    private static function topTensorLogits(GgufTensorLoader $loader, string $tensor, array $hidden, int $limit): array
    {
        $limit = max(1, $limit);
        $native = self::nativeTensorScan($loader, $tensor, $hidden, ['top_k' => $limit]);
        if (is_array($native) && $native !== []) {
            $normalized = [];
            foreach ($native as $tokenId => $score) {
                $normalized[(int) $tokenId] = (float) $score;
            }
            arsort($normalized, SORT_NUMERIC);
            return $normalized;
        }

        $best = [];
        $rowCount = $loader->rowCount($tensor);

        for ($row = 0; $row < $rowCount; $row++) {
            $score = self::tensorRowDot($loader, $tensor, $row, $hidden);
            if (count($best) < $limit) {
                $best[$row] = $score;
                asort($best, SORT_NUMERIC);
                continue;
            }

            $minKey = array_key_first($best);
            if ($minKey === null) {
                continue;
            }
            $minScore = $best[$minKey];
            if ($score <= $minScore) {
                continue;
            }

            unset($best[$minKey]);
            $best[$row] = $score;
            asort($best, SORT_NUMERIC);
        }

        arsort($best, SORT_NUMERIC);
        return $best;
    }

    /**
     * @param array<int,float> $input
     * @param array<string,int>|null $options
     * @return array<int|float,mixed>|null
     */
    private static function nativeTensorScan(
        GgufTensorLoader $loader,
        string $tensor,
        array $input,
        ?array $options = null
    ): ?array {
        if (!function_exists('king_native_gguf_tensor_scan')) {
            return null;
        }

        try {
            $tensorMeta = $loader->nativeTensorMeta($tensor);
            $nativeOptions = [];
            if (is_array($options)) {
                foreach (['row_start', 'row_limit', 'top_k'] as $key) {
                    if (isset($options[$key]) && is_int($options[$key])) {
                        $nativeOptions[$key] = $options[$key];
                    }
                }
            }

            /** @var array<int|float,mixed> $result */
            $result = king_native_gguf_tensor_scan($loader->path(), $tensorMeta, $input, $nativeOptions);
            return is_array($result) ? $result : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<int,float> $logits token_id => score
     */
    private static function sampleFromLogits(array $logits, float $temperature, int $topK, float $topP): int
    {
        if ($logits === []) {
            return 0;
        }

        arsort($logits, SORT_NUMERIC);

        if ($topK > 0 && count($logits) > $topK) {
            $logits = array_slice($logits, 0, $topK, true);
        }

        $scaled = [];
        $max = -INF;
        foreach ($logits as $tokenId => $score) {
            $v = $score / max(0.01, $temperature);
            $scaled[(int) $tokenId] = $v;
            if ($v > $max) {
                $max = $v;
            }
        }

        $expScores = [];
        $sum = 0.0;
        foreach ($scaled as $tokenId => $score) {
            $e = exp($score - $max);
            $expScores[$tokenId] = $e;
            $sum += $e;
        }

        if ($sum <= 0.0) {
            $first = array_key_first($logits);
            return is_int($first) ? $first : (int) $first;
        }

        $probs = [];
        foreach ($expScores as $tokenId => $e) {
            $probs[(int) $tokenId] = $e / $sum;
        }

        if ($topP > 0.0 && $topP < 1.0) {
            arsort($probs, SORT_NUMERIC);
            $acc = 0.0;
            $trimmed = [];
            foreach ($probs as $tokenId => $p) {
                $trimmed[(int) $tokenId] = $p;
                $acc += $p;
                if ($acc >= $topP) {
                    break;
                }
            }
            $renorm = array_sum($trimmed);
            if ($renorm > 0.0) {
                foreach ($trimmed as $tokenId => $p) {
                    $trimmed[$tokenId] = $p / $renorm;
                }
                $probs = $trimmed;
            }
        }

        $r = mt_rand() / mt_getrandmax();
        $acc = 0.0;
        foreach ($probs as $tokenId => $p) {
            $acc += $p;
            if ($r <= $acc) {
                return (int) $tokenId;
            }
        }

        $last = array_key_last($probs);
        return is_int($last) ? $last : (int) $last;
    }

    /**
     * @param array<int,string> $keys
     */
    private static function metadataInt(GgufTensorLoader $loader, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = $loader->metadata($key, null);
            if (is_int($value)) {
                return $value;
            }
            if (is_float($value)) {
                return (int) $value;
            }
        }
        return null;
    }

    /**
     * @param array<int,string> $keys
     */
    private static function metadataFloat(GgufTensorLoader $loader, array $keys, float $default): float
    {
        foreach ($keys as $key) {
            $value = $loader->metadata($key, null);
            if (is_float($value) || is_int($value)) {
                return (float) $value;
            }
        }

        return $default;
    }

    /**
     * @param array<int,int> $indices
     */
    private static function sampleIndices(int $sourceDim, int $targetDim): array
    {
        $sourceDim = max(1, $sourceDim);
        $targetDim = max(1, min($targetDim, $sourceDim));

        $out = [];
        for ($i = 0; $i < $targetDim; $i++) {
            $idx = (int) floor(($i * $sourceDim) / $targetDim);
            if ($idx >= $sourceDim) {
                $idx = $sourceDim - 1;
            }
            $out[] = $idx;
        }

        return $out;
    }
}
