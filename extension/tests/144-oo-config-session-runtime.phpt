--TEST--
King Config and Session OO wrappers share the active skeleton runtime
--FILE--
<?php
$config = new King\Config();
var_dump($config instanceof King\Config);

$factoryConfig = King\Config::new();
var_dump($factoryConfig instanceof King\Config);

$session = new King\Session('127.0.0.1', 443, $config);
var_dump($session instanceof King\Session);
var_dump($session->isConnected());

$stats = $session->stats();
var_dump($stats['config_binding']);
var_dump($stats['config_is_frozen']);
var_dump($stats['state']);

var_dump($session->poll(5));
var_dump(king_get_stats($session)['poll_calls']);
var_dump(king_close($session));
var_dump($session->isConnected());
var_dump($session->stats()['state']);

$procedural = king_connect('127.0.0.1', 443, $factoryConfig);
var_dump(is_resource($procedural));
var_dump(king_get_stats($procedural)['config_binding']);
var_dump(king_close($procedural));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
string(8) "resource"
bool(true)
string(4) "open"
bool(true)
int(1)
bool(true)
bool(false)
string(6) "closed"
bool(true)
string(8) "resource"
bool(true)
