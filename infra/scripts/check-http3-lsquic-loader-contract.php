#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$clientPath = $root . '/extension/src/client/http3.c';
$loaderPath = $root . '/extension/src/client/http3/lsquic_loader.inc';
$runtimePath = $root . '/extension/src/client/http3/lsquic_runtime.inc';
$errorsAndValidationPath = $root . '/extension/src/client/http3/errors_and_validation.inc';
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

$client = king_http3_loader_require_file($clientPath, $errors);
$loader = king_http3_loader_require_file($loaderPath, $errors);
$runtime = king_http3_loader_require_file($runtimePath, $errors);
$errorsAndValidation = king_http3_loader_require_file($errorsAndValidationPath, $errors);

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
    '#include "http3/lsquic_runtime.inc"',
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

$requiredRuntimeNeedles = [
    'king_http3_lsquic_runtime_init',
    'king_http3_lsquic_runtime_destroy',
    'king_http3_lsquic_packets_out',
    'king_http3_lsquic_runtime_packet_in',
    'sendmsg(runtime->socket_fd',
    'lsquic_engine_packet_in_fn',
    'lsquic_engine_init_settings_fn',
    'lsquic_engine_check_settings_fn',
    'lsquic_engine_new_fn',
    'lsquic_engine_connect_fn',
    'N_LSQVER',
    'LSENG_HTTP',
    'king_http3_lsquic_stream_if',
    '.on_new_conn = king_http3_lsquic_on_new_conn',
    '.on_new_stream = king_http3_lsquic_on_new_stream',
    '.on_read = king_http3_lsquic_on_read',
    '.on_write = king_http3_lsquic_on_write',
    '.on_close = king_http3_lsquic_on_close',
    '.on_hsk_done = king_http3_lsquic_on_hsk_done',
    '.on_sess_resume_info = king_http3_lsquic_on_sess_resume_info',
    'king_ticket_ring_get(runtime->lsquic_session_resume',
    'king_ticket_ring_put(session, session_len)',
    'runtime->tls_ticket_source = "ring"',
    'runtime->tls_session_resumed = status == LSQ_HSK_RESUMED_OK',
    'runtime->quic_packets_received++',
    'lsquic_engine_earliest_adv_tick_fn',
    'king_secure_zero(runtime->lsquic_session_resume',
];

foreach ($requiredRuntimeNeedles as $needle) {
    king_http3_loader_require_contains('LSQUIC runtime adapter', $runtime, $needle, $errors);
}

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

if ($errors !== []) {
    king_http3_loader_fail(implode(PHP_EOL, $errors));
}

echo "HTTP/3 LSQUIC loader contract passed." . PHP_EOL;
