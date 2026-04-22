#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$clientPath = $root . '/extension/src/client/http3.c';
$loaderPath = $root . '/extension/src/client/http3/lsquic_loader.inc';
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
    'lsquic_engine_new',
    'lsquic_engine_connect',
    'lsquic_engine_process_conns',
    'lsquic_engine_has_unsent_packets',
    'lsquic_engine_send_unsent_packets',
    'lsquic_engine_earliest_adv_tick',
    'lsquic_conn_make_stream',
    'lsquic_conn_close',
    'lsquic_stream_write',
    'lsquic_stream_read',
    'lsquic_stream_flush',
    'lsquic_stream_close',
    'lsquic_stream_wantread',
    'lsquic_stream_wantwrite',
    'lsquic_stream_id',
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
