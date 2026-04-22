--TEST--
King client HTTP/3 has a real LSQUIC loader contract and no Quiche loader fallback
--FILE--
<?php
$root = dirname(__DIR__, 2);
$client = (string) file_get_contents($root . '/extension/src/client/http3.c');
$loaderPath = $root . '/extension/src/client/http3/lsquic_loader.inc';
$loader = (string) file_get_contents($loaderPath);
$dispatch = (string) file_get_contents($root . '/extension/src/client/http3/dispatch_api.inc');
$errors = (string) file_get_contents($root . '/extension/src/client/http3/errors_and_validation.inc');
$quicheLoaderPath = $root . '/extension/src/client/http3/quiche_loader.inc';

function require_contains(string $label, string $source, string $needle): void
{
    if (!str_contains($source, $needle)) {
        throw new RuntimeException($label . ' must contain ' . $needle);
    }
}

function require_not_contains(string $label, string $source, string $needle): void
{
    if (str_contains($source, $needle)) {
        throw new RuntimeException($label . ' must not contain ' . $needle);
    }
}

if (!file_exists($loaderPath)) {
    throw new RuntimeException('Missing LSQUIC loader.');
}

if (file_exists($quicheLoaderPath)) {
    throw new RuntimeException('Client HTTP/3 Quiche loader fallback still exists.');
}

require_contains('Client HTTP/3 source', $client, '#include "http3/lsquic_loader.inc"');
require_contains('Client HTTP/3 source', $client, 'king_http3_lsquic_api_t');
require_contains('Client HTTP/3 shared header contract', $client, 'typedef struct _king_http3_header');
require_contains('Client HTTP/3 shared header contract', $client, 'king_http3_header_t *request_headers;');
require_not_contains('Client HTTP/3 source', $client, 'http3/quiche_loader.inc');
require_not_contains('Client HTTP/3 source', $client, 'quiche_h3_header');

foreach ([
    'dlopen(',
    'dlsym(',
    'KING_LSQUIC_LIBRARY',
    'lsquic_global_init',
    'KING_LSQUIC_GLOBAL_CLIENT',
    'lsquic_engine_init_settings',
    'lsquic_engine_check_settings',
    'lsquic_engine_new',
    'lsquic_engine_connect',
    'lsquic_engine_packet_in',
    'lsquic_engine_process_conns',
    'lsquic_engine_send_unsent_packets',
    'lsquic_engine_get_conns_count',
    'lsquic_engine_count_attq',
    'lsquic_conn_make_stream',
    'lsquic_conn_n_avail_streams',
    'lsquic_conn_n_pending_streams',
    'lsquic_conn_cancel_pending_streams',
    'lsquic_conn_status',
    'lsquic_stream_send_headers',
    'lsquic_stream_get_hset',
    'lsquic_stream_write',
    'lsquic_stream_read',
    'lsquic_stream_flush',
    'lsquic_stream_shutdown',
    'lsquic_stream_get_ctx',
    'lsquic_stream_set_ctx',
    'lsquic_conn_get_ctx',
    'lsquic_conn_set_ctx',
    'king_http3_lsquic.load_error',
] as $needle) {
    require_contains('LSQUIC loader', $loader, $needle);
}

foreach (['stub', 'fake'] as $needle) {
    require_not_contains('LSQUIC loader', $loader, $needle);
}

require_contains('Client HTTP/3 LSQUIC API table', $client, 'int (*lsquic_engine_earliest_adv_tick_fn)(void *, int *);');
require_not_contains('Client HTTP/3 LSQUIC API table', $client, 'int (*lsquic_engine_earliest_adv_tick_fn)(void *, long *, int *);');
require_contains('Client HTTP/3 LSQUIC API table', $client, 'void (*lsquic_conn_make_stream_fn)(void *);');
require_not_contains('Client HTTP/3 LSQUIC API table', $client, 'void *(*lsquic_conn_make_stream_fn)(void *);');
require_contains('HTTP/3 dispatch backend selector', $dispatch, 'king_http3_ensure_lsquic_ready()');
require_contains('HTTP/3 dispatch backend selector', $dispatch, 'king_http3_throw_lsquic_unavailable(function_name)');
require_contains('HTTP/3 dispatch backend selector', $dispatch, 'king_http3_throw_lsquic_build_required(function_name)');
require_not_contains('HTTP/3 dispatch backend selector', $dispatch, 'king_http3_ensure_quiche_ready');
require_not_contains('HTTP/3 dispatch backend selector', $dispatch, 'king_http3_quiche.load_error');
require_contains('HTTP/3 non-LSQUIC build failure', $errors, 'requires an LSQUIC-enabled HTTP/3 client build.');

echo "OK\n";
?>
--EXPECT--
OK
