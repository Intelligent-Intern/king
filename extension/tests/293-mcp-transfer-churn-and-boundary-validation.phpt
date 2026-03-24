--TEST--
King MCP seeded transfer churn preserves payloads and enforces backend key boundaries
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$storagePath = sys_get_temp_dir() . '/king_mcp_fuzz_' . getmypid();
@mkdir($storagePath, 0777, true);

king_object_store_init(['storage_root_path' => $storagePath]);
$connection = king_mcp_connect('127.0.0.1', 8443, null);
$expectedPayloads = [];

for ($i = 0; $i < 24; $i++) {
    $assetId = sprintf('asset-%02d', $i % 8);
    $payload = sprintf('seed-293-%02d-%s', $i, str_repeat(chr(97 + ($i % 26)), 4 + ($i % 5)));
    $source = fopen('php://temp', 'w+');
    fwrite($source, $payload);
    rewind($source);

    if (!king_mcp_upload_from_stream($connection, 'svc', 'blob', $assetId, $source)) {
        echo "upload-failed\n";
    }

    fclose($source);
    $expectedPayloads[$assetId] = $payload;
}

$allMatched = true;
foreach ($expectedPayloads as $assetId => $payload) {
    $destination = fopen('php://temp', 'w+');
    if (!king_mcp_download_to_stream($connection, 'svc', 'blob', $assetId, $destination)) {
        $allMatched = false;
        fclose($destination);
        break;
    }

    rewind($destination);
    $downloaded = stream_get_contents($destination);
    fclose($destination);

    if ($downloaded !== $payload) {
        $allMatched = false;
        break;
    }
}

$tooLongIdentifier = str_repeat('x', 115);
$source = fopen('php://temp', 'w+');
fwrite($source, 'overflow');
rewind($source);
$boundaryRejected = king_mcp_upload_from_stream($connection, 'svc', 'blob', $tooLongIdentifier, $source) === false
    && str_contains(king_mcp_get_error(), 'Object Store key limit');
fclose($source);

$mcp = new King\MCP('127.0.0.1', 8443);
$ooRejected = false;
$source = fopen('php://temp', 'w+');
try {
    $mcp->uploadFromStream('svc', 'blob', $tooLongIdentifier, $source);
} catch (Throwable $e) {
    $ooRejected = $e instanceof King\ValidationException
        && str_contains($e->getMessage(), 'Object Store key limit');
} finally {
    fclose($source);
}

king_mcp_close($connection);

$source = fopen('php://temp', 'w+');
fwrite($source, 'closed');
rewind($source);
$closedRejected = king_mcp_upload_from_stream($connection, 'svc', 'blob', 'asset-closed', $source) === false
    && str_contains(king_mcp_get_error(), 'closed MCP connection');
fclose($source);

if (is_dir($storagePath)) {
    foreach (scandir($storagePath) as $file) {
        if ($file !== '.' && $file !== '..') {
            @unlink($storagePath . '/' . $file);
        }
    }
    @rmdir($storagePath);
}

var_dump(count($expectedPayloads) === 8);
var_dump($allMatched);
var_dump($boundaryRejected);
var_dump($ooRejected);
var_dump($closedRejected);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
