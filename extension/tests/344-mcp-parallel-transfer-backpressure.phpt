--TEST--
King MCP parallel transfers keep one slow peer connection from blocking unrelated work
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/mcp_test_helper.inc';

function king_mcp_parallel_write_result(string $path, array $result): void
{
    file_put_contents($path, json_encode($result, JSON_UNESCAPED_SLASHES));
}

function king_mcp_parallel_read_result(string $path): array
{
    $result = json_decode((string) file_get_contents($path), true);
    @unlink($path);

    return is_array($result) ? $result : [];
}

function king_mcp_parallel_make_stream(string $payload)
{
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $payload);
    rewind($stream);
    return $stream;
}

$server = king_mcp_test_start_parallel_server();
$port = $server['port'];

$slowUploadResultPath = tempnam(sys_get_temp_dir(), 'king-mcp-slow-upload-');
$slowDownloadResultPath = tempnam(sys_get_temp_dir(), 'king-mcp-slow-download-');

$pid = pcntl_fork();
if ($pid === 0) {
    $connection = king_mcp_connect('127.0.0.1', $port, null);
    $stream = king_mcp_parallel_make_stream(str_repeat('slow-upload', 4096));
    $startedAt = hrtime(true);
    $ok = king_mcp_upload_from_stream($connection, 'svc', 'slow-upload-400', 'asset-slow-upload', $stream);
    $elapsedMs = (hrtime(true) - $startedAt) / 1000000;
    fclose($stream);
    king_mcp_close($connection);
    king_mcp_parallel_write_result($slowUploadResultPath, [
        'ok' => $ok,
        'elapsed_ms' => $elapsedMs,
    ]);
    exit(0);
}

usleep(50000);

$fastConnection = king_mcp_connect('127.0.0.1', $port, null);
$fastStream = king_mcp_parallel_make_stream('fast-upload');
$fastStartedAt = hrtime(true);
$fastUploadOk = king_mcp_upload_from_stream($fastConnection, 'svc', 'blob', 'asset-fast-upload', $fastStream);
$fastUploadElapsedMs = (hrtime(true) - $fastStartedAt) / 1000000;
fclose($fastStream);
king_mcp_close($fastConnection);

pcntl_waitpid($pid, $status);
$slowUploadResult = king_mcp_parallel_read_result($slowUploadResultPath);

var_dump($slowUploadResult['ok'] ?? false);
var_dump(($slowUploadResult['elapsed_ms'] ?? 0) >= 350);
var_dump($fastUploadOk);
var_dump($fastUploadElapsedMs < 250);

$pid = pcntl_fork();
if ($pid === 0) {
    $connection = king_mcp_connect('127.0.0.1', $port, null);
    $seedStream = king_mcp_parallel_make_stream('slow-download-payload');
    $seedOk = king_mcp_upload_from_stream($connection, 'svc', 'slow-download-400', 'asset-slow-download', $seedStream);
    fclose($seedStream);

    $destination = fopen('php://temp', 'w+');
    $startedAt = hrtime(true);
    $downloadOk = king_mcp_download_to_stream($connection, 'svc', 'slow-download-400', 'asset-slow-download', $destination);
    $elapsedMs = (hrtime(true) - $startedAt) / 1000000;
    rewind($destination);
    $payload = stream_get_contents($destination);
    fclose($destination);
    king_mcp_close($connection);

    king_mcp_parallel_write_result($slowDownloadResultPath, [
        'seed_ok' => $seedOk,
        'download_ok' => $downloadOk,
        'elapsed_ms' => $elapsedMs,
        'payload_ok' => $payload === 'slow-download-payload',
    ]);
    exit(0);
}

usleep(50000);

$fastConnection = king_mcp_connect('127.0.0.1', $port, null);
$fastStream = king_mcp_parallel_make_stream('fast-upload-2');
$fastStartedAt = hrtime(true);
$fastUploadOk = king_mcp_upload_from_stream($fastConnection, 'svc', 'blob', 'asset-fast-upload-2', $fastStream);
$fastUploadElapsedMs = (hrtime(true) - $fastStartedAt) / 1000000;
fclose($fastStream);
king_mcp_close($fastConnection);

pcntl_waitpid($pid, $status);
$slowDownloadResult = king_mcp_parallel_read_result($slowDownloadResultPath);

var_dump($slowDownloadResult['seed_ok'] ?? false);
var_dump($slowDownloadResult['download_ok'] ?? false);
var_dump($slowDownloadResult['payload_ok'] ?? false);
var_dump(($slowDownloadResult['elapsed_ms'] ?? 0) >= 350);
var_dump($fastUploadOk);
var_dump($fastUploadElapsedMs < 250);

king_mcp_test_crash_server($server);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
