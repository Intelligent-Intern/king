--TEST--
King server HTTP/3 path has no Quiche fallback
--FILE--
<?php
$root = dirname(__DIR__, 2);
$serverDir = $root . '/extension/src/server/http3';
$http3 = (string) file_get_contents($root . '/extension/src/server/http3.c');
$listenOnce = (string) file_get_contents($serverDir . '/listen_once_api.inc');
$optionsRuntime = (string) file_get_contents($serverDir . '/options_and_runtime.inc');
$requestResponse = (string) file_get_contents($serverDir . '/request_response.inc');
$serverSources = $http3 . $listenOnce . $optionsRuntime . $requestResponse;

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

foreach ([
    $serverDir . '/quiche_loader.inc',
    $serverDir . '/event_loop.inc',
] as $removedFile) {
    if (file_exists($removedFile)) {
        throw new RuntimeException('Server HTTP/3 Quiche fallback file still exists: ' . basename($removedFile));
    }
}

foreach ([
    '#include <quiche.h>',
    'quiche_h3_header',
    'quiche_config',
    'quiche_conn',
    'quiche_h3_config',
    'quiche_h3_conn',
    'quiche_recv_info',
    'king_server_http3_quiche_api_t',
    'king_server_http3_quiche',
    'king_server_http3_ensure_quiche_ready',
    'king_server_http3_prepare_runtime_config',
    'king_server_http3_handle_first_packet',
    'king_server_http3_process_events',
    'king_server_http3_try_send_response',
    'king_server_http3_fill_random_bytes',
    'king_server_http3_apply_transport_snapshot_from_socket',
    'http3/quiche_loader.inc',
    'http3/event_loop.inc',
    'QUICHE_',
] as $needle) {
    require_not_contains('Server HTTP/3 source path', $serverSources, $needle);
}

require_contains('Server response header ownership', $http3, 'typedef struct _king_server_http3_header');
require_contains('Server response header ownership', $http3, 'king_server_http3_header_t *headers;');
require_contains('Server LSQUIC include path', $http3, '#include "http3/lsquic_loader.inc"');
require_contains('Server LSQUIC include path', $http3, '#include "http3/lsquic_listen_once.inc"');
require_contains('Server LSQUIC dispatch', $listenOnce, 'king_server_http3_listen_once_lsquic(');
require_contains(
    'Non-LSQUIC server build failure',
    $listenOnce,
    'requires an LSQUIC-enabled HTTP/3 server build.'
);

if (!preg_match('/#if defined\(KING_HTTP3_BACKEND_LSQUIC\).*king_server_http3_listen_once_lsquic\(/s', $listenOnce)) {
    throw new RuntimeException('Server HTTP/3 listener must dispatch only LSQUIC builds to the on-wire listener.');
}

if (!preg_match('/#else\s+king_server_local_set_errorf\(/s', $listenOnce)) {
    throw new RuntimeException('Server HTTP/3 listener must fail closed when the LSQUIC backend is not built.');
}

echo "OK\n";
?>
--EXPECT--
OK
