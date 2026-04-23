<?php
declare(strict_types=1);

namespace King\Voltron;

use InvalidArgumentException;
use RuntimeException;

final class VoltronConnector
{
    private const SERVICE_TYPE = 'KING_SERVICE_TYPE_AI_MODEL';
    private const CAPABILITY_ROLES = ['embedding', 'attention', 'ffn', 'output_head', 'model_inference'];

    private string $nodeId;
    private string $runId;
    private array $modelConfig;
    private array $blockAssignments;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(string $nodeId, string $modelName)
    {
        if ($nodeId === '') {
            throw new InvalidArgumentException('nodeId must be non-empty.');
        }
        if ($modelName === '') {
            throw new InvalidArgumentException('modelName must be non-empty.');
        }

        $this->nodeId = $nodeId;
        $this->modelConfig = self::loadModelConfig($modelName);
        $this->runId = 'voltron-' . $modelName . '-' . $nodeId . '-' . time();
        $this->blockAssignments = [];
    }

    /**
     * @param array{max_memory_mb:int,capabilities:array<string>,location?:array{lat:float,lng:float}} $nodeSpec
     * @throws RuntimeException
     */
    public function registerNode(array $nodeSpec): void
    {
        self::assertSemanticDnsFunctions();

        $capabilities = $nodeSpec['capabilities'] ?? [];
        foreach ($capabilities as $cap) {
            if (!in_array($cap, self::CAPABILITY_ROLES, true)) {
                throw new InvalidArgumentException('Invalid capability: ' . $cap);
            }
        }

        $availableCaps = array_unique(array_merge($capabilities, ['model_inference']));
        $record = [
            'service_id' => $this->nodeId,
            'service_name' => $this->modelConfig['name'] . '-node-' . $this->nodeId,
            'service_type' => self::SERVICE_TYPE,
            'status' => 'KING_SERVICE_STATUS_HEALTHY',
            'capabilities' => $availableCaps,
            'max_memory_mb' => $nodeSpec['max_memory_mb'] ?? 4096,
            'cpu_requirement' => 1.0,
            'max_concurrent_requests' => 1,
            'location' => $nodeSpec['location'] ?? ['lat' => 0.0, 'lng' => 0.0],
        ];

        $result = king_semantic_dns_register_service($record);
        if ($result !== true) {
            throw new RuntimeException('Failed to register node: ' . ($result['error'] ?? 'unknown'));
        }
    }

    /**
     * @param array<string,array{max_memory_mb:int,capabilities:array<string>}> $clusterNodes
     * @return array{steps:array<int,array<string,mixed>>,run_config:array{model_name:string,total_blocks:int,estimated_memory_mb:int,run_id:string}}
     */
    public function buildPipeline(array $clusterNodes): array
    {
        $partition = ModelPartitioner::partition($this->modelConfig, $clusterNodes);
        $this->blockAssignments = $partition['block_assignments'];

        $steps = $partition['steps'];
        foreach ($steps as &$step) {
            $step['params']['run_id'] = $this->runId;
        }
        unset($step);

        $runConfig = $partition['run_config'];
        $runConfig['run_id'] = $this->runId;

        return [
            'steps' => $steps,
            'run_config' => $runConfig,
        ];
    }

    /**
     * Advertise current node as capable via Semantic DNS.
     * Call periodically to keep registration alive.
     *
     * @throws RuntimeException
     */
    public function advertiseCapability(): void
    {
        self::assertSemanticDnsFunctions();

        $result = king_semantic_dns_update_service_status(
            $this->nodeId,
            'KING_SERVICE_STATUS_HEALTHY'
        );

        if ($result !== true) {
            throw new RuntimeException('Failed to advertise: ' . ($result['error'] ?? 'unknown'));
        }
    }

    /**
     * Query Semantic DNS for available model nodes.
     *
     * @return array<int,array{node_id:string,capabilities:array<string>,max_memory_mb:int}>
     */
    public function discoverClusterNodes(): array
    {
        self::assertSemanticDnsFunctions();

        $query = [
            'service_type' => self::SERVICE_TYPE,
            'status' => 'KING_SERVICE_STATUS_HEALTHY',
            'capabilities' => ['model_inference'],
        ];

        $result = king_semantic_dns_query($query);
        if (!is_array($result) || ($result['count'] ?? 0) === 0) {
            return [];
        }

        $nodes = [];
        foreach ($result['services'] ?? [] as $service) {
            $nodes[] = [
                'node_id' => $service['service_id'],
                'capabilities' => $service['capabilities'] ?? [],
                'max_memory_mb' => $service['max_memory_mb'] ?? 4096,
            ];
        }

        return $nodes;
    }

