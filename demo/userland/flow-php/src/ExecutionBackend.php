<?php
declare(strict_types=1);

namespace King\Flow;

use InvalidArgumentException;
use LogicException;
use RuntimeException;

interface ExecutionBackend
{
    /**
     * @param array<string,mixed> $config
     */
    public function registerTool(string $toolName, array $config): void;

    public function registerHandler(string $toolName, callable $handler): void;

    /**
     * @param array<int,array<string,mixed>> $pipeline
     * @param array<string,mixed> $options
     */
    public function start(mixed $initialData, array $pipeline, array $options = []): ExecutionRunSnapshot;

    public function continueRun(string $runId): ExecutionRunSnapshot;

    public function claimNext(): ExecutionRunSnapshot|false;

    public function inspect(string $runId): ?ExecutionRunSnapshot;

    public function cancelRun(string $runId): bool;

    public function capabilities(): ExecutionBackendCapabilities;
}

interface PredictiveRunIdExecutionBackend extends ExecutionBackend
{
    public function predictNextRunId(): string;
}

final class ExecutionBackendCapabilities
{
    public function __construct(
        private string $backend,
        private string $topologyScope,
        private string $submissionMode,
        private string $continuationMode,
        private string $claimMode,
        private string $cancellationMode,
        private string $controllerHandlerRequirement,
        private string $executorHandlerRequirement,
        private string $handlerBoundaryContract = 'durable_tool_name_refs_only'
    ) {
    }

    public function backend(): string
    {
        return $this->backend;
    }

    public function topologyScope(): string
    {
        return $this->topologyScope;
    }

    public function submissionMode(): string
    {
        return $this->submissionMode;
    }

    public function continuationMode(): string
    {
        return $this->continuationMode;
    }

    public function claimMode(): string
    {
        return $this->claimMode;
    }

    public function cancellationMode(): string
    {
        return $this->cancellationMode;
    }

    public function controllerHandlerRequirement(): string
    {
        return $this->controllerHandlerRequirement;
    }

    public function executorHandlerRequirement(): string
    {
        return $this->executorHandlerRequirement;
    }

    public function handlerBoundaryContract(): string
    {
        return $this->handlerBoundaryContract;
    }

    public function supportsResumeById(): bool
    {
        return $this->continuationMode === 'resume_run_by_id';
    }

    public function supportsClaimNext(): bool
    {
        return $this->claimMode === 'claim_next_queued_run';
    }

    public function supportsPersistedCancellation(): bool
    {
        return $this->cancellationMode === 'persisted_run_cancel';
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'backend' => $this->backend,
            'topology_scope' => $this->topologyScope,
            'submission_mode' => $this->submissionMode,
            'continuation_mode' => $this->continuationMode,
            'claim_mode' => $this->claimMode,
            'cancellation_mode' => $this->cancellationMode,
            'controller_handler_requirement' => $this->controllerHandlerRequirement,
            'executor_handler_requirement' => $this->executorHandlerRequirement,
            'handler_boundary_contract' => $this->handlerBoundaryContract,
        ];
    }
}

final class ExecutionRunSnapshot
{
    /** @var array<string,mixed> */
    private array $snapshot;

    /**
     * @param array<string,mixed> $snapshot
     */
    public function __construct(array $snapshot)
    {
        $runId = $snapshot['run_id'] ?? null;
        $status = $snapshot['status'] ?? null;

        if (!is_string($runId) || $runId === '') {
            throw new InvalidArgumentException('execution snapshot must contain a non-empty run_id.');
        }

        if (!is_string($status) || $status === '') {
            throw new InvalidArgumentException('execution snapshot must contain a non-empty status.');
        }

        $this->snapshot = $snapshot;
    }

    public function runId(): string
    {
        return (string) $this->snapshot['run_id'];
    }

    public function status(): string
    {
        return (string) $this->snapshot['status'];
    }

    public function executionBackend(): string
    {
        return (string) ($this->snapshot['execution_backend'] ?? '');
    }

    public function topologyScope(): string
    {
        return (string) ($this->snapshot['topology_scope'] ?? '');
    }

    public function stepCount(): int
    {
        return (int) ($this->snapshot['step_count'] ?? 0);
    }

    public function completedStepCount(): int
    {
        return (int) ($this->snapshot['completed_step_count'] ?? 0);
    }

