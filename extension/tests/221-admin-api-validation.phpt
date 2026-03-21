--TEST--
King admin api listen validates config, mTLS material, and session state
--FILE--
<?php
$session = king_connect('127.0.0.1', 443);

var_dump(king_admin_api_listen($session, null));
var_dump(king_get_last_error());

try {
    king_admin_api_listen($session, new stdClass());
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

var_dump(king_admin_api_listen($session, [
    'enable' => 'yes',
]));
var_dump(king_get_last_error());

var_dump(king_admin_api_listen($session, [
    'enable' => true,
    'bind_host' => '127.0.0.1',
    'port' => 80,
    'auth_mode' => 'mtls',
    'ca_file' => __FILE__,
    'cert_file' => __FILE__,
    'key_file' => __FILE__,
]));
var_dump(king_get_last_error());

var_dump(king_admin_api_listen($session, [
    'enable' => true,
    'auth_mode' => 'token',
    'ca_file' => __FILE__,
    'cert_file' => __FILE__,
    'key_file' => __FILE__,
]));
var_dump(king_get_last_error());

var_dump(king_admin_api_listen($session, [
    'enable' => true,
    'auth_mode' => 'mtls',
    'ca_file' => __FILE__,
    'cert_file' => '',
    'key_file' => __FILE__,
]));
var_dump(king_get_last_error());

var_dump(king_admin_api_listen($session, [
    'enable' => true,
    'unknown' => 1,
]));
var_dump(king_get_last_error());

var_dump(king_close($session));
var_dump(king_admin_api_listen($session, [
    'enable' => true,
    'bind_host' => '127.0.0.1',
    'port' => 2025,
    'auth_mode' => 'mtls',
    'ca_file' => __FILE__,
    'cert_file' => __FILE__,
    'key_file' => __FILE__,
]));
var_dump(king_get_last_error());
?>
--EXPECTF--
bool(false)
string(76) "king_admin_api_listen() requires admin API enablement via config or php.ini."
string(9) "TypeError"
string(%d) "king_admin_api_listen(): Argument #2 ($config) must be null, array, a King\Config resource, or a King\Config object"
bool(false)
string(60) "king_admin_api_listen() config key 'enable' must be boolean."
bool(false)
string(60) "king_admin_api_listen() port must be between 1024 and 65535."
bool(false)
string(65) "king_admin_api_listen() currently supports only auth_mode 'mtls'."
bool(false)
string(92) "king_admin_api_listen() auth_mode 'mtls' requires readable ca_file, cert_file, and key_file."
bool(false)
string(66) "king_admin_api_listen() config contains unsupported key 'unknown'."
bool(true)
bool(false)
string(59) "king_admin_api_listen() cannot operate on a closed session."
