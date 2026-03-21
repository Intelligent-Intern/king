--TEST--
King CancelToken OO wrapper keeps local cancellation state
--FILE--
<?php
$token = new King\CancelToken();

var_dump($token->isCancelled());
$token->cancel();
var_dump($token->isCancelled());
$token->cancel();
var_dump($token->isCancelled());
?>
--EXPECT--
bool(false)
bool(true)
bool(true)
