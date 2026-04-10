--TEST--
Repo-local Flow PHP partition plan splits batches deterministically and merges completed partition snapshots honestly
--FILE--
<?php
require_once __DIR__ . '/../../demo/userland/flow-php/src/ExecutionBackend.php';
require_once __DIR__ . '/../../demo/userland/flow-php/src/Partitioning.php';

use King\Flow\OrchestratorExecutionBackend;
use King\Flow\PartitionMergeResult;
use King\Flow\PartitionPlan;

function flow_partition_plan_emit(array $context): array
{
    $input = $context['input'] ?? [];

    return ['output' => [
        'partition_id' => $input['partition_id'] ?? null,
        'batch_id' => $input['batch_id'] ?? null,
        'ids' => array_column($input['rows'] ?? [], 'id'),
    ]];
}

$plan = PartitionPlan::fromRowsByField([
    ['id' => 10, 'region' => 'US', 'payload' => str_repeat('a', 12)],
    ['id' => 11, 'region' => 'EMEA', 'payload' => str_repeat('b', 12)],
    ['id' => 12, 'region' => 'US', 'payload' => str_repeat('c', 12)],
    ['id' => 13, 'region' => 'APAC', 'payload' => str_repeat('d', 12)],
    ['id' => 14, 'region' => 'US', 'payload' => str_repeat('e', 12)],
    ['id' => 15, 'region' => 'EMEA', 'payload' => str_repeat('f', 12)],
], 'region', 2, 120);

$backend = new OrchestratorExecutionBackend();
$backend->registerTool('emit', ['model' => 'gpt-sim', 'max_tokens' => 64]);
$backend->registerHandler('emit', 'flow_partition_plan_emit');

$batches = $plan->batches();
$snapshots = [];
foreach (array_reverse($batches) as $batch) {
    $snapshots[] = $backend->start(
        [
            'partition_id' => $batch->partitionId(),
            'batch_id' => $batch->batchId(),
            'rows' => $batch->rows(),
        ],
        [$batch->annotateStep(['tool' => 'emit'])],
        ['trace_id' => 'flow-partition-plan-613-' . $batch->batchSequence()]
    );
}

$merge = PartitionMergeResult::fromExecutionSnapshots($plan, $snapshots);

var_dump($plan->partitionIds() === [
    'partition-000-apac',
    'partition-001-emea',
    'partition-002-us',
]);
var_dump($plan->partitionCount() === 3);
var_dump($plan->batchCount() === 4);
var_dump($plan->mergeStrategy() === 'partition_then_batch');
var_dump($plan->backpressureContract() === 'bounded_partition_batches');
var_dump($batches[0]->batchId() === 'partition-000-apac-batch-000000');
var_dump($batches[1]->batchId() === 'partition-001-emea-batch-000000');
var_dump($batches[2]->batchId() === 'partition-002-us-batch-000000');
var_dump($batches[3]->batchId() === 'partition-002-us-batch-000001');
var_dump($batches[2]->annotateStep(['tool' => 'emit'])['partition_id'] === 'partition-002-us');
var_dump($batches[2]->annotateStep(['tool' => 'emit'])['batch_id'] === 'partition-002-us-batch-000000');
var_dump($snapshots[0]->steps()[0]['telemetry_adapter']['partition_id'] === 'partition-002-us');
var_dump($snapshots[0]->steps()[0]['telemetry_adapter']['batch_id'] === 'partition-002-us-batch-000001');
var_dump($merge->complete() === true);
var_dump($merge->pendingBatchIds() === []);
var_dump($merge->failedBatches() === []);
var_dump(array_map(
    static fn (array $payload): string => (string) ($payload['batch_id'] ?? ''),
    $merge->mergedOutputs()
) === [
    'partition-000-apac-batch-000000',
    'partition-001-emea-batch-000000',
    'partition-002-us-batch-000000',
    'partition-002-us-batch-000001',
]);
var_dump($merge->partitionOutputs()['partition-001-emea'][0]['ids'] === [11, 15]);
var_dump($merge->partitionOutputs()['partition-002-us'][0]['ids'] === [10, 12]);
var_dump($merge->partitionOutputs()['partition-002-us'][1]['ids'] === [14]);
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
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
