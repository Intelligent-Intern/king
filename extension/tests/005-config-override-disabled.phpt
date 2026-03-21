--TEST--
King\Config rejects non-empty userland overrides when policy disables them
--FILE--
<?php
try {
    king_new_config(['foo' => 'bar']);
    var_dump('no-exception');
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}
?>
--EXPECT--
string(21) "King\RuntimeException"
string(52) "Configuration override is disabled by system policy."
