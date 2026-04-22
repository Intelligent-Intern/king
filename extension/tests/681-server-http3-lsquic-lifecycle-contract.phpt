--TEST--
King server HTTP/3 LSQUIC listener preserves TLS reload, cancel, and shutdown lifecycle
--FILE--
<?php
$root = dirname(__DIR__, 2);
$http3 = (string) file_get_contents($root . '/extension/src/server/http3.c');
$listenOnce = (string) file_get_contents($root . '/extension/src/server/http3/listen_once_api.inc');
$lsquicListenOnce = (string) file_get_contents($root . '/extension/src/server/http3/lsquic_listen_once.inc');
$lsquicRuntime = (string) file_get_contents($root . '/extension/src/server/http3/lsquic_runtime.inc');
$lsquicStreamRuntime = (string) file_get_contents($root . '/extension/src/server/http3/lsquic_stream_runtime.inc');
$lsquicTls = (string) file_get_contents($root . '/extension/src/server/http3/lsquic_tls.inc');
$optionsRuntime = (string) file_get_contents($root . '/extension/src/server/http3/options_and_runtime.inc');
$localListener = (string) file_get_contents($root . '/extension/src/server/local_listener.inc');
$serverTls = (string) file_get_contents($root . '/extension/src/server/tls.c');
$serverCancel = (string) file_get_contents($root . '/extension/src/server/cancel.c');

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
    'const char *tls_default_cert_file;',
    'const char *tls_default_key_file;',
    'king_client_session_t *lsquic_session;',
    'SSL_CTX *lsquic_ssl_ctx;',
] as $needle) {
    require_contains('LSQUIC runtime lifecycle fields', $http3, $needle);
}

foreach ([
    'options->tls_default_cert_file = king_tls_and_crypto_config.tls_default_cert_file',
    'options->tls_default_key_file = king_tls_and_crypto_config.tls_default_key_file',
    'options->tls_default_cert_file = cfg->tls.tls_default_cert_file',
    'options->tls_default_key_file = cfg->tls.tls_default_key_file',
    "requires 'tls_default_cert_file'",
    "requires 'tls_default_key_file'",
] as $needle) {
    require_contains('HTTP/3 TLS option reload source', $optionsRuntime, $needle);
}

foreach ([
    'SSL_CTX_new(TLS_method())',
    'SSL_CTX_set_alpn_select_cb(ctx, king_server_http3_lsquic_select_alpn, NULL)',
    'SSL_CTX_use_certificate_chain_file(ctx, options->tls_default_cert_file)',
    'SSL_CTX_use_PrivateKey_file(ctx, options->tls_default_key_file, SSL_FILETYPE_PEM)',
    'SSL_CTX_check_private_key(ctx)',
    'return runtime != NULL ? runtime->lsquic_ssl_ctx : NULL',
] as $needle) {
    require_contains('LSQUIC TLS context lifecycle', $lsquicTls, $needle);
}

foreach ([
    'PHP_FUNCTION(king_server_reload_tls_config)',
    'king_server_tls_reload_paths',
    'king_server_tls_path_is_readable',
    'session->server_tls_active = true',
    'session->server_tls_apply_count++',
    'session->server_tls_reload_count++',
    '&session->tls_default_cert_file',
    '&session->tls_default_key_file',
] as $needle) {
    require_contains('Shared server TLS reload semantics', $serverTls, $needle);
}

require_order('LSQUIC runtime binds the shared session before callbacks', $lsquicListenOnce, [
    'runtime->lsquic_function_name = function_name;',
    'runtime->lsquic_session = session;',
    'runtime->lsquic_request_state = request_state;',
    'runtime->lsquic_response_state = response_state;',
]);

foreach ([
    'king_server_http3_lsquic_mark_stream_cancelled_if_registered',
    'runtime->lsquic_session == NULL',
    '!runtime->lsquic_request_state->request_stream_seen',
    '!runtime->lsquic_response_state->initialized',
    'runtime->lsquic_response_state->close_sent',
    'king_server_local_mark_stream_cancelled_if_registered',
] as $needle) {
    require_contains('LSQUIC cancel bridge', $lsquicStreamRuntime, $needle);
}

require_order('LSQUIC response failure marks registered stream cancel', $lsquicListenOnce, [
    'if (runtime->lsquic_response_failed)',
    'king_server_http3_lsquic_mark_stream_cancelled_if_registered(runtime);',
    'failed while writing the active LSQUIC HTTP/3 response',
]);

require_order('LSQUIC premature connection close marks registered stream cancel', $lsquicListenOnce, [
    'if (runtime->lsquic_connection_closed',
    'king_server_http3_lsquic_mark_stream_cancelled_if_registered(runtime);',
    'saw the LSQUIC HTTP/3 connection close before a complete response was sent',
]);

require_order('LSQUIC stream close goes through the cancel bridge before detaching state', $lsquicStreamRuntime, [
    'stream_state->stream_closed = true;',
    'king_server_http3_lsquic_mark_stream_cancelled_if_registered(stream_state->runtime);',
    'stream_state->runtime->lsquic_stream_state = NULL;',
]);

require_contains('Shared server cancel semantics', $localListener, 'king_client_session_mark_cancelled');
require_contains('Shared server cancel semantics', $serverCancel, 'king_server_cancel_invoke_if_registered');
require_contains('Shared server cancel semantics', $serverCancel, 'zend_hash_index_del(&session->server_cancel_handlers');

foreach ([
    'lsquic_conn_close_fn(runtime->lsquic_conn)',
    'lsquic_engine_process_conns_fn(runtime->lsquic_engine)',
    'lsquic_engine_destroy_fn(runtime->lsquic_engine)',
    'SSL_CTX_free(runtime->lsquic_ssl_ctx)',
    'efree(runtime->lsquic_stream_state)',
    'runtime->lsquic_session = NULL',
    'runtime->lsquic_backend_active = false',
    'close(runtime->socket_fd)',
] as $needle) {
    require_contains('LSQUIC shutdown cleanup', $optionsRuntime, $needle);
}

require_order('HTTP/3 one-shot cleanup leaves unowned sockets for runtime destroy', $listenOnce, [
    'bool session_owns_runtime_socket = runtime.socket_fd >= 0',
    '&& session->transport_socket_fd == runtime.socket_fd;',
    'king_server_local_close_session(session);',
    'if (session_owns_runtime_socket)',
    'runtime.socket_fd = -1;',
]);

if (str_contains($lsquicListenOnce . $lsquicRuntime . $lsquicStreamRuntime, 'quiche_conn_close')) {
    throw new RuntimeException('LSQUIC lifecycle path must not call Quiche connection shutdown APIs.');
}

if (!str_contains($listenOnce, 'king_server_http3_runtime_destroy(&runtime);')) {
    throw new RuntimeException('HTTP/3 one-shot cleanup must always reach runtime destroy.');
}

echo "OK\n";
?>
--EXPECT--
OK
