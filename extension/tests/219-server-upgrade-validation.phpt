--TEST--
King server cancel, early-hints, and websocket-upgrade helpers validate stream/session state and keep stable errors
--FILE--
<?php
$session = king_connect('127.0.0.1', 443);

try {
    king_server_on_cancel($session, 1, new stdClass());
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

var_dump(king_server_on_cancel($session, -1, static function (): void {}));
var_dump(king_get_last_error());

var_dump(king_server_send_early_hints($session, -1, []));
var_dump(king_get_last_error());

var_dump(king_server_send_early_hints($session, 1, [['broken']]));
var_dump(king_get_last_error());

$websocket = king_server_upgrade_to_websocket($session, 8);
var_dump(is_resource($websocket));
var_dump(king_get_last_error());

var_dump(king_server_upgrade_to_websocket($session, 8));
var_dump(king_get_last_error());

var_dump(king_cancel_stream(10, 'both', $session));
var_dump(king_server_send_early_hints($session, 10, ['Link' => '</x.css>; rel=preload']));
var_dump(king_get_last_error());

var_dump(king_server_upgrade_to_websocket($session, 10));
var_dump(king_get_last_error());

var_dump(king_close($session));

var_dump(king_server_on_cancel($session, 12, static function (): void {}));
var_dump(king_get_last_error());

var_dump(king_server_send_early_hints($session, 12, []));
var_dump(king_get_last_error());

var_dump(king_server_upgrade_to_websocket($session, 12));
var_dump(king_get_last_error());
?>
--EXPECTF--
string(9) "TypeError"
string(%d) "king_server_on_cancel(): Argument #3 ($handler) must be a valid callback"
bool(false)
string(47) "king_server_on_cancel() stream_id must be >= 0."
bool(false)
string(54) "king_server_send_early_hints() stream_id must be >= 0."
bool(false)
string(97) "king_server_send_early_hints() numeric early hint entries must provide a string name and a value."
bool(false)
string(114) "king_server_upgrade_to_websocket() requires an active HTTP/1 websocket upgrade request on on-wire server sessions."
bool(false)
string(114) "king_server_upgrade_to_websocket() requires an active HTTP/1 websocket upgrade request on on-wire server sessions."
bool(true)
bool(false)
string(77) "king_server_send_early_hints() cannot operate on locally cancelled stream 10."
bool(false)
string(81) "king_server_upgrade_to_websocket() cannot operate on locally cancelled stream 10."
bool(true)
bool(false)
string(59) "king_server_on_cancel() cannot operate on a closed session."
bool(false)
string(66) "king_server_send_early_hints() cannot operate on a closed session."
bool(false)
string(70) "king_server_upgrade_to_websocket() cannot operate on a closed session."
