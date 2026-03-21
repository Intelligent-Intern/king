--TEST--
King client transport maps native connect failures to a King NetworkException
--FILE--
<?php
try {
    king_connect('invalid host name []', 443);
    echo "no-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump(str_starts_with(
        $e->getMessage(),
        "king_connect() failed to resolve UDP endpoint for host 'invalid host name []'"
    ));
    var_dump(str_starts_with(
        king_get_last_error(),
        "king_connect() failed to resolve UDP endpoint for host 'invalid host name []'"
    ));
}
?>
--EXPECT--
string(21) "King\NetworkException"
bool(true)
bool(true)
