--TEST--
King server WebSocket-over-HTTP3 keeps local honesty and rejects fake on-wire Extended CONNECT
--FILE--
<?php
$root = dirname(__DIR__, 2);
$websocket = (string) file_get_contents($root . '/extension/src/server/websocket.c');
$http3 = (string) file_get_contents($root . '/extension/src/server/http3.c');
$requestResponse = (string) file_get_contents($root . '/extension/src/server/http3/request_response.inc');
$localHttp3Test = (string) file_get_contents($root . '/extension/tests/542-server-websocket-http3-local-honesty.phpt');
$wireHttp1Test = (string) file_get_contents($root . '/extension/tests/334-http1-server-websocket-wire-validation.phpt');
$docs = (string) file_get_contents($root . '/documentation/server-runtime.md');

function require_contains(string $label, string $source, string $needle): void
{
    if (!str_contains($source, $needle)) {
        throw new RuntimeException($label . ' must contain ' . $needle);
    }
}

function require_absent(string $label, string $source, string $needle): void
{
    if (str_contains($source, $needle)) {
        throw new RuntimeException($label . ' must not contain ' . $needle);
    }
}

function require_order(string $label, string $source, array $needles): void
{
    $cursor = -1;
    foreach ($needles as $needle) {
        $position = strpos($source, $needle, $cursor + 1);
        if ($position === false) {
            throw new RuntimeException($label . ' is missing ' . $needle);
        }
        if ($position <= $cursor) {
            throw new RuntimeException($label . ' must order ' . implode(' -> ', $needles));
        }
        $cursor = $position;
    }
}

foreach ([
    'king_server_websocket_reject_unsupported_on_wire_upgrade',
    'king_server_websocket_session_alpn_is(session, "h3")',
    'does not support on-wire HTTP/3 WebSocket Extended CONNECT upgrades yet.',
    'king_server_websocket_session_alpn_is(session, "h2")',
    'does not support on-wire HTTP/2 WebSocket Extended CONNECT upgrades yet.',
    'requires an active HTTP/1 websocket upgrade request on on-wire server sessions.',
] as $needle) {
    require_contains('On-wire websocket upgrade rejection', $websocket, $needle);
}

require_order('On-wire sessions reject before local queue-backed websocket allocation', $websocket, [
    'on_wire_upgrade =',
    'if (session->transport_socket_fd >= 0 && !on_wire_upgrade)',
    'king_server_websocket_reject_unsupported_on_wire_upgrade',
    'state = ecalloc(1, sizeof(*state));',
]);

require_order('Real HTTP/1 websocket upgrade still requires the pending key path', $websocket, [
    'session->server_pending_websocket_upgrade',
    'session->server_pending_request_target != NULL',
    'session->server_pending_websocket_key != NULL',
    'king_server_websocket_send_upgrade_response',
]);

foreach ([
    'session->server_pending_websocket_upgrade = false;',
    'session->server_pending_websocket_key = NULL;',
    'king_server_local_add_common_request_fields(',
] as $needle) {
    require_contains('HTTP/3 on-wire request materialization stays ordinary request-only', $requestResponse, $needle);
}

require_absent('HTTP/3 on-wire request materialization', $requestResponse, 'server_pending_websocket_upgrade = true');
require_absent('HTTP/3 on-wire request materialization', $requestResponse, 'server_pending_websocket_key = zend_string_init');
require_absent('HTTP/3 on-wire request materialization', $requestResponse, '":protocol"');
require_absent('HTTP/3 on-wire request materialization', $requestResponse, 'extended CONNECT');

foreach ([
    '#include "http3/request_response.inc"',
    '#include "http3/lsquic_stream_runtime.inc"',
    '#include "http3/lsquic_listen_once.inc"',
] as $needle) {
    require_contains('LSQUIC server path shares HTTP/3 request materialization', $http3, $needle);
}

foreach ([
    'king_http3_server_listen(',
    'king_server_upgrade_to_websocket($session, $request[\'stream_id\'])',
    'server_http3_local',
    'wss://127.0.0.1:9443/stream/0',
] as $needle) {
    require_contains('Local HTTP/3 websocket honesty test', $localHttp3Test, $needle);
}

foreach ([
    'king_server_websocket_wire_start_server(\'plain\')',
    'HTTP/1 websocket upgrade request on on-wire server sessions',
    'server_http1_socket',
] as $needle) {
    require_contains('HTTP/1 on-wire non-upgrade rejection test', $wireHttp1Test, $needle);
}

foreach ([
    'On-wire HTTP/3 WebSocket support is also fenced explicitly.',
    'HTTP/3 Extended CONNECT stream',
    'rejects an on-wire `h3` session',
    'would not speak to the remote peer',
] as $needle) {
    require_contains('Server runtime documentation', $docs, $needle);
}

echo "OK\n";
?>
--EXPECT--
OK
