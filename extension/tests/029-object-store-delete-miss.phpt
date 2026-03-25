--TEST--
King object-store delete exposes validation for invalid identifiers and miss behavior
--FILE--
<?php
try {
    king_object_store_delete('missing-object');
    echo "unexpected_uninitialized_miss\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

$dir = sys_get_temp_dir() . '/king_os_delete_' . getmypid();
king_object_store_init(['storage_root_path' => $dir]);

var_dump(king_object_store_delete('missing-object'));

try {
    king_object_store_delete('pipeline/object');
    echo "unexpected_invalid_delete\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

foreach (scandir($dir) as $f) {
    if ($f !== '.' && $f !== '..') {
        @unlink("$dir/$f");
    }
}
@rmdir($dir);
?>
--EXPECT--
string(24) "King\\RuntimeException"
string(42) "Object-store registry is unavailable."
bool(false)
string(24) "King\\ValidationException"
string(34) "Object ID is invalid for object-store paths."
