<?php
declare(strict_types=1);

namespace King\Voltron;

require_once __DIR__ . '/VoltronArtifacts.php';
require_once __DIR__ . '/GgufTensorLoader.php';
require_once __DIR__ . '/VoltronTokenizer.php';
require_once __DIR__ . '/VoltronKernels.php';

/**
 * @param array<string,mixed> $context
 * @return array<string,mixed>
 */
function voltron_step_definition(array $context): array
{
    $step = $context['step'] ?? [];
    if (!is_array($step)) {
        return [];
    }
    $definition = $step['definition'] ?? null;
    if (is_array($definition)) {
        return $definition;
    }
    return $step;
}

/**
 * @param array<string,mixed> $context
 * @return array<string,mixed>
 */
function voltron_step_params(array $context): array
{
    $definition = voltron_step_definition($context);
    $params = $definition['params'] ?? [];
    if (!is_array($params)) {
        return [];
    }
    return $params;
}

/**
 * @param array<string,mixed> $context
 */
function voltron_prompt_from_context(array $context): string
{
    $input = $context['input'] ?? [];
    if (!is_array($input)) {
        return '';
    }

    $prompt = $input['prompt'] ?? '';
    return is_string($prompt) ? $prompt : '';
}

/**
 * @param array<string,mixed> $context
 * @return array<string,mixed>
 */
function voltron_response_provenance(array $context, string $stepId, array $kernel): array
{
    $run = $context['run'] ?? [];
    if (!is_array($run)) {
        $run = [];
    }

    return [
        'source' => 'king_voltron_handler',
        'tool' => 'voltron.execute_model_block',
        'backend' => is_string($run['execution_backend'] ?? null) ? $run['execution_backend'] : 'unknown',
        'topology' => is_string($run['topology_scope'] ?? null) ? $run['topology_scope'] : 'unknown',
        'step_id' => $stepId,
        'kernel' => $kernel,
    ];
}

/**
 * @param array<string,mixed> $input
 * @param array<string,mixed> $params
 * @return array<string,mixed>
 */
function voltron_state_from_input(array $input, array $params, string $prompt): array
{
    $state = $input['voltron_state'] ?? null;
    if (is_array($state)) {
        return $state;
    }

    $artifactUri = is_string($params['input_artifact'] ?? null) ? (string) $params['input_artifact'] : '';
    if ($artifactUri !== '' && !str_starts_with($artifactUri, 'object://models/')) {
        try {
            $artifactPayload = voltron_artifact_get($artifactUri);
            if (is_array($artifactPayload) && is_array($artifactPayload['voltron_state'] ?? null)) {
                return $artifactPayload['voltron_state'];
            }
        } catch (\Throwable) {
            // Ignore artifact miss for first/root step.
        }
    }

    return VoltronKernels::initialState($prompt, $params);
}

/**
 * @param array<string,mixed> $context
 * @return array<string,mixed>
 */
