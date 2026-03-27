--TEST--
King MCP OO runtime maps protocol transport and backend failures into stable public exception classes
--FILE--
<?php
require __DIR__ . '/mcp_test_helper.inc';

$server = king_mcp_test_start_server();

try {
    $protocol = new King\MCP('127.0.0.1', $server['port']);
    try {
        $protocol->request('svc', 'fail', '{}');
        echo "no-exception-1\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage());
    } finally {
        $protocol->close();
    }

    $transport = new King\MCP('127.0.0.1', $server['port']);
    try {
        $transport->request('svc', 'drop-request', '{}');
        echo "no-exception-2\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump(str_contains($e->getMessage(), 'remote MCP peer'));
    } finally {
        $transport->close();
    }
} finally {
    king_mcp_test_stop_server($server);
}

$backendServer = king_mcp_test_start_server();
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$backendScript = tempnam(sys_get_temp_dir(), 'king-mcp-backend-');
$stateDir = sys_get_temp_dir() . '/king-mcp-backend-dir-' . getmypid();

mkdir($stateDir);

$backendCode = <<<'PHP'
<?php
$host = $argv[1];
$port = (int) $argv[2];
$mcp = new King\MCP($host, $port);
$source = fopen('php://temp', 'w+');
fwrite($source, 'backend-payload');
rewind($source);

try {
    $mcp->uploadFromStream('svc', 'blob', 'asset-backend', $source);
    echo "no-exception-3\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'local transfer state path'));
}

fclose($source);
$mcp->close();
PHP;

file_put_contents($backendScript, $backendCode);

$command = [
    PHP_BINARY,
    '-n',
    '-d',
    'extension=' . $extensionPath,
    '-d',
    'king.mcp_transfer_state_path=' . $stateDir,
    $backendScript,
    '127.0.0.1',
    (string) $backendServer['port'],
];

$process = proc_open($command, [
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $pipes);

if (!is_resource($process)) {
    throw new RuntimeException('failed to launch MCP backend child process');
}

$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$status = proc_close($process);

king_mcp_test_stop_server($backendServer);

var_dump($status);
echo $stdout;
var_dump($stderr === '');

@unlink($backendScript);
@rmdir($stateDir);
?>
--EXPECTF--
string(25) "King\MCPProtocolException"
string(39) "Remote MCP peer forced request failure."
string(27) "King\MCPConnectionException"
bool(true)
int(0)
string(21) "King\MCPDataException"
bool(true)
bool(true)
