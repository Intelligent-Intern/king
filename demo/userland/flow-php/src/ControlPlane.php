<?php
declare(strict_types=1);

namespace King\Flow;

require_once __DIR__ . '/ExecutionBackend.php';
require_once __DIR__ . '/CheckpointStore.php';

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

interface FlowControlStore
{
    public function load(string $controlRunId): ?FlowControlRecord;

    /**
     * @param array<string,mixed> $state
     */
    public function create(string $controlRunId, array $state): FlowControlCommitResult;

    /**
     * @param array<string,mixed> $state
     */
    public function replace(string $controlRunId, array $state, FlowControlRecord $expected): FlowControlCommitResult;
}

final class FlowControlRecord
{
    /** @var array<string,mixed> */
    private array $state;

    /** @var array<string,mixed> */
    private array $metadata;

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        private string $controlRunId,
        private string $objectId,
        private int $version,
        private string $etag,
        array $state,
        array $metadata = [],
        private ?string $updatedAt = null
    ) {
        $this->state = $state;
        $this->metadata = $metadata;
    }

    public function controlRunId(): string
    {
        return $this->controlRunId;
    }

    public function objectId(): string
    {
        return $this->objectId;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function etag(): string
    {
        return $this->etag;
    }

    public function updatedAt(): ?string
    {
        return $this->updatedAt;
    }

    /**
     * @return array<string,mixed>
     */
    public function state(): array
    {
        return $this->state;
    }

    /**
     * @return array<string,mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function checkpointId(): ?string
    {
        $checkpointId = $this->state['checkpoint_id'] ?? null;

        return is_string($checkpointId) && $checkpointId !== '' ? $checkpointId : null;
    }

    public function activeBackendRunId(): ?string
    {
        $runId = $this->state['active_backend_run_id'] ?? null;

        return is_string($runId) && $runId !== '' ? $runId : null;
    }

    /**
     * @return list<string>
     */
    public function backendRunIds(): array
    {
        $runIds = $this->state['backend_run_ids'] ?? null;
        if (!is_array($runIds)) {
            return [];
        }

        return array_values(array_filter($runIds, static fn(mixed $value): bool => is_string($value) && $value !== ''));
    }

    public function controlStatus(): string
    {
        $status = $this->state['control_status'] ?? null;

        return is_string($status) && $status !== '' ? $status : 'unknown';
    }

    public function pauseMode(): ?string
    {
        $mode = $this->state['pause_mode'] ?? null;

        return is_string($mode) && $mode !== '' ? $mode : null;
    }

    public function lastAction(): ?string
    {
        $action = $this->state['last_action'] ?? null;

        return is_string($action) && $action !== '' ? $action : null;
    }

    public function recoveryCount(): int
    {
        return (int) ($this->state['recovery_count'] ?? 0);
    }

    public function backend(): ?string
    {
        $backend = $this->state['backend'] ?? null;

        return is_string($backend) && $backend !== '' ? $backend : null;
    }

    public function topologyScope(): ?string
    {
        $scope = $this->state['topology_scope'] ?? null;

        return is_string($scope) && $scope !== '' ? $scope : null;
    }

    public function recoveryPlan(): CheckpointRecoveryPlan
    {
        return CheckpointRecoveryPlan::fromArray(is_array($this->state['recovery_plan'] ?? null) ? $this->state['recovery_plan'] : []);
    }

    public function initialData(): mixed
    {
        return $this->state['initial_data'] ?? null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function pipeline(): array
    {
        $pipeline = $this->state['pipeline'] ?? null;

        return is_array($pipeline) ? $pipeline : [];
    }

    /**
     * @return array<string,mixed>
     */
    public function options(): array
    {
        $options = $this->state['options'] ?? null;

        return is_array($options) ? $options : [];
    }
}

final class FlowControlCommitResult
{
    public function __construct(
        private bool $committed,
        private bool $conflict,
        private ?FlowControlRecord $record = null,
        private ?string $message = null
    ) {
    }

    public function committed(): bool
    {
        return $this->committed;
    }

    public function conflict(): bool
    {
        return $this->conflict;
    }

    public function record(): ?FlowControlRecord
    {
        return $this->record;
    }

    public function message(): ?string
    {
        return $this->message;
    }
}

final class ObjectStoreFlowControlStore implements FlowControlStore
{
    /** @var array<string,mixed> */
    private array $writeOptions;

    /**
     * @param array<string,mixed> $writeOptions
     */
    public function __construct(
        private string $prefix,
        array $writeOptions = []
    ) {
        $prefix = trim($prefix, '/');
        if ($prefix === '') {
            throw new InvalidArgumentException('prefix must not be empty.');
        }

        $this->prefix = $prefix;
        $this->writeOptions = $writeOptions;
    }

    public function load(string $controlRunId): ?FlowControlRecord
    {
        $objectId = $this->objectIdFor($controlRunId);
        $metadata = \king_object_store_get_metadata($objectId);
        if ($metadata === false) {
            return null;
        }

        $payload = \king_object_store_get($objectId);
        if (!is_string($payload)) {
            throw new RuntimeException('control-plane payload disappeared before it could be read.');
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('control-plane payload is not valid JSON.', previous: $error);
        }

        if (!is_array($decoded)) {
            throw new RuntimeException('control-plane payload is not a valid object.');
        }

        if (($decoded['flow_control_schema_version'] ?? null) !== 1) {
            throw new RuntimeException('control-plane payload uses an unsupported schema version.');
        }

        if (($decoded['control_run_id'] ?? null) !== $controlRunId) {
            throw new RuntimeException('control-plane payload identity does not match the requested control run id.');
        }

        return new FlowControlRecord(
            $controlRunId,
            $objectId,
            (int) ($metadata['version'] ?? 0),
            (string) ($metadata['etag'] ?? ''),
            is_array($decoded['state'] ?? null) ? $decoded['state'] : [],
            $metadata,
            isset($decoded['updated_at']) ? (string) $decoded['updated_at'] : null
        );
    }

    public function create(string $controlRunId, array $state): FlowControlCommitResult
    {
        $objectId = $this->objectIdFor($controlRunId);
        $payload = $this->encodePayload($controlRunId, $state);
        $options = $this->writeOptions($payload);
        $options['if_none_match'] = '*';

        return $this->commit($controlRunId, $objectId, $payload, $options);
    }

    public function replace(string $controlRunId, array $state, FlowControlRecord $expected): FlowControlCommitResult
    {
        if ($expected->controlRunId() !== $controlRunId) {
            throw new InvalidArgumentException('expected control record does not match the requested control run id.');
        }

        $objectId = $this->objectIdFor($controlRunId);
        if ($expected->objectId() !== $objectId) {
            throw new InvalidArgumentException('expected control record does not belong to this control prefix.');
        }

        $payload = $this->encodePayload($controlRunId, $state);
        $options = $this->writeOptions($payload);
        $options['if_match'] = $expected->etag();
        $options['expected_version'] = $expected->version();

        return $this->commit($controlRunId, $objectId, $payload, $options);
    }

    /**
     * @param array<string,mixed> $options
     */
    private function commit(string $controlRunId, string $objectId, string $payload, array $options): FlowControlCommitResult
    {
        try {
            $written = \king_object_store_put($objectId, $payload, $options);
            if ($written !== true) {
                throw new RuntimeException('control-plane write returned false.');
            }
        } catch (Throwable $error) {
            if ($this->isPreconditionConflict($error)) {
                return new FlowControlCommitResult(
                    false,
                    true,
                    $this->load($controlRunId),
                    $error->getMessage()
                );
            }

            throw $error;
        }

        $record = $this->load($controlRunId);
        if (!$record instanceof FlowControlRecord) {
            throw new RuntimeException('control-plane write succeeded but the committed record could not be reloaded.');
        }

        return new FlowControlCommitResult(true, false, $record, null);
    }

    /**
     * @param array<string,mixed> $state
     */
    private function encodePayload(string $controlRunId, array $state): string
    {
        try {
            return json_encode([
                'flow_control_schema_version' => 1,
                'control_run_id' => $controlRunId,
                'updated_at' => gmdate(DATE_ATOM),
                'state' => $state,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (JsonException $error) {
            throw new RuntimeException('control-plane state could not be encoded as JSON.', previous: $error);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function writeOptions(string $payload): array
    {
        $options = $this->writeOptions;
        $options['content_type'] ??= 'application/vnd.king.flow-control+json';
        $options['object_type'] ??= 'document';
        $options['cache_policy'] ??= 'etag';
        $options['integrity_sha256'] = hash('sha256', $payload);

        return $options;
    }

    private function objectIdFor(string $controlRunId): string
    {
        $controlRunId = trim($controlRunId);
        if ($controlRunId === '') {
            throw new InvalidArgumentException('controlRunId must not be empty.');
        }

        return rawurlencode($this->prefix) . '--' . rawurlencode($controlRunId) . '.json';
    }

    private function isPreconditionConflict(Throwable $error): bool
    {
        if ($error::class !== 'King\\ValidationException') {
            return false;
        }

        $message = $error->getMessage();

        return str_contains($message, 'if_none_match')
            || str_contains($message, 'if_match')
            || str_contains($message, 'expected_version');
    }
}

final class CheckpointRecoveryPlan
{
    public function __construct(
        private string $mode = 'merge_initial_with_checkpoint_progress'
    ) {
        $supported = [
            'checkpoint_state',
            'checkpoint_progress',
            'merge_initial_with_checkpoint_state',
            'merge_initial_with_checkpoint_progress',
        ];

        if (!in_array($mode, $supported, true)) {
            throw new InvalidArgumentException('unsupported checkpoint recovery mode.');
        }
    }

    public static function mergeInitialWithCheckpointProgress(): self
    {
        return new self('merge_initial_with_checkpoint_progress');
    }

    public function mode(): string
    {
        return $this->mode;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return ['mode' => $this->mode];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self((string) ($data['mode'] ?? 'merge_initial_with_checkpoint_progress'));
    }

    public function buildInitialData(mixed $initialData, CheckpointRecord $checkpoint): mixed
    {
        return match ($this->mode) {
            'checkpoint_state' => $checkpoint->state()->toArray(),
            'checkpoint_progress' => $checkpoint->state()->progress(),
            'merge_initial_with_checkpoint_state' => $this->buildMergedInitialWithCheckpointState($initialData, $checkpoint),
            default => $this->buildMergedInitialWithCheckpointProgress($initialData, $checkpoint),
        };
    }

    private function buildMergedInitialWithCheckpointState(mixed $initialData, CheckpointRecord $checkpoint): array
    {
        if (!is_array($initialData)) {
            throw new RuntimeException('checkpoint recovery mode merge_initial_with_checkpoint_state requires array initial data.');
        }

        return array_replace($initialData, [
            'checkpoint' => $checkpoint->state()->toArray(),
            'checkpoint_id' => $checkpoint->checkpointId(),
            'checkpoint_version' => $checkpoint->version(),
        ]);
    }

    private function buildMergedInitialWithCheckpointProgress(mixed $initialData, CheckpointRecord $checkpoint): array
    {
        if (!is_array($initialData)) {
            throw new RuntimeException('checkpoint recovery mode merge_initial_with_checkpoint_progress requires array initial data.');
        }

        $progress = $checkpoint->state()->progress();
        if (!is_array($progress)) {
            throw new RuntimeException('checkpoint recovery mode merge_initial_with_checkpoint_progress requires array checkpoint progress.');
        }

        return array_replace($initialData, $progress);
    }
}

final class FlowControlSnapshot
{
    public function __construct(
        private FlowControlRecord $record,
        private ExecutionBackendCapabilities $capabilities,
        private ?ExecutionRunSnapshot $backendSnapshot,
        private ?CheckpointRecord $checkpointRecord,
        private string $effectiveControlStatus
    ) {
    }

    public function controlRunId(): string
    {
        return $this->record->controlRunId();
    }

    public function controlStatus(): string
    {
        return $this->effectiveControlStatus;
    }

    public function backendSnapshot(): ?ExecutionRunSnapshot
    {
        return $this->backendSnapshot;
    }

    public function checkpointRecord(): ?CheckpointRecord
    {
        return $this->checkpointRecord;
    }

    public function capabilities(): ExecutionBackendCapabilities
    {
        return $this->capabilities;
    }

    public function checkpointResumeAvailable(): bool
    {
        return $this->checkpointRecord instanceof CheckpointRecord;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'control_run_id' => $this->record->controlRunId(),
            'control_status' => $this->effectiveControlStatus,
            'active_backend_run_id' => $this->record->activeBackendRunId(),
            'backend_run_ids' => $this->record->backendRunIds(),
            'backend' => $this->record->backend(),
            'topology_scope' => $this->record->topologyScope(),
            'pause_mode' => $this->record->pauseMode(),
            'last_action' => $this->record->lastAction(),
            'recovery_count' => $this->record->recoveryCount(),
            'checkpoint_id' => $this->record->checkpointId(),
            'recovery_plan' => $this->record->recoveryPlan()->toArray(),
            'checkpoint_resume_available' => $this->checkpointResumeAvailable(),
            'capabilities' => $this->capabilities->toArray(),
            'backend_snapshot' => $this->backendSnapshot?->toArray(),
            'checkpoint_record' => $this->checkpointRecord === null ? null : [
                'checkpoint_id' => $this->checkpointRecord->checkpointId(),
                'object_id' => $this->checkpointRecord->objectId(),
                'version' => $this->checkpointRecord->version(),
                'etag' => $this->checkpointRecord->etag(),
                'updated_at' => $this->checkpointRecord->checkpointedAt(),
                'state' => $this->checkpointRecord->state()->toArray(),
            ],
        ];
    }
}

final class FlowControlPlane
{
    public function __construct(
        private ExecutionBackend $backend,
        private CheckpointStore $checkpointStore,
        private FlowControlStore $controlStore
    ) {
    }

    /**
     * @param array<int,array<string,mixed>> $pipeline
     * @param array<string,mixed> $options
     */
    public function start(
        string $controlRunId,
        mixed $initialData,
        array $pipeline,
        ?string $checkpointId = null,
        CheckpointRecoveryPlan|array|null $recoveryPlan = null,
        array $options = []
    ): FlowControlSnapshot {
        $controlRunId = trim($controlRunId);
        if ($controlRunId === '') {
            throw new InvalidArgumentException('controlRunId must not be empty.');
        }

        if ($this->controlStore->load($controlRunId) !== null) {
            throw new InvalidArgumentException('controlRunId is already present in the control-plane store.');
        }

        $plan = $this->normalizeRecoveryPlan($recoveryPlan);
        $capabilities = $this->backend->capabilities();
        $prospectiveState = [
            'checkpoint_id' => $checkpointId,
            'recovery_plan' => $plan->toArray(),
            'initial_data' => $initialData,
            'pipeline' => $pipeline,
            'options' => $options,
            'backend' => $capabilities->backend(),
            'topology_scope' => $capabilities->topologyScope(),
            'active_backend_run_id' => null,
            'backend_run_ids' => [],
            'control_status' => 'starting',
            'pause_mode' => null,
            'last_action' => 'start',
            'recovery_count' => 0,
        ];
        $this->assertStateSerializable($prospectiveState);

        $record = null;
        $predictedRunId = null;
        if ($capabilities->submissionMode() === 'run_immediately') {
            if (!$this->backend instanceof PredictiveRunIdExecutionBackend) {
                throw new RuntimeException(
                    'control-plane start requires predictive run IDs for immediate execution backends.'
                );
            }

            $predictedRunId = $this->backend->predictNextRunId();
            $prospectiveState['active_backend_run_id'] = $predictedRunId;
            $prospectiveState['backend_run_ids'] = [$predictedRunId];
            $prospectiveState['control_status'] = 'starting';

            $commit = $this->controlStore->create($controlRunId, $prospectiveState);
            if (!$commit->committed() || !$commit->record() instanceof FlowControlRecord) {
                throw new RuntimeException('control-plane start could not persist the new flow record.');
            }

            $record = $commit->record();
        }

        try {
            $backendSnapshot = $this->backend->start($initialData, $pipeline, $options);
        } catch (Throwable $error) {
            if ($record instanceof FlowControlRecord) {
                $failedState = $record->state();
                $failedState['active_backend_run_id'] = null;
                $failedState['backend_run_ids'] = [];
                $failedState['control_status'] = 'failed';
                $failedState['last_action'] = 'start_failed';

                try {
                    $this->replaceState($record, $failedState);
                } catch (Throwable) {
                    // Preserve the original start failure as the primary error.
                }
            }

            throw $error;
        }

        $state = $record?->state() ?? $prospectiveState;
        $existingRunIds = $record?->backendRunIds() ?? [];
        if ($predictedRunId !== null && $backendSnapshot->runId() !== $predictedRunId) {
            $existingRunIds = array_values(array_filter(
                $existingRunIds,
                static fn (string $runId): bool => $runId !== $predictedRunId
            ));
        }

        $state['active_backend_run_id'] = $backendSnapshot->runId();
        $state['backend_run_ids'] = array_values(array_unique([
            ...$existingRunIds,
            $backendSnapshot->runId(),
        ]));
        $state['control_status'] = $this->mapBackendStatus($backendSnapshot->status());

        if ($record instanceof FlowControlRecord) {
            $record = $this->replaceState($record, $state);
        } else {
            $commit = $this->controlStore->create($controlRunId, $state);
            if (!$commit->committed() || !$commit->record() instanceof FlowControlRecord) {
                throw new RuntimeException('control-plane start could not persist the new flow record.');
            }

            $record = $commit->record();
        }

        return $this->composeSnapshot($record, $backendSnapshot);
    }

    public function inspect(string $controlRunId): ?FlowControlSnapshot
    {
        $record = $this->controlStore->load($controlRunId);
        if (!$record instanceof FlowControlRecord) {
            return null;
        }

        return $this->composeSnapshot($record);
    }

    public function pause(string $controlRunId): FlowControlSnapshot
    {
        $record = $this->requireRecord($controlRunId);
        $backendSnapshot = $this->inspectBackendSnapshot($record);
        $state = $record->state();

        if (in_array($record->controlStatus(), ['completed', 'failed', 'cancelled', 'paused'], true)) {
            return $this->composeSnapshot($record, $backendSnapshot);
        }

        if (
            $backendSnapshot instanceof ExecutionRunSnapshot
            && $backendSnapshot->status() === 'queued'
            && $this->backend->capabilities()->supportsPersistedCancellation()
        ) {
            $this->backend->cancelRun($backendSnapshot->runId());
            $state['control_status'] = 'paused';
            $state['pause_mode'] = 'cancelled_before_claim';
        } elseif (
            $backendSnapshot instanceof ExecutionRunSnapshot
            && $backendSnapshot->status() === 'running'
            && $this->backend->capabilities()->supportsPersistedCancellation()
        ) {
            $this->backend->cancelRun($backendSnapshot->runId());
            $state['control_status'] = 'pause_requested';
            $state['pause_mode'] = 'persisted_cancel_requested';
        } else {
            $state['control_status'] = 'pause_requested';
            $state['pause_mode'] = 'intent_only';
        }

        $state['last_action'] = 'pause';
        $updated = $this->replaceState($record, $state);

        return $this->composeSnapshot($updated);
    }

    public function cancel(string $controlRunId): FlowControlSnapshot
    {
        $record = $this->requireRecord($controlRunId);
        $backendSnapshot = $this->inspectBackendSnapshot($record);
        $state = $record->state();

        if (in_array($record->controlStatus(), ['completed', 'failed', 'cancelled'], true)) {
            return $this->composeSnapshot($record, $backendSnapshot);
        }

        if (
            $backendSnapshot instanceof ExecutionRunSnapshot
            && in_array($backendSnapshot->status(), ['queued', 'running'], true)
            && $this->backend->capabilities()->supportsPersistedCancellation()
        ) {
            $this->backend->cancelRun($backendSnapshot->runId());
            $state['control_status'] = $backendSnapshot->status() === 'queued' ? 'cancelled' : 'cancel_requested';
        } else {
            $state['control_status'] = 'cancel_requested';
        }

        $state['pause_mode'] = null;
        $state['last_action'] = 'cancel';
        $updated = $this->replaceState($record, $state);

        return $this->composeSnapshot($updated);
    }

    public function resume(string $controlRunId): FlowControlSnapshot
    {
        $record = $this->requireRecord($controlRunId);
        $backendSnapshot = $this->inspectBackendSnapshot($record);

        if (
            $backendSnapshot instanceof ExecutionRunSnapshot
            && $backendSnapshot->status() === 'running'
            && $this->backend->capabilities()->supportsResumeById()
            && !in_array($record->controlStatus(), ['paused', 'pause_requested', 'cancelled', 'cancel_requested'], true)
        ) {
            $resumed = $this->backend->continueRun($backendSnapshot->runId());
            $state = $record->state();
            $state['control_status'] = $this->mapBackendStatus($resumed->status());
            $state['last_action'] = 'resume_run';
            $updated = $this->replaceState($record, $state);

            return $this->composeSnapshot($updated, $resumed);
        }

        if (in_array($record->controlStatus(), ['paused', 'pause_requested', 'failed', 'cancelled', 'cancel_requested'], true)) {
            $checkpoint = $this->loadCheckpointFor($record);
            if ($checkpoint instanceof CheckpointRecord) {
                return $this->restartRecord(
                    $record,
                    $record->recoveryPlan()->buildInitialData($record->initialData(), $checkpoint),
                    $checkpoint,
                    'recover_from_checkpoint'
                );
            }

            return $this->restartRecord(
                $record,
                $record->initialData(),
                null,
                'restart_from_initial_state'
            );
        }

        return $this->composeSnapshot($record, $backendSnapshot);
    }

    public function recoverFromCheckpoint(string $controlRunId): FlowControlSnapshot
    {
        $record = $this->requireRecord($controlRunId);
        $checkpoint = $this->loadCheckpointFor($record);
        if (!$checkpoint instanceof CheckpointRecord) {
            throw new RuntimeException('checkpoint-aware recovery requires a persisted checkpoint record.');
        }

        return $this->restartRecord(
            $record,
            $record->recoveryPlan()->buildInitialData($record->initialData(), $checkpoint),
            $checkpoint,
            'recover_from_checkpoint'
        );
    }

    private function requireRecord(string $controlRunId): FlowControlRecord
    {
        $controlRunId = trim($controlRunId);
        if ($controlRunId === '') {
            throw new InvalidArgumentException('controlRunId must not be empty.');
        }

        $record = $this->controlStore->load($controlRunId);
        if (!$record instanceof FlowControlRecord) {
            throw new RuntimeException('control-plane record was not found.');
        }

        return $record;
    }

    private function inspectBackendSnapshot(FlowControlRecord $record): ?ExecutionRunSnapshot
    {
        $runId = $record->activeBackendRunId();
        if ($runId === null) {
            return null;
        }

        return $this->backend->inspect($runId);
    }

    private function loadCheckpointFor(FlowControlRecord $record): ?CheckpointRecord
    {
        $checkpointId = $record->checkpointId();
        if ($checkpointId === null) {
            return null;
        }

        return $this->checkpointStore->load($checkpointId);
    }

    private function composeSnapshot(
        FlowControlRecord $record,
        ?ExecutionRunSnapshot $backendSnapshot = null,
        ?CheckpointRecord $checkpoint = null
    ): FlowControlSnapshot {
        $backendSnapshot ??= $this->inspectBackendSnapshot($record);
        $checkpoint ??= $this->loadCheckpointFor($record);
        $effectiveStatus = $this->effectiveControlStatus($record, $backendSnapshot);

        return new FlowControlSnapshot(
            $record,
            $this->backend->capabilities(),
            $backendSnapshot,
            $checkpoint,
            $effectiveStatus
        );
    }

    private function restartRecord(
        FlowControlRecord $record,
        mixed $initialData,
        ?CheckpointRecord $checkpoint,
        string $lastAction
    ): FlowControlSnapshot {
        $options = $record->options();
        $recoveryCount = $record->recoveryCount() + 1;
        $options['flow_control_recovery'] = [
            'control_run_id' => $record->controlRunId(),
            'checkpoint_id' => $checkpoint?->checkpointId(),
            'checkpoint_version' => $checkpoint?->version(),
            'previous_backend_run_id' => $record->activeBackendRunId(),
            'recovery_count' => $recoveryCount,
            'mode' => $checkpoint instanceof CheckpointRecord ? 'checkpoint' : 'initial_state',
        ];

        $backendSnapshot = $this->backend->start($initialData, $record->pipeline(), $options);
        $state = $record->state();
        $state['options'] = $options;
        $state['active_backend_run_id'] = $backendSnapshot->runId();
        $state['backend_run_ids'] = array_values(array_unique([
            ...$record->backendRunIds(),
            $backendSnapshot->runId(),
        ]));
        $state['control_status'] = $this->mapBackendStatus($backendSnapshot->status());
        $state['pause_mode'] = null;
        $state['last_action'] = $lastAction;
        $state['recovery_count'] = $recoveryCount;

        if ($checkpoint instanceof CheckpointRecord) {
            $state['last_checkpoint_version'] = $checkpoint->version();
            $state['last_checkpoint_etag'] = $checkpoint->etag();
        } else {
            $state['last_checkpoint_version'] = null;
            $state['last_checkpoint_etag'] = null;
        }

        $updated = $this->replaceState($record, $state);

        return $this->composeSnapshot($updated, $backendSnapshot, $checkpoint);
    }

    /**
     * @param array<string,mixed> $state
     */
    private function replaceState(FlowControlRecord $record, array $state): FlowControlRecord
    {
        $this->assertStateSerializable($state);
        $commit = $this->controlStore->replace($record->controlRunId(), $state, $record);
        if (!$commit->committed() || !$commit->record() instanceof FlowControlRecord) {
            throw new RuntimeException('control-plane state replacement could not be persisted.');
        }

        return $commit->record();
    }

    private function effectiveControlStatus(FlowControlRecord $record, ?ExecutionRunSnapshot $backendSnapshot): string
    {
        $stored = $record->controlStatus();

        if ($stored === 'pause_requested' && $backendSnapshot?->status() === 'cancelled') {
            return 'paused';
        }

        if ($stored === 'cancel_requested' && $backendSnapshot?->status() === 'cancelled') {
            return 'cancelled';
        }

        if (in_array($stored, ['paused', 'pause_requested', 'cancelled', 'cancel_requested'], true)) {
            return $stored;
        }

        if ($backendSnapshot instanceof ExecutionRunSnapshot) {
            return $this->mapBackendStatus($backendSnapshot->status());
        }

        return $stored;
    }

    private function mapBackendStatus(string $backendStatus): string
    {
        return match ($backendStatus) {
            'queued', 'running', 'completed', 'failed', 'cancelled' => $backendStatus,
            default => 'unknown',
        };
    }

    private function normalizeRecoveryPlan(CheckpointRecoveryPlan|array|null $recoveryPlan): CheckpointRecoveryPlan
    {
        if ($recoveryPlan instanceof CheckpointRecoveryPlan) {
            return $recoveryPlan;
        }

        if (is_array($recoveryPlan)) {
            return CheckpointRecoveryPlan::fromArray($recoveryPlan);
        }

        return CheckpointRecoveryPlan::mergeInitialWithCheckpointProgress();
    }

    /**
     * @param array<string,mixed> $state
     */
    private function assertStateSerializable(array $state): void
    {
        try {
            json_encode($state, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (JsonException $error) {
            throw new RuntimeException(
                'control-plane state must stay durable and JSON-serializable across restart boundaries.',
                previous: $error
            );
        }
    }
}
