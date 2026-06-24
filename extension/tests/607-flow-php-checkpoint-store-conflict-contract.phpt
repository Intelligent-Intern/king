--TEST--
Repo-local Flow PHP checkpoint store reports version conflicts instead of losing updates
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require_once __DIR__ . '/../../userland/flow-php/src/StreamingSource.php';
require_once __DIR__ . '/../../userland/flow-php/src/StreamingSink.php';
require_once __DIR__ . '/../../userland/flow-php/src/CheckpointStore.php';

use King\Flow\CheckpointState;
use King\Flow\ObjectStoreCheckpointStore;
use King\Flow\SourceCursor;

function king_flow_checkpoint_conflict_cleanup(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            king_flow_checkpoint_conflict_cleanup($path . '/' . $entry);
        }

        @rmdir($path);
        return;
    }

    @unlink($path);
}

$root = sys_get_temp_dir() . '/king-flow-checkpoint-store-607-' . getmypid();
king_flow_checkpoint_conflict_cleanup($root);
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

$latest = $store->load('partition-1');

var_dump($created->committed());
var_dump($advanced->committed());
var_dump($advanced->record()?->version());
var_dump($conflict->committed());
var_dump($conflict->conflict());
var_dump($conflict->record()?->version());
var_dump($conflict->record()?->state()->offsets()['records']);
var_dump($conflict->record()?->state()->progress()['writer'] === 'A');
var_dump(str_contains((string) $conflict->message(), 'expected_version') || str_contains((string) $conflict->message(), 'if_match'));
var_dump($latest?->version());
var_dump($latest?->state()->offsets()['records']);

king_flow_checkpoint_conflict_cleanup($root);
?>
--EXPECT--
bool(true)
bool(true)
int(2)
bool(false)
bool(true)
int(2)
int(2)
bool(true)
bool(true)
int(2)
int(2)
