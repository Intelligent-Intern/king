--TEST--
King MCP only permits loopback peers by default and requires an explicit allowlist for remote peers
--FILE--
<?php
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$childScript = tempnam(sys_get_temp_dir(), 'king-mcp-allowlist-');

$code = <<<'PHP'
<?php
$resource = king_mcp_connect('example.internal', 7001, null);
var_dump(is_resource($resource));
var_dump(king_mcp_close($resource));

$ipv6 = new King\MCP('2001:db8::7', 7001);
var_dump($ipv6 instanceof King\MCP);
$ipv6->close();
echo "child-done\n";
PHP;

file_put_contents($childScript, $code);

$default = king_mcp_connect('example.internal', 7001, null);
var_dump($default);
var_dump(str_contains(
    king_mcp_get_error(),
    'Only loopback peers are allowed by default'
));

$loopback = king_mcp_connect('localhost', 7001, null);
var_dump(is_resource($loopback));
var_dump(king_mcp_close($loopback));

$command = [
    PHP_BINARY,
    '-n',
    '-d',
    'extension=' . $extensionPath,
    '-d',
    'king.mcp_allowed_peer_hosts=example.internal,[2001:db8::7]',
    $childScript,
];

$process = proc_open($command, [
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $pipes);

if (!is_resource($process)) {
    throw new RuntimeException('failed to launch MCP allowlist child process');
}

$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$status = proc_close($process);

var_dump($status);
echo $stdout;
var_dump($stderr === '');

@unlink($childScript);
?>
--EXPECT--
bool(false)
bool(true)
bool(true)
bool(true)
int(0)
bool(true)
bool(true)
bool(true)
child-done
bool(true)