    /**
     * @return array<string,string>
     */
    public function getBlockAssignments(): array
    {
        return $this->blockAssignments;
    }

    public function getRunId(): string
    {
        return $this->runId;
    }

    public function getModelConfig(): array
    {
        return $this->modelConfig;
    }

    /**
     * @throws RuntimeException
     */
    private static function loadModelConfig(string $modelName): array
    {
        $modelName = ucfirst($modelName);
        if (!is_callable([ModelConfig::class, $modelName])) {
            throw new InvalidArgumentException('Unknown model: ' . $modelName);
        }

        $config = call_user_func([ModelConfig::class, $modelName]);
        ModelConfig::assertValid($config);

        return $config;
    }

    private static function assertSemanticDnsFunctions(): void
    {
        $funcs = ['king_semantic_dns_register_service', 'king_semantic_dns_query', 'king_semantic_dns_update_service_status'];
        foreach ($funcs as $func) {
            if (!function_exists($func)) {
                throw new RuntimeException('Required Semantic DNS function unavailable: ' . $func);
            }
        }
    }
}

final class VoltronToolHandlers
{
    /**
     * Registers the Voltron tool handlers with the orchestrator.
     *
     * @throws RuntimeException
     */
    public static function register(): void
    {
        if (!function_exists('king_pipeline_orchestrator_register_handler')) {
            throw new RuntimeException('Orchestrator not available');
        }

        king_pipeline_orchestrator_register_handler('voltron.execute_model_block', [self::class, 'executeModelBlock']);
        king_pipeline_orchestrator_register_handler('voltron.emit_final', [self::class, 'emitFinal']);
    }

    /**
     * @param array<string,mixed> $params
     * @return array{status:string,output_artifact?:array<string,mixed>,runtime_ms:int}
     */
    public static function executeModelBlock(array $params): array
    {
        $start = hrtime(true);

        $blockId = $params['block_id'] ?? '';
        $blockType = $params['block_type'] ?? '';
        $inputArtifact = $params['input_artifact'] ?? '';
        $checkpointArtifact = $params['checkpoint_artifact'] ?? '';

        if ($blockId === '' || $blockType === '') {
            return [
                'status' => 'failed',
                'error' => 'block_id and block_type required',
                'runtime_ms' => (hrtime(true) - $start) / 1_000_000,
            ];
        }

        if (!function_exists('king_object_store_get')) {
            return [
                'status' => 'failed',
                'error' => 'Object store not available',
                'runtime_ms' => (hrtime(true) - $start) / 1_000_000,
            ];
        }

        $inputData = null;
        if ($inputArtifact !== '') {
            $uri = parse_url($inputArtifact);
            $objectId = $uri['path'] ?? '';
            if ($objectId !== '') {
                $inputData = king_object_store_get(ltrim($objectId, '/'));
            }
        }

        $outputData = self::simulateBlockExecution($blockType, $blockId, $inputData);

        $outputArtifact = null;
        if ($outputData !== null) {
            $artifactRef = [
                'artifact_uri' => 'object://activations/voltron/' . $blockId . '/output',
                'object_id' => 'voltron/' . $blockId . '/output',
                'kind' => 'tensor',
                'size_bytes' => strlen($outputData),
                'tensor_meta' => [
                    'shape' => [1, 4096],
                    'dtype' => 'float16',
                    'checksum' => hash('sha256', $outputData),
                ],
            ];
            $outputArtifact = $artifactRef;
        }

        return [
            'status' => 'success',
            'output_artifact' => $outputArtifact,
            'runtime_ms' => (hrtime(true) - $start) / 1_000_000,
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array{status:string,output_artifact?:array<string,mixed>,runtime_ms:int}
     */
    public static function emitFinal(array $params): array
    {
        $start = hrtime(true);

        $modelName = $params['model_name'] ?? 'unknown';
        $runId = $params['run_id'] ?? '';

        return [
            'status' => 'success',
            'model_name' => $modelName,
            'run_id' => $runId,
            'runtime_ms' => (hrtime(true) - $start) / 1_000_000,
        ];
    }

    /**
     * @return string|null
     */
    private static function simulateBlockExecution(string $blockType, string $blockId, ?string $inputData): ?string
    {
        if ($blockType === ModelConfig::TYPE_EMBED) {
            return random_bytes(8192);
        }
        if ($blockType === ModelConfig::TYPE_ATTENTION) {
            return random_bytes(8192);
        }
        if ($blockType === ModelConfig::TYPE_FFN) {
            return random_bytes(12288);
        }
        if ($blockType === ModelConfig::TYPE_OUTPUT_HEAD) {
            return random_bytes(1024);
        }

        return random_bytes(8192);
    }
}