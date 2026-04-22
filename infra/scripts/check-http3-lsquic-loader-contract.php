#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$clientPath = $root . '/extension/src/client/http3.c';
$loaderPath = $root . '/extension/src/client/http3/lsquic_loader.inc';
$serverPath = $root . '/extension/src/server/http3.c';
$serverLoaderPath = $root . '/extension/src/server/http3/lsquic_loader.inc';
$serverTlsPath = $root . '/extension/src/server/http3/lsquic_tls.inc';
$serverStreamRuntimePath = $root . '/extension/src/server/http3/lsquic_stream_runtime.inc';
$serverRuntimePath = $root . '/extension/src/server/http3/lsquic_runtime.inc';
$serverListenOncePath = $root . '/extension/src/server/http3/lsquic_listen_once.inc';
$streamRuntimePath = $root . '/extension/src/client/http3/lsquic_stream_runtime.inc';
$runtimePath = $root . '/extension/src/client/http3/lsquic_runtime.inc';
$lsquicDispatchPath = $root . '/extension/src/client/http3/lsquic_dispatch.inc';
$lsquicMultiDispatchPath = $root . '/extension/src/client/http3/lsquic_multi_dispatch.inc';
$runtimeInitPath = $root . '/extension/src/client/http3/runtime_init.inc';
$requestResponsePath = $root . '/extension/src/client/http3/request_response.inc';
$dispatchPath = $root . '/extension/src/client/http3/dispatch_api.inc';
$errorsAndValidationPath = $root . '/extension/src/client/http3/errors_and_validation.inc';
$oneShotWireTestPath = $root . '/extension/tests/190-http3-request-send-roundtrip.phpt';
$ooWireTestPath = $root . '/extension/tests/191-oo-http3-client-runtime.phpt';
$errors = [];

