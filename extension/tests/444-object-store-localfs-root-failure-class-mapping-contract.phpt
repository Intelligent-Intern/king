--TEST--
King object-store local_fs root outages map to explicit system failures instead of misses
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$root = sys_get_temp_dir() . '/king_object_store_root_failure_444_' . getmypid();
$gone = $root . '.gone';

foreach ([$gone, $root] as $path) {
    if (!is_dir($path)) {
        continue;
    }

    foreach (scandir($path) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        @unlink($path . '/' . $entry);
    }
    @rmdir($path);
}

mkdir($root, 0700, true);

var_dump(king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
]));
var_dump(king_object_store_put('doc', 'alpha'));
var_dump(rename($root, $gone));

try {
    king_object_store_get('doc');
    echo "no-get-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'local_fs read failed'));
}

try {
    king_object_store_list();
    echo "no-list-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'local_fs list failed'));
}

try {
    king_object_store_get_metadata('doc');
    echo "no-metadata-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'local_fs metadata read failed'));
}

$stream = fopen('php://temp', 'w+');
try {
    king_object_store_get_to_stream('doc', $stream);
    echo "no-stream-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'local_fs read failed'));
}

try {
    king_object_store_delete('doc');
    echo "no-delete-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'local_fs delete failed'));
}

foreach ([$gone, $root] as $path) {
    if (!is_dir($path)) {
        continue;
    }

    foreach (scandir($path) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        @unlink($path . '/' . $entry);
    }
    @rmdir($path);
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
string(20) "King\SystemException"
bool(true)
string(20) "King\SystemException"
bool(true)
string(20) "King\SystemException"
bool(true)
string(20) "King\SystemException"
bool(true)
string(20) "King\SystemException"
bool(true)
