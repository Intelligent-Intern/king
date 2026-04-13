--TEST--
Repo-local Flow PHP MCP host rejects STOP shutdown from non-loopback peers
--SKIPIF--
<?php
$ipOutput = trim((string) shell_exec('ip -4 -o addr show up scope global 2>/dev/null'));
if ($ipOutput === '') {
    $ipOutput = trim((string) shell_exec('ip -4 -o addr show up 2>/dev/null'));
}

if ($ipOutput === '') {
    echo "skip ip route metadata is unavailable";
    return;
}

if (!preg_match('/\\sinet\\s+((?!127\\.)[0-9]+(?:\\.[0-9]+){3})\\//', $ipOutput)) {
    echo "skip a non-loopback IPv4 address is required";
}
?>
--FILE--
<?php
require_once __DIR__ . '/../../demo/userland/flow-php/src/McpHost.php';

use King\Flow\McpHost;
use King\Flow\McpHostRequest;
use King\Flow\McpHostResponse;

$ipOutput = trim((string) shell_exec('ip -4 -o addr show up scope global 2>/dev/null'));
if ($ipOutput === '') {
    $ipOutput = trim((string) shell_exec('ip -4 -o addr show up 2>/dev/null'));
}

if (preg_match('/\\sinet\\s+((?!127\\.)[0-9]+(?:\\.[0-9]+){3})\\//', $ipOutput, $matches) !== 1) {
    throw new RuntimeException('missing non-loopback IPv4 test address.');
}

$nonLoopbackHost = (string) $matches[1];
$host = new McpHost('0.0.0.0', 0);
$host->start();

$client = @stream_socket_client('tcp://' . $nonLoopbackHost . ':' . $host->port(), $errno, $errstr, 1.0);
if (!is_resource($client)) {
    throw new RuntimeException('failed to connect non-loopback client: ' . $errstr);
}

stream_set_blocking($client, true);
fwrite($client, "STOP\n");
fflush($client);

$result = $host->serve(
    static fn (McpHostRequest $request): McpHostResponse => McpHostResponse::ok(),
    1,
    50
);

$line = trim((string) fgets($client));
fclose($client);

$parts = explode("\t", $line, 2);
$payload = '';
if (($parts[0] ?? '') === 'ERR' && isset($parts[1])) {
    $decoded = base64_decode($parts[1], true);
    if (is_string($decoded)) {
        $payload = $decoded;
    }
}

var_dump(($parts[0] ?? '') === 'ERR');
var_dump(str_contains($payload, 'restricted to loopback clients'));
var_dump($result->stopReason() === 'max_commands');
var_dump($result->commandsHandled() === 1);
var_dump($result->protocolErrors() === 1);
var_dump($host->isRunning() === true);

$host->shutdown();
var_dump($host->isRunning() === false);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
