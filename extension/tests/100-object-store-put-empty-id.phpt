--TEST--
King object-store put rejects an empty object id
--FILE--
<?php
try {
    king_object_store_put('', 'payload');
} catch (King\Exception $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}
?>
--EXPECT--
string(24) "King\ValidationException"
string(25) "Object ID cannot be empty"
