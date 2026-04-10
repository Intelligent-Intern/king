--TEST--
Repo-local Flow PHP ETL pipeline proves source checkpoint sink telemetry and remote-peer execution end to end
--SKIPIF--
<?php
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) {
    echo "skip proc_open and stream_socket_server are required";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/orchestrator_remote_peer_helper.inc';
require __DIR__ . '/telemetry_otlp_test_helper.inc';

function king_flow_etl_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function king_flow_etl_cleanup_tree(string $path): void
{
    if ($path === '' || !file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            king_flow_etl_cleanup_tree($path . '/' . $entry);
        }

        @rmdir($path);
        return;
    }

    @unlink($path);
}

function king_flow_etl_run_command(string $command): array
{
    $process = proc_open(
        ['/bin/bash', '-lc', $command],
        [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );

    king_flow_etl_assert(is_resource($process), 'failed to launch command: ' . $command);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'status' => proc_close($process),
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

function king_flow_etl_decode_controller_result(array $result, string $label): array
{
    king_flow_etl_assert(
        ($result['status'] ?? null) === 0,
        $label . ' exited with status ' . json_encode($result['status'] ?? null)
            . ' stdout=' . json_encode($result['stdout'] ?? '')
            . ' stderr=' . json_encode($result['stderr'] ?? '')
    );
    king_flow_etl_assert(
        trim((string) ($result['stderr'] ?? '')) === '',
        $label . ' wrote unexpected stderr: ' . json_encode($result['stderr'] ?? '')
    );

    $decoded = json_decode(trim((string) ($result['stdout'] ?? '')), true);
    king_flow_etl_assert(is_array($decoded), $label . ' did not return valid JSON.');

    return $decoded;
}

function king_flow_etl_attribute_map(array $attributes): array
{
    $map = [];

    foreach ($attributes as $attribute) {
        if (!is_array($attribute) || !isset($attribute['key']) || !is_array($attribute['value'] ?? null)) {
            continue;
        }

        $value = $attribute['value'];
        if (array_key_exists('stringValue', $value)) {
            $map[$attribute['key']] = $value['stringValue'];
            continue;
        }
        if (array_key_exists('intValue', $value)) {
            $map[$attribute['key']] = (string) $value['intValue'];
            continue;
        }
        if (array_key_exists('doubleValue', $value)) {
            $map[$attribute['key']] = (string) $value['doubleValue'];
            continue;
        }
        if (array_key_exists('boolValue', $value)) {
            $map[$attribute['key']] = $value['boolValue'] ? 'true' : 'false';
        }
    }

    return $map;
}

function king_flow_etl_collect_spans(array $capture): array
{
    $spans = [];

    foreach ($capture as $entry) {
        if (($entry['path'] ?? null) !== '/v1/traces') {
            continue;
        }

        $payload = json_decode((string) ($entry['body'] ?? ''), true);
        king_flow_etl_assert(is_array($payload), 'collector emitted malformed OTLP trace JSON.');

        $bodySpans = $payload['resourceSpans'][0]['scopeSpans'][0]['spans'] ?? null;
        king_flow_etl_assert(is_array($bodySpans), 'collector emitted malformed OTLP span payload.');

        foreach ($bodySpans as $span) {
            if (is_array($span)) {
                $spans[] = $span;
            }
        }
    }

    return $spans;
}

function king_flow_etl_collect_metric_values(array $capture): array
{
    $metrics = [];

    foreach ($capture as $entry) {
        if (($entry['path'] ?? null) !== '/v1/metrics') {
            continue;
        }

        $payload = json_decode((string) ($entry['body'] ?? ''), true);
        king_flow_etl_assert(is_array($payload), 'collector emitted malformed OTLP metric JSON.');

        $bodyMetrics = $payload['resourceMetrics'][0]['scopeMetrics'][0]['metrics'] ?? null;
        king_flow_etl_assert(is_array($bodyMetrics), 'collector emitted malformed OTLP metric payload.');

        foreach ($bodyMetrics as $metric) {
            if (!is_array($metric) || !isset($metric['name'])) {
                continue;
            }

            $dataPoint = $metric['sum']['dataPoints'][0]
                ?? $metric['gauge']['dataPoints'][0]
                ?? $metric['histogram']['dataPoints'][0]
                ?? $metric['summary']['dataPoints'][0]
                ?? null;
            if (!is_array($dataPoint)) {
                continue;
            }

            $value = null;
            if (array_key_exists('asInt', $dataPoint)) {
                $value = (int) $dataPoint['asInt'];
            } elseif (array_key_exists('asDouble', $dataPoint)) {
                $value = (float) $dataPoint['asDouble'];
            } elseif (array_key_exists('count', $dataPoint)) {
                $value = (int) $dataPoint['count'];
            }

            if ($value === null) {
                continue;
            }

            if (isset($metrics[$metric['name']]) && is_numeric($metrics[$metric['name']])) {
                $metrics[$metric['name']] += $value;
                continue;
            }

            $metrics[$metric['name']] = $value;
        }
    }

    return $metrics;
}

function king_flow_etl_filter_pipeline_spans(array $spans, string $name, string $backend): array
{
    $matches = [];

    foreach ($spans as $span) {
        if (($span['name'] ?? null) !== $name) {
            continue;
        }

        $attributes = king_flow_etl_attribute_map($span['attributes'] ?? []);
        if (
            ($attributes['component'] ?? null) === 'pipeline_orchestrator'
            && ($attributes['telemetry_adapter_contract'] ?? null) === 'run_partition_batch_retry_failure_identity'
            && ($attributes['execution_backend'] ?? null) === $backend
        ) {
            $matches[] = [
                'span' => $span,
                'attributes' => $attributes,
            ];
        }
    }

    return $matches;
}

$extensionPath = dirname(__DIR__) . '/modules/king.so';
$flowSrcDir = dirname(__DIR__, 2) . '/demo/userland/flow-php/src';
$controllerScript = tempnam(sys_get_temp_dir(), 'king-flow-etl-e2e-controller-');
$remoteBootstrapScript = tempnam(sys_get_temp_dir(), 'king-flow-etl-e2e-remote-bootstrap-');
$localStatePath = tempnam(sys_get_temp_dir(), 'king-flow-etl-e2e-local-state-');
$remoteStatePath = tempnam(sys_get_temp_dir(), 'king-flow-etl-e2e-remote-state-');
$localRoot = sys_get_temp_dir() . '/king-flow-etl-e2e-local-store-' . getmypid();
$remoteRoot = sys_get_temp_dir() . '/king-flow-etl-e2e-remote-store-' . getmypid();

@unlink($localStatePath);
@unlink($remoteStatePath);
king_flow_etl_cleanup_tree($localRoot);
king_flow_etl_cleanup_tree($remoteRoot);
mkdir($localRoot, 0700, true);
mkdir($remoteRoot, 0700, true);

$controllerTemplate = <<<'PHP'
<?php
require_once __FLOW_SRC_DIR__ . '/SerializationBridge.php';
require_once __FLOW_SRC_DIR__ . '/CheckpointStore.php';
require_once __FLOW_SRC_DIR__ . '/ExecutionBackend.php';
require_once __FLOW_SRC_DIR__ . '/Partitioning.php';

use King\Flow\CheckpointState;
use King\Flow\NdjsonCodec;
use King\Flow\ObjectStoreByteSink;
use King\Flow\ObjectStoreByteSource;
use King\Flow\ObjectStoreCheckpointStore;
use King\Flow\ObjectStoreDataset;
use King\Flow\OrchestratorExecutionBackend;
use King\Flow\PartitionAttempt;
use King\Flow\PartitionMergeResult;
use King\Flow\PartitionPlan;
use King\Flow\SerializedRecordReader;
use King\Flow\SerializedRecordWriter;
use King\Flow\SinkCursor;
use King\Flow\SinkWriteResult;
use King\Flow\SourceCursor;
use King\Flow\StreamingSink;

function king_flow_etl_controller_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function king_flow_etl_transform_orders(array $context): array
{
    $input = $context['input'] ?? null;
    king_flow_etl_controller_assert(is_array($input), 'transform handler expected array input.');

    $rows = $input['rows'] ?? null;
    king_flow_etl_controller_assert(is_array($rows), 'transform handler expected rows.');

    $partitionId = $context['step']['definition']['partition_id'] ?? null;
    $batchId = $context['step']['definition']['batch_id'] ?? null;
    king_flow_etl_controller_assert(is_string($partitionId) && $partitionId !== '', 'transform handler lost partition_id.');
    king_flow_etl_controller_assert(is_string($batchId) && $batchId !== '', 'transform handler lost batch_id.');

    $backend = $context['run']['execution_backend'] ?? null;
    $topology = $context['run']['topology_scope'] ?? null;

    $transformed = [];
    foreach ($rows as $row) {
        king_flow_etl_controller_assert(is_array($row), 'transform handler received a non-array row.');
        $transformed[] = [
            'order_id' => (string) ($row['order_id'] ?? ''),
            'country' => strtoupper((string) ($row['country'] ?? '')),
            'net_total' => round((float) ($row['total'] ?? 0), 2),
            'gross_total' => round(((float) ($row['total'] ?? 0)) * 1.19, 2),
            'partition_id' => $partitionId,
            'batch_id' => $batchId,
            'execution_backend' => is_string($backend) ? $backend : 'unknown',
            'topology_scope' => is_string($topology) ? $topology : 'unknown',
        ];
    }

    return ['output' => [
        'partition_id' => $partitionId,
        'batch_id' => $batchId,
        'rows' => $transformed,
        'row_count' => count($transformed),
    ]];
}

/**
 * @param array<int,array<string,mixed>> $records
 * @param array<string,mixed> $options
 * @return array{write:SerializedRecordWriteResult,descriptor:array<string,mixed>|null,payload_hash:string}
 */
function king_flow_etl_write_ndjson_object(string $objectId, array $records, array $options = []): array
{
    $codec = new NdjsonCodec();
    $state = [];
    $payload = '';
    foreach ($records as $index => $record) {
        $payload .= $codec->encodeRecord($record, $index + 1, $state);
    }

    $options['content_type'] ??= 'application/x-ndjson';
    $options['object_type'] ??= 'document';
    $options['cache_policy'] ??= 'smart_cdn';
    $options['expires_at'] ??= '2099-01-01T00:00:00Z';
    $options['integrity_sha256'] = hash('sha256', $payload);

    $writer = new SerializedRecordWriter(
        static fn (?SinkCursor $cursor): StreamingSink => new ObjectStoreByteSink($objectId, $options, $cursor),
        $codec
    );

    foreach ($records as $record) {
        $write = $writer->writeRecord($record);
        if ($write->failure() !== null) {
            throw new RuntimeException('failed to write NDJSON record for object ' . $objectId);
        }
    }

    $complete = $writer->complete();
    if ($complete->failure() !== null) {
        throw new RuntimeException(
            'failed to complete NDJSON object ' . $objectId . ': '
            . json_encode($complete->failure()->toArray(), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
        );
    }

    $descriptor = (new ObjectStoreDataset($objectId, 8))->describe();

    return [
        'write' => $complete,
        'descriptor' => $descriptor?->toArray(),
        'payload_hash' => $options['integrity_sha256'],
    ];
}

/**
 * @return array{records:array<int,array<string,mixed>>,result:array<string,mixed>}
 */
function king_flow_etl_read_ndjson_from_source(
    string $objectId,
    int $chunkBytes,
    ?SourceCursor $cursor = null,
    ?int $stopAfter = null
): array {
    $records = [];
    $reader = new SerializedRecordReader(new ObjectStoreByteSource($objectId, $chunkBytes), new NdjsonCodec());
    $result = $reader->pumpRecords(
        static function (mixed $record, SourceCursor $nextCursor, bool $complete) use (&$records, $stopAfter): bool {
            king_flow_etl_controller_assert(is_array($record), 'source reader emitted a non-array record.');
            $records[] = $record;

            return $stopAfter === null ? true : count($records) < $stopAfter;
        },
        $cursor
    );

    return [
        'records' => $records,
        'result' => [
            'complete' => $result->complete(),
            'records_delivered' => $result->recordsDelivered(),
            'bytes_delivered' => $result->bytesDelivered(),
            'cursor' => $result->cursor()->toArray(),
        ],
    ];
}

/**
 * @return array<int,array<string,mixed>>
 */
function king_flow_etl_read_ndjson_from_dataset(string $objectId, int $chunkBytes = 8): array
{
    $records = [];
    $dataset = new ObjectStoreDataset($objectId, $chunkBytes);
    $reader = new SerializedRecordReader($dataset->source(), new NdjsonCodec());
    $reader->pumpRecords(
        static function (mixed $record) use (&$records): bool {
            king_flow_etl_controller_assert(is_array($record), 'dataset reader emitted a non-array record.');
            $records[] = $record;

            return true;
        }
    );

    return $records;
}

$root = $argv[1] ?? '';
$collectorPort = isset($argv[2]) ? (int) $argv[2] : 0;
$scenario = $argv[3] ?? 'local';

king_flow_etl_controller_assert($root !== '', 'missing object-store root path.');
king_flow_etl_controller_assert($collectorPort > 0, 'missing collector port.');

king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
    'backup_backend' => 'distributed',
    'chunk_size_kb' => 1,
]);

king_telemetry_init([
    'otel_exporter_endpoint' => 'http://127.0.0.1:' . $collectorPort,
    'exporter_timeout_ms' => 500,
]);

$rawOrders = [
    ['order_id' => 'A-1', 'country' => 'de', 'total' => 10.0],
    ['order_id' => 'A-2', 'country' => 'us', 'total' => 12.5],
    ['order_id' => 'A-3', 'country' => 'de', 'total' => 3.4],
    ['order_id' => 'A-4', 'country' => 'fr', 'total' => 7.0],
    ['order_id' => 'A-5', 'country' => 'us', 'total' => 2.6],
];

$input = king_flow_etl_write_ndjson_object('raw-orders.ndjson', $rawOrders, [
    'object_type' => 'document',
]);

$firstRead = king_flow_etl_read_ndjson_from_source('raw-orders.ndjson', 11, null, 2);
$checkpointStore = new ObjectStoreCheckpointStore('checkpoints/orders-import', [
    'expires_at' => '2099-01-01T00:00:00Z',
]);
$checkpointId = 'orders-import-' . $scenario;
$checkpoint = $checkpointStore->create(
    $checkpointId,
    new CheckpointState(
        ['records' => count($firstRead['records']), 'batches' => 0],
        ['resume_from' => 'source_cursor', 'phase' => 'ingest_paused'],
        SourceCursor::fromArray($firstRead['result']['cursor']),
        null,
        ['phase' => 'ingest_paused', 'records' => $firstRead['records']]
    )
);
king_flow_etl_controller_assert($checkpoint->committed(), 'failed to create initial ETL checkpoint.');

$resumeCursor = $checkpoint->record()?->state()->sourceCursor();
king_flow_etl_controller_assert($resumeCursor instanceof SourceCursor, 'checkpoint lost the resumable source cursor.');
$secondRead = king_flow_etl_read_ndjson_from_source('raw-orders.ndjson', 11, $resumeCursor);
$allRecords = array_merge($firstRead['records'], $secondRead['records']);
king_flow_etl_controller_assert(count($allRecords) === count($rawOrders), 'combined source read lost records.');

$plan = PartitionPlan::fromRowsByField($allRecords, 'country', 2, 1024);
$backend = new OrchestratorExecutionBackend();
$backend->registerTool('transform-orders', [
    'label' => 'orders-transform-v1',
    'max_tokens' => 128,
]);
$backend->registerHandler('transform-orders', 'king_flow_etl_transform_orders');

$snapshots = [];
$attempts = [];
$manifest = [];
$recordsProcessed = 0;
$checkpointRecord = $checkpoint->record();

foreach ($plan->batches() as $batch) {
    $snapshot = $backend->start(
        ['rows' => $batch->rows(), 'scenario' => $scenario],
        [$batch->annotateStep(['tool' => 'transform-orders'])],
        $batch->executionOptions([
            'trace_id' => 'orders-etl-' . $scenario . '-' . $batch->batchId(),
        ])
    );

    king_flow_etl_controller_assert($snapshot->status() === 'completed', 'batch run did not complete.');

    $payload = $snapshot->payload();
    king_flow_etl_controller_assert(is_array($payload), 'batch run returned a non-array payload.');
    king_flow_etl_controller_assert(is_array($payload['rows'] ?? null), 'batch payload lost transformed rows.');

    $objectId = 'warehouse--' . $batch->partitionId() . '--' . $batch->batchId() . '.ndjson';
    $output = king_flow_etl_write_ndjson_object($objectId, $payload['rows'], [
        'object_type' => 'document',
    ]);

    $manifest[] = [
        'object_id' => $objectId,
        'partition_id' => $batch->partitionId(),
        'batch_id' => $batch->batchId(),
        'descriptor' => $output['descriptor'],
    ];

    $recordsProcessed += count($payload['rows']);
    $checkpointState = new CheckpointState(
        ['records' => $recordsProcessed, 'batches' => count($manifest)],
        [
            'resume_from' => 'after_batch_commit',
            'last_batch_id' => $batch->batchId(),
            'plan_batch_count' => $plan->batchCount(),
        ],
        SourceCursor::fromArray($secondRead['result']['cursor']),
        $output['write']->cursor(),
        [
            'manifest' => $manifest,
            'processed_batch_ids' => array_column($manifest, 'batch_id'),
            'partition_count' => $plan->partitionCount(),
            'batch_count' => $plan->batchCount(),
        ]
    );

    king_flow_etl_controller_assert($checkpointRecord instanceof \King\Flow\CheckpointRecord, 'checkpoint record disappeared.');
    $checkpointCommit = $checkpointStore->replace($checkpointId, $checkpointState, $checkpointRecord);
    king_flow_etl_controller_assert($checkpointCommit->committed(), 'failed to replace ETL checkpoint after batch commit.');
    $checkpointRecord = $checkpointCommit->record();

    $snapshots[] = $snapshot->toArray();
    $attempt = PartitionAttempt::fromExecutionSnapshot($snapshot);
    if ($attempt instanceof PartitionAttempt) {
        $attempts[] = $attempt->toArray();
    }

    king_flow_etl_controller_assert(
        king_telemetry_flush(),
        'failed to flush telemetry after batch ' . $batch->batchId()
    );
}

$snapshotObjects = array_map(
    static fn (array $snapshot): \King\Flow\ExecutionRunSnapshot => new \King\Flow\ExecutionRunSnapshot($snapshot),
    $snapshots
);
$merge = PartitionMergeResult::fromExecutionSnapshots($plan, $snapshotObjects);

$outputRecords = [];
foreach ($manifest as $entry) {
    $outputRecords = array_merge($outputRecords, king_flow_etl_read_ndjson_from_dataset($entry['object_id']));
}

$liveMetrics = king_telemetry_get_metrics();
$flushResult = king_telemetry_flush();
$telemetryStatus = king_telemetry_get_status();
$component = king_system_get_component_info('pipeline_orchestrator');

echo json_encode([
    'mode' => $backend->capabilities()->backend(),
    'capabilities' => $backend->capabilities()->toArray(),
    'input' => [
        'write_complete' => $input['write']->complete(),
        'write_committed' => $input['write']->transportCommitted(),
        'descriptor' => $input['descriptor'],
        'payload_hash' => $input['payload_hash'],
        'first_read' => $firstRead['result'],
        'second_read' => $secondRead['result'],
    ],
    'plan' => $plan->toArray(),
    'snapshots' => $snapshots,
    'attempts' => $attempts,
    'merge' => $merge->toArray(),
    'checkpoint' => [
        'version' => $checkpointRecord?->version(),
        'offsets' => $checkpointRecord?->state()->offsets(),
        'replay_boundary' => $checkpointRecord?->state()->replayBoundary(),
        'source_cursor' => $checkpointRecord?->state()->sourceCursorArray(),
        'sink_cursor' => $checkpointRecord?->state()->sinkCursorArray(),
        'progress' => $checkpointRecord?->state()->progress(),
    ],
    'manifest' => $manifest,
    'output_records' => $outputRecords,
    'live_metrics' => $liveMetrics,
    'flush_result' => $flushResult,
    'telemetry_status' => $telemetryStatus,
    'component' => is_array($component['configuration'] ?? null) ? $component['configuration'] : [],
], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION), "\n";
PHP;
file_put_contents(
    $controllerScript,
    str_replace('__FLOW_SRC_DIR__', var_export($flowSrcDir, true), $controllerTemplate)
);

$remoteBootstrap = <<<'PHP'
<?php
function king_flow_etl_remote_transform_orders(array $context): array
{
    $input = $context['input'] ?? null;
    if (!is_array($input) || !is_array($input['rows'] ?? null)) {
        throw new RuntimeException('remote transform handler expected rows.');
    }

    $partitionId = $context['step']['definition']['partition_id'] ?? null;
    $batchId = $context['step']['definition']['batch_id'] ?? null;
    if (!is_string($partitionId) || $partitionId === '' || !is_string($batchId) || $batchId === '') {
        throw new RuntimeException('remote transform handler lost partition or batch identity.');
    }

    $backend = $context['run']['execution_backend'] ?? 'remote_peer';
    $topology = $context['run']['topology_scope'] ?? 'tcp_host_port_execution_peer';
    $transformed = [];
    foreach ($input['rows'] as $row) {
        if (!is_array($row)) {
            throw new RuntimeException('remote transform handler received a non-array row.');
        }

        $transformed[] = [
            'order_id' => (string) ($row['order_id'] ?? ''),
            'country' => strtoupper((string) ($row['country'] ?? '')),
            'net_total' => round((float) ($row['total'] ?? 0), 2),
            'gross_total' => round(((float) ($row['total'] ?? 0)) * 1.19, 2),
            'partition_id' => $partitionId,
            'batch_id' => $batchId,
            'execution_backend' => is_string($backend) ? $backend : 'remote_peer',
            'topology_scope' => is_string($topology) ? $topology : 'tcp_host_port_execution_peer',
        ];
    }

    return ['output' => [
        'partition_id' => $partitionId,
        'batch_id' => $batchId,
        'rows' => $transformed,
        'row_count' => count($transformed),
    ]];
}

return [
    'transform-orders' => 'king_flow_etl_remote_transform_orders',
];
PHP;
file_put_contents($remoteBootstrapScript, $remoteBootstrap);

$localCollector = king_telemetry_test_start_collector(array_fill(0, 12, [
    'status' => 200,
    'body' => 'ok',
]));

$localCommand = sprintf(
    '%s -n -d %s -d %s -d %s %s %s %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg('extension=' . $extensionPath),
    escapeshellarg('king.security_allow_config_override=1'),
    escapeshellarg('king.orchestrator_state_path=' . $localStatePath),
    escapeshellarg($controllerScript),
    escapeshellarg($localRoot),
    escapeshellarg((string) $localCollector['port'])
);
$localCommand .= ' ' . escapeshellarg('local');

$local = king_flow_etl_decode_controller_result(
    king_flow_etl_run_command($localCommand),
    'local-controller'
);
$localCapture = king_telemetry_test_stop_collector($localCollector);

$remoteCollector = king_telemetry_test_start_collector(array_fill(0, 12, [
    'status' => 200,
    'body' => 'ok',
]));
$remoteServer = king_orchestrator_remote_peer_start(null, '127.0.0.1', null, [$remoteBootstrapScript]);

try {
    $remoteCommand = sprintf(
        '%s -n -d %s -d %s -d %s -d %s -d %s -d %s %s %s %s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg('extension=' . $extensionPath),
        escapeshellarg('king.security_allow_config_override=1'),
        escapeshellarg('king.orchestrator_execution_backend=remote_peer'),
        escapeshellarg('king.orchestrator_remote_host=' . $remoteServer['host']),
        escapeshellarg('king.orchestrator_remote_port=' . $remoteServer['port']),
        escapeshellarg('king.orchestrator_state_path=' . $remoteStatePath),
        escapeshellarg($controllerScript),
        escapeshellarg($remoteRoot),
        escapeshellarg((string) $remoteCollector['port'])
    );
    $remoteCommand .= ' ' . escapeshellarg('remote');

    $remote = king_flow_etl_decode_controller_result(
        king_flow_etl_run_command($remoteCommand),
        'remote-controller'
    );
} finally {
    $remoteCapture = king_telemetry_test_stop_collector($remoteCollector);
    $remoteServerCapture = king_orchestrator_remote_peer_stop($remoteServer);
}

$expectedLocalRecords = [
    ['order_id' => 'A-1', 'country' => 'DE', 'net_total' => 10, 'gross_total' => 11.9, 'partition_id' => 'partition-000-de', 'batch_id' => 'partition-000-de-batch-000000', 'execution_backend' => 'local', 'topology_scope' => 'local_in_process'],
    ['order_id' => 'A-3', 'country' => 'DE', 'net_total' => 3.4, 'gross_total' => 4.05, 'partition_id' => 'partition-000-de', 'batch_id' => 'partition-000-de-batch-000000', 'execution_backend' => 'local', 'topology_scope' => 'local_in_process'],
    ['order_id' => 'A-4', 'country' => 'FR', 'net_total' => 7, 'gross_total' => 8.33, 'partition_id' => 'partition-001-fr', 'batch_id' => 'partition-001-fr-batch-000000', 'execution_backend' => 'local', 'topology_scope' => 'local_in_process'],
    ['order_id' => 'A-2', 'country' => 'US', 'net_total' => 12.5, 'gross_total' => 14.88, 'partition_id' => 'partition-002-us', 'batch_id' => 'partition-002-us-batch-000000', 'execution_backend' => 'local', 'topology_scope' => 'local_in_process'],
    ['order_id' => 'A-5', 'country' => 'US', 'net_total' => 2.6, 'gross_total' => 3.09, 'partition_id' => 'partition-002-us', 'batch_id' => 'partition-002-us-batch-000000', 'execution_backend' => 'local', 'topology_scope' => 'local_in_process'],
];
$expectedRemoteRecords = [
    ['order_id' => 'A-1', 'country' => 'DE', 'net_total' => 10, 'gross_total' => 11.9, 'partition_id' => 'partition-000-de', 'batch_id' => 'partition-000-de-batch-000000', 'execution_backend' => 'remote_peer', 'topology_scope' => 'tcp_host_port_execution_peer'],
    ['order_id' => 'A-3', 'country' => 'DE', 'net_total' => 3.4, 'gross_total' => 4.05, 'partition_id' => 'partition-000-de', 'batch_id' => 'partition-000-de-batch-000000', 'execution_backend' => 'remote_peer', 'topology_scope' => 'tcp_host_port_execution_peer'],
    ['order_id' => 'A-4', 'country' => 'FR', 'net_total' => 7, 'gross_total' => 8.33, 'partition_id' => 'partition-001-fr', 'batch_id' => 'partition-001-fr-batch-000000', 'execution_backend' => 'remote_peer', 'topology_scope' => 'tcp_host_port_execution_peer'],
    ['order_id' => 'A-2', 'country' => 'US', 'net_total' => 12.5, 'gross_total' => 14.88, 'partition_id' => 'partition-002-us', 'batch_id' => 'partition-002-us-batch-000000', 'execution_backend' => 'remote_peer', 'topology_scope' => 'tcp_host_port_execution_peer'],
    ['order_id' => 'A-5', 'country' => 'US', 'net_total' => 2.6, 'gross_total' => 3.09, 'partition_id' => 'partition-002-us', 'batch_id' => 'partition-002-us-batch-000000', 'execution_backend' => 'remote_peer', 'topology_scope' => 'tcp_host_port_execution_peer'],
];

foreach ([
    'local' => [$local, $localCapture, $expectedLocalRecords, 'local', 'local_in_process'],
    'remote' => [$remote, $remoteCapture, $expectedRemoteRecords, 'remote_peer', 'tcp_host_port_execution_peer'],
] as $label => [$result, $capture, $expectedRecords, $expectedBackend, $expectedTopology]) {
    king_flow_etl_assert(($result['mode'] ?? null) === $expectedBackend, $label . ' scenario drifted backend identity.');
    king_flow_etl_assert(($result['capabilities']['backend'] ?? null) === $expectedBackend, $label . ' capabilities drifted backend identity.');
    king_flow_etl_assert(($result['input']['write_complete'] ?? null) === true, $label . ' scenario did not commit raw input.');
    king_flow_etl_assert(($result['input']['write_committed'] ?? null) === true, $label . ' scenario lost raw input commit.');
    king_flow_etl_assert(($result['input']['descriptor']['content_type'] ?? null) === 'application/x-ndjson', $label . ' scenario lost input content type.');
    king_flow_etl_assert(($result['input']['descriptor']['cache_policy_name'] ?? null) === 'smart_cdn', $label . ' scenario lost input cache policy.');
    king_flow_etl_assert(($result['input']['descriptor']['topology']['distributed_present'] ?? null) === true, $label . ' scenario lost distributed backup topology.');
    king_flow_etl_assert(($result['input']['first_read']['complete'] ?? null) === false, $label . ' first read unexpectedly completed.');
    king_flow_etl_assert(($result['input']['first_read']['records_delivered'] ?? null) === 2, $label . ' first read delivered wrong record count.');
    king_flow_etl_assert(($result['input']['second_read']['complete'] ?? null) === true, $label . ' resume read did not complete.');
    king_flow_etl_assert(($result['plan']['partition_count'] ?? null) === 3, $label . ' plan partition count drifted.');
    king_flow_etl_assert(($result['plan']['batch_count'] ?? null) === 3, $label . ' plan batch count drifted.');
    king_flow_etl_assert(($result['merge']['complete'] ?? null) === true, $label . ' merge did not complete.');
    king_flow_etl_assert(($result['merge']['pending_batch_ids'] ?? null) === [], $label . ' merge left pending batches.');
    king_flow_etl_assert(($result['merge']['failed_batches'] ?? null) === [], $label . ' merge recorded failed batches.');
    king_flow_etl_assert(
        ($result['checkpoint']['version'] ?? null) === 8,
        $label . ' checkpoint version drifted: ' . json_encode($result['checkpoint']['version'] ?? null)
    );
    king_flow_etl_assert(($result['checkpoint']['offsets']['records'] ?? null) === 5, $label . ' checkpoint lost processed record count.');
    king_flow_etl_assert(($result['checkpoint']['offsets']['batches'] ?? null) === 3, $label . ' checkpoint lost processed batch count.');
    king_flow_etl_assert(($result['checkpoint']['replay_boundary']['resume_from'] ?? null) === 'after_batch_commit', $label . ' checkpoint replay boundary drifted.');
    king_flow_etl_assert(($result['checkpoint']['source_cursor']['transport'] ?? null) === 'serialized_record_reader', $label . ' checkpoint lost serialized source cursor.');
    king_flow_etl_assert(($result['checkpoint']['sink_cursor']['transport'] ?? null) === 'serialized_record_writer', $label . ' checkpoint lost serialized sink cursor.');
    king_flow_etl_assert(count($result['manifest'] ?? []) === 3, $label . ' manifest count drifted.');
    king_flow_etl_assert(($result['component']['telemetry_adapter_contract'] ?? null) === 'run_partition_batch_retry_failure_identity', $label . ' component lost telemetry adapter contract.');
    king_flow_etl_assert(($result['flush_result'] ?? null) === true, $label . ' telemetry flush failed.');
    king_flow_etl_assert((int) (($result['telemetry_status']['queue_size'] ?? -1)) === 0, $label . ' telemetry queue did not drain.');
    king_flow_etl_assert(
        ($result['output_records'] ?? null) === $expectedRecords,
        $label . ' output records drifted: actual='
            . json_encode($result['output_records'] ?? null, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)
            . ' expected='
            . json_encode($expectedRecords, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)
    );

    $snapshots = $result['snapshots'] ?? [];
    king_flow_etl_assert(count($snapshots) === 3, $label . ' snapshot count drifted.');
    king_flow_etl_assert(array_column($snapshots, 'run_id') === ['run-1', 'run-2', 'run-3'], $label . ' run ids drifted.');
    foreach ($snapshots as $index => $snapshot) {
        king_flow_etl_assert(($snapshot['status'] ?? null) === 'completed', $label . ' snapshot did not complete.');
        king_flow_etl_assert(($snapshot['execution_backend'] ?? null) === $expectedBackend, $label . ' snapshot lost backend identity.');
        king_flow_etl_assert(($snapshot['topology_scope'] ?? null) === $expectedTopology, $label . ' snapshot lost topology identity.');
        king_flow_etl_assert(($snapshot['telemetry_adapter']['partition_count'] ?? null) === 1, $label . ' snapshot lost partition telemetry identity.');
        king_flow_etl_assert(($snapshot['telemetry_adapter']['batch_count'] ?? null) === 1, $label . ' snapshot lost batch telemetry identity.');
        king_flow_etl_assert(($snapshot['steps'][0]['telemetry_adapter']['partition_id'] ?? null) === $result['manifest'][$index]['partition_id'], $label . ' step lost partition_id.');
        king_flow_etl_assert(($snapshot['steps'][0]['telemetry_adapter']['batch_id'] ?? null) === $result['manifest'][$index]['batch_id'], $label . ' step lost batch_id.');
    }

    $attempts = $result['attempts'] ?? [];
    king_flow_etl_assert(count($attempts) === 3, $label . ' attempt count drifted.');
    foreach ($attempts as $attempt) {
        king_flow_etl_assert(($attempt['execution_backend'] ?? null) === $expectedBackend, $label . ' attempt lost backend identity.');
        king_flow_etl_assert(($attempt['topology_scope'] ?? null) === $expectedTopology, $label . ' attempt lost topology identity.');
        king_flow_etl_assert(($attempt['status'] ?? null) === 'completed', $label . ' attempt did not complete.');
    }

    if ($expectedBackend === 'local') {
        king_flow_etl_assert(
            (int) (($result['telemetry_status']['metric_registry_high_watermark'] ?? 0)) === 3,
            $label . ' telemetry status lost metric registry high watermark.'
        );
        king_flow_etl_assert(
            (int) (($result['telemetry_status']['export_success_count'] ?? 0)) === 3,
            $label . ' telemetry status lost export success count.'
        );

        $collectorMetrics = king_flow_etl_collect_metric_values($capture);
        king_flow_etl_assert(($collectorMetrics['pipeline.run.count'] ?? null) === 3, $label . ' collector lost pipeline.run.count.');
        king_flow_etl_assert(($collectorMetrics['pipeline.partition.count'] ?? null) === 3, $label . ' collector lost pipeline.partition.count.');
        king_flow_etl_assert(($collectorMetrics['pipeline.batch.count'] ?? null) === 3, $label . ' collector lost pipeline.batch.count.');

        $collectorSpans = king_flow_etl_collect_spans($capture);
        $runSpans = king_flow_etl_filter_pipeline_spans($collectorSpans, 'pipeline-orchestrator-run', $expectedBackend);
        $stepSpans = king_flow_etl_filter_pipeline_spans($collectorSpans, 'pipeline-orchestrator-step', $expectedBackend);
        king_flow_etl_assert(count($runSpans) >= 3, $label . ' collector lost pipeline run spans.');
        king_flow_etl_assert(count($stepSpans) >= 3, $label . ' collector lost pipeline step spans.');
        king_flow_etl_assert(
            ($stepSpans[0]['attributes']['partition_id'] ?? null) !== null
            && ($stepSpans[0]['attributes']['batch_id'] ?? null) !== null,
            $label . ' collector step spans lost partition/batch identity.'
        );
    }
}

king_flow_etl_assert(is_array($remoteServerCapture), 'remote server did not persist a capture.');
king_flow_etl_assert(($remoteServerCapture['registered_handlers'] ?? null) === ['transform-orders'], 'remote server lost registered handler identity.');
king_flow_etl_assert(count($remoteServerCapture['events'] ?? []) === 3, 'remote server event count drifted.');
foreach ($remoteServerCapture['events'] as $event) {
    king_flow_etl_assert(($event['handler_boundary']['required_tools'] ?? null) === ['transform-orders'], 'remote server lost required tool boundary.');
    king_flow_etl_assert(($event['tool_configs']['transform-orders']['label'] ?? null) === 'orders-transform-v1', 'remote server lost durable tool config.');
    king_flow_etl_assert(is_array($event['result']['rows'] ?? null), 'remote server did not execute the transform handler.');
}

foreach ([
    $controllerScript,
    $remoteBootstrapScript,
    $localStatePath,
    $remoteStatePath,
] as $path) {
    @unlink($path);
}

king_flow_etl_cleanup_tree($localRoot);
king_flow_etl_cleanup_tree($remoteRoot);

echo "OK\n";
?>
--EXPECT--
OK
