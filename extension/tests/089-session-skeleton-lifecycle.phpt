--TEST--
King low-level session skeleton exposes a real resource lifecycle and stable stats
--FILE--
<?php
$session = king_connect('127.0.0.1', 443, ['sni' => 'example.com', 'alpn' => ['h3']]);
var_dump(is_resource($session));
var_dump(get_resource_type($session));

$initial = king_get_stats($session);
var_dump($initial['build']);
var_dump($initial['transport']);
var_dump($initial['host']);
var_dump($initial['port']);
var_dump($initial['state']);
var_dump($initial['config_binding']);
var_dump($initial['config_is_frozen']);
var_dump($initial['config_userland_overrides_applied']);
var_dump($initial['config_option_count']);
var_dump($initial['poll_calls']);
var_dump($initial['cancel_calls']);
var_dump($initial['canceled_stream_count']);
var_dump($initial['last_canceled_stream_id']);
var_dump($initial['last_cancel_mode']);
var_dump(king_poll($session, 5));
var_dump(king_cancel_stream(3, 'read', $session));

$afterPoll = king_get_stats($session);
var_dump($afterPoll['state']);
var_dump($afterPoll['poll_calls']);
var_dump($afterPoll['last_poll_timeout_ms']);
var_dump($afterPoll['cancel_calls']);
var_dump($afterPoll['canceled_stream_count']);
var_dump($afterPoll['last_canceled_stream_id']);
var_dump($afterPoll['last_cancel_mode']);
var_dump(king_close($session));

$afterClose = king_get_stats($session);
var_dump($afterClose['state']);
var_dump($afterClose['poll_calls']);
?>
--EXPECT--
bool(true)
string(12) "King\Session"
string(8) "skeleton"
string(4) "quic"
string(9) "127.0.0.1"
int(443)
string(4) "open"
string(12) "inline_array"
bool(false)
bool(false)
int(2)
int(0)
int(0)
int(0)
int(-1)
string(4) "none"
bool(true)
bool(true)
string(4) "open"
int(1)
int(5)
int(1)
int(1)
int(3)
string(4) "read"
bool(true)
string(6) "closed"
int(1)
