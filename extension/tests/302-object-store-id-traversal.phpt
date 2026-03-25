--TEST--
King object-store and CDN APIs reject identifier traversal attempts
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$root = sys_get_temp_dir() . '/king_obj_id_traversal_' . getmypid();
$outside = sys_get_temp_dir() . '/king_obj_id_traversal_outside_' . getmypid();
$marker = $outside . '-target';

@unlink($outside);
@unlink($marker);

king_object_store_init(['storage_root_path' => $root]);

var_dump(king_object_store_put('safe-object', 'ok'));

$bad_ids = [
    '../' . basename($marker),
    'sub/../' . basename($marker),
    'sub\\' . basename($marker),
];

foreach ($bad_ids as $bad_id) {
    try {
        king_object_store_put($bad_id, 'bad');
        echo "unexpected_put\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage());
    }

    try {
        king_object_store_get($bad_id);
        echo "unexpected_get\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage());
    }

    try {
        king_object_store_delete($bad_id);
        echo "unexpected_delete\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage());
    }

    try {
        king_cdn_cache_object($bad_id);
        echo "unexpected_cdn\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage());
    }
}

var_dump(file_exists($outside));
var_dump(file_exists($marker));

foreach (scandir($root) as $file) {
    if ($file !== '.' && $file !== '..') {
        @unlink("$root/$file");
    }
}
@rmdir($root);
--EXPECT--
bool(true)
string(24) "King\ValidationException"
string(44) "Object ID is invalid for object-store paths."
string(24) "King\ValidationException"
string(44) "Object ID is invalid for object-store paths."
string(24) "King\ValidationException"
string(44) "Object ID is invalid for object-store paths."
string(24) "King\ValidationException"
string(44) "Object ID is invalid for object-store paths."
string(24) "King\ValidationException"
string(44) "Object ID is invalid for object-store paths."
string(24) "King\ValidationException"
string(44) "Object ID is invalid for object-store paths."
string(24) "King\ValidationException"
string(44) "Object ID is invalid for object-store paths."
string(24) "King\ValidationException"
string(44) "Object ID is invalid for object-store paths."
string(24) "King\ValidationException"
string(44) "Object ID is invalid for object-store paths."
string(24) "King\ValidationException"
string(44) "Object ID is invalid for object-store paths."
string(24) "King\ValidationException"
string(44) "Object ID is invalid for object-store paths."
string(24) "King\ValidationException"
string(44) "Object ID is invalid for object-store paths."
bool(false)
bool(false)