function voltron_execute_model_block_handler(array $context): array
{
    $start = hrtime(true);

    $definition = voltron_step_definition($context);
    $params = voltron_step_params($context);

    $stepId = is_string($definition['id'] ?? null) ? (string) $definition['id'] : 'unknown-step';
    $blockName = str_replace('voltron.execute_block.', '', $stepId);
    $blockType = is_string($params['block_type'] ?? null) ? (string) $params['block_type'] : $blockName;

    $input = $context['input'] ?? [];
    if (!is_array($input)) {
        $input = [];
    }
    $prompt = voltron_prompt_from_context($context);

    $state = voltron_state_from_input($input, $params, $prompt);
    $state = VoltronKernels::executeBlock($blockType, $state, $params);

    $outputArtifact = is_string($params['output_artifact'] ?? null) ? (string) $params['output_artifact'] : '';
    $artifactMeta = null;
    if ($outputArtifact !== '') {
        $artifactMeta = voltron_artifact_put(
            $outputArtifact,
            [
                'voltron_state' => $state,
                'block_id' => $stepId,
                'block_type' => $blockType,
            ],
            $stepId
        );
    }

    $kernelProvenance = [
        'engine' => 'king_voltron_block_kernels',
        'gguf_path' => is_string($params['gguf_path'] ?? null) ? (string) $params['gguf_path'] : null,
        'gguf_object_id' => is_string($params['gguf_object_id'] ?? null) ? (string) $params['gguf_object_id'] : null,
        'model_source' => is_string($params['model_source'] ?? null) ? (string) $params['model_source'] : null,
        'block_type' => $blockType,
        'latent_dim' => is_int($state['latent_dim'] ?? null) ? (int) $state['latent_dim'] : null,
    ];

    $provenance = voltron_response_provenance($context, $stepId, $kernelProvenance);

    echo "  → {$stepId}\n";

    return [
        'output' => [
            'block_id' => $stepId,
            'block_name' => $blockName,
            'block_type' => $blockType,
            'prompt' => $prompt,
            'response' => is_string($state['generated_text'] ?? null) ? (string) $state['generated_text'] : '',
            'final' => ($blockType === 'output_head') && (($state['stop'] ?? false) === true),
            'decode_stop' => ($state['stop'] ?? false) === true,
            'finished_reason' => is_string($state['finished_reason'] ?? null) ? (string) $state['finished_reason'] : null,
            'source_system' => 'king_voltron',
            'response_provenance' => $provenance,
            'input_artifact' => is_string($params['input_artifact'] ?? null) ? (string) $params['input_artifact'] : null,
            'output_artifact' => $outputArtifact !== '' ? $outputArtifact : null,
            'output_artifact_meta' => $artifactMeta,
            'voltron_state' => $state,
        ],
        'runtime_ms' => (hrtime(true) - $start) / 1_000_000,
    ];
}

/**
 * @param array<string,mixed> $context
 * @return array<string,mixed>
 */
function voltron_emit_final_handler(array $context): array
{
    $input = $context['input'] ?? [];
    if (!is_array($input)) {
        $input = [];
    }

    $state = is_array($input['voltron_state'] ?? null) ? $input['voltron_state'] : [];

    return [
        'output' => [
            'completed' => true,
            'response' => is_string($state['generated_text'] ?? null)
                ? (string) $state['generated_text']
                : (is_string($input['response'] ?? null) ? (string) $input['response'] : ''),
            'decode_stop' => ($state['stop'] ?? false) === true,
            'finished_reason' => is_string($state['finished_reason'] ?? null) ? (string) $state['finished_reason'] : null,
            'source_system' => 'king_voltron',
            'response_provenance' => is_array($input['response_provenance'] ?? null) ? $input['response_provenance'] : null,
            'block_id' => is_string($input['block_id'] ?? null) ? (string) $input['block_id'] : null,
            'block_name' => is_string($input['block_name'] ?? null) ? (string) $input['block_name'] : null,
            'input_artifact' => is_string($input['input_artifact'] ?? null) ? (string) $input['input_artifact'] : null,
            'output_artifact' => is_string($input['output_artifact'] ?? null) ? (string) $input['output_artifact'] : null,
            'voltron_state' => $state,
        ],
        'runtime_ms' => 0.0,
    ];
}

/**
 * @return array<string,callable>
 */
function voltron_remote_peer_handler_map(): array
{
    return [
        'voltron.execute_model_block' => __NAMESPACE__ . '\\voltron_execute_model_block_handler',
        'voltron.emit_final' => __NAMESPACE__ . '\\voltron_emit_final_handler',
    ];
}

function voltron_register_handlers(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }

    if (!function_exists('king_pipeline_orchestrator_register_tool')) {
        throw new RuntimeException('King orchestrator tool registration API is unavailable.');
    }
    if (!function_exists('king_pipeline_orchestrator_register_handler')) {
        throw new RuntimeException('King orchestrator handler registration API is unavailable.');
    }

    king_pipeline_orchestrator_register_tool('voltron.execute_model_block', [
        'name' => 'voltron.execute_model_block',
        'description' => 'Execute partitioned model block kernel',
    ]);
    king_pipeline_orchestrator_register_handler(
        'voltron.execute_model_block',
        __NAMESPACE__ . '\\voltron_execute_model_block_handler'
    );

    king_pipeline_orchestrator_register_tool('voltron.emit_final', [
        'name' => 'voltron.emit_final',
        'description' => 'Emit final response from Voltron kernel state',
    ]);
    king_pipeline_orchestrator_register_handler(
        'voltron.emit_final',
        __NAMESPACE__ . '\\voltron_emit_final_handler'
    );

    $registered = true;
}
