--TEST--
King MCP rehydrates persisted local transfer state across restart and consumes it after successful download
--FILE--
<?php
require __DIR__ . '/mcp_test_helper.inc';

$extensionPath = dirname(__DIR__) . '/modules/king.so';
$statePath = sys_get_temp_dir() . '/king-mcp-local-transfer-state-' . getmypid() . '.bin';
$producerScript = tempnam(sys_get_temp_dir(), 'king-mcp-producer-');
$consumerScript = tempnam(sys_get_temp_dir(), 'king-mcp-consumer-');
$cleanupScript = tempnam(sys_get_temp_dir(), 'king-mcp-cleanup-');

$producerCode = <<<'PHP'
<?php
$host = $argv[1];
$port = (int) $argv[2];
$connection = king_mcp_connect($host, $port, null);
$source = fopen('php://temp', 'w+');
fwrite($source, 'persisted-local-transfer');
rewind($source);
var_dump(king_mcp_upload_from_stream($connection, 'svc', 'blob', 'asset-rehydrate', $source));
fclose($source);
var_dump(king_mcp_close($connection));
PHP;

$consumerCode = <<<'PHP'
<?php
$host = $argv[1];
$port = (int) $argv[2];
$mcp = new King\MCP($host, $port);
$destination = fopen('php://temp', 'w+');
$mcp->downloadToStream('svc', 'blob', 'asset-rehydrate', $destination);
rewind($destination);
var_dump(stream_get_contents($destination) === 'persisted-local-transfer');
fclose($destination);
$mcp->close();
echo "consumer-closed\n";
PHP;

$cleanupCode = <<<'PHP'
<?php
$host = $argv[1];
$port = (int) $argv[2];
$connection = king_mcp_connect($host, $port, null);
$destination = fopen('php://temp', 'w+');
var_dump(king_mcp_download_to_stream($connection, 'svc', 'blob', 'asset-rehydrate', $destination));
var_dump(king_mcp_get_error());
fclose($destination);
var_dump(king_mcp_close($connection));
PHP;

file_put_contents($producerScript, $producerCode);
file_put_contents($consumerScript, $consumerCode);
file_put_contents($cleanupScript, $cleanupCode);

$run = static function (string $script, array $args) use ($extensionPath, $statePath): array {
    $command = array_merge([
        PHP_BINARY,
        '-n',
        '-d',
        'extension=' . $extensionPath,
        '-d',
        'king.mcp_transfer_state_path=' . $statePath,
        $script,
    ], $args);

    $descriptor = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptor, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('failed to launch MCP child process');
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'stdout' => $stdout,
        'stderr' => $stderr,
        'status' => proc_close($process),
    ];
};

$firstServer = king_mcp_test_start_server();
$port = $firstServer['port'];

$producer = $run($producerScript, ['127.0.0.1', (string) $port]);
$firstCapture = king_mcp_test_stop_server($firstServer);

$secondServer = king_mcp_test_start_server($port);
$consumer = $run($consumerScript, ['127.0.0.1', (string) $port]);
$cleanup = $run($cleanupScript, ['127.0.0.1', (string) $port]);
$secondCapture = king_mcp_test_stop_server($secondServer);

var_dump($producer['status']);
echo $producer['stdout'];
var_dump($consumer['status']);
echo $consumer['stdout'];
var_dump($cleanup['status']);
echo $cleanup['stdout'];
var_dump(!file_exists($statePath));

$firstOperations = array_map(
    static fn(array $event): string => $event['operation'],
    $firstCapture['events'] ?? []
);
$secondOperations = array_map(
    static fn(array $event): string => $event['operation'],
    $secondCapture['events'] ?? []
);

var_dump($firstOperations);
var_dump($secondOperations);

@unlink($producerScript);
@unlink($consumerScript);
@unlink($cleanupScript);
@unlink($statePath);
?>
--EXPECTF--
int(0)
bool(true)
bool(true)
int(0)
bool(true)
consumer-closed
int(0)
bool(false)
string(%d) "king_mcp_download_to_stream() could not find an MCP transfer for the requested payload identifier."
bool(true)
bool(true)
array(2) {
  [0]=>
  string(6) "upload"
  [1]=>
  string(4) "stop"
}
array(3) {
  [0]=>
  string(8) "download"
  [1]=>
  string(8) "download"
  [2]=>
  string(4) "stop"
}
