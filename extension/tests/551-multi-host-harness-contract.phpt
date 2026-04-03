--TEST--
King repo-wide multi-host namespace harness reaches a host-bound server over a non-loopback path and captures the cross-host-style peer identity
--SKIPIF--
<?php
foreach (['unshare', 'slirp4netns', 'ip'] as $binary) {
    if (trim((string) shell_exec('command -v ' . escapeshellarg($binary))) === '') {
        echo "skip {$binary} is required for the multi-host namespace harness";
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
    echo "skip a non-loopback IPv4 address is required for the multi-host namespace harness";
}
?>
--FILE--
<?php
require __DIR__ . '/multi_host_test_helper.inc';

$server = king_multi_host_test_start_server_script(
    __DIR__ . '/multi_host_echo_server.inc',
    bindHost: '0.0.0.0'
);
$client = [];
$capture = [];

try {
    $client = king_multi_host_test_run_namespaced_php_script(
        __DIR__ . '/multi_host_echo_client.inc',
        $server['port'],
        [],
        [],
        'repo multi-host harness'
    );
} finally {
    $capture = king_multi_host_test_finalize_server($server);
}

if (($client['__exit_code'] ?? 1) !== 0) {
    throw new RuntimeException(
        'multi-host namespace client failed: ' . ($client['__stderr'] ?? 'unknown error')
    );
}

if (isset($client['exception_class'])) {
    throw new RuntimeException(
        'multi-host namespace client threw '
        . $client['exception_class']
        . ': '
        . $client['exception_message']
    );
}

if (($client['reply'] ?? null) !== 'host-ack:namespace-hello') {
    throw new RuntimeException('namespaced client reply drifted: ' . json_encode($client['reply'] ?? null));
}

if (($capture['received'] ?? null) !== 'namespace-hello') {
    throw new RuntimeException('host-bound server did not receive the expected namespace payload.');
}

$peerPrefix = ($client['outbound_addr'] ?? '') . ':';
if ($peerPrefix === ':') {
    throw new RuntimeException('multi-host namespace client did not report its outbound address.');
}

if (!str_starts_with((string) ($capture['peer_name'] ?? ''), $peerPrefix)) {
    throw new RuntimeException(
        'host-bound server peer drifted: expected prefix '
        . $peerPrefix
        . ', got '
        . json_encode($capture['peer_name'] ?? '')
    );
}

echo "OK\n";
?>
--EXPECT--
OK
