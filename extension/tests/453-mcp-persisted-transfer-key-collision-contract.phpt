--TEST--
King MCP persisted transfer keys stay collision-free when identifiers differ in the final base64url character
--FILE--
<?php
require __DIR__ . '/mcp_test_helper.inc';

$extensionPath = dirname(__DIR__) . '/modules/king.so';
$statePath = sys_get_temp_dir() . '/king-mcp-transfer-key-collision-' . getmypid() . '.bin';
$producerScript = tempnam(sys_get_temp_dir(), 'king-mcp-key-producer-');
$consumerScript = tempnam(sys_get_temp_dir(), 'king-mcp-key-consumer-');

$producerCode = <<<'PHP'
<?php
$host = $argv[1];
$port = (int) $argv[2];
$connection = king_mcp_connect($host, $port, null);

$upload = static function ($connection, string $identifier, string $payload): bool {
    $source = fopen('php://temp', 'w+');
    fwrite($source, $payload);
    rewind($source);
    $result = king_mcp_upload_from_stream($connection, 'svc', 'blob', $identifier, $source);
    fclose($source);

    return $result;
};

var_dump($upload($connection, 'A', 'payload-A'));
var_dump($upload($connection, 'B', 'payload-B'));
var_dump(king_mcp_close($connection));
PHP;

$consumerCode = <<<'PHP'
<?php
$host = $argv[1];
$port = (int) $argv[2];
$connection = king_mcp_connect($host, $port, null);

$download = static function ($connection, string $identifier): array {
    $destination = fopen('php://temp', 'w+');
    $result = king_mcp_download_to_stream($connection, 'svc', 'blob', $identifier, $destination);
    rewind($destination);
    $payload = stream_get_contents($destination);
    fclose($destination);

    return [$result, $payload];
};

[$resultA, $payloadA] = $download($connection, 'A');
[$resultB, $payloadB] = $download($connection, 'B');

var_dump($resultA);
var_dump($payloadA === 'payload-A');
var_dump($resultB);
var_dump($payloadB === 'payload-B');
var_dump(king_mcp_close($connection));
PHP;

file_put_contents($producerScript, $producerCode);
file_put_contents($consumerScript, $consumerCode);

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
$secondCapture = king_mcp_test_stop_server($secondServer);

var_dump($producer['status']);
echo $producer['stdout'];
var_dump($consumer['status']);
echo $consumer['stdout'];

$firstOperations = array_map(
    static fn(array $event): string => $event['operation'],
    $firstCapture['events'] ?? []
);
$secondHits = array_map(
    static fn(array $event): bool => (bool) ($event['hit'] ?? false),
    array_values(array_filter(
        $secondCapture['events'] ?? [],
        static fn(array $event): bool => ($event['operation'] ?? '') === 'download'
    ))
);

var_dump($firstOperations);
var_dump($secondHits);

@unlink($producerScript);
@unlink($consumerScript);
@unlink($statePath);
?>
--EXPECT--
int(0)
bool(true)
bool(true)
bool(true)
int(0)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
array(3) {
  [0]=>
  string(6) "upload"
  [1]=>
  string(6) "upload"
  [2]=>
  string(4) "stop"
}
array(2) {
  [0]=>
  bool(false)
  [1]=>
  bool(false)
}
