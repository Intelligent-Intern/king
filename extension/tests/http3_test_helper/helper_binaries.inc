<?php

function king_http3_failure_peer_binary(): string
{
    $root = dirname(dirname(KING_HTTP3_TESTS_DIR));
    $template = KING_HTTP3_TESTS_DIR . '/http3_failure_peer.rs';
    $manifest = $root . '/quiche/apps/Cargo.toml';
    $source = $root . '/quiche/apps/src/bin/king-http3-failure-peer.rs';
    $binary = $root . '/quiche/target/release/king-http3-failure-peer';

    if (trim((string) shell_exec('command -v cargo')) === '') {
        throw new RuntimeException('cargo is required to build the HTTP/3 failure peer helper');
    }

    if (!is_file($template)) {
        throw new RuntimeException('missing tracked HTTP/3 failure peer template');
    }

    $templateContents = file_get_contents($template);
    if (!is_string($templateContents) || $templateContents === '') {
        throw new RuntimeException('failed to read tracked HTTP/3 failure peer template');
    }

    $currentSource = is_file($source) ? file_get_contents($source) : false;
    if (!is_string($currentSource) || $currentSource !== $templateContents) {
        if (file_put_contents($source, $templateContents) !== strlen($templateContents)) {
            throw new RuntimeException('failed to synchronize tracked HTTP/3 failure peer source');
        }
    }

    if (is_file($binary) && filemtime($binary) >= max(filemtime($source), filemtime($template))) {
        return $binary;
    }

    $command = sprintf(
        'cargo build --manifest-path %s --release --locked --bin king-http3-failure-peer 2>&1',
        escapeshellarg($manifest)
    );

    exec($command, $output, $exitCode);
    if ($exitCode !== 0 || !is_file($binary)) {
        throw new RuntimeException(
            "failed to build HTTP/3 failure peer helper:\n" . implode("\n", $output)
        );
    }

    return $binary;
}

function king_http3_delayed_body_client_binary(): string
{
    $root = dirname(dirname(KING_HTTP3_TESTS_DIR));
    $template = KING_HTTP3_TESTS_DIR . '/http3_delayed_body_client.rs';
    $manifest = $root . '/quiche/apps/Cargo.toml';
    $source = $root . '/quiche/apps/src/bin/king-http3-delayed-body-client.rs';
    $binary = $root . '/quiche/target/release/king-http3-delayed-body-client';

    if (trim((string) shell_exec('command -v cargo')) === '') {
        throw new RuntimeException('cargo is required to build the HTTP/3 delayed body client helper');
    }

    if (!is_file($template)) {
        throw new RuntimeException('missing tracked HTTP/3 delayed body client template');
    }

    $templateContents = file_get_contents($template);
    if (!is_string($templateContents) || $templateContents === '') {
        throw new RuntimeException('failed to read tracked HTTP/3 delayed body client template');
    }

    $currentSource = is_file($source) ? file_get_contents($source) : false;
    if (!is_string($currentSource) || $currentSource !== $templateContents) {
        if (file_put_contents($source, $templateContents) !== strlen($templateContents)) {
            throw new RuntimeException('failed to synchronize tracked HTTP/3 delayed body client source');
        }
    }

    if (is_file($binary) && filemtime($binary) >= max(filemtime($source), filemtime($template))) {
        return $binary;
    }

    $command = sprintf(
        'cargo build --manifest-path %s --release --locked --bin king-http3-delayed-body-client 2>&1',
        escapeshellarg($manifest)
    );

    exec($command, $output, $exitCode);
    if ($exitCode !== 0 || !is_file($binary)) {
        throw new RuntimeException(
            "failed to build HTTP/3 delayed body client helper:\n" . implode("\n", $output)
        );
    }

    return $binary;
}

function king_http3_abort_client_binary(): string
{
    $root = dirname(dirname(KING_HTTP3_TESTS_DIR));
    $template = KING_HTTP3_TESTS_DIR . '/http3_abort_client.rs';
    $manifest = $root . '/quiche/apps/Cargo.toml';
    $source = $root . '/quiche/apps/src/bin/king-http3-abort-client.rs';
    $binary = $root . '/quiche/target/release/king-http3-abort-client';

    if (trim((string) shell_exec('command -v cargo')) === '') {
        throw new RuntimeException('cargo is required to build the HTTP/3 abort client helper');
    }

    if (!is_file($template)) {
        throw new RuntimeException('missing tracked HTTP/3 abort client template');
    }

    $templateContents = file_get_contents($template);
    if (!is_string($templateContents) || $templateContents === '') {
        throw new RuntimeException('failed to read tracked HTTP/3 abort client template');
    }

    $currentSource = is_file($source) ? file_get_contents($source) : false;
    if (!is_string($currentSource) || $currentSource !== $templateContents) {
        if (file_put_contents($source, $templateContents) !== strlen($templateContents)) {
            throw new RuntimeException('failed to synchronize tracked HTTP/3 abort client source');
        }
    }

    if (is_file($binary) && filemtime($binary) >= max(filemtime($source), filemtime($template))) {
        return $binary;
    }

    $command = sprintf(
        'cargo build --manifest-path %s --release --locked --bin king-http3-abort-client 2>&1',
        escapeshellarg($manifest)
    );

    exec($command, $output, $exitCode);
    if ($exitCode !== 0 || !is_file($binary)) {
        throw new RuntimeException(
            "failed to build HTTP/3 abort client helper:\n" . implode("\n", $output)
        );
    }

    return $binary;
}

function king_http3_multi_peer_binary(): string
{
    $root = dirname(dirname(KING_HTTP3_TESTS_DIR));
    $template = KING_HTTP3_TESTS_DIR . '/http3_multi_peer.rs';
    $manifest = $root . '/quiche/apps/Cargo.toml';
    $source = $root . '/quiche/apps/src/bin/king-http3-multi-peer.rs';
    $binary = $root . '/quiche/target/release/king-http3-multi-peer';

    if (trim((string) shell_exec('command -v cargo')) === '') {
        throw new RuntimeException('cargo is required to build the HTTP/3 multi peer helper');
    }

    if (!is_file($template)) {
        throw new RuntimeException('missing tracked HTTP/3 multi peer template');
    }

    $templateContents = file_get_contents($template);
    if (!is_string($templateContents) || $templateContents === '') {
        throw new RuntimeException('failed to read tracked HTTP/3 multi peer template');
    }

    $currentSource = is_file($source) ? file_get_contents($source) : false;
    if (!is_string($currentSource) || $currentSource !== $templateContents) {
        if (file_put_contents($source, $templateContents) !== strlen($templateContents)) {
            throw new RuntimeException('failed to synchronize tracked HTTP/3 multi peer source');
        }
    }

    if (is_file($binary) && filemtime($binary) >= max(filemtime($source), filemtime($template))) {
        return $binary;
    }

    $command = sprintf(
        'cargo build --manifest-path %s --release --locked --bin king-http3-multi-peer 2>&1',
        escapeshellarg($manifest)
    );

    exec($command, $output, $exitCode);
    if ($exitCode !== 0 || !is_file($binary)) {
        throw new RuntimeException(
            "failed to build HTTP/3 multi peer helper:\n" . implode("\n", $output)
        );
    }

    return $binary;
}
