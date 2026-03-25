--TEST--
King object-store lookup exposes a stable miss contract in the current runtime
--FILE--
<?php
var_dump(king_object_store_get('missing-object'));
try {
    king_object_store_get('pipeline/object', []);
    echo "unexpected_miss\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

try {
    king_object_store_get('');
    var_dump('no-exception');
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}
?>
--EXPECT--
bool(false)
string(24) "King\\ValidationException"
string(34) "Object ID is invalid for object-store paths."
string(24) "King\\ValidationException"
string(42) "Object ID must be between 1 and 127 bytes."
