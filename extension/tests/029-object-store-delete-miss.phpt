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
--EXPECTF--
Fatal error: Uncaught King\RuntimeException: Object-store registry is unavailable. in /home/jochen/projects/king.site/king/extension/tests/029-object-store-delete-miss.php:2
Stack trace:
#0 /home/jochen/projects/king.site/king/extension/tests/029-object-store-delete-miss.php(2): king_object_store_delete('missing-object')
#1 {main}
  thrown in /home/jochen/projects/king.site/king/extension/tests/029-object-store-delete-miss.php on line 2