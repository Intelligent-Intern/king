<?php
declare(strict_types=1);

namespace King\Voltron;

require_once __DIR__ . '/ModelConfig.php';
require_once __DIR__ . '/ModelPartitioner.php';
require_once __DIR__ . '/VoltronHandlers.php';
require_once __DIR__ . '/VoltronScheduler.php';
require_once __DIR__ . '/GgufTensorLoader.php';

use RuntimeException;

final class VoltronRunner
{
    private static int $runSequence = 0;

    private string $modelName;
    private string $runId;
    private bool $showDag;
    private ?string $requiredBackend;
    private string $traceId;
    /** @var array<int,string> */
    private array $preferredPeers;

    private string $inferenceModelName;
    private string $inferenceQuantization;
    private bool $inferenceQuantizationExplicit;
    private int $inferenceMaxTokens;
    private float $inferenceTemperature;
    private float $inferenceTopP;
    private int $inferenceTopK;

    private ?string $ggufPath;
    private ?string $ggufObjectId;
    private int $kernelHiddenDim;
    private int $kernelCandidateTokens;

    /**
     * @param array{
     *   require_backend?:string,
     *   trace_id?:string,
     *   peers?:array<int,string>,
     *   inference_model_name?:string,
     *   inference_quantization?:string,
     *   inference_max_tokens?:int,
     *   inference_temperature?:float,
     *   inference_top_p?:float,
     *   inference_top_k?:int,
     *   gguf_path?:string,
     *   gguf_object_id?:string,
     *   kernel_hidden_dim?:int,
     *   kernel_candidate_tokens?:int
     * } $runtimeOptions
     */
    public function __construct(
        string $modelName = 'qwen2.5-coder:3b',
        bool $showDag = false,
        array $runtimeOptions = []
    ) {
        $this->modelName = $modelName;
        self::$runSequence++;
        $this->runId = 'voltron-' . $modelName . '-' . time() . '-' . self::$runSequence;
        $this->showDag = $showDag;
        $this->requiredBackend = isset($runtimeOptions['require_backend']) && is_string($runtimeOptions['require_backend'])
            ? $runtimeOptions['require_backend']
            : null;
        $this->traceId = isset($runtimeOptions['trace_id']) && is_string($runtimeOptions['trace_id']) && $runtimeOptions['trace_id'] !== ''
            ? $runtimeOptions['trace_id']
            : 'voltron';
        $this->preferredPeers = isset($runtimeOptions['peers']) && is_array($runtimeOptions['peers'])
            ? array_values(array_filter(
                array_map(static fn($p) => is_string($p) ? trim($p) : '', $runtimeOptions['peers']),
                static fn(string $p): bool => $p !== ''
            ))
            : [];

        $envInferenceModel = getenv('VOLTRON_INFERENCE_MODEL_NAME');
        $envInferenceQuant = getenv('VOLTRON_INFERENCE_QUANTIZATION');
        $envInferenceMaxTokens = getenv('VOLTRON_INFERENCE_MAX_TOKENS');
        $envInferenceTemperature = getenv('VOLTRON_INFERENCE_TEMPERATURE');
        $envInferenceTopP = getenv('VOLTRON_INFERENCE_TOP_P');
        $envInferenceTopK = getenv('VOLTRON_INFERENCE_TOP_K');

        $this->inferenceModelName = isset($runtimeOptions['inference_model_name']) && is_string($runtimeOptions['inference_model_name']) && trim($runtimeOptions['inference_model_name']) !== ''
            ? trim($runtimeOptions['inference_model_name'])
            : ((is_string($envInferenceModel) && trim($envInferenceModel) !== '') ? trim($envInferenceModel) : $modelName);

        $this->inferenceQuantizationExplicit = (
            (isset($runtimeOptions['inference_quantization']) && is_string($runtimeOptions['inference_quantization']) && trim($runtimeOptions['inference_quantization']) !== '')
            || (is_string($envInferenceQuant) && trim($envInferenceQuant) !== '')
        );
        $this->inferenceQuantization = isset($runtimeOptions['inference_quantization']) && is_string($runtimeOptions['inference_quantization']) && trim($runtimeOptions['inference_quantization']) !== ''
            ? trim($runtimeOptions['inference_quantization'])
            : ((is_string($envInferenceQuant) && trim($envInferenceQuant) !== '') ? trim($envInferenceQuant) : 'Q4_K');

        $this->inferenceMaxTokens = isset($runtimeOptions['inference_max_tokens']) && is_int($runtimeOptions['inference_max_tokens']) && $runtimeOptions['inference_max_tokens'] > 0
            ? $runtimeOptions['inference_max_tokens']
            : ((is_string($envInferenceMaxTokens) && ctype_digit($envInferenceMaxTokens) && (int) $envInferenceMaxTokens > 0) ? (int) $envInferenceMaxTokens : 64);
        $this->inferenceTemperature = isset($runtimeOptions['inference_temperature']) && is_float($runtimeOptions['inference_temperature'])
            ? $runtimeOptions['inference_temperature']
            : ((is_string($envInferenceTemperature) && is_numeric($envInferenceTemperature)) ? (float) $envInferenceTemperature : 0.2);
        $this->inferenceTopP = isset($runtimeOptions['inference_top_p']) && is_float($runtimeOptions['inference_top_p'])
            ? $runtimeOptions['inference_top_p']
            : ((is_string($envInferenceTopP) && is_numeric($envInferenceTopP)) ? (float) $envInferenceTopP : 0.95);
        $this->inferenceTopK = isset($runtimeOptions['inference_top_k']) && is_int($runtimeOptions['inference_top_k']) && $runtimeOptions['inference_top_k'] >= 0
            ? $runtimeOptions['inference_top_k']
            : ((is_string($envInferenceTopK) && ctype_digit($envInferenceTopK)) ? (int) $envInferenceTopK : 40);

        $envGgufPath = getenv('VOLTRON_GGUF_PATH');
        $envGgufObject = getenv('VOLTRON_GGUF_OBJECT_ID');
        $envKernelHidden = getenv('VOLTRON_KERNEL_HIDDEN_DIM');
        $envKernelCandidates = getenv('VOLTRON_KERNEL_CANDIDATE_TOKENS');

        $this->ggufPath = isset($runtimeOptions['gguf_path']) && is_string($runtimeOptions['gguf_path']) && trim($runtimeOptions['gguf_path']) !== ''
            ? trim($runtimeOptions['gguf_path'])
            : ((is_string($envGgufPath) && trim($envGgufPath) !== '') ? trim($envGgufPath) : null);
        $this->ggufObjectId = isset($runtimeOptions['gguf_object_id']) && is_string($runtimeOptions['gguf_object_id']) && trim($runtimeOptions['gguf_object_id']) !== ''
            ? trim($runtimeOptions['gguf_object_id'])
            : ((is_string($envGgufObject) && trim($envGgufObject) !== '') ? trim($envGgufObject) : null);

        $this->kernelHiddenDim = isset($runtimeOptions['kernel_hidden_dim']) && is_int($runtimeOptions['kernel_hidden_dim']) && $runtimeOptions['kernel_hidden_dim'] > 0
            ? $runtimeOptions['kernel_hidden_dim']
            : ((is_string($envKernelHidden) && ctype_digit($envKernelHidden) && (int) $envKernelHidden > 0) ? (int) $envKernelHidden : 64);
        $this->kernelCandidateTokens = isset($runtimeOptions['kernel_candidate_tokens']) && is_int($runtimeOptions['kernel_candidate_tokens']) && $runtimeOptions['kernel_candidate_tokens'] > 0
            ? $runtimeOptions['kernel_candidate_tokens']
            : ((is_string($envKernelCandidates) && ctype_digit($envKernelCandidates) && (int) $envKernelCandidates > 0) ? (int) $envKernelCandidates : 1024);
    }

