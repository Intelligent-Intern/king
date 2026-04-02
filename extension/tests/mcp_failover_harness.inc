<?php

require_once __DIR__ . '/mcp_test_helper.inc';

/* Reusable MCP multi-peer failover harness for loss, rejoin, and topology-breakage contracts. */

function king_mcp_failover_harness_cleanup_dir(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }

    $entries = scandir($path);
    if (!is_array($entries)) {
        @rmdir($path);
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        king_mcp_failover_harness_cleanup_dir($path . '/' . $entry);
    }

    @rmdir($path);
}

function king_mcp_failover_harness_peer_key(string $name): string
{
    $sanitized = preg_replace('/[^a-z0-9._-]+/i', '-', $name);
    if (!is_string($sanitized) || $sanitized === '') {
        throw new RuntimeException('MCP failover harness peer name must contain at least one portable character.');
    }

    return $sanitized;
}

function king_mcp_failover_harness_create(
    array $peerNames,
    string $bindHost = '127.0.0.1'
): array {
    $root = sys_get_temp_dir() . '/king-mcp-failover-' . getmypid() . '-' . bin2hex(random_bytes(6));

    if (!mkdir($root, 0700, true) && !is_dir($root)) {
        throw new RuntimeException('failed to create the MCP failover harness root directory');
    }

    $harness = [
        'root' => $root,
        'bind_host' => $bindHost,
        'peers' => [],
    ];

    foreach ($peerNames as $peerName) {
        if (!is_string($peerName) || $peerName === '') {
            throw new RuntimeException('MCP failover harness peer names must be non-empty strings.');
        }

        if (isset($harness['peers'][$peerName])) {
            throw new RuntimeException('duplicate MCP failover harness peer: ' . $peerName);
        }

        $peerKey = king_mcp_failover_harness_peer_key($peerName);
        $harness['peers'][$peerName] = [
            'name' => $peerName,
            'key' => $peerKey,
            'host' => $bindHost,
            'port' => null,
            'generation' => 0,
            'server' => null,
            'capture_history' => [],
            'transfer_state_path' => $root . '/' . $peerKey . '.state.json',
        ];

        king_mcp_failover_harness_restart_peer($harness, $peerName);
    }

    return $harness;
}

function king_mcp_failover_harness_peer_endpoint(array $harness, string $peerName): array
{
    if (!isset($harness['peers'][$peerName])) {
        throw new RuntimeException('unknown MCP failover harness peer: ' . $peerName);
    }

    $peer = $harness['peers'][$peerName];
    if (!is_int($peer['port']) || $peer['port'] <= 0) {
        throw new RuntimeException('MCP failover harness peer is not currently listening: ' . $peerName);
    }

    return [
        'host' => (string) $peer['host'],
        'port' => (int) $peer['port'],
        'generation' => (int) $peer['generation'],
    ];
}

function king_mcp_failover_harness_connect_peer(array $harness, string $peerName)
{
    $endpoint = king_mcp_failover_harness_peer_endpoint($harness, $peerName);
    return king_mcp_connect($endpoint['host'], $endpoint['port'], null);
}

function king_mcp_failover_harness_restart_peer(array &$harness, string $peerName): array
{
    if (!isset($harness['peers'][$peerName])) {
        throw new RuntimeException('unknown MCP failover harness peer: ' . $peerName);
    }

    $peer = &$harness['peers'][$peerName];
    if (is_array($peer['server'])) {
        throw new RuntimeException('MCP failover harness peer is already running: ' . $peerName);
    }

    $server = king_mcp_test_start_persisted_server(
        $peer['transfer_state_path'],
        $peer['port'],
        $peer['host']
    );

    $peer['generation']++;
    $peer['port'] = (int) $server['port'];
    $peer['server'] = $server;

    return [
        'host' => (string) $peer['host'],
        'port' => (int) $peer['port'],
        'generation' => (int) $peer['generation'],
    ];
}

function king_mcp_failover_harness_record_capture(
    array &$harness,
    string $peerName,
    string $termination,
    array $capture
): array {
    $peer = &$harness['peers'][$peerName];
    $entry = [
        'peer' => $peerName,
        'generation' => (int) $peer['generation'],
        'termination' => $termination,
        'capture' => $capture,
    ];
    $peer['capture_history'][] = $entry;
    $peer['server'] = null;

    return $entry;
}

function king_mcp_failover_harness_crash_peer(
    array &$harness,
    string $peerName,
    int $signal = 9
): array {
    if (!isset($harness['peers'][$peerName])) {
        throw new RuntimeException('unknown MCP failover harness peer: ' . $peerName);
    }

    $peer = $harness['peers'][$peerName];
    if (!is_array($peer['server'])) {
        throw new RuntimeException('MCP failover harness peer is not currently running: ' . $peerName);
    }

    $capture = king_mcp_test_crash_server($peer['server'], $signal);

    return king_mcp_failover_harness_record_capture($harness, $peerName, 'crash', $capture);
}

function king_mcp_failover_harness_stop_peer(array &$harness, string $peerName): array
{
    if (!isset($harness['peers'][$peerName])) {
        throw new RuntimeException('unknown MCP failover harness peer: ' . $peerName);
    }

    $peer = $harness['peers'][$peerName];
    if (!is_array($peer['server'])) {
        throw new RuntimeException('MCP failover harness peer is not currently running: ' . $peerName);
    }

    $capture = king_mcp_test_stop_server($peer['server']);

    return king_mcp_failover_harness_record_capture($harness, $peerName, 'stop', $capture);
}

function king_mcp_failover_harness_capture_history(array $harness, string $peerName): array
{
    if (!isset($harness['peers'][$peerName])) {
        throw new RuntimeException('unknown MCP failover harness peer: ' . $peerName);
    }

    return $harness['peers'][$peerName]['capture_history'];
}

function king_mcp_failover_harness_shutdown(array &$harness): array
{
    foreach (array_keys($harness['peers']) as $peerName) {
        if (is_array($harness['peers'][$peerName]['server'])) {
            king_mcp_failover_harness_stop_peer($harness, $peerName);
        }
    }

    $captures = [];
    foreach ($harness['peers'] as $peerName => $peer) {
        $captures[$peerName] = $peer['capture_history'];
    }

    return $captures;
}

function king_mcp_failover_harness_destroy(array &$harness): void
{
    king_mcp_failover_harness_cleanup_dir((string) ($harness['root'] ?? ''));
}
