--TEST--
King low-level session runtime validates connect and poll error contracts
--FILE--
<?php
var_dump(king_connect('', 443));
var_dump(king_get_last_error());

var_dump(king_connect('example.com', 0));
var_dump(king_get_last_error());

$session = king_connect('127.0.0.1', 443);
var_dump(is_resource($session));

var_dump(king_poll($session, -1));
var_dump(king_get_last_error());

var_dump(king_cancel_stream(-1, 'both', $session));
var_dump(king_get_last_error());

var_dump(king_cancel_stream(1, 'invalid', $session));
var_dump(king_get_last_error());

var_dump(king_close($session));
var_dump(king_cancel_stream(1, 'both', $session));
var_dump(king_get_last_error());
var_dump(king_poll($session, 1));
var_dump(king_get_last_error());
?>
--EXPECT--
bool(false)
string(56) "king_connect() requires a valid host name (1-255 bytes)."
bool(false)
string(48) "king_connect() port must be between 1 and 65535."
bool(true)
bool(false)
string(36) "king_poll() timeout_ms must be >= 0."
bool(false)
string(44) "king_cancel_stream() stream_id must be >= 0."
bool(false)
string(60) "king_cancel_stream() how must be 'read', 'write', or 'both'."
bool(true)
bool(false)
string(64) "king_cancel_stream() cannot cancel a stream on a closed session."
bool(false)
string(42) "king_poll() cannot drive a closed session."
