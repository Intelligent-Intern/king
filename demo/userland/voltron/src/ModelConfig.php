<?php
declare(strict_types=1);

namespace King\Voltron;

use InvalidArgumentException;
use RuntimeException;

final class ModelConfig
{
    public const TYPE_EMBED = 'embed';
    public const TYPE_ATTENTION = 'attention';
    public const TYPE_FFN = 'ffn';
    public const TYPE_OUTPUT_HEAD = 'output_head';
    public const TYPE_FULL_MODEL = 'full_model';

    public const QUANT_NONE = 'none';
    public const QUANT_FP16 = 'fp16';
    public const QUANT_Q4_K = 'q4_k';
    public const QUANT_Q8_0 = 'q8_0';

    /**
     * @param array{type:string,name:string,total_params:int,quantization:string,block_schema:array<int,array{id:string,type:string,layers:array{int,int},memory_mb:int,dependencies:array<string>}>} $config
     * @throws InvalidArgumentException
     */
    public static function assertValid(array $config): void
    {
        $type = $config['type'] ?? '';
        if (!is_string($type) || $type === '') {
            throw new InvalidArgumentException('model_config.type must be non-empty string.');
        }

        $name = $config['name'] ?? '';
        if (!is_string($name) || $name === '') {
            throw new InvalidArgumentException('model_config.name must be non-empty string.');
        }

        $totalParams = $config['total_params'] ?? 0;
        if (!is_int($totalParams) || $totalParams <= 0) {
            throw new InvalidArgumentException('model_config.total_params must be positive integer.');
        }

        $quantization = $config['quantization'] ?? self::QUANT_NONE;
        if (!in_array($quantization, [self::QUANT_NONE, self::QUANT_FP16, self::QUANT_Q4_K, self::QUANT_Q8_0], true)) {
            throw new InvalidArgumentException('model_config.quantization must be one of: none, fp16, q4_k, q8_0.');
        }

        $blockSchema = $config['block_schema'] ?? [];
        if (!is_array($blockSchema) || $blockSchema === []) {
            throw new InvalidArgumentException('model_config.block_schema must be non-empty array.');
        }

        foreach ($blockSchema as $block) {
            self::assertValidBlock($block);
        }

        self::assertBlockDependencies($blockSchema);
    }

    /**
     * @param array{id:string,type:string,layers:array{int,int},memory_mb:int,dependencies:array<string>} $block
     * @throws InvalidArgumentException
     */
    private static function assertValidBlock(array $block): void
    {
        $id = $block['id'] ?? '';
        if (!is_string($id) || $id === '') {
            throw new InvalidArgumentException('block.id must be non-empty string.');
        }

        $type = $block['type'] ?? '';
        if (!in_array($type, [self::TYPE_EMBED, self::TYPE_ATTENTION, self::TYPE_FFN, self::TYPE_OUTPUT_HEAD, self::TYPE_FULL_MODEL], true)) {
            throw new InvalidArgumentException('block.type must be embed|attention|ffn|output_head|full_model.');
        }

        $layers = $block['layers'] ?? null;
        if (!is_array($layers) || count($layers) !== 2) {
            throw new InvalidArgumentException('block.layers must be [start, end] array.');
        }
        if ($layers[0] < 0 || $layers[1] < $layers[0]) {
            throw new InvalidArgumentException('block.layers must be [start, end] with end >= start.');
        }

        $memoryMb = $block['memory_mb'] ?? 0;
        if (!is_int($memoryMb) || $memoryMb <= 0) {
            throw new InvalidArgumentException('block.memory_mb must be positive integer.');
        }
    }

    /**
     * @param array<int,array{id:string,type:string,layers:array{int,int},memory_mb:int,dependencies:array<string>}> $blockSchema
     * @throws InvalidArgumentException
     */
    private static function assertBlockDependencies(array $blockSchema): void
    {
        $blockIds = array_column($blockSchema, 'id');

        foreach ($blockSchema as $block) {
            $deps = $block['dependencies'] ?? [];
            if (!is_array($deps)) {
                continue;
            }
            foreach ($deps as $dep) {
                if (!in_array($dep, $blockIds, true)) {
                    throw new InvalidArgumentException('block.id "' . $block['id'] . '" depends on unknown block: ' . $dep);
                }
            }
        }

        if (self::hasCycle($blockSchema)) {
            throw new InvalidArgumentException('block_schema contains dependency cycle.');
        }
    }

