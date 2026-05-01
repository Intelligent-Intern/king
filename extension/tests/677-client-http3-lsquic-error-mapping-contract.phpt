--TEST--
King client HTTP/3 LSQUIC loader errors map to existing King exceptions
--FILE--
<?php
$root = dirname(__DIR__, 2);
$client = (string) file_get_contents($root . '/extension/src/client/http3.c');
$loader = (string) file_get_contents($root . '/extension/src/client/http3/lsquic_loader.inc');
$errors = (string) file_get_contents($root . '/extension/src/client/http3/errors_and_validation.inc');
$guard = (string) file_get_contents($root . '/infra/scripts/check-http3-lsquic-loader-contract.php');
$output = [];
$status = 1;

exec(PHP_BINARY . ' -n ' . escapeshellarg($root . '/infra/scripts/check-http3-lsquic-loader-contract.php') . ' 2>&1', $output, $status);

var_dump(str_contains($client, 'king_http3_lsquic_load_error_kind_t'));
var_dump(str_contains($client, 'KING_HTTP3_LSQUIC_LOAD_ERROR_LIBRARY'));
var_dump(str_contains($client, 'KING_HTTP3_LSQUIC_LOAD_ERROR_SYMBOL'));
var_dump(str_contains($client, 'KING_HTTP3_LSQUIC_LOAD_ERROR_GLOBAL_INIT'));
var_dump(str_contains($loader, 'king_http3_lsquic.load_error_kind = KING_HTTP3_LSQUIC_LOAD_ERROR_LIBRARY'));
var_dump(str_contains($loader, 'king_http3_lsquic.load_error_kind = KING_HTTP3_LSQUIC_LOAD_ERROR_SYMBOL'));
var_dump(str_contains($loader, 'king_http3_lsquic.load_error_kind = KING_HTTP3_LSQUIC_LOAD_ERROR_GLOBAL_INIT'));
var_dump(str_contains($errors, 'king_http3_lsquic_loader_exception_class'));
var_dump(str_contains($errors, 'king_http3_throw_lsquic_unavailable'));
var_dump(str_contains($errors, 'king_ce_system_exception'));
var_dump(str_contains($errors, 'king_ce_protocol_exception'));
var_dump(str_contains($guard, 'HTTP/3 error mapper'));
var_dump($status === 0);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
