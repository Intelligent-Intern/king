--TEST--
Repo-local Flow PHP failure taxonomy classifies checkpoint conflicts and execution runtime and backend failures
--SKIPIF--
<?php
if (!function_exists('proc_open')) {
    echo "skip proc_open is required";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require_once __DIR__ . '/../../demo/userland/flow-php/src/StreamingSource.php';
require_once __DIR__ . '/../../demo/userland/flow-php/src/StreamingSink.php';
require_once __DIR__ . '/../../demo/userland/flow-php/src/CheckpointStore.php';
require_once __DIR__ . '/../../demo/userland/flow-php/src/ExecutionBackend.php';
require_once __DIR__ . '/../../demo/userland/flow-php/src/FailureTaxonomy.php';

use King\Flow\CheckpointState;
use King\Flow\FlowFailureTaxonomy;
use King\Flow\ObjectStoreCheckpointStore;
use King\Flow\OrchestratorExecutionBackend;
use King\Flow\SourceCursor;

function king_flow_failure_taxonomy_cleanup_tree(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            king_flow_failure_taxonomy_cleanup_tree($path . '/' . $entry);
        }

        @rmdir($path);
        return;
    }

    @unlink($path);
}

$root = sys_get_temp_dir() . '/king-flow-failure-taxonomy-612-' . getmypid();
king_flow_failure_taxonomy_cleanup_tree($root);
mkdir($root, 0700, true);

king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
    'chunk_size_kb' => 1,
]);

$store = new ObjectStoreCheckpointStore('checkpoints/orders-import');
$created = $store->create(
    'partition-1',
    new CheckpointState(
        ['records' => 1, 'bytes' => 10],
        ['resume_from' => 'after_source_chunk'],
        new SourceCursor('object_store', 'orders.ndjson', 10, 'range_offset', [
            'next_offset' => 10,
        ]),
        null,
        ['writer' => 'seed']
    )
);

$viewA = $store->load('partition-1');
$viewB = $store->load('partition-1');

$advanced = $store->replace(
    'partition-1',
    new CheckpointState(
        ['records' => 2, 'bytes' => 20],
        ['resume_from' => 'after_checkpoint_commit'],
        new SourceCursor('object_store', 'orders.ndjson', 20, 'range_offset', [
            'next_offset' => 20,
        ]),
        null,
        ['writer' => 'A']
    ),
    $viewA
);

$conflict = $store->replace(
    'partition-1',
    new CheckpointState(
        ['records' => 99, 'bytes' => 990],
        ['resume_from' => 'stale-writer'],
        new SourceCursor('object_store', 'orders.ndjson', 990, 'range_offset', [
            'next_offset' => 990,
        ]),
        null,
        ['writer' => 'B']
    ),
    $viewB
);
$checkpointFailure = FlowFailureTaxonomy::fromCheckpointCommitResult($conflict, 'replace');

function flow_exec_runtime_failure(array $context): array
{
    throw new RuntimeException('etl-userland-runtime');
}

$execution = new OrchestratorExecutionBackend();
$execution->registerTool('explode', ['model' => 'gpt-sim', 'max_tokens' => 64]);
$execution->registerHandler('explode', 'flow_exec_runtime_failure');

try {
    $execution->start(
        ['text' => 'runtime-failure', 'history' => []],
        [['tool' => 'explode']],
        ['trace_id' => 'flow-failure-taxonomy-runtime-612']
    );
    $runtimeFailure = null;
} catch (Throwable $error) {
    $lastRunId = king_system_get_component_info('pipeline_orchestrator')['configuration']['last_run_id'] ?? '';
    $runtimeSnapshot = $execution->inspect((string) $lastRunId);
    $runtimeFailure = $runtimeSnapshot === null
        ? null
        : FlowFailureTaxonomy::fromExecutionSnapshot($runtimeSnapshot, 'start');
}

$unsafeQueuePath = sys_get_temp_dir() . '/king-flow-failure-taxonomy-unsafe-queue-' . getmypid();
@mkdir($unsafeQueuePath, 0700, true);
@chmod($unsafeQueuePath, 0777);

$backendScript = tempnam(sys_get_temp_dir(), 'king-flow-failure-taxonomy-backend-');
$backendTemplate = <<<'PHP'
<?php
require_once __EXECUTION_BACKEND_PATH__;
require_once __FAILURE_TAXONOMY_PATH__;

use King\Flow\FlowFailureTaxonomy;
use King\Flow\OrchestratorExecutionBackend;

$backend = new OrchestratorExecutionBackend();
$backend->registerTool('summarizer', ['model' => 'gpt-sim', 'max_tokens' => 64]);

try {
    $backend->start(
        ['text' => 'backend-failure'],
        [['tool' => 'summarizer']],
        ['trace_id' => 'flow-failure-taxonomy-backend-612']
    );
    echo json_encode(['unexpected' => true]), "\n";
} catch (Throwable $error) {
    echo json_encode(
        FlowFailureTaxonomy::fromThrowable($error, 'execution', 'start', ['backend' => 'file_worker'])->toArray(),
        JSON_INVALID_UTF8_SUBSTITUTE
    ), "\n";
}
PHP;
file_put_contents(
    $backendScript,
    str_replace(
        ['__EXECUTION_BACKEND_PATH__', '__FAILURE_TAXONOMY_PATH__'],
        [
            var_export(dirname(__DIR__, 2) . '/demo/userland/flow-php/src/ExecutionBackend.php', true),
            var_export(dirname(__DIR__, 2) . '/demo/userland/flow-php/src/FailureTaxonomy.php', true),
        ],
        $backendTemplate
    )
);

$backendCommand = [
    PHP_BINARY,
    '-n',
    '-d', 'extension=' . dirname(__DIR__) . '/modules/king.so',
    '-d', 'king.security_allow_config_override=1',
    '-d', 'king.orchestrator_execution_backend=file_worker',
    '-d', 'king.orchestrator_worker_queue_path=' . $unsafeQueuePath,
    $backendScript,
];
$backendProcess = proc_open($backendCommand, [
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $backendPipes);
$backendStdout = stream_get_contents($backendPipes[1]);
$backendStderr = stream_get_contents($backendPipes[2]);
fclose($backendPipes[1]);
fclose($backendPipes[2]);
$backendStatus = proc_close($backendProcess);
$backendFailure = json_decode(trim($backendStdout), true);

var_dump($created->committed());
var_dump($advanced->committed());
var_dump($checkpointFailure?->category() === 'resume_conflict');
var_dump($checkpointFailure?->retryDisposition() === 'reload_checkpoint_and_resume');
var_dump($checkpointFailure?->retryable() === true);

var_dump($runtimeFailure?->category() === 'runtime');
var_dump($runtimeFailure?->retryDisposition() === 'caller_managed_retry');
var_dump($runtimeFailure?->retryable() === true);

var_dump($backendStatus === 0);
var_dump(trim($backendStderr) === '');
var_dump(($backendFailure['category'] ?? null) === 'backend');
var_dump(($backendFailure['retry_disposition'] ?? null) === 'retry_after_backend_recovery');
var_dump(($backendFailure['retryable'] ?? null) === true);

@unlink($backendScript);
if (is_dir($unsafeQueuePath)) {
    foreach (scandir($unsafeQueuePath) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        @unlink($unsafeQueuePath . '/' . $entry);
    }
    @rmdir($unsafeQueuePath);
}
king_flow_failure_taxonomy_cleanup_tree($root);
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
bool(true)
bool(true)
bool(true)
