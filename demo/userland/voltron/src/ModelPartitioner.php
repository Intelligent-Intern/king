<?php
declare(strict_types=1);

namespace King\Voltron;

use InvalidArgumentException;

final class ModelPartitioner
{
    /**
     * @param array{type:string,name:string,total_params:int,quantization:string,block_schema:array<int,array{id:string,type:string,layers:array{int,int},memory_mb:int,dependencies:array<string>},model_source?:string,checkpoints?:array<int,string>}|ModelConfig $modelConfig
     * @param array<string,array{max_memory_mb:int,capabilities:array<string>}> $nodeCapabilities node_id => capability specs
     * @return array{steps:array<int,array<string,mixed>>,block_assignments:array<string,string>,run_config:array{model_name:string,total_blocks:int,estimated_memory_mb:int}}
     */
    public static function partition(
        array $modelConfig,
        array $nodeCapabilities
    ): array {
        if ($modelConfig instanceof ModelConfig) {
            $config = $modelConfig->gemma2B();
        } else {
            $config = $modelConfig;
        }

        ModelConfig::assertValid($config);

        $blockSchema = $config['block_schema'];
        $sortedBlocks = self::topologicalSort($blockSchema);

        $assignments = [];
        $steps = [];

        foreach ($sortedBlocks as $block) {
            $blockId = $block['id'];
            $memoryMb = $block['memory_mb'];
            $type = $block['type'];
            $deps = $block['dependencies'] ?? [];

            $assignedNode = self::findBestNode($nodeCapabilities, $memoryMb, $type);
            if ($assignedNode === null) {
                throw new InvalidArgumentException('No node available for block ' . $blockId . ' requires ' . $memoryMb . 'MB');
            }

            $assignments[$blockId] = $assignedNode;

            $stepId = 'voltron.execute_block.' . $blockId;
            $inputDep = $deps[0] ?? null;
            $inputArtifact = $inputDep !== null
                ? 'object://activations/' . $config['name'] . '/' . $inputDep . '/output'
                : ($config['model_source'] ?? 'object://');
            $outputArtifact = 'object://activations/' . $config['name'] . '/' . $blockId . '/output';

            $steps[] = [
                'id' => $stepId,
                'tool' => 'voltron.execute_model_block',
                'deps' => $inputDep !== null ? ['voltron.execute_block.' . $inputDep] : [],
                'params' => [
                    'run_id' => 'voltron-' . $config['name'],
                    'model_name' => $config['name'],
                    'model_source' => $config['model_source'] ?? null,
                    'block_id' => $blockId,
                    'block_type' => $type,
                    'layers' => $block['layers'],
                    'layer_start' => $block['layers'][0],
                    'layer_end' => $block['layers'][1],
                    'owner_node_id' => $assignedNode,
                    'memory_mb' => $memoryMb,
                    'input_artifact' => $inputArtifact,
                    'output_artifact' => $outputArtifact,
                    'checkpoint_artifact' => self::findCheckpoint($config, $block['layers']),
                ],
            ];
        }

        $steps[] = [
            'id' => 'voltron.emit_final.' . $config['name'],
            'tool' => 'voltron.emit_final',
            'deps' => ['voltron.execute_block.' . end($sortedBlocks)['id']],
            'params' => [
                'run_id' => 'voltron-' . $config['name'],
                'model_name' => $config['name'],
            ],
        ];

        $totalMemory = array_sum(array_column($blockSchema, 'memory_mb'));

        return [
            'steps' => $steps,
            'block_assignments' => $assignments,
            'run_config' => [
                'model_name' => $config['name'],
                'total_blocks' => count($blockSchema),
                'estimated_memory_mb' => $totalMemory,
            ],
        ];
    }

    /**
     * @param array<string,array{max_memory_mb:int,capabilities:array<string>}> $nodeCapabilities
     * @return string|null
     */
    private static function findBestNode(array $nodeCapabilities, int $requiredMb, string $blockType): ?string
    {
        $candidates = [];

        foreach ($nodeCapabilities as $nodeId => $caps) {
            $maxMemory = $caps['max_memory_mb'] ?? PHP_INT_MAX;
            $nodeCaps = $caps['capabilities'] ?? [];

            if ($maxMemory < $requiredMb) {
                continue;
            }

            $score = 0;
            if (in_array('model_inference', $nodeCaps, true)) {
                $score += 10;
            }
            if ($blockType === ModelConfig::TYPE_EMBED && in_array('embedding', $nodeCaps, true)) {
                $score += 5;
            }
            if ($blockType === ModelConfig::TYPE_ATTENTION && in_array('attention', $nodeCaps, true)) {
                $score += 5;
            }
            if ($blockType === ModelConfig::TYPE_OUTPUT_HEAD && in_array('output_head', $nodeCaps, true)) {
                $score += 5;
            }

            $candidates[] = ['node_id' => $nodeId, 'score' => $score, 'memory' => $maxMemory];
        }

        if ($candidates === []) {
            foreach ($nodeCapabilities as $nodeId => $caps) {
                $maxMemory = $caps['max_memory_mb'] ?? PHP_INT_MAX;
                if ($maxMemory >= $requiredMb) {
                    return $nodeId;
                }
            }
            return null;
        }

        usort($candidates, function($a, $b) {
            return $b['score'] <=> $a['score'] ?: $b['memory'] <=> $a['memory'];
        });

        return $candidates[0]['node_id'] ?? null;
    }

    /**
     * @param array{id:string,type:string,layers:array{int,int},memory_mb:int,dependencies:array<string>} $blockSchema
     * @return array<int,array{id:string,type:string,layers:array{int,int},memory_mb:int,dependencies:array<string>}>
     */
    private static function topologicalSort(array $blockSchema): array
    {
        $inDegree = [];
        $adjacency = [];

        foreach ($blockSchema as $block) {
            $blockId = $block['id'];
            $inDegree[$blockId] = count($block['dependencies'] ?? []);
            $adjacency[$blockId] = $block['dependencies'] ?? [];
        }

        $queue = [];
        foreach ($inDegree as $id => $degree) {
            if ($degree === 0) {
                $queue[] = $id;
            }
        }

        $sorted = [];
        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($blockSchema as $block) {
                if (in_array($current, $block['dependencies'] ?? [], true)) {
                    $inDegree[$block['id']]--;
                    if ($inDegree[$block['id']] === 0) {
                        $queue[] = $block['id'];
                    }
                }
            }
            $sorted[] = $blockSchema[array_search($current, array_column($blockSchema, 'id'))];
        }

        if (count($sorted) !== count($blockSchema)) {
            throw new InvalidArgumentException('Cycle detected in block schema');
        }

        return $sorted;
    }

    /**
     * @param array{type:string,name:string,total_params:int,quantization:string,block_schema:array<int,array{id:string,type:string,layers:array{int,int},memory_mb:int,dependencies:array<string>},model_source?:string,checkpoints?:array<int,string>} $config
     * @param array{int,int} $layers
     * @return string|null
     */
    private static function findCheckpoint(array $config, array $layers): ?string
    {
        $checkpoints = $config['checkpoints'] ?? [];
        if ($checkpoints === []) {
            return null;
        }

        $startLayer = $layers[0];
        $checkpointIndex = (int) floor($startLayer / 5);

        return $checkpoints[$checkpointIndex] ?? null;
    }
}
