--TEST--
King object-store: distributed backend now exposes the same real CRUD contract as the other runtime backends
--INI--
king.security_allow_config_override=1
--FILE--
<?php

$root = sys_get_temp_dir() . '/king_backends_' . getmypid();
$cleanupTree = static function (string $path) use (&$cleanupTree): void {
    if ($path === '' || !file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        $entries = scandir($path);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $cleanupTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
        return;
    }

    @unlink($path);
};

$cleanupTree($root);
mkdir($root, 0755, true);

king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
]);

var_dump(king_object_store_put('local_doc', 'local data'));
var_dump(king_object_store_get('local_doc'));

$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_primary_backend']);
var_dump($stats['runtime_primary_backend_contract']);
var_dump($stats['runtime_primary_adapter_status']);

king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'distributed',
]);

$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_primary_backend']);
var_dump($stats['runtime_primary_backend_contract']);
var_dump($stats['runtime_primary_adapter_status']);
var_dump($stats['runtime_simulated_backends'] === '');

var_dump(king_object_store_put('distributed_doc', 'distributed data'));
var_dump(king_object_store_get('distributed_doc'));

$metadata = king_object_store_get_metadata('distributed_doc');
var_dump(is_array($metadata));
var_dump($metadata['distributed_present']);
var_dump($metadata['content_length']);

$list = king_object_store_list();
var_dump(count(array_filter(
    $list,
    static fn(array $entry): bool => ($entry['object_id'] ?? '') === 'distributed_doc'
)) === 1);

var_dump(king_object_store_delete('distributed_doc'));
var_dump(king_object_store_get('distributed_doc'));

$cleanupTree($root);
?>
--EXPECT--
bool(true)
string(10) "local data"
string(8) "local_fs"
string(5) "local"
string(2) "ok"
string(11) "distributed"
string(11) "distributed"
string(2) "ok"
bool(true)
bool(true)
string(16) "distributed data"
bool(true)
int(1)
int(16)
bool(true)
bool(true)
bool(false)
