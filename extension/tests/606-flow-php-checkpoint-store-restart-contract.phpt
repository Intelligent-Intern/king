--TEST--
Repo-local Flow PHP checkpoint store survives restart with resumable source and sink progress
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require_once __DIR__ . '/../../userland/flow-php/src/StreamingSource.php';
require_once __DIR__ . '/../../userland/flow-php/src/StreamingSink.php';
require_once __DIR__ . '/../../userland/flow-php/src/CheckpointStore.php';

use King\Flow\CheckpointState;
use King\Flow\ObjectStoreCheckpointStore;
use King\Flow\SinkCursor;
use King\Flow\SourceCursor;

function king_flow_checkpoint_store_cleanup(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            king_flow_checkpoint_store_cleanup($path . '/' . $entry);
        }

        @rmdir($path);
        return;
    }

    @unlink($path);
}

$root = sys_get_temp_dir() . '/king-flow-checkpoint-store-606-' . getmypid();
king_flow_checkpoint_store_cleanup($root);
mkdir($root, 0700, true);

king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
    'chunk_size_kb' => 1,
]);

$store = new ObjectStoreCheckpointStore('checkpoints/orders-import', [
    'expires_at' => '2099-01-01T00:00:00Z',
]);

$created = $store->create(
    'partition-0',
    new CheckpointState(
        ['records' => 2, 'bytes' => 12],
        ['resume_from' => 'after_batch_commit', 'source_boundary' => 12],
        new SourceCursor('object_store', 'orders.ndjson', 12, 'range_offset', [
            'next_offset' => 12,
        ]),
        new SinkCursor('object_store', 'warehouse/orders.ndjson', 1024, 'resume_upload_session', [
            'upload_id' => 'upload-1',
            'pending_buffer_base64' => '',
        ]),
        ['batch_id' => 'batch-001', 'worker' => 'local']
    )
);

var_dump($created->committed());
var_dump($created->conflict());
var_dump($created->record()?->version());
var_dump($created->record()?->state()->sourceCursor()?->resumeStrategy() === 'range_offset');
var_dump($created->record()?->state()->sinkCursor()?->resumeStrategy() === 'resume_upload_session');
var_dump($created->record()?->state()->replayBoundary()['resume_from'] === 'after_batch_commit');
var_dump($created->record()?->metadata()['content_type'] === 'application/vnd.king.flow-checkpoint+json');
var_dump(($created->record()?->metadata()['integrity_sha256'] ?? '') !== '');
$objectId = $created->record()?->objectId();

king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
    'chunk_size_kb' => 1,
]);

$reloadedStore = new ObjectStoreCheckpointStore('checkpoints/orders-import', [
    'expires_at' => '2099-01-01T00:00:00Z',
]);
$loaded = $reloadedStore->load('partition-0');

var_dump($loaded !== null);
var_dump($loaded?->version());
var_dump($loaded?->state()->offsets()['bytes']);
var_dump($loaded?->state()->sourceCursor()?->bytesConsumed());
var_dump($loaded?->state()->sinkCursor()?->bytesAccepted());

$replaced = $reloadedStore->replace(
    'partition-0',
    new CheckpointState(
        ['records' => 4, 'bytes' => 24],
        ['resume_from' => 'after_checkpoint_commit', 'source_boundary' => 24],
        new SourceCursor('object_store', 'orders.ndjson', 24, 'range_offset', [
            'next_offset' => 24,
        ]),
        new SinkCursor('object_store', 'warehouse/orders.ndjson', 2048, 'resume_upload_session', [
            'upload_id' => 'upload-2',
            'pending_buffer_base64' => '',
        ]),
        ['batch_id' => 'batch-002', 'worker' => 'restart']
    ),
    $loaded
);

var_dump($replaced->committed());
var_dump($replaced->record()?->version());
var_dump($replaced->record()?->state()->offsets()['records']);
var_dump($replaced->record()?->state()->progress()['batch_id'] === 'batch-002');
var_dump(king_object_store_get_metadata($objectId)['version']);
var_dump(str_contains(
    (string) king_object_store_get($objectId),
    '"checkpoint_id":"partition-0"'
));

king_flow_checkpoint_store_cleanup($root);
?>
--EXPECT--
bool(true)
bool(false)
int(1)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(1)
int(12)
int(12)
int(1024)
bool(true)
int(2)
int(4)
bool(true)
int(2)
bool(true)