    /**
     * @return array{backend:string,topology:string}
     */
    private function orchestratorBackendInfo(): array
    {
        if (!function_exists('king_system_get_component_info')) {
            return ['backend' => 'unknown', 'topology' => 'unknown'];
        }

        $component = king_system_get_component_info('pipeline_orchestrator');
        $config = is_array($component['configuration'] ?? null) ? $component['configuration'] : [];

        return [
            'backend' => is_string($config['execution_backend'] ?? null) ? $config['execution_backend'] : 'unknown',
            'topology' => is_string($config['topology_scope'] ?? null) ? $config['topology_scope'] : 'unknown',
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $steps
     * @param array<string,string> $ownerMap
     */
    private function printDagWithScheduling(array $steps, array $ownerMap): void
    {
        echo "═══ Computational DAG (Hotpath) ═══\n";
        foreach ($steps as $i => $s) {
            $id = str_replace('voltron.execute_block.', '', (string) ($s['id'] ?? ''));
            $deps = (is_array($s['deps'] ?? null) && $s['deps'] !== [])
                ? implode(', ', array_map(static fn($d) => str_replace('voltron.execute_block.', '', (string) $d), $s['deps']))
                : '(root)';
            $owner = is_string($ownerMap[$s['id'] ?? ''] ?? null) ? $ownerMap[$s['id']] : 'n/a';
            $inputArtifact = is_string($s['params']['input_artifact'] ?? null) ? (string) $s['params']['input_artifact'] : '-';
            $outputArtifact = is_string($s['params']['output_artifact'] ?? null) ? (string) $s['params']['output_artifact'] : '-';

            echo sprintf("  %2d │ %-20s ← %-16s | owner=%s\n", $i, $id, $deps, $owner);
            echo sprintf("     │ input=%s\n", $inputArtifact);
            if ($outputArtifact !== '-') {
                echo sprintf("     │ output=%s\n", $outputArtifact);
            }
        }
    }

    /**
     * @param array<int,array<string,mixed>> $steps
     * @param array<string,string> $ownerMap
     */
    private function printOpsHotPath(array $steps, array $ownerMap): void
    {
        echo "═══ Ops Hot Path (DAG Order) ═══\n";
        foreach ($steps as $i => $step) {
            $stepId = is_string($step['id'] ?? null) ? (string) $step['id'] : ('step-' . $i);
            $op = is_string($step['tool'] ?? null) ? (string) $step['tool'] : 'unknown_op';
            $owner = is_string($ownerMap[$stepId] ?? null) ? (string) $ownerMap[$stepId] : 'n/a';
            $deps = is_array($step['deps'] ?? null) ? $step['deps'] : [];
            $depsText = $deps !== []
                ? implode(', ', array_map(static fn($d) => str_replace('voltron.execute_block.', '', (string) $d), $deps))
                : '(root)';
            $shortStep = str_replace('voltron.execute_block.', '', $stepId);

            echo sprintf("  %2d │ %s | op=%s | owner=%s | deps=%s\n", $i, $shortStep, $op, $owner, $depsText);
        }
        echo "═══ Handler Execution Stream ═══\n";
    }

    /**
     * @param array<int,array<string,mixed>> $trace
     */
    private function printRemoteTrace(array $trace): void
    {
        echo "\n=== Peer Artifact Exchange ===\n";
        foreach ($trace as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $stepId = is_string($entry['step_id'] ?? null) ? (string) $entry['step_id'] : 'unknown-step';
            $owner = is_string($entry['owner_peer'] ?? null) ? (string) $entry['owner_peer'] : 'n/a';
            $executedBy = is_string($entry['executed_by_peer'] ?? null) ? (string) $entry['executed_by_peer'] : 'n/a';
            $dispatch = is_string($entry['dispatch'] ?? null) ? (string) $entry['dispatch'] : 'n/a';
            $inputUri = is_string($entry['input_artifact_uri'] ?? null) ? (string) $entry['input_artifact_uri'] : '-';
            $outputUri = is_string($entry['output_artifact_uri'] ?? null) ? (string) $entry['output_artifact_uri'] : '-';
            $handoffIn = is_string($entry['handoff_request_artifact_uri'] ?? null) ? (string) $entry['handoff_request_artifact_uri'] : '-';
            $handoffOut = is_string($entry['handoff_response_artifact_uri'] ?? null) ? (string) $entry['handoff_response_artifact_uri'] : '-';

            echo sprintf(
                "  - %s | owner=%s executed_by=%s dispatch=%s\n",
                $stepId,
                $owner,
                $executedBy,
                $dispatch
            );
            echo sprintf("    input=%s\n", $inputUri);
            echo sprintf("    output=%s\n", $outputUri);
            if ($handoffIn !== '-' || $handoffOut !== '-') {
                echo sprintf("    handoff_in=%s\n", $handoffIn);
                echo sprintf("    handoff_out=%s\n", $handoffOut);
            }
        }
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function assertVoltronProvenance(array $result): array
    {
        $sourceSystem = $result['source_system'] ?? null;
        $provenance = $result['response_provenance'] ?? null;

        if (!is_string($sourceSystem) || $sourceSystem !== 'king_voltron') {
            throw new RuntimeException('Rejected final response: source_system is not king_voltron.');
        }
        if (!is_array($provenance)) {
            throw new RuntimeException('Rejected final response: missing response_provenance.');
        }

        $source = $provenance['source'] ?? null;
        $tool = $provenance['tool'] ?? null;
        if (!is_string($source) || $source !== 'king_voltron_handler') {
            throw new RuntimeException('Rejected final response: provenance source is not king_voltron_handler.');
        }
        if (!is_string($tool) || $tool !== 'voltron.execute_model_block') {
            throw new RuntimeException('Rejected final response: provenance tool is not voltron.execute_model_block.');
        }

        $kernel = $provenance['kernel'] ?? null;
        if (!is_array($kernel)) {
            throw new RuntimeException('Rejected final response: missing kernel provenance metadata.');
        }

        $engine = $kernel['engine'] ?? null;
        if (!is_string($engine) || $engine !== 'king_voltron_block_kernels') {
            throw new RuntimeException('Rejected final response: kernel engine is not king_voltron_block_kernels.');
        }

        return $provenance;
    }

    /**
     * @param array<int,array<string,mixed>> $steps
     * @return array<int,array<string,mixed>>
     */
    private function withKernelSettings(array $steps, string $inferenceModelName, string $inferenceQuantization): array
    {
        $updated = [];
        foreach ($steps as $step) {
            if (!is_array($step)) {
                continue;
            }

            $stepId = $step['id'] ?? null;
            $tool = $step['tool'] ?? null;
            if (
                is_string($stepId)
                && str_starts_with($stepId, 'voltron.execute_block.')
                && is_string($tool)
                && $tool === 'voltron.execute_model_block'
            ) {
                $params = $step['params'] ?? [];
                if (!is_array($params)) {
                    $params = [];
                }

                $params['inference_model_name'] = $inferenceModelName;
                $params['inference_quantization'] = $inferenceQuantization;
                $params['inference_max_tokens'] = $this->inferenceMaxTokens;
                $params['inference_temperature'] = $this->inferenceTemperature;
                $params['inference_top_p'] = $this->inferenceTopP;
                $params['inference_top_k'] = $this->inferenceTopK;
                $params['kernel_hidden_dim'] = $this->kernelHiddenDim;
                $params['kernel_candidate_tokens'] = $this->kernelCandidateTokens;

                if ($this->ggufPath !== null) {
                    $params['gguf_path'] = $this->ggufPath;
                }
                if ($this->ggufObjectId !== null) {
                    $params['gguf_object_id'] = $this->ggufObjectId;
                }

                $step['params'] = $params;
            }

            $updated[] = $step;
        }

        return $updated;
    }

    private function resolveModelBlockCount(string $inferenceModelName): ?int
    {
        try {
            $params = [
                'inference_model_name' => $inferenceModelName,
                'model_name' => $this->modelName,
            ];
            if ($this->ggufPath !== null) {
                $params['gguf_path'] = $this->ggufPath;
            }
            if ($this->ggufObjectId !== null) {
                $params['gguf_object_id'] = $this->ggufObjectId;
            }

            $loader = GgufTensorLoader::fromParams($params);
            foreach (['qwen2.block_count', 'llama.block_count', 'gptneox.block_count'] as $key) {
                $value = $loader->metadata($key, null);
                if (is_int($value) && $value > 1) {
                    return $value;
                }
                if (is_float($value) && $value > 1.0) {
                    return (int) $value;
                }
            }
        } catch (\Throwable) {
            // Fall back to static config when GGUF metadata is not available at plan stage.
        }

        return null;
    }

    public function run(string $prompt): array
    {
        if (!function_exists('king_pipeline_orchestrator_run')) {
            throw new RuntimeException('King orchestrator runtime is unavailable.');
        }

        voltron_register_handlers();

        $backendInfo = $this->orchestratorBackendInfo();
        if ($this->requiredBackend !== null && $backendInfo['backend'] !== $this->requiredBackend) {
            throw new RuntimeException(
                sprintf(
                    "Configured backend is '%s', but Voltron requires '%s' for this run.",
                    $backendInfo['backend'],
                    $this->requiredBackend
                )
            );
        }

        $inferenceModelName = $this->inferenceModelName;
        $inferenceQuantization = $this->inferenceQuantization;
        $modelBlockCount = $this->resolveModelBlockCount($inferenceModelName);

        $nodes = ['local' => ['max_memory_mb' => 8192, 'capabilities' => ['model_inference']]];
        $config = ModelConfig::qwen2_5_3b($modelBlockCount);
        $partition = ModelPartitioner::partition($config, $nodes);

        if (
            !$this->inferenceQuantizationExplicit
            && is_string($config['quantization'] ?? null)
            && trim((string) $config['quantization']) !== ''
        ) {
            $inferenceQuantization = strtoupper(trim((string) $config['quantization']));
        }

        $steps = $this->withKernelSettings($partition['steps'], $inferenceModelName, $inferenceQuantization);
        $schedule = VoltronScheduler::buildSchedule($steps, $this->preferredPeers);
        $stepOwners = $schedule['step_owners'];

        echo "=== Voltron Runner (King Infra) ===\n";
        echo "Model: {$this->modelName}\n";
        echo "Prompt: {$prompt}\n";
        echo "Backend: {$backendInfo['backend']} ({$backendInfo['topology']})\n\n";

        if ($this->showDag) {
            $this->printDagWithScheduling($steps, $stepOwners);
            $this->printOpsHotPath($steps, $stepOwners);
        }

        $startTime = hrtime(true);

        $state = null;
        $iterations = 0;
        $finalResult = [];
        $aggregateRemoteTrace = [];
        $lastProvenance = [];

        for ($iter = 0; $iter < $this->inferenceMaxTokens; $iter++) {
            $iterations++;

            $result = king_pipeline_orchestrator_run(
                [
                    'run_id' => $this->runId . '-tok-' . $iter,
                    'model' => $this->modelName,
                    'prompt' => $prompt,
                    'voltron_state' => $state,
                    'decode_iteration' => $iter,
                ],
                ['steps' => $steps],
                [
                    'trace_id' => $this->traceId,
                    'voltron_schedule' => $stepOwners,
                    'voltron_schedule_peers' => $schedule['peers'],
                    'voltron_schedule_source' => $schedule['discovery_source'],
                    'voltron_schedule_generated_at_ms' => $schedule['generated_at_ms'],
                    'voltron_decode_iteration' => $iter,
                ]
            );

            if (!is_array($result)) {
                throw new RuntimeException('Voltron run returned non-array result payload.');
            }

            $lastProvenance = $this->assertVoltronProvenance($result);
            $state = is_array($result['voltron_state'] ?? null) ? $result['voltron_state'] : $state;
            $finalResult = $result;

            if (is_array($result['voltron_remote_trace'] ?? null)) {
                foreach ($result['voltron_remote_trace'] as $entry) {
                    if (is_array($entry)) {
                        $aggregateRemoteTrace[] = $entry;
                    }
                }
            }

            if (($result['decode_stop'] ?? false) === true) {
                break;
            }
        }

        $runtimeMs = (hrtime(true) - $startTime) / 1_000_000;
        if ($aggregateRemoteTrace !== []) {
            $finalResult['voltron_remote_trace'] = $aggregateRemoteTrace;
        }

        $backendInfo = $this->orchestratorBackendInfo();

        echo "\n=== Voltron Output ({$runtimeMs}ms) ===\n";
        echo 'Blocks executed per iteration: ' . count($steps) . "\n";
        echo "Decode iterations: {$iterations}\n";
        echo "Execution backend: {$backendInfo['backend']} ({$backendInfo['topology']})\n";
        echo 'Scheduler peers: ' . implode(', ', $schedule['peers']) . " (source={$schedule['discovery_source']})\n";
        echo "All tokens decoded via King orchestrator DAG block kernels.\n";
        echo "Activation flow: embed → attention → ffn → ... → output_head\n";
        echo "Inference target: {$inferenceModelName}/{$inferenceQuantization}\n";
        $resolvedDepth = $modelBlockCount ?? 36;
        echo "Transformer blocks executed: {$resolvedDepth}\n";

        $provenanceBackend = is_string($lastProvenance['backend'] ?? null) ? (string) $lastProvenance['backend'] : 'unknown';
        $provenanceStep = is_string($lastProvenance['step_id'] ?? null) ? (string) $lastProvenance['step_id'] : 'unknown';
        echo "Response provenance: king_voltron_handler via {$provenanceBackend} ({$provenanceStep})\n";

        $reason = is_string($finalResult['finished_reason'] ?? null) ? (string) $finalResult['finished_reason'] : null;
        if ($reason !== null) {
            echo "Decode stop reason: {$reason}\n";
        }

        if (is_string($finalResult['response'] ?? null) && $finalResult['response'] !== '') {
            echo "\nResponse:\n" . $finalResult['response'] . "\n";
        }
        if (is_array($finalResult['voltron_remote_trace'] ?? null)) {
            $this->printRemoteTrace($finalResult['voltron_remote_trace']);
        }

        return [
            'model' => $this->modelName,
            'prompt' => $prompt,
            'blocks' => count($steps),
            'iterations' => $iterations,
            'runtime_ms' => $runtimeMs,
            'backend' => $backendInfo,
            'schedule' => $schedule,
            'dag_result' => $finalResult,
        ];
    }
}
