--TEST--
King object-store metadata survives a re-init (durable persistence)
--FILE--
<?php

$dir = sys_get_temp_dir() . '/king_os_persist_' . getmypid();

// First "session": write an object
king_object_store_init([
    'storage_root_path' => $dir,
]);

king_object_store_put('doc1', 'hello world');

// Simulate a re-init representing a new PHP process that reattaches to the
// same storage directory.
king_object_store_init([
    'storage_root_path' => $dir,
]);

// Object data must still be readable
$data = king_object_store_get('doc1');
var_dump($data);

// Stats must have been rehydrated from disk (count >= 1)
$stats = king_object_store_get_stats();
$count = $stats['object_store']['object_count'];
var_dump($count >= 1);

// Listing must not show .meta files
$list = king_object_store_list();
$ids = array_column($list, 'object_id');
$has_meta = count(array_filter($ids, fn($id) => str_ends_with($id, '.meta'))) > 0;
var_dump($has_meta);

// Cleanup
foreach (scandir($dir) as $f) {
    if ($f !== '.' && $f !== '..') @unlink("$dir/$f");
}
@rmdir($dir);

?>
--EXPECT--
string(11) "hello world"
bool(true)
bool(false)
