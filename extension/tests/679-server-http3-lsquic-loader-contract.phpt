--TEST--
King server HTTP/3 has a real LSQUIC loader contract
--FILE--
<?php
$root = dirname(__DIR__, 2);
$server = (string) file_get_contents($root . '/extension/src/server/http3.c');
$loaderPath = $root . '/extension/src/server/http3/lsquic_loader.inc';
$loader = (string) file_get_contents($loaderPath);
$output = [];
$status = 1;

exec(PHP_BINARY . ' -n ' . escapeshellarg($root . '/infra/scripts/check-http3-lsquic-loader-contract.php') . ' 2>&1', $output, $status);

var_dump(file_exists($loaderPath));
var_dump(str_contains($server, 'king_server_http3_lsquic_api_t'));
var_dump(str_contains($server, '#include "http3/lsquic_loader.inc"'));
var_dump(str_contains($loader, 'KING_LSQUIC_GLOBAL_SERVER'));
var_dump(str_contains($loader, 'KING_LSQUIC_LIBRARY'));
var_dump(str_contains($loader, 'dlopen('));
var_dump(str_contains($loader, 'dlsym('));
var_dump(str_contains($loader, 'lsquic_global_init'));
var_dump(str_contains($loader, 'king_server_http3_lsquic.global_initialized = true'));
var_dump(str_contains($loader, 'king_server_http3_lsquic.ready = true'));
var_dump(str_contains($loader, 'return SUCCESS;'));
var_dump(str_contains($loader, 'stub'));
var_dump(str_contains($loader, 'fake'));
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
bool(false)
bool(false)
bool(true)
