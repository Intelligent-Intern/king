--TEST--
King Session OO helpers expose local ALPN and Early Hints state
--INI--
king.http_enable_early_hints=0
--FILE--
<?php
$session = new King\Session('127.0.0.1', 443);

var_dump($session->alpn());
$stats = $session->stats();
var_dump($stats['alpn']);
var_dump($stats['http_enable_early_hints']);

$session->enableEarlyHints(true);
var_dump($session->stats()['http_enable_early_hints']);

$session->enableEarlyHints(false);
var_dump(king_get_stats($session)['http_enable_early_hints']);

$session->close();
var_dump($session->alpn());
?>
--EXPECT--
string(0) ""
string(0) ""
bool(false)
bool(true)
bool(false)
string(0) ""
