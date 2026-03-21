--TEST--
King server TLS reload validates session state and file-path inputs
--INI--
king.tls_ticket_key_file=/definitely/missing-ticket.key
--FILE--
<?php
$session = king_connect('127.0.0.1', 443);

try {
    king_server_reload_tls_config(new stdClass(), __FILE__, __FILE__);
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

var_dump(king_server_reload_tls_config($session, '', __FILE__));
var_dump(king_get_last_error());

var_dump(king_server_reload_tls_config($session, __FILE__, ''));
var_dump(king_get_last_error());

var_dump(king_server_reload_tls_config($session, __FILE__, __FILE__));
var_dump(king_get_last_error());

var_dump(king_close($session));
var_dump(king_server_reload_tls_config($session, __FILE__, __FILE__));
var_dump(king_get_last_error());
?>
--EXPECTF--
string(9) "TypeError"
string(%d) "king_server_reload_tls_config(): Argument #1 ($session) must be a King\Session resource or King\Session object"
bool(false)
string(%d) "king_server_reload_tls_config() cert_file_path must be a non-empty readable file path."
bool(false)
string(%d) "king_server_reload_tls_config() key_file_path must be a non-empty readable file path."
bool(false)
string(%d) "king_server_reload_tls_config() configured tls_ticket_key_file must be readable when set."
bool(true)
bool(false)
string(%d) "king_server_reload_tls_config() cannot operate on a closed session."
