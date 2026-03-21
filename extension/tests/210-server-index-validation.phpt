--TEST--
king_server_listen validates host, port, config, and override policy before dispatch
--FILE--
<?php
$handler = static function (): void {
};

var_dump(king_server_listen('', 8443, null, $handler));
var_dump(king_get_last_error());

var_dump(king_server_listen('127.0.0.1', 0, null, $handler));
var_dump(king_get_last_error());

try {
    king_server_listen('127.0.0.1', 8443, fopen('php://memory', 'r'), $handler);
    echo "no-exception-1\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

try {
    king_server_listen('127.0.0.1', 8443, ['http2.enable' => false], $handler);
    echo "no-exception-2\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}
?>
--EXPECTF--
bool(false)
string(%d) "king_server_listen() requires a non-empty host."
bool(false)
string(%d) "king_server_listen() port must be between 1 and 65535."
string(9) "TypeError"
string(%d) "king_server_listen(): supplied resource is not a valid King\Config resource"
string(21) "King\RuntimeException"
string(%d) "Configuration override is disabled by system policy."
