--TEST--
King MCP request and transfer flows work across a namespaced multi-host topology
--SKIPIF--
<?php
foreach (['unshare', 'slirp4netns', 'ip'] as $binary) {
    if (trim((string) shell_exec('command -v ' . escapeshellarg($binary))) === '') {
        echo "skip {$binary} is required for the MCP multi-host namespace harness";
        return;
    }
}

$probe = @shell_exec('unshare -Urn true 2>/dev/null; printf %s $?');
if (trim((string) $probe) !== '0') {
    echo "skip unprivileged user and network namespaces are unavailable";
    return;
}

$ipOutput = trim((string) shell_exec('ip -4 -o addr show up scope global 2>/dev/null'));
if ($ipOutput === '') {
    $ipOutput = trim((string) shell_exec('ip -4 -o addr show up 2>/dev/null'));
}
if (!preg_match('/\\sinet\\s+((?!127\\.)[0-9]+(?:\\.[0-9]+){3})\\//', $ipOutput)) {
    echo "skip a non-loopback IPv4 address is required for the MCP multi-host namespace harness";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/mcp_test_helper.inc';

$server = king_mcp_test_start_server(bindHost: '0.0.0.0');
$client = [];
$capture = [];

try {
    $client = king_mcp_test_run_namespaced_client_script(
        __DIR__ . '/mcp_multi_host_client.inc',
        $server['port']
    );
} finally {
    $stop = @stream_socket_client(
        king_mcp_test_format_tcp_endpoint('127.0.0.1', $server['port']),
        $errno,
        $errstr,
        0.5
    );
    if (is_resource($stop)) {
        fwrite($stop, "STOP\n");
        fflush($stop);
        fclose($stop);
    }
    $capture = king_mcp_test_finalize_server($server);
}

if (($client['__exit_code'] ?? 1) !== 0) {
    throw new RuntimeException(
        'namespaced MCP client failed: ' . ($client['__stderr'] ?? 'unknown error')
    );
}

if (isset($client['exception_class'])) {
    throw new RuntimeException(
        'namespaced MCP client threw ' . $client['exception_class'] . ': ' . $client['exception_message']
    );
}

foreach ([
    'procedural_request' => '{"res":"{\"route\":\"multi-host-procedural\"}"}',
    'procedural_upload' => true,
    'procedural_upload_error' => '',
    'procedural_download' => true,
    'procedural_download_error' => '',
    'procedural_download_payload' => 'namespaced-mcp-blob',
    'procedural_close' => true,
    'procedural_close_error' => '',
    'oo_request' => '{"res":"{\"route\":\"multi-host-oo\"}"}',
    'oo_close' => null,
    'last_error' => '',
] as $key => $expected) {
    if (($client[$key] ?? null) !== $expected) {
        throw new RuntimeException(
            'client key ' . $key . ' mismatch: expected '
            . json_encode($expected)
            . ', got '
            . json_encode($client[$key] ?? null)
        );
    }
}

$peerPrefix = ($client['outbound_addr'] ?? '') . ':';
if ($peerPrefix === ':') {
    throw new RuntimeException('namespaced MCP client did not report its outbound address.');
}

if (count($capture['connections'] ?? []) < 2) {
    throw new RuntimeException('host MCP server did not record the expected multi-host client connections.');
}

foreach (array_slice($capture['connections'], 0, 2) as $index => $connection) {
    $peerName = (string) ($connection['peer_name'] ?? '');
    if (!str_starts_with($peerName, $peerPrefix)) {
        throw new RuntimeException(
            'connection ' . $index . ' peer mismatch: expected prefix '
            . $peerPrefix
            . ', got '
            . json_encode($peerName)
        );
    }
}

$operations = array_map(
    static fn (array $event): ?string => $event['operation'] ?? null,
    $capture['events'] ?? []
);

foreach (['request', 'upload', 'download', 'request', 'stop'] as $expectedOperation) {
    if (($actual = array_shift($operations)) !== $expectedOperation) {
        throw new RuntimeException(
            'event sequence mismatch: expected '
            . $expectedOperation
            . ', got '
            . json_encode($actual)
        );
    }
}

echo "OK\n";
?>
--EXPECT--
OK