    public function cancelRequested(): bool
    {
        return (bool) ($this->snapshot['cancel_requested'] ?? false);
    }

    public function payload(): mixed
    {
        return $this->snapshot['result'] ?? null;
    }

    public function error(): ?string
    {
        $error = $this->snapshot['error'] ?? null;

        return is_string($error) ? $error : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function errorClassification(): ?array
    {
        $classification = $this->snapshot['error_classification'] ?? null;

        return is_array($classification) ? $classification : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function telemetryAdapter(): ?array
    {
        $adapter = $this->snapshot['telemetry_adapter'] ?? null;

        return is_array($adapter) ? $adapter : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function distributedObservability(): ?array
    {
        $observability = $this->snapshot['distributed_observability'] ?? null;

        return is_array($observability) ? $observability : null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function steps(): array
    {
        $steps = $this->snapshot['steps'] ?? null;

        return is_array($steps) ? $steps : [];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function handlerBoundary(): ?array
    {
        $boundary = $this->snapshot['handler_boundary'] ?? null;

        return is_array($boundary) ? $boundary : null;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->snapshot;
    }
}

final class OrchestratorExecutionBackend implements PredictiveRunIdExecutionBackend
{
    /**
     * @param array<string,mixed> $config
     */
    public function registerTool(string $toolName, array $config): void
    {
        $this->assertNonEmptyIdentifier($toolName, 'toolName');

        if (\king_pipeline_orchestrator_register_tool($toolName, $config) !== true) {
            throw new RuntimeException(
                sprintf("failed to register orchestrator tool '%s'.", $toolName)
            );
        }
    }

    public function registerHandler(string $toolName, callable $handler): void
    {
        $this->assertNonEmptyIdentifier($toolName, 'toolName');

        if (\king_pipeline_orchestrator_register_handler($toolName, $handler) !== true) {
            throw new RuntimeException(
                sprintf("failed to register orchestrator handler for tool '%s'.", $toolName)
            );
        }
    }

    public function capabilities(): ExecutionBackendCapabilities
    {
        [$backend, $topologyScope] = $this->resolveBackendIdentity();

        return match ($backend) {
            'file_worker' => new ExecutionBackendCapabilities(
                'file_worker',
                $topologyScope,
                'queue_dispatch',
                'claim_recovered_or_queued_run',
                'claim_next_queued_run',
                'persisted_run_cancel',
                'required_to_persist_worker_boundary',
                'worker_process_registered_handlers'
            ),
            'remote_peer' => new ExecutionBackendCapabilities(
                'remote_peer',
                $topologyScope,
                'run_immediately',
                'resume_run_by_id',
                'not_supported',
                'cancel_token_only',
                'required_to_persist_remote_boundary',
                'remote_peer_registered_handlers'
            ),
            default => new ExecutionBackendCapabilities(
                'local',
                $topologyScope,
                'run_immediately',
                'resume_run_by_id',
                'not_supported',
                'cancel_token_only',
                'required_for_local_execution',
                'same_process_registered_handlers'
            ),
        };
    }

    public function predictNextRunId(): string
    {
        $config = $this->componentConfiguration();
        $lastRunId = is_string($config['last_run_id'] ?? null) ? $config['last_run_id'] : '';

        if (preg_match('/^run-(\d+)$/', $lastRunId, $matches) === 1) {
            return 'run-' . (((int) $matches[1]) + 1);
        }

        return 'run-' . (((int) ($config['run_history_count'] ?? 0)) + 1);
    }

    public function start(mixed $initialData, array $pipeline, array $options = []): ExecutionRunSnapshot
    {
        $capabilities = $this->capabilities();

        if ($capabilities->submissionMode() === 'queue_dispatch') {
            return $this->snapshotFromArray(
                \king_pipeline_orchestrator_dispatch($initialData, $pipeline, $this->normalizeOptions($options))
            );
        }

        $before = $this->runHistorySignature();
        \king_pipeline_orchestrator_run($initialData, $pipeline, $this->normalizeOptions($options));

        return $this->latestSnapshotAfterMutation($before, 'king_pipeline_orchestrator_run');
    }

    public function continueRun(string $runId): ExecutionRunSnapshot
    {
        $capabilities = $this->capabilities();
        if (!$capabilities->supportsResumeById()) {
            throw new LogicException(
                sprintf(
                    "execution backend '%s' continues persisted work via claimNext(), not continueRun().",
                    $capabilities->backend()
                )
            );
        }

        $this->assertNonEmptyIdentifier($runId, 'runId');
        \king_pipeline_orchestrator_resume_run($runId);

        return $this->requireSnapshot($runId, 'king_pipeline_orchestrator_resume_run');
    }

    public function claimNext(): ExecutionRunSnapshot|false
    {
        $capabilities = $this->capabilities();
        if (!$capabilities->supportsClaimNext()) {
            throw new LogicException(
                sprintf(
                    "execution backend '%s' does not support queued worker claims.",
                    $capabilities->backend()
                )
            );
        }

        $snapshot = \king_pipeline_orchestrator_worker_run_next();
        if ($snapshot === false) {
            return false;
        }

        return $this->snapshotFromArray($snapshot);
    }

    public function inspect(string $runId): ?ExecutionRunSnapshot
    {
        $this->assertNonEmptyIdentifier($runId, 'runId');

        $snapshot = \king_pipeline_orchestrator_get_run($runId);
        if ($snapshot === false) {
            return null;
        }

        return $this->snapshotFromArray($snapshot);
    }

    public function cancelRun(string $runId): bool
    {
        $capabilities = $this->capabilities();
        if (!$capabilities->supportsPersistedCancellation()) {
            throw new LogicException(
                sprintf(
                    "execution backend '%s' uses live cancel tokens or deadlines, not persisted cancelRun().",
                    $capabilities->backend()
                )
            );
        }

        $this->assertNonEmptyIdentifier($runId, 'runId');

        return \king_pipeline_orchestrator_cancel_run($runId);
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    private function snapshotFromArray(array $snapshot): ExecutionRunSnapshot
    {
        return new ExecutionRunSnapshot($snapshot);
    }

    /**
     * @param array{last_run_id:string,run_history_count:int} $before
     */
    private function latestSnapshotAfterMutation(array $before, string $operation): ExecutionRunSnapshot
    {
        $after = $this->runHistorySignature();
        $runId = $after['last_run_id'];

        if (
            $runId === ''
            || ($runId === $before['last_run_id'] && $after['run_history_count'] <= $before['run_history_count'])
        ) {
            throw new RuntimeException(
                sprintf('%s completed without exposing a new persisted run snapshot.', $operation)
            );
        }

        return $this->requireSnapshot($runId, $operation);
    }

    private function requireSnapshot(string $runId, string $operation): ExecutionRunSnapshot
    {
        $snapshot = $this->inspect($runId);
        if ($snapshot === null) {
            throw new RuntimeException(
                sprintf("%s persisted run '%s' but it could not be reloaded.", $operation, $runId)
            );
        }

        return $snapshot;
    }

    /**
     * @return array{last_run_id:string,run_history_count:int}
     */
    private function runHistorySignature(): array
    {
        $config = $this->componentConfiguration();

        return [
            'last_run_id' => is_string($config['last_run_id'] ?? null) ? $config['last_run_id'] : '',
            'run_history_count' => (int) ($config['run_history_count'] ?? 0),
        ];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function resolveBackendIdentity(): array
    {
        $config = $this->componentConfiguration();
        $backend = is_string($config['execution_backend'] ?? null) && $config['execution_backend'] !== ''
            ? $config['execution_backend']
            : 'local';

        $topologyScope = is_string($config['topology_scope'] ?? null) && $config['topology_scope'] !== ''
            ? $config['topology_scope']
            : match ($backend) {
                'file_worker' => 'same_host_file_worker',
                'remote_peer' => 'tcp_host_port_execution_peer',
                default => 'local_in_process',
            };

        return [$backend, $topologyScope];
    }

    /**
     * @return array<string,mixed>
     */
    private function componentConfiguration(): array
    {
        $component = \king_system_get_component_info('pipeline_orchestrator');
        $configuration = $component['configuration'] ?? null;

        return is_array($configuration) ? $configuration : [];
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>|null
     */
    private function normalizeOptions(array $options): ?array
    {
        return $options === [] ? null : $options;
    }

    private function assertNonEmptyIdentifier(string $value, string $label): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException($label . ' must not be empty.');
        }
    }
}
