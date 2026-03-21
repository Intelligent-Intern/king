--TEST--
king_server_listen routes to the active protocol listener for the resolved config snapshot
--INI--
king.security_allow_config_override=1
--FILE--
<?php
function king_server_index_test_handler(array $request): array
{
    $GLOBALS['king_server_index_protocols'][] = $request['protocol'];
    return ['status' => 204];
}

var_dump(king_server_listen('127.0.0.1', 8443, null, 'king_server_index_test_handler'));
var_dump(king_get_last_error());
var_dump($GLOBALS['king_server_index_protocols'][0]);

var_dump(king_server_listen(
    '127.0.0.1',
    8443,
    ['http2.enable' => false],
    'king_server_index_test_handler'
));
var_dump(king_get_last_error());
var_dump($GLOBALS['king_server_index_protocols'][1]);

$config = new King\Config(['tcp.enable' => false]);

var_dump(king_server_listen('127.0.0.1', 8443, $config, 'king_server_index_test_handler'));
var_dump(king_get_last_error());
var_dump($GLOBALS['king_server_index_protocols'][2]);
?>
--EXPECTF--
bool(true)
string(0) ""
string(6) "http/2"
bool(true)
string(0) ""
string(8) "http/1.1"
bool(true)
string(0) ""
string(6) "http/3"