    /**
     * @param array<int,array{id:string,type:string,layers:array{int,int},memory_mb:int,dependencies:array<string>}> $blockSchema
     */
    private static function hasCycle(array $blockSchema): bool
    {
        $adjacency = [];
        $allNodes = [];
        foreach ($blockSchema as $block) {
            $id = $block['id'];
            $deps = $block['dependencies'] ?? [];
            $allNodes[] = $id;
            foreach ($deps as $dep) {
                $adjacency[$dep][] = $id;
            }
        }

        $visited = [];
        $recursionStack = [];

        $dfs = function(string $node) use (&$dfs, &$adjacency, &$visited, &$recursionStack): bool {
            $visited[$node] = true;
            $recursionStack[$node] = true;

            foreach ($adjacency[$node] ?? [] as $dependent) {
                if (!isset($visited[$dependent])) {
                    if ($dfs($dependent)) {
                        return true;
                    }
                } elseif (isset($recursionStack[$dependent])) {
                    return true;
                }
            }

            $recursionStack[$node] = false;
            return false;
        };

        foreach ($allNodes as $id) {
            if (!isset($visited[$id])) {
                if ($dfs($id)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{type:string,name:string,total_params:int,quantization:string,block_schema:array<int,array{id:string,type:string,layers:array{int,int},memory_mb:int,dependencies:array<string>},model_source?:string,checkpoints?:array<int,string>}
     */
    public static function gemma2B(): array
    {
        return [
            'type' => self::TYPE_FULL_MODEL,
            'name' => 'gemma2B',
            'total_params' => 2000000000,
            'quantization' => self::QUANT_FP16,
            'model_source' => 'object://models/gemma-2b-it fp16',
            'checkpoints' => [
                'object://checkpoints/gemma2b/layer-0-4',
                'object://checkpoints/gemma2b/layer-5-9',
                'object://checkpoints/gemma2b/layer-10-14',
                'object://checkpoints/gemma2b/layer-15-17',
            ],
            'block_schema' => [
                [
                    'id' => 'embed',
                    'type' => self::TYPE_EMBED,
                    'layers' => [0, 0],
                    'memory_mb' => 128,
                    'dependencies' => [],
                ],
                [
                    'id' => 'attention_1',
                    'type' => self::TYPE_ATTENTION,
                    'layers' => [1, 5],
                    'memory_mb' => 512,
                    'dependencies' => ['embed'],
                ],
                [
                    'id' => 'ffn_1',
                    'type' => self::TYPE_FFN,
                    'layers' => [1, 5],
                    'memory_mb' => 768,
                    'dependencies' => ['attention_1'],
                ],
                [
                    'id' => 'attention_2',
                    'type' => self::TYPE_ATTENTION,
                    'layers' => [6, 10],
                    'memory_mb' => 512,
                    'dependencies' => ['ffn_1'],
                ],
                [
                    'id' => 'ffn_2',
                    'type' => self::TYPE_FFN,
                    'layers' => [6, 10],
                    'memory_mb' => 768,
                    'dependencies' => ['attention_2'],
                ],
                [
                    'id' => 'attention_3',
                    'type' => self::TYPE_ATTENTION,
                    'layers' => [11, 14],
                    'memory_mb' => 512,
                    'dependencies' => ['ffn_2'],
                ],
                [
                    'id' => 'ffn_3',
                    'type' => self::TYPE_FFN,
                    'layers' => [11, 14],
                    'memory_mb' => 768,
                    'dependencies' => ['attention_3'],
                ],
                [
                    'id' => 'attention_4',
                    'type' => self::TYPE_ATTENTION,
                    'layers' => [15, 17],
                    'memory_mb' => 384,
                    'dependencies' => ['ffn_3'],
                ],
                [
                    'id' => 'ffn_4',
                    'type' => self::TYPE_FFN,
                    'layers' => [15, 17],
                    'memory_mb' => 512,
                    'dependencies' => ['attention_4'],
                ],
                [
                    'id' => 'output_head',
                    'type' => self::TYPE_OUTPUT_HEAD,
                    'layers' => [17, 17],
                    'memory_mb' => 64,
                    'dependencies' => ['ffn_4'],
                ],
            ],
        ];
    }

    /**
     * @return array{type:string,name:string,total_params:int,quantization:string,block_schema:array<int,array{id:string,type:string,layers:array{int,int},memory_mb:int,dependencies:array<string>},model_source?:string}
     */
    public static function gemma7B(): array
    {
        return [
            'type' => self::TYPE_FULL_MODEL,
            'name' => 'gemma7B',
            'total_params' => 7000000000,
            'quantization' => self::QUANT_FP16,
            'model_source' => 'object://models/gemma-7b-it fp16',
            'block_schema' => [
                [
                    'id' => 'embed',
                    'type' => self::TYPE_EMBED,
                    'layers' => [0, 0],
                    'memory_mb' => 256,
                    'dependencies' => [],
                ],
                [
                    'id' => 'attention_block_1',
                    'type' => self::TYPE_ATTENTION,
                    'layers' => [1, 7],
                    'memory_mb' => 1024,
                    'dependencies' => ['embed'],
                ],
                [
                    'id' => 'ffn_block_1',
                    'type' => self::TYPE_FFN,
                    'layers' => [1, 7],
                    'memory_mb' => 1536,
                    'dependencies' => ['attention_block_1'],
                ],
                [
                    'id' => 'attention_block_2',
                    'type' => self::TYPE_ATTENTION,
                    'layers' => [8, 14],
                    'memory_mb' => 1024,
                    'dependencies' => ['ffn_block_1'],
                ],
                [
                    'id' => 'ffn_block_2',
                    'type' => self::TYPE_FFN,
                    'layers' => [8, 14],
                    'memory_mb' => 1536,
                    'dependencies' => ['attention_block_2'],
                ],
                [
                    'id' => 'attention_block_3',
                    'type' => self::TYPE_ATTENTION,
                    'layers' => [15, 21],
                    'memory_mb' => 1024,
                    'dependencies' => ['ffn_block_2'],
                ],
                [
                    'id' => 'ffn_block_3',
                    'type' => self::TYPE_FFN,
                    'layers' => [15, 21],
                    'memory_mb' => 1536,
                    'dependencies' => ['attention_block_3'],
                ],
                [
                    'id' => 'attention_block_4',
                    'type' => self::TYPE_ATTENTION,
                    'layers' => [22, 27],
                    'memory_mb' => 896,
                    'dependencies' => ['ffn_block_3'],
                ],
                [
                    'id' => 'ffn_block_4',
                    'type' => self::TYPE_FFN,
                    'layers' => [22, 27],
                    'memory_mb' => 1280,
                    'dependencies' => ['attention_block_4'],
                ],
                [
                    'id' => 'output_head',
                    'type' => self::TYPE_OUTPUT_HEAD,
                    'layers' => [27, 27],
                    'memory_mb' => 128,
                    'dependencies' => ['ffn_block_4'],
                ],
            ],
        ];
    }
}