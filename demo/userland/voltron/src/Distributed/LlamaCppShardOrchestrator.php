<?php
declare(strict_types=1);

namespace King\Voltron\Distributed;

require_once dirname(__DIR__) . '/GgufTensorLoader.php';
require_once dirname(__DIR__) . '/VoltronTokenizer.php';
require_once dirname(__DIR__) . '/VoltronNativeLayerWorker.php';

use King\Voltron\GgufTensorLoader;
use King\Voltron\VoltronNativeLayerWorker;
use King\Voltron\VoltronTokenizer;
use RuntimeException;

final class LlamaCppShardOrchestrator
{
    private string $modelPath;
    private string $llamaServerPath;
    private int $totalLayers;
    private int $shardCount;
    /** @var array<int,int> */
    private array $shardPorts = [];
    private GgufTensorLoader $loader;
    private VoltronTokenizer $tokenizer;
    /** @var array<string,mixed> */
    private array $runtime;
    /** @var array<string,mixed> */
    private array $kvCache = [];

    private const BASE_PORT = 9700;

    public function __construct(
        string $modelPath = '',
        string $llamaServerPath = '/tmp/llama.cpp/build/bin/llama-server',
        int $shardCount = 6
    ) {
        if ($modelPath === '') {
            $modelPath = (string) (getenv('VOLTRON_GGUF_PATH') ?: '/Users/sasha/qwen2.5-coder-3b-Q4_K.gguf');
        }

        $this->modelPath = $modelPath;
        $this->llamaServerPath = $llamaServerPath;
        $this->shardCount = max(1, $shardCount);
        $this->loader = GgufTensorLoader::fromParams(['gguf_path' => $modelPath]);
        $this->runtime = $this->buildRuntime();
        $this->totalLayers = (int) ($this->loader->metadata('qwen2.block_count', $this->loader->metadata('llama.block_count', 36)));

        $this->tokenizer = new VoltronTokenizer(
            $this->loader->tokenizerTokens(),
            $this->loader->tokenizerTypes(),
            $this->loader->tokenizerScores(),
            $this->loader->tokenizerMerges(),
            $this->loader->tokenizerModel(),
            $this->loader->tokenizerPre(),
            $this->metadataInt(['tokenizer.ggml.bos_token_id', 'tokenizer.ggml.bos_id']),
            $this->metadataInt(['tokenizer.ggml.eos_token_id', 'tokenizer.ggml.eos_id']),
            $this->metadataInt(['tokenizer.ggml.unknown_token_id', 'tokenizer.ggml.unk_token_id', 'tokenizer.ggml.unk_id'])
        );

        for ($i = 0; $i < $this->shardCount; $i++) {
            $this->shardPorts[$i] = self::BASE_PORT + $i;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function getShardInfo(): array
    {
        $layersPerShard = (int) ceil($this->totalLayers / $this->shardCount);
        $shards = [];

        for ($i = 0; $i < $this->shardCount; $i++) {
            $layerStart = $i * $layersPerShard;
            $layerEnd = min($this->totalLayers - 1, $layerStart + $layersPerShard - 1);
            if ($layerStart > $layerEnd) {
                break;
            }

            $shards[] = [
                'index' => $i,
                'layer_start' => $layerStart,
                'layer_end' => $layerEnd,
                'port' => $this->shardPorts[$i],
            ];
        }

        return [
            'model_path' => $this->modelPath,
            'total_layers' => $this->totalLayers,
            'shard_count' => count($shards),
            'layers_per_shard' => $layersPerShard,
            'shards' => $shards,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function spawnShardServers(): array
    {
        foreach ($this->getShardInfo()['shards'] as $shard) {
            $cmd = sprintf(
                '%s -m %s -c 2048 -ngl 0 --port %d 2>&1 > /tmp/llama-server-%d.log &',
                escapeshellarg($this->llamaServerPath),
                escapeshellarg($this->modelPath),
                $this->shardPorts[(int) $shard['index']],
                (int) $shard['index']
            );

            exec($cmd);
            usleep(100000);
        }

        sleep(3);
        return $this->healthCheck();
    }

    public function killShardServers(): void
    {
        exec('pkill -f "llama-server" 2>/dev/null');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function healthCheck(): array
    {
        $results = [];

        foreach ($this->getShardInfo()['shards'] as $shard) {
            $index = (int) $shard['index'];
            $port = (int) $shard['port'];
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);

            if ($socket !== false) {
                $results[$index] = ['status' => 'up', 'port' => $port];
                fclose($socket);
                continue;
            }

            $results[$index] = ['status' => 'down', 'error' => $errstr];
        }

        return $results;
    }

    /**
     * @return array{prompt:string,output:string,tokens:array<int,int>}
     */
    public function generate(string $prompt, int $maxTokens = 20): array
    {
        $formattedPrompt = $this->tokenizer->formatPrompt($prompt);
        $promptTokens = $this->tokenizer->encode($formattedPrompt, false);
        if ($promptTokens === []) {
            $promptTokens[] = $this->tokenizer->bosId() ?? 0;
        }

        $this->kvCache = [];
        $hidden = [];

        foreach ($promptTokens as $position => $tokenId) {
            $hidden = $this->embed((int) $tokenId);
            $hidden = $this->forwardAllShards($hidden, $position);
        }

        $generated = [];
        for ($i = 0; $i < $maxTokens; $i++) {
            $nextToken = $this->sample($hidden);
            $generated[] = $nextToken;

            $eos = $this->tokenizer->eosId();
            if (is_int($eos) && $nextToken === $eos) {
                break;
            }

            $position = count($promptTokens) + count($generated) - 1;
            $hidden = $this->embed($nextToken);
            $hidden = $this->forwardAllShards($hidden, $position);
        }

        return [
            'prompt' => $prompt,
            'output' => $this->tokenizer->decode($generated),
            'tokens' => $generated,
        ];
    }

    /**
     * @return array<int,float>
     */
    private function embed(int $tokenId): array
    {
        $tensor = (string) $this->runtime['embed_tensor'];
        $rowCount = $this->loader->rowCount($tensor);

        if ($tokenId < 0 || $tokenId >= $rowCount) {
            $tokenId = max(0, min($rowCount - 1, $tokenId));
        }

        return $this->loader->readRow($tensor, $tokenId);
    }

    /**
     * @param array<int,float> $hidden
     * @return array<int,float>
     */
    private function forwardAllShards(array $hidden, int $position): array
    {
        foreach ($this->getShardInfo()['shards'] as $shard) {
            $result = VoltronNativeLayerWorker::forwardLayers(
                $this->loader,
                $this->runtime,
                $hidden,
                $this->kvCache,
                $position,
                (int) $shard['layer_start'],
                (int) $shard['layer_end']
            );

            if (!is_array($result)) {
                throw new RuntimeException('Native Voltron layer worker is unavailable or failed.');
            }

            $hidden = is_array($result['hidden'] ?? null) ? $result['hidden'] : $hidden;
            $this->kvCache = is_array($result['kv_cache'] ?? null) ? $result['kv_cache'] : $this->kvCache;
        }

        return $hidden;
    }

    private function sample(array $hidden): int
    {
        if (is_string($this->runtime['output_norm_tensor'] ?? null) && $this->runtime['output_norm_tensor'] !== '') {
            $norm = $this->loader->readRow((string) $this->runtime['output_norm_tensor'], 0);
            $hidden = $this->rmsNorm($hidden, $norm, (float) $this->runtime['rms_eps']);
        }

        $outputTensor = (string) $this->runtime['output_tensor'];
        $tensorMeta = $this->loader->nativeTensorMeta($outputTensor);

        if (function_exists('king_native_gguf_tensor_scan')) {
            /** @var array<int,float> $top */
            $top = king_native_gguf_tensor_scan(
                $this->loader->path(),
                $tensorMeta,
                $hidden,
                ['top_k' => 1]
            );
            $tokenId = array_key_first($top);
            if ($tokenId !== null) {
                return (int) $tokenId;
            }
        }

        $bestToken = 0;
        $bestScore = -INF;
        $rowCount = $this->loader->rowCount($outputTensor);
        for ($token = 0; $token < $rowCount; $token++) {
            $row = $this->loader->readRow($outputTensor, $token);
            $score = $this->dot($hidden, $row);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestToken = $token;
            }
        }

        return $bestToken;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildRuntime(): array
    {
        $hiddenDim = $this->metadataInt(['qwen2.embedding_length', 'llama.embedding_length']);
        if (!is_int($hiddenDim) || $hiddenDim <= 0) {
            $hiddenDim = $this->loader->ne0($this->resolveTensor([
                'token_embd.weight',
                'tok_embeddings.weight',
                'model.embed_tokens.weight',
                'transformer.wte.weight',
            ]));
        }

        $headCount = $this->metadataInt(['qwen2.attention.head_count', 'llama.attention.head_count']) ?? 1;
        $headCountKv = $this->metadataInt(['qwen2.attention.head_count_kv', 'llama.attention.head_count_kv']) ?? $headCount;
        $architecture = $this->metadataString(['general.architecture']) ?? '';

        return [
            'hidden_dim' => $hiddenDim,
            'head_count' => max(1, $headCount),
            'head_count_kv' => max(1, $headCountKv),
            'head_dim' => max(1, intdiv($hiddenDim, max(1, $headCount))),
            'architecture' => $architecture,
            'rope_freq_base' => $this->metadataFloat(['qwen2.rope.freq_base', 'llama.rope.freq_base'], 10000.0),
            'rope_type' => $this->inferRopeType($architecture),
            'rms_eps' => $this->metadataFloat(
                ['qwen2.attention.layer_norm_rms_epsilon', 'llama.attention.layer_norm_rms_epsilon'],
                1e-5
            ),
            'embed_tensor' => $this->resolveTensor([
                'token_embd.weight',
                'tok_embeddings.weight',
                'model.embed_tokens.weight',
                'transformer.wte.weight',
            ]),
            'output_tensor' => $this->resolveTensor([
                'output.weight',
                'lm_head.weight',
                'token_embd.weight',
            ]),
            'output_norm_tensor' => $this->loader->findTensor([
                'output_norm.weight',
                'model.norm.weight',
                'norm.weight',
            ]),
        ];
    }

    /** @param array<int,string> $candidates */
    private function resolveTensor(array $candidates): string
    {
        $name = $this->loader->findTensor($candidates);
        if (!is_string($name) || $name === '') {
            throw new RuntimeException('Missing GGUF tensor. Tried: ' . implode(', ', $candidates));
        }

        return $name;
    }

    /** @param array<int,string> $keys */
    private function metadataInt(array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = $this->loader->metadata($key);
            if (is_int($value)) {
                return $value;
            }
            if (is_float($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    /** @param array<int,string> $keys */
    private function metadataFloat(array $keys, float $default): float
    {
        foreach ($keys as $key) {
            $value = $this->loader->metadata($key);
            if (is_float($value) || is_int($value)) {
                return (float) $value;
            }
        }

        return $default;
    }

    /** @param array<int,string> $keys */
    private function metadataString(array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->loader->metadata($key);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function inferRopeType(string $architecture): int
    {
        $rawType = $this->loader->metadata('rope.type');
        if (is_int($rawType)) {
            return $rawType;
        }
        if (is_float($rawType)) {
            return (int) $rawType;
        }
        if (is_string($rawType) && $rawType !== '') {
            $normalized = strtolower(trim($rawType));
            if ($normalized === 'neox') {
                return 2;
            }
            if ($normalized === 'normal' || $normalized === 'norm') {
                return 0;
            }
        }

        return strtolower($architecture) === 'qwen2' ? 2 : 0;
    }

    /**
     * @param array<int,float> $x
     * @param array<int,float> $weights
     * @return array<int,float>
     */
    private function rmsNorm(array $x, array $weights, float $eps): array
    {
        $mean = 0.0;
        $count = max(1, count($x));
        foreach ($x as $value) {
            $mean += $value * $value;
        }
        $inv = 1.0 / sqrt(($mean / $count) + $eps);

        $out = [];
        foreach ($x as $i => $value) {
            $out[] = $value * $inv * (float) ($weights[$i] ?? 1.0);
        }

        return $out;
    }

    /**
     * @param array<int,float> $left
     * @param array<int,float> $right
     */
    private function dot(array $left, array $right): float
    {
        $count = min(count($left), count($right));
        $sum = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $sum += $left[$i] * $right[$i];
        }

        return $sum;
    }
}

if (php_sapi_name() === 'cli' && basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    echo "=== Distributed Llama.cpp Shard Orchestrator ===\n\n";

    $orchestrator = new LlamaCppShardOrchestrator();
    $info = $orchestrator->getShardInfo();

    echo "Model: {$info['model_path']}\n";
    echo "Total layers: {$info['total_layers']}\n";
    echo "Shard count: {$info['shard_count']}\n";
    echo "Layers per shard: {$info['layers_per_shard']}\n\n";

    foreach ($info['shards'] as $shard) {
        echo "Shard {$shard['index']}: layers {$shard['layer_start']}-{$shard['layer_end']} on port {$shard['port']}\n";
    }

    echo "\nGenerating with native layer worker...\n";
    $result = $orchestrator->generate('2+2', 5);
    echo "Output: {$result['output']}\n";
}
