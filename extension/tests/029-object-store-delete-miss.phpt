--TEST--
King object-store delete exposes a stable no-op miss contract in the skeleton build
--FILE--
<?php
var_dump(king_object_store_delete('missing-object'));
var_dump(king_object_store_delete('pipeline/object'));

try {
    king_object_store_delete('');
    var_dump('no-exception');
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}
?>
--EXPECT--
bool(false)
bool(false)
string(24) "King\ValidationException"
string(25) "Object ID cannot be empty"
