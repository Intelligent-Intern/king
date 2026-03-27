--TEST--
King MCP transfer identifiers stay collision-free across newline-shaped and binary-safe tuples
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/mcp_test_helper.inc';

function king_mcp_test_upload_payload($connection, string $service, string $method, string $identifier, string $payload): bool
{
    $source = fopen('php://temp', 'w+');
    fwrite($source, $payload);
    rewind($source);
    $result = king_mcp_upload_from_stream($connection, $service, $method, $identifier, $source);
    fclose($source);

    return $result;
}

function king_mcp_test_download_payload($connection, string $service, string $method, string $identifier): array
{
    $destination = fopen('php://temp', 'w+');
    $result = king_mcp_download_to_stream($connection, $service, $method, $identifier, $destination);
    rewind($destination);
    $payload = stream_get_contents($destination);
    fclose($destination);

    return [$result, $payload];
}

function king_mcp_test_upload_payload_oo(King\MCP $connection, string $service, string $method, string $identifier, string $payload): bool
{
    $source = fopen('php://temp', 'w+');
    fwrite($source, $payload);
    rewind($source);

    try {
        $connection->uploadFromStream($service, $method, $identifier, $source);
        fclose($source);
        return true;
    } catch (Throwable $e) {
        fclose($source);
        return false;
    }
}

function king_mcp_test_download_payload_oo(King\MCP $connection, string $service, string $method, string $identifier): array
{
    $destination = fopen('php://temp', 'w+');

    try {
        $connection->downloadToStream($service, $method, $identifier, $destination);
        rewind($destination);
        $payload = stream_get_contents($destination);
        fclose($destination);
        return [true, $payload];
    } catch (Throwable $e) {
        fclose($destination);
        return [false, null];
    }
}

$server = king_mcp_test_start_server();
$connection = king_mcp_connect('127.0.0.1', $server['port'], null);

$newlineServiceA = 'svc';
$newlineMethodA = "blob\nalpha";
$newlineIdA = 'asset';
$newlinePayloadA = 'newline-payload-a';

$newlineServiceB = "svc\nblob";
$newlineMethodB = 'alpha';
$newlineIdB = 'asset';
$newlinePayloadB = 'newline-payload-b';

var_dump(king_mcp_test_upload_payload($connection, $newlineServiceA, $newlineMethodA, $newlineIdA, $newlinePayloadA));
var_dump(king_mcp_test_upload_payload($connection, $newlineServiceB, $newlineMethodB, $newlineIdB, $newlinePayloadB));

[$newlineDownloadAOk, $newlineDownloadedA] = king_mcp_test_download_payload(
    $connection,
    $newlineServiceA,
    $newlineMethodA,
    $newlineIdA
);
[$newlineDownloadBOk, $newlineDownloadedB] = king_mcp_test_download_payload(
    $connection,
    $newlineServiceB,
    $newlineMethodB,
    $newlineIdB
);

var_dump($newlineDownloadAOk && $newlineDownloadedA === $newlinePayloadA);
var_dump($newlineDownloadBOk && $newlineDownloadedB === $newlinePayloadB);

$mcp = new King\MCP('127.0.0.1', $server['port']);
$binaryIdentifierA = "asset\0one";
$binaryIdentifierB = "asset\0two";
$binaryPayloadA = 'binary-payload-a';
$binaryPayloadB = 'binary-payload-b';

var_dump(king_mcp_test_upload_payload_oo($mcp, 'svc', 'blob', $binaryIdentifierA, $binaryPayloadA));
var_dump(king_mcp_test_upload_payload_oo($mcp, 'svc', 'blob', $binaryIdentifierB, $binaryPayloadB));

[$binaryDownloadAOk, $binaryDownloadedA] = king_mcp_test_download_payload_oo(
    $mcp,
    'svc',
    'blob',
    $binaryIdentifierA
);
[$binaryDownloadBOk, $binaryDownloadedB] = king_mcp_test_download_payload_oo(
    $mcp,
    'svc',
    'blob',
    $binaryIdentifierB
);

var_dump($binaryDownloadAOk && $binaryDownloadedA === $binaryPayloadA);
var_dump($binaryDownloadBOk && $binaryDownloadedB === $binaryPayloadB);

king_mcp_close($connection);
$mcp->close();

$capture = king_mcp_test_stop_server($server);
$commandCount = 0;
foreach ($capture['connections'] ?? [] as $capturedConnection) {
    $commandCount += (int) ($capturedConnection['command_count'] ?? 0);
}

var_dump(count($capture['connections'] ?? []) === 3);
var_dump($commandCount === 8);
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