function king_http3_loader_fail(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function king_http3_loader_require_file(string $path, array &$errors): string
{
    if (!is_file($path)) {
        $errors[] = 'Missing required file: ' . $path;
        return '';
    }

    $contents = file_get_contents($path);
    if ($contents === false) {
        $errors[] = 'Unable to read required file: ' . $path;
        return '';
    }

    return $contents;
}

function king_http3_loader_require_contains(
    string $label,
    string $source,
    string $needle,
    array &$errors
): void {
    if (!str_contains($source, $needle)) {
        $errors[] = $label . ' must contain ' . $needle;
    }
}

function king_http3_loader_extract_function(string $source, string $signature): ?string
{
    $start = strpos($source, $signature);
    if ($start === false) {
        return null;
    }

    $brace = strpos($source, '{', $start);
    if ($brace === false) {
        return null;
    }

    $depth = 0;
    $length = strlen($source);
    for ($offset = $brace; $offset < $length; ++$offset) {
        $char = $source[$offset];
        if ($char === '{') {
            ++$depth;
            continue;
        }

        if ($char === '}') {
            --$depth;
            if ($depth === 0) {
                return substr($source, $brace + 1, $offset - $brace - 1);
            }
        }
    }

    return null;
}

function king_http3_loader_require_order(
    string $label,
    string $source,
    array $needles,
    array &$errors
): void {
    $cursor = -1;
    foreach ($needles as $needle) {
        $position = strpos($source, $needle);
        if ($position === false) {
            $errors[] = $label . ' is missing ordered marker ' . $needle;
            return;
        }

        if ($position <= $cursor) {
            $errors[] = $label . ' must order ' . implode(' -> ', $needles);
            return;
        }

        $cursor = $position;
    }
}

function king_http3_loader_require_guarded_include(
    string $label,
    string $source,
    string $guard,
    string $include,
    array &$errors
): void {
    $includePosition = strpos($source, $include);
    if ($includePosition === false) {
        $errors[] = $label . ' is missing guarded include ' . $include;
        return;
    }

    $beforeInclude = substr($source, 0, $includePosition);
    $guardPosition = strrpos($beforeInclude, $guard);
    if ($guardPosition === false) {
        $errors[] = $label . ' must guard ' . $include . ' with ' . $guard;
        return;
    }

    $endifPosition = strpos($source, "\n#endif", $includePosition);
    if ($endifPosition === false) {
        $errors[] = $label . ' must close the guard around ' . $include;
    }
}

$client = king_http3_loader_require_file($clientPath, $errors);
$loader = king_http3_loader_require_file($loaderPath, $errors);
$server = king_http3_loader_require_file($serverPath, $errors);
$serverLoader = king_http3_loader_require_file($serverLoaderPath, $errors);
$serverTls = king_http3_loader_require_file($serverTlsPath, $errors);
$serverStreamRuntime = king_http3_loader_require_file($serverStreamRuntimePath, $errors);
$serverRuntime = king_http3_loader_require_file($serverRuntimePath, $errors);
$serverListenOnce = king_http3_loader_require_file($serverListenOncePath, $errors);
$streamRuntime = king_http3_loader_require_file($streamRuntimePath, $errors);
$runtime = king_http3_loader_require_file($runtimePath, $errors);
$lsquicDispatch = king_http3_loader_require_file($lsquicDispatchPath, $errors);
$lsquicMultiDispatch = king_http3_loader_require_file($lsquicMultiDispatchPath, $errors);
$runtimeInit = king_http3_loader_require_file($runtimeInitPath, $errors);
$requestResponse = king_http3_loader_require_file($requestResponsePath, $errors);
$dispatch = king_http3_loader_require_file($dispatchPath, $errors);
$errorsAndValidation = king_http3_loader_require_file($errorsAndValidationPath, $errors);
$oneShotWireTest = king_http3_loader_require_file($oneShotWireTestPath, $errors);
$ooWireTest = king_http3_loader_require_file($ooWireTestPath, $errors);

if ($errors !== []) {
    king_http3_loader_fail(implode(PHP_EOL, $errors));
}

king_http3_loader_require_contains(
    'Client HTTP/3 source',
    $client,
    '#include "http3/lsquic_loader.inc"',
    $errors
);
king_http3_loader_require_contains(
    'Client HTTP/3 source',
    $client,
    '#include "http3/lsquic_stream_runtime.inc"',
    $errors
);
king_http3_loader_require_contains(
    'Client HTTP/3 source',
    $client,
    '#include "http3/lsquic_runtime.inc"',
    $errors
);
king_http3_loader_require_contains(
    'Client HTTP/3 source',
    $client,
    '#include "http3/lsquic_dispatch.inc"',
    $errors
);
king_http3_loader_require_contains(
    'Client HTTP/3 source',
    $client,
    '#include "http3/lsquic_multi_dispatch.inc"',
    $errors
);
king_http3_loader_require_contains(
    'Client HTTP/3 source',
    $client,
    'king_http3_lsquic_api_t',
    $errors
);
king_http3_loader_require_contains(
    'Client HTTP/3 source',
    $client,
    'king_http3_lsquic_load_error_kind_t',
    $errors
);
king_http3_loader_require_contains(
    'Client HTTP/3 source',
    $client,
    'load_error_kind',
    $errors
);
king_http3_loader_require_contains(
    'Client HTTP/3 shared header contract',
    $client,
    'typedef struct _king_http3_header',
    $errors
);
king_http3_loader_require_contains(
    'Client HTTP/3 shared header contract',
    $client,
    'king_http3_header_t *request_headers;',
    $errors
);
king_http3_loader_require_contains(
    'LSQUIC stream runtime header contract',
    $streamRuntime,
    'const king_http3_header_t *headers;',
    $errors
);
king_http3_loader_require_contains(
    'LSQUIC runtime header contract',
    $runtime,
    'const king_http3_header_t *headers',
    $errors
);
king_http3_loader_require_contains(
    'HTTP/3 request preparation header contract',
    $requestResponse,
    'king_http3_header_t **headers_out',
    $errors
);
king_http3_loader_require_guarded_include(
    'Client HTTP/3 source',
    $client,
    '#if !defined(KING_HTTP3_BACKEND_LSQUIC)',
    '#include <quiche.h>',
    $errors
);
king_http3_loader_require_guarded_include(
    'Client HTTP/3 source',
    $client,
    '#if !defined(KING_HTTP3_BACKEND_LSQUIC)',
    '#include "http3/quiche_loader.inc"',
    $errors
);

foreach ([
    'Client HTTP/3 source' => $client,
    'HTTP/3 request/response helpers' => $requestResponse,
    'HTTP/3 dispatch backend selector' => $dispatch,
    'LSQUIC one-shot dispatch' => $lsquicDispatch,
    'LSQUIC multi dispatch' => $lsquicMultiDispatch,
    'LSQUIC runtime adapter' => $runtime,
    'LSQUIC stream runtime bridge' => $streamRuntime,
] as $label => $source) {
    if (str_contains($source, 'quiche_h3_header')) {
        $errors[] = $label . ' must use king_http3_header_t instead of quiche_h3_header.';
    }
}

$requiredClientNeedles = [
    'void (*lsquic_engine_init_settings_fn)(void *, unsigned);',
    'int (*lsquic_engine_check_settings_fn)(const void *, unsigned, char *, size_t);',
    'int (*lsquic_engine_packet_in_fn)(void *, const unsigned char *, size_t, const struct sockaddr *, const struct sockaddr *, void *, int);',
    'int (*lsquic_engine_earliest_adv_tick_fn)(void *, int *);',
    'unsigned (*lsquic_engine_get_conns_count_fn)(void *);',
    'unsigned (*lsquic_engine_count_attq_fn)(void *, int);',
    'void (*lsquic_conn_make_stream_fn)(void *);',
    'unsigned (*lsquic_conn_n_avail_streams_fn)(const void *);',
    'unsigned (*lsquic_conn_n_pending_streams_fn)(const void *);',
    'unsigned (*lsquic_conn_cancel_pending_streams_fn)(void *, unsigned);',
    'int (*lsquic_conn_status_fn)(void *, char *, size_t);',
    'int (*lsquic_stream_send_headers_fn)(void *, const void *, int);',
    'void *(*lsquic_stream_get_hset_fn)(void *);',
    'ssize_t (*lsquic_stream_write_fn)(void *, const void *, size_t);',
    'ssize_t (*lsquic_stream_read_fn)(void *, void *, size_t);',
    'int (*lsquic_stream_flush_fn)(void *);',
    'int (*lsquic_stream_shutdown_fn)(void *, int);',
    'int (*lsquic_stream_close_fn)(void *);',
    'int (*lsquic_stream_wantread_fn)(void *, int);',
    'int (*lsquic_stream_wantwrite_fn)(void *, int);',
    'void *(*lsquic_stream_get_ctx_fn)(const void *);',
    'void (*lsquic_stream_set_ctx_fn)(void *, void *);',
    'void *(*lsquic_conn_get_ctx_fn)(const void *);',
    'void (*lsquic_conn_set_ctx_fn)(void *, void *);',
];

foreach ($requiredClientNeedles as $needle) {
    king_http3_loader_require_contains('Client HTTP/3 LSQUIC API table', $client, $needle, $errors);
}

foreach ([
    'int (*lsquic_engine_earliest_adv_tick_fn)(void *, long *, int *);',
    'void *(*lsquic_conn_make_stream_fn)(void *);',
    'void (*lsquic_stream_close_fn)(void *);',
    'void (*lsquic_stream_wantread_fn)(void *, int);',
    'void (*lsquic_stream_wantwrite_fn)(void *, int);',
    'lsquic_config_new_fn',
    'lsquic_h3_send_request_fn',
    'LSQUIC_H3_ERR_DONE',
] as $forbidden) {
    if (str_contains($client, $forbidden)) {
        $errors[] = 'Client HTTP/3 LSQUIC API table must not contain stale or non-LSQUIC marker: ' . $forbidden;
    }
}

$requiredLoaderNeedles = [
    'KING_LSQUIC_LIBRARY',
    'dlopen(',
    'dlsym(',
    'KING_HTTP3_LSQUIC_LOAD_ERROR_LIBRARY',
    'KING_HTTP3_LSQUIC_LOAD_ERROR_SYMBOL',
    'KING_HTTP3_LSQUIC_LOAD_ERROR_GLOBAL_INIT',
    'king_http3_lsquic.load_error',
    'king_http3_lsquic.load_error_kind',
    'return FAILURE',
    'lsquic_global_init',
    'lsquic_global_cleanup',
    'lsquic_engine_init_settings',
    'lsquic_engine_check_settings',
    'lsquic_engine_new',
    'lsquic_engine_connect',
    'lsquic_engine_packet_in',
    'lsquic_engine_process_conns',
    'lsquic_engine_has_unsent_packets',
    'lsquic_engine_send_unsent_packets',
    'lsquic_engine_earliest_adv_tick',
    'lsquic_engine_get_conns_count',
    'lsquic_engine_count_attq',
    'lsquic_conn_make_stream',
    'lsquic_conn_n_avail_streams',
    'lsquic_conn_n_pending_streams',
    'lsquic_conn_cancel_pending_streams',
    'lsquic_conn_status',
    'lsquic_conn_close',
    'lsquic_stream_send_headers',
    'lsquic_stream_get_hset',
    'lsquic_stream_write',
    'lsquic_stream_read',
    'lsquic_stream_flush',
    'lsquic_stream_shutdown',
    'lsquic_stream_close',
    'lsquic_stream_wantread',
    'lsquic_stream_wantwrite',
    'lsquic_stream_get_ctx',
    'lsquic_stream_set_ctx',
    'lsquic_stream_id',
    'lsquic_conn_get_ctx',
    'lsquic_conn_set_ctx',
    'KING_LSQUIC_GLOBAL_CLIENT',
    'init_result != 0',
    'king_http3_lsquic.global_initialized = true',
    'king_http3_lsquic.ready = true',
];

foreach ($requiredLoaderNeedles as $needle) {
    king_http3_loader_require_contains('LSQUIC loader', $loader, $needle, $errors);
}

foreach (['stub', 'fake', 'HAVE_KING_LSQUIC', 'KING_HTTP3_BACKEND_LSQUIC'] as $forbidden) {
    if (stripos($loader, $forbidden) !== false) {
        $errors[] = 'LSQUIC loader must not contain feature-only or placeholder marker: ' . $forbidden;
    }
}

foreach ([
    '#include <lsquic.h>',
    '#include <lsxpack_header.h>',
    'king_server_http3_lsquic_api_t',
    'king_server_http3_lsquic_load_error_kind_t',
    'king_server_http3_lsquic = {0}',
    '#include "http3/lsquic_loader.inc"',
    '#include "http3/lsquic_tls.inc"',
    '#include "http3/lsquic_stream_runtime.inc"',
    '#include "http3/lsquic_runtime.inc"',
    '#include "http3/lsquic_listen_once.inc"',
] as $needle) {
    king_http3_loader_require_contains('Server HTTP/3 source', $server, $needle, $errors);
}

foreach ([
    '#include "http3/lsquic_loader.inc"',
    '#include "http3/lsquic_tls.inc"',
    '#include "http3/lsquic_stream_runtime.inc"',
    '#include "http3/lsquic_runtime.inc"',
    '#include "http3/lsquic_listen_once.inc"',
] as $include) {
    king_http3_loader_require_guarded_include(
        'Server HTTP/3 source',
        $server,
        '#if defined(KING_HTTP3_BACKEND_LSQUIC)',
        $include,
        $errors
    );
}

foreach ([
    'KING_LSQUIC_GLOBAL_SERVER',
    'KING_LSQUIC_LIBRARY',
    'dlopen(',
    'dlsym(',
    'lsquic_global_init',
    'lsquic_global_cleanup',
    'lsquic_engine_init_settings',
    'lsquic_engine_check_settings',
    'lsquic_engine_new',
    'lsquic_engine_destroy',
    'lsquic_engine_packet_in',
    'lsquic_engine_process_conns',
    'lsquic_engine_has_unsent_packets',
    'lsquic_engine_send_unsent_packets',
    'lsquic_engine_earliest_adv_tick',
    'lsquic_engine_get_conns_count',
    'lsquic_engine_count_attq',
    'lsquic_conn_status',
    'lsquic_conn_close',
    'lsquic_conn_get_ctx',
    'lsquic_conn_set_ctx',
    'lsquic_stream_send_headers',
    'lsquic_stream_get_hset',
    'lsquic_stream_write',
    'lsquic_stream_read',
    'lsquic_stream_flush',
    'lsquic_stream_shutdown',
    'lsquic_stream_close',
    'lsquic_stream_wantread',
    'lsquic_stream_wantwrite',
    'lsquic_stream_get_ctx',
    'lsquic_stream_set_ctx',
    'lsquic_stream_id',
    'KING_SERVER_HTTP3_LSQUIC_LOAD_ERROR_LIBRARY',
    'KING_SERVER_HTTP3_LSQUIC_LOAD_ERROR_SYMBOL',
    'KING_SERVER_HTTP3_LSQUIC_LOAD_ERROR_GLOBAL_INIT',
    'king_server_http3_lsquic.global_initialized = true',
    'king_server_http3_lsquic.ready = true',
] as $needle) {
    king_http3_loader_require_contains('Server LSQUIC loader', $serverLoader, $needle, $errors);
}

foreach (['stub', 'fake', 'HAVE_KING_LSQUIC', 'KING_HTTP3_BACKEND_LSQUIC'] as $forbidden) {
    if (stripos($serverLoader, $forbidden) !== false) {
        $errors[] = 'Server LSQUIC loader must not contain feature-only or placeholder marker: ' . $forbidden;
    }
}

$serverEnsureBody = king_http3_loader_extract_function(
    $serverLoader,
    'static zend_result king_server_http3_ensure_lsquic_ready(void)'
);
if ($serverEnsureBody === null) {
    $errors[] = 'Server LSQUIC loader must define king_server_http3_ensure_lsquic_ready().';
} else {
    if (preg_match('/\A\s*return\s+SUCCESS\s*;/s', $serverEnsureBody) === 1) {
        $errors[] = 'king_server_http3_ensure_lsquic_ready() must not be an unconditional success path.';
    }

    king_http3_loader_require_order(
        'king_server_http3_ensure_lsquic_ready()',
        $serverEnsureBody,
        [
            'dlopen(',
            'king_server_http3_lsquic_load_symbol',
            'lsquic_global_init_fn',
            'KING_LSQUIC_GLOBAL_SERVER',
            'king_server_http3_lsquic.global_initialized = true',
            'king_server_http3_lsquic.ready = true',
        ],
        $errors
    );
}

foreach ([
    'king_server_http3_lsquic_create_ssl_ctx',
    'SSL_CTX_use_certificate_chain_file',
    'king_server_http3_lsquic_get_ssl_ctx',
] as $needle) {
    king_http3_loader_require_contains('Server LSQUIC TLS adapter', $serverTls, $needle, $errors);
}

foreach ([
    'king_server_http3_lsquic_stream_if',
    '.on_new_conn = king_server_http3_lsquic_on_new_conn',
    '.on_new_stream = king_server_http3_lsquic_on_new_stream',
    'king_server_http3_lsquic_hsi_process_header',
    'king_server_http3_collect_request_header',
    'king_server_http3_lsquic_packets_out',
    'sendmsg(runtime->socket_fd',
    'king_server_http3_lsquic_send_response_headers',
    'lsxpack_header_set_offset2',
    'lsquic_stream_send_headers_fn',
    'lsquic_stream_write_fn',
    'lsquic_stream_shutdown_fn',
    'smart_str_appendl(&request->body',
] as $needle) {
    king_http3_loader_require_contains('Server LSQUIC stream runtime adapter', $serverStreamRuntime, $needle, $errors);
}

foreach ([
    'king_server_http3_lsquic_runtime_init',
    'LSENG_HTTP_SERVER',
    'king_server_http3_lsquic_runtime_packet_in',
    'lsquic_engine_packet_in_fn',
    'king_server_http3_lsquic_runtime_process_egress',
    'lsquic_engine_process_conns_fn',
] as $needle) {
    king_http3_loader_require_contains('Server LSQUIC runtime adapter', $serverRuntime, $needle, $errors);
}

foreach ([
    'king_server_http3_listen_once_lsquic',
    'king_server_http3_ensure_lsquic_ready()',
    'king_server_http3_open_listener_socket(host, port, runtime, function_name)',
    'king_server_http3_lsquic_runtime_init(runtime, options, function_name)',
    'king_server_http3_lsquic_runtime_process_egress(runtime, function_name)',
    'king_server_http3_build_wire_request',
    'king_server_local_invoke_handler',
    'king_server_http3_prepare_response_state',
    'king_server_http3_apply_transport_snapshot_from_runtime',
    'server_http3_lsquic_socket',
    'king_server_http3_lsquic_runtime_packet_in',
] as $needle) {
    king_http3_loader_require_contains('Server LSQUIC listen_once path', $serverListenOnce, $needle, $errors);
}

$requiredStreamRuntimeNeedles = [
    'struct _king_http3_lsquic_request_state',
    'king_http3_lsquic_request_from_stream_ctx',
    'king_http3_lsquic_request_from_stream',
    'king_http3_lsquic_runtime_peek_pending_request',
    'king_http3_lsquic_runtime_take_pending_request',
    'king_http3_lsquic_send_headers',
    'king_http3_lsquic_write_body',
    'king_http3_lsquic_read_response',
    'king_http3_lsquic_hset_if',
    'king_http3_lsquic_hsi_create_header_set',
    'king_http3_lsquic_hsi_prepare_decode',
    'king_http3_lsquic_hsi_process_header',
    'king_http3_lsquic_packets_out',
    'sendmsg(runtime->socket_fd',
    'king_http3_lsquic_stream_if',
    '.on_new_conn = king_http3_lsquic_on_new_conn',
    '.on_new_stream = king_http3_lsquic_on_new_stream',
    '.on_read = king_http3_lsquic_on_read',
    '.on_write = king_http3_lsquic_on_write',
    '.on_close = king_http3_lsquic_on_close',
    '.on_hsk_done = king_http3_lsquic_on_hsk_done',
    '.on_sess_resume_info = king_http3_lsquic_on_sess_resume_info',
    'lsquic_stream_send_headers_fn',
    'lsquic_stream_write_fn',
    'lsquic_stream_read_fn',
    'lsquic_stream_get_hset_fn',
    'lsquic_stream_shutdown_fn',
    'lsxpack_header_set_offset2',
    'lsxpack_header_prepare_decode',
    'king_http3_collect_response_header',
    'smart_str_appendl(&request->response->body',
    'request->response->response_complete = true',
    'request->headers_sent = true',
    'request->request_failed = true',
    'king_ticket_ring_put(session, session_len)',
    'runtime->tls_session_resumed = status == LSQ_HSK_RESUMED_OK',
];

foreach ($requiredStreamRuntimeNeedles as $needle) {
    king_http3_loader_require_contains('LSQUIC stream runtime bridge', $streamRuntime, $needle, $errors);
}

$requiredRuntimeNeedles = [
    'king_http3_lsquic_runtime_init',
    'king_http3_lsquic_runtime_destroy',
    'king_http3_lsquic_runtime_prepare_request',
    'king_http3_lsquic_runtime_prepare_requests',
    'king_http3_lsquic_request_state_init',
    'king_http3_lsquic_runtime_packet_in',
    'lsquic_engine_packet_in_fn',
    'lsquic_engine_init_settings_fn',
    'lsquic_engine_check_settings_fn',
    'lsquic_engine_new_fn',
    'lsquic_engine_connect_fn',
    'N_LSQVER',
    'LSENG_HTTP',
    'king_http3_lsquic_stream_if',
    'king_http3_lsquic_packets_out',
    'king_http3_lsquic.lsquic_conn_make_stream_fn(runtime->lsquic_conn)',
    'runtime->lsquic_pending_requests = requests',
    'runtime->lsquic_pending_request_count = request_count',
    'king_ticket_ring_get(runtime->lsquic_session_resume',
    'runtime->tls_ticket_source = "ring"',
    'runtime->quic_packets_received++',
    'lsquic_engine_earliest_adv_tick_fn',
    'king_secure_zero(runtime->lsquic_session_resume',
];

foreach ($requiredRuntimeNeedles as $needle) {
    king_http3_loader_require_contains('LSQUIC runtime adapter', $runtime, $needle, $errors);
}

foreach ([
    'king_http3_runtime_open_udp_socket',
    'getaddrinfo(',
    'SOCK_DGRAM',
    'king_http3_make_socket_nonblocking',
] as $needle) {
    king_http3_loader_require_contains('Shared HTTP/3 UDP runtime init', $runtimeInit, $needle, $errors);
}

foreach ([
    'king_http3_ensure_lsquic_ready()',
    'king_http3_throw_lsquic_unavailable(function_name)',
    'king_http3_execute_request_lsquic(',
    'king_http3_execute_multi_requests_lsquic(',
] as $needle) {
    king_http3_loader_require_contains('HTTP/3 dispatch backend selection', $dispatch, $needle, $errors);
}

foreach ([
    'king_http3_execute_request_lsquic',
    'king_http3_lsquic_drive_requests',
    'king_http3_lsquic_requests_have_started',
    'king_http3_runtime_open_udp_socket(&runtime, &target, function_name)',
    'king_http3_lsquic_runtime_init(&runtime, &target, options, function_name)',
    'king_http3_lsquic_runtime_prepare_request(',
    'king_http3_lsquic_runtime_process_egress(runtime, function_name)',
    'king_http3_lsquic_runtime_packet_in(',
    'king_http3_lsquic_poll_timeout_ms(runtime',
    'king_http3_materialize_response(return_value, &response, &runtime, url_str)',
] as $needle) {
    king_http3_loader_require_contains('LSQUIC one-shot dispatch', $lsquicDispatch, $needle, $errors);
}

foreach ([
    'king_http3_execute_multi_requests_lsquic',
    'king_http3_runtime_open_udp_socket(&runtime, &requests[0].target, function_name)',
    'king_http3_lsquic_runtime_init(&runtime, &requests[0].target, options, function_name)',
    'king_http3_lsquic_request_state_init(',
    'king_http3_lsquic_runtime_prepare_requests(',
    'king_http3_lsquic_drive_requests(',
    'king_http3_materialize_response(',
] as $needle) {
    king_http3_loader_require_contains('LSQUIC multi dispatch', $lsquicMultiDispatch, $needle, $errors);
}

king_http3_loader_require_contains(
    'LSQUIC response materialization',
    $requestResponse,
    '"lsquic_h3"',
    $errors
);

$ensureBody = king_http3_loader_extract_function(
    $loader,
    'static zend_result king_http3_ensure_lsquic_ready(void)'
);
if ($ensureBody === null) {
    $errors[] = 'LSQUIC loader must define king_http3_ensure_lsquic_ready().';
} else {
    if (preg_match('/\A\s*return\s+SUCCESS\s*;/s', $ensureBody) === 1) {
        $errors[] = 'king_http3_ensure_lsquic_ready() must not be an unconditional success path.';
    }

    king_http3_loader_require_order(
        'king_http3_ensure_lsquic_ready()',
        $ensureBody,
        [
            'dlopen(',
            'king_http3_lsquic_load_symbol',
            'lsquic_global_init_fn',
            'king_http3_lsquic.global_initialized = true',
            'king_http3_lsquic.ready = true',
        ],
        $errors
    );
}

foreach ([
    'king_http3_lsquic_loader_exception_class',
    'king_http3_throw_lsquic_unavailable',
    'KING_HTTP3_LSQUIC_LOAD_ERROR_LIBRARY',
    'KING_HTTP3_LSQUIC_LOAD_ERROR_SYMBOL',
    'KING_HTTP3_LSQUIC_LOAD_ERROR_GLOBAL_INIT',
    'king_ce_system_exception',
    'king_ce_protocol_exception',
] as $needle) {
    king_http3_loader_require_contains('HTTP/3 error mapper', $errorsAndValidation, $needle, $errors);
}

foreach ([
    'king_http3_request_send(',
    'king_client_send_request(',
    'KING_LSQUIC_LIBRARY',
    'lsquic_h3',
    "string(9) \"lsquic_h3\"",
] as $needle) {
    king_http3_loader_require_contains('HTTP/3 one-shot wire test', $oneShotWireTest, $needle, $errors);
}

foreach ([
    'KING_QUICHE_LIBRARY',
    'string(9) "quiche_h3"',
] as $forbidden) {
    if (str_contains($oneShotWireTest, $forbidden)) {
        $errors[] = 'HTTP/3 one-shot wire test must target LSQUIC client runtime, not ' . $forbidden;
    }
}

foreach ([
    'new King\\Client\\Http3Client($config)',
    '$client->request(',
    'KING_LSQUIC_LIBRARY',
    '$warmup[\'transport_backend\']',
    "string(9) \"lsquic_h3\"",
] as $needle) {
    king_http3_loader_require_contains('OO HTTP/3 wire test', $ooWireTest, $needle, $errors);
}

foreach ([
    'KING_QUICHE_LIBRARY',
    'string(9) "quiche_h3"',
] as $forbidden) {
    if (str_contains($ooWireTest, $forbidden)) {
        $errors[] = 'OO HTTP/3 wire test must target LSQUIC client runtime, not ' . $forbidden;
    }
}

if ($errors !== []) {
    king_http3_loader_fail(implode(PHP_EOL, $errors));
}

echo "HTTP/3 LSQUIC loader contract passed." . PHP_EOL;
