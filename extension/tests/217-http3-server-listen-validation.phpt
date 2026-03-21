--TEST--
King HTTP/3 server leaf validates host port and handler response contracts
--FILE--
<?php
$ok = static function (array $request): array {
    return ['status' => 204];
};

var_dump(king_http3_server_listen('', 8443, null, $ok));
var_dump(king_get_last_error());

var_dump(king_http3_server_listen('127.0.0.1', 0, null, $ok));
var_dump(king_get_last_error());

var_dump(king_http3_server_listen(
    '127.0.0.1',
    8443,
    null,
    static function (array $request): string {
        return 'nope';
    }
));
var_dump(king_get_last_error());

var_dump(king_http3_server_listen(
    '127.0.0.1',
    8443,
    null,
    static function (array $request): array {
        return ['headers' => 'nope'];
    }
));
var_dump(king_get_last_error());

var_dump(king_http3_server_listen(
    '127.0.0.1',
    8443,
    null,
    static function (array $request): array {
        return ['status' => 99];
    }
));
var_dump(king_get_last_error());
?>
--EXPECTF--
bool(false)
string(%d) "king_http3_server_listen() requires a non-empty host."
bool(false)
string(%d) "king_http3_server_listen() port must be between 1 and 65535."
bool(false)
string(%d) "king_http3_server_listen() handler must return an array response."
bool(false)
string(%d) "king_http3_server_listen() handler response 'headers' must be an array when present."
bool(false)
string(%d) "king_http3_server_listen() handler response 'status' must resolve to an HTTP status code between 100 and 599."
