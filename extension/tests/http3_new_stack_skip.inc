<?php

function king_http3_skipif_skip(string $message): void
{
    echo 'skip ' . $message;
    exit;
}

function king_http3_skipif_require_openssl(): void
{
    if (trim((string) shell_exec('command -v openssl 2>/dev/null')) === '') {
        king_http3_skipif_skip('openssl is required for the local HTTP/3 fixture');
    }
}

function king_http3_skipif_require_lsquic_runtime(): void
{
    $library = getenv('KING_LSQUIC_LIBRARY');
    if (!is_string($library) || $library === '' || !is_file($library)) {
        king_http3_skipif_skip('KING_LSQUIC_LIBRARY must point at a prebuilt liblsquic runtime');
    }

    if (!extension_loaded('king')) {
        king_http3_skipif_skip('king extension is required');
    }

    ob_start();
    phpinfo(INFO_MODULES);
    $moduleInfo = (string) ob_get_clean();
    if (!str_contains($moduleInfo, 'runtime-loaded LSQUIC')) {
        king_http3_skipif_skip('king extension must be built with KING_HTTP3_BACKEND_LSQUIC');
    }
}

function king_http3_skipif_require_c_helpers(): void
{
    $root = dirname(__DIR__, 2);
    $script = $root . '/infra/scripts/build-http3-test-helpers.sh';
    if (!is_file($script) || !is_executable($script)) {
        king_http3_skipif_skip('HTTP/3 C helper build script is required');
    }

    $command = escapeshellarg($script) . ' build >/dev/null 2>&1';
    exec($command, $output, $status);
    if ($status !== 0) {
        king_http3_skipif_skip('HTTP/3 C helper binaries must build from the repo-owned helper sources');
    }
}
