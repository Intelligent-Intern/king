--TEST--
King object-store: backend failure semantics for still-simulated runtime backends
--INI--
king.security_allow_config_override=1
--FILE--
<?php

$root = sys_get_temp_dir() . '/king_backends_' . getmypid();
if (!is_dir($root)) {
    mkdir($root, 0755, true);
}

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

$unsupportedBackends = ['distributed'];

foreach ($unsupportedBackends as $backend) {
    king_object_store_init([
        'storage_root_path' => $root,
        'primary_backend' => $backend,
    ]);

    $stats = king_object_store_get_stats()['object_store'];
    var_dump($stats['runtime_primary_backend_contract']);
    var_dump($stats['runtime_primary_adapter_status']);
    var_dump(strlen((string) $stats['runtime_primary_adapter_error']) > 0);

    try {
        king_object_store_put('cloud_doc', 'cloud data');
        echo "unexpected_put\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump(str_contains($e->getMessage(), 'put operations'));
    }

    try {
        king_object_store_get('cloud_doc');
        echo "unexpected_get\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump(str_contains($e->getMessage(), 'get operations'));
    }

    try {
        king_object_store_delete('cloud_doc');
        echo "unexpected_delete\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump(str_contains($e->getMessage(), 'delete operations'));
    }

    try {
        king_object_store_list();
        echo "unexpected_list\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump(str_contains($e->getMessage(), 'list operations'));
    }

    try {
        king_object_store_get_metadata('cloud_doc');
        echo "unexpected_meta\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump(str_contains($e->getMessage(), 'metadata reads'));
    }

    $stats = king_object_store_get_stats()['object_store'];
    var_dump($stats['runtime_primary_adapter_status']);
    var_dump(str_contains((string) $stats['runtime_primary_adapter_error'], 'metadata reads'));
}

foreach (scandir($root) as $file) {
    if ($file !== '.' && $file !== '..') {
        @unlink("$root/$file");
    }
}
@rmdir($root);
?>
--EXPECT--
bool(true)
string(10) "local data"
string(8) "local_fs"
string(5) "local"
string(2) "ok"
string(9) "simulated"
string(9) "simulated"
bool(true)
string(21) "King\RuntimeException"
bool(true)
string(21) "King\RuntimeException"
bool(true)
string(21) "King\RuntimeException"
bool(true)
string(21) "King\RuntimeException"
bool(true)
string(21) "King\RuntimeException"
bool(true)
string(6) "failed"
bool(true)
