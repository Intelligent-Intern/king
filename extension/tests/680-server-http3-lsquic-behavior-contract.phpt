--TEST--
King server HTTP/3 LSQUIC listener preserves request, body, hints, response, and CORS semantics
--FILE--
<?php
$root = dirname(__DIR__, 2);
$listenOnce = (string) file_get_contents($root . '/extension/src/server/http3/lsquic_listen_once.inc');
$streamRuntime = (string) file_get_contents($root . '/extension/src/server/http3/lsquic_stream_runtime.inc');
$requestResponse = (string) file_get_contents($root . '/extension/src/server/http3/request_response.inc');
$localListener = (string) file_get_contents($root . '/extension/src/server/local_listener.inc');
$earlyHints = (string) file_get_contents($root . '/extension/src/server/early_hints.c');

function require_contains(string $label, string $source, string $needle): void
{
    if (!str_contains($source, $needle)) {
        throw new RuntimeException($label . ' must contain ' . $needle);
    }
}

function require_order(string $label, string $source, array $needles): void
{
    $cursor = -1;
    foreach ($needles as $needle) {
        $position = strpos($source, $needle);
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
    'king_server_http3_add_header_value',
    'tolower((unsigned char) name[i])',
    'king_server_http3_collect_request_header',
    'state->header_bytes += name_len + value_len',
    '":method"',
    '":path"',
    '":scheme"',
    '":authority"',
    'king_server_http3_build_wire_request',
    'king_server_local_add_common_request_fields',
] as $needle) {
    require_contains('HTTP/3 request materialization', $requestResponse, $needle);
}

foreach ([
    'king_server_local_add_normalized_target_fields(request)',
    'add_assoc_zval(request, "session", &zsession)',
    'add_assoc_long(request, "stream_id", stream_id)',
    'king_server_cors_add_request_metadata(request, session)',
] as $needle) {
    require_contains('Shared server request semantics', $localListener, $needle);
}

foreach ([
    'king_server_http3_lsquic_read_request',
    'lsquic_stream_read_fn',
    'smart_str_appendl(&request->body',
    'request->body_bytes > KING_SERVER_HTTP3_MAX_REQUEST_BODY_BYTES',
    '*stream_state->runtime->lsquic_request_complete = true',
] as $needle) {
    require_contains('LSQUIC request body drain', $streamRuntime, $needle);
}

require_order('LSQUIC handler gate waits for request FIN', $listenOnce, [
    'king_server_http3_lsquic_runtime_process_egress(runtime, function_name)',
    'if (*request_complete && !*handler_invoked)',
    'king_server_http3_build_wire_request',
    'king_server_local_invoke_handler',
]);

foreach ([
    'runtime->lsquic_request_state->stream_id =',
    'king_server_http3_lsquic.lsquic_stream_id_fn(stream)',
] as $needle) {
    require_contains('LSQUIC stream id exposure', $streamRuntime, $needle);
}

foreach ([
    'PHP_FUNCTION(king_server_send_early_hints)',
    'king_server_control_validate_stream_id',
    'session->server_early_hints_count++',
    'session->server_last_early_hints_stream_id = stream_id',
] as $needle) {
    require_contains('Shared early hints session semantics', $earlyHints, $needle);
}

require_order('LSQUIC response validation and normalization', $listenOnce, [
    'king_server_local_validate_response(retval, session, "http/3", function_name)',
    'king_server_http3_prepare_response_state',
]);

foreach ([
    'king_server_http3_response_append_header',
    'zend_string_tolower(header_name)',
    'ZSTR_VAL(normalized_name)[0] == \':\'',
    'zend_string_equals_literal(normalized_name, "connection")',
    'zend_string_equals_literal(normalized_name, "content-length")',
    'state->headers[index].name = (const uint8_t *) ":status"',
] as $needle) {
    require_contains('HTTP/3 response normalization', $requestResponse, $needle);
}

foreach ([
    'king_server_http3_lsquic_send_response_headers',
    'lsxpack_header_set_offset2',
    'lsquic_stream_send_headers_fn',
    'king_server_http3_lsquic_write_response_body',
    'lsquic_stream_write_fn',
] as $needle) {
    require_contains('LSQUIC response writer uses normalized state', $streamRuntime, $needle);
}

foreach ([
    'king_server_cors_add_request_metadata(request, session)',
    'king_server_cors_apply_response(retval, session)',
] as $needle) {
    require_contains('Shared CORS semantics', $localListener, $needle);
}

if (str_contains($listenOnce . $streamRuntime, 'quiche_h3_header')) {
    throw new RuntimeException('LSQUIC server listener behavior path must not depend on quiche_h3_header.');
}

echo "OK\n";
?>
--EXPECT--
OK
