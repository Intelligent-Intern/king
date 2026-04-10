--TEST--
Repo-local Flow PHP control plane reconciles predictive run-id races without stale backend bindings
--FILE--
<?php
require_once __DIR__ . '/../../demo/userland/flow-php/src/ControlPlane.php';

use King\Flow\CheckpointCommitResult;
use King\Flow\CheckpointRecord;
use King\Flow\CheckpointState;
use King\Flow\CheckpointStore;
use King\Flow\ExecutionBackendCapabilities;
use King\Flow\ExecutionRunSnapshot;
use King\Flow\FlowControlCommitResult;
use King\Flow\FlowControlPlane;
use King\Flow\FlowControlRecord;
use King\Flow\FlowControlStore;
use King\Flow\PredictiveRunIdExecutionBackend;

final class InMemoryFlowControlStore implements FlowControlStore
{
    /** @var array<string,FlowControlRecord> */
    private array $records = [];

    public function load(string $controlRunId): ?FlowControlRecord
    {
        return $this->records[$controlRunId] ?? null;
    }

    public function create(string $controlRunId, array $state): FlowControlCommitResult
    {
        if (isset($this->records[$controlRunId])) {
            return new FlowControlCommitResult(false, true, $this->records[$controlRunId], 'conflict');
        }

        $record = new FlowControlRecord(
            $controlRunId,
            'memory://' . $controlRunId,
            1,
            'etag-1',
            $state
        );
        $this->records[$controlRunId] = $record;

        return new FlowControlCommitResult(true, false, $record);
    }

    public function replace(string $controlRunId, array $state, FlowControlRecord $expected): FlowControlCommitResult
    {
        $current = $this->records[$controlRunId] ?? null;
        if (!$current instanceof FlowControlRecord) {
            return new FlowControlCommitResult(false, true, null, 'not_found');
        }

        if ($current->etag() !== $expected->etag() || $current->version() !== $expected->version()) {
            return new FlowControlCommitResult(false, true, $current, 'conflict');
        }

        $version = $current->version() + 1;
        $etag = 'etag-' . $version;
        $record = new FlowControlRecord(
            $controlRunId,
            $current->objectId(),
            $version,
            $etag,
            $state
        );
        $this->records[$controlRunId] = $record;

        return new FlowControlCommitResult(true, false, $record);
    }
}

final class NullCheckpointStore implements CheckpointStore
{
    public function load(string $checkpointId): ?CheckpointRecord
    {
        return null;
    }

    public function create(string $checkpointId, CheckpointState $state): CheckpointCommitResult
    {
        throw new \LogicException('checkpoint creation is not used in this contract.');
    }

    public function replace(string $checkpointId, CheckpointState $state, CheckpointRecord $expected): CheckpointCommitResult
    {
        throw new \LogicException('checkpoint replacement is not used in this contract.');
    }
}

final class PredictiveRaceBackend implements PredictiveRunIdExecutionBackend
{
    /** @var list<string> */
    public array $inspected = [];

    /** @var list<string> */
    public array $cancelled = [];

    /** @var array<string,string> */
    private array $statuses = [
        'run-1' => 'running',
        'run-2' => 'running',
    ];

    /**
     * @param array<string,mixed> $config
     */
    public function registerTool(string $toolName, array $config): void
    {
    }

    public function registerHandler(string $toolName, callable $handler): void
    {
    }

    public function capabilities(): ExecutionBackendCapabilities
    {
        return new ExecutionBackendCapabilities(
            'local',
            'single_process',
            'run_immediately',
            'resume_run_by_id',
            'not_supported',
            'persisted_run_cancel',
            'required_for_local_execution',
            'same_process_registered_handlers'
        );
    }

    public function predictNextRunId(): string
    {
        return 'run-1';
    }

    public function start(mixed $initialData, array $pipeline, array $options = []): ExecutionRunSnapshot
    {
        return $this->snapshotFor('run-2');
    }

    public function continueRun(string $runId): ExecutionRunSnapshot
    {
        return $this->snapshotFor($runId);
    }

    public function claimNext(): ExecutionRunSnapshot|false
    {
        return false;
    }

    public function inspect(string $runId): ?ExecutionRunSnapshot
    {
        $this->inspected[] = $runId;
        if (!isset($this->statuses[$runId])) {
            return null;
        }

        return $this->snapshotFor($runId);
    }

    public function cancelRun(string $runId): bool
    {
        $this->cancelled[] = $runId;
        if (!isset($this->statuses[$runId])) {
            return false;
        }

        $this->statuses[$runId] = 'cancelled';

        return true;
    }

    private function snapshotFor(string $runId): ExecutionRunSnapshot
    {
        return new ExecutionRunSnapshot([
            'run_id' => $runId,
            'status' => $this->statuses[$runId] ?? 'failed',
            'execution_backend' => 'local',
            'topology_scope' => 'single_process',
        ]);
    }
}

$backend = new PredictiveRaceBackend();
$controlStore = new InMemoryFlowControlStore();
$control = new FlowControlPlane(
    $backend,
    new NullCheckpointStore(),
    $controlStore
);

$started = $control->start(
    'control-race',
    ['input' => 'alpha'],
    [
        ['tool' => 'prepare'],
    ],
    null,
    null,
    ['trace_id' => 'flow-control-race-665']
)->toArray();

var_dump(($started['control_status'] ?? null) === 'running');
var_dump(($started['active_backend_run_id'] ?? null) === 'run-2');
var_dump(($started['backend_run_ids'] ?? null) === ['run-2']);
var_dump(($started['last_action'] ?? null) === 'start');

$persisted = $control->inspect('control-race')?->toArray();
var_dump(($persisted['active_backend_run_id'] ?? null) === 'run-2');
var_dump(($persisted['backend_run_ids'] ?? null) === ['run-2']);

$cancelled = $control->cancel('control-race')->toArray();
var_dump(($cancelled['active_backend_run_id'] ?? null) === 'run-2');
var_dump($backend->cancelled === ['run-2']);
var_dump(!in_array('run-1', $backend->cancelled, true));
var_dump(!in_array('run-1', $backend->inspected, true));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
