--TEST--
King client stream-cancel alias shares the native session cancel runtime
--FILE--
<?php
$session = king_connect('127.0.0.1', 443);

var_dump(king_client_stream_cancel(7, 'write', $session));

$stats = king_get_stats($session);
var_dump($stats['cancel_calls']);
var_dump($stats['canceled_stream_count']);
var_dump($stats['last_canceled_stream_id']);
var_dump($stats['last_cancel_mode']);

var_dump(king_client_stream_cancel(7, 'both', $session));
var_dump(king_get_last_error());
?>
--EXPECT--
bool(true)
int(1)
int(1)
int(7)
string(5) "write"
bool(false)
string(66) "king_client_stream_cancel() stream 7 is already locally cancelled."
