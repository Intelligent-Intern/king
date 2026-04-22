<?php

function king_http3_helper_binary(string $helperName): string
{
    $root = dirname(dirname(KING_HTTP3_TESTS_DIR));
    $outputDir = getenv('KING_HTTP3_TEST_HELPER_OUTPUT_DIR');
    if (!is_string($outputDir) || $outputDir === '') {
        $outputDir = $root . '/.cache/king/http3-test-helpers';
    }

    $binary = $outputDir . '/bin/' . $helperName;
    if (is_executable($binary)) {
        return $binary;
    }

    $script = $root . '/infra/scripts/build-http3-test-helpers.sh';
    if (!is_file($script) || !is_executable($script)) {
        throw new RuntimeException('missing repo-owned HTTP/3 C helper build script');
    }

    $command = escapeshellarg($script) . ' build 2>&1';
    exec($command, $output, $exitCode);
    if ($exitCode !== 0 || !is_executable($binary)) {
        throw new RuntimeException(
            "failed to build HTTP/3 C/LSQUIC helper {$helperName}:\n" . implode("\n", $output)
        );
    }

    return $binary;
}

function king_http3_failure_peer_binary(): string
{
    return king_http3_helper_binary('king-http3-failure-peer');
}

function king_http3_delayed_body_client_binary(): string
{
    return king_http3_helper_binary('king-http3-delayed-body-client');
}

function king_http3_abort_client_binary(): string
{
    return king_http3_helper_binary('king-http3-abort-client');
}

function king_http3_multi_peer_binary(): string
{
    return king_http3_helper_binary('king-http3-multi-peer');
}
