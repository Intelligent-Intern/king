--TEST--
Repo-local Flow PHP checkpoint store uses collision-safe object IDs and preserves legacy checkpoint compatibility
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require_once __DIR__ . '/../../demo/userland/flow-php/src/StreamingSource.php';
require_once __DIR__ . '/../../demo/userland/flow-php/src/StreamingSink.php';
require_once __DIR__ . '/../../demo/userland/flow-php/src/CheckpointStore.php';

use King\Flow\CheckpointState;
use King\Flow\ObjectStoreCheckpointStore;

function king_flow_checkpoint_store_collision_cleanup(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            king_flow_checkpoint_store_collision_cleanup($path . '/' . $entry);
        }

        @rmdir($path);
        return;
    }

    @unlink($path);
}

$root = sys_get_temp_dir() . '/king-flow-checkpoint-collision-667-' . getmypid();
king_flow_checkpoint_store_collision_cleanup($root);
mkdir($root, 0700, true);

king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
    'chunk_size_kb' => 1,
]);

$storeA = new ObjectStoreCheckpointStore('checkpoints/foo');
$storeB = new ObjectStoreCheckpointStore('checkpoints/foo--bar');

$reflection = new ReflectionClass(ObjectStoreCheckpointStore::class);
$objectIdFor = $reflection->getMethod('objectIdFor');
$objectIdFor->setAccessible(true);

$newA = $objectIdFor->invoke($storeA, 'bar--baz');
$newB = $objectIdFor->invoke($storeB, 'baz');

var_dump($newA !== $newB);
var_dump(str_starts_with($newA, 'checkpoint-v2!'));
var_dump(str_starts_with($newB, 'checkpoint-v2!'));

$legacyObjectId = rawurlencode('checkpoints/foo') . '--' . rawurlencode('bar--baz') . '.json';
$legacyPayload = json_encode([
    'checkpoint_schema_version' => 1,
    'checkpoint_id' => 'bar--baz',
    'checkpointed_at' => gmdate(DATE_ATOM),
    'state' => (new CheckpointState([], [], null, null, ['seed' => 'legacy']))->toArray(),
], JSON_UNESCAPED_SLASHES);

var_dump(king_object_store_put($legacyObjectId, $legacyPayload) === true);

$loadedLegacy = $storeA->load('bar--baz');
var_dump($loadedLegacy !== null);
var_dump($loadedLegacy?->objectId() === $legacyObjectId);
var_dump(($loadedLegacy?->state()->progress()['seed'] ?? null) === 'legacy');

$replacedLegacy = $storeA->replace(
    'bar--baz',
    new CheckpointState([], [], null, null, ['seed' => 'updated']),
    $loadedLegacy
);
var_dump($replacedLegacy->committed());
var_dump($replacedLegacy->record()?->objectId() === $legacyObjectId);
var_dump(($replacedLegacy->record()?->state()->progress()['seed'] ?? null) === 'updated');

$created = $storeA->create(
    'fresh',
    new CheckpointState([], [], null, null, ['seed' => 'fresh'])
);
var_dump($created->committed());
var_dump(str_starts_with((string) $created->record()?->objectId(), 'checkpoint-v2!'));
var_dump((string) $created->record()?->objectId() !== $legacyObjectId);

king_flow_checkpoint_store_collision_cleanup($root);
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
