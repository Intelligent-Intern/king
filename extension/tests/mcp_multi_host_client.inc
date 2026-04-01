<?php

if ($argc < 4) {
    fwrite(STDERR, "usage: mcp_multi_host_client.inc <capture-path> <host> <port>\n");
    exit(1);
}

$capturePath = $argv[1];
$host = $argv[2];
$port = (int) $argv[3];
$capture = [];
$procedural = null;
$oo = null;

try {
    $capture['target_host'] = $host;
    $capture['target_port'] = $port;

    $procedural = king_mcp_connect($host, $port, null);
    $capture['procedural_connected'] = is_resource($procedural);
    $capture['procedural_request'] = king_mcp_request(
        $procedural,
        'svc',
        'ping',
        '{"route":"multi-host-procedural"}'
    );

    $source = fopen('php://temp', 'w+');
    $destination = fopen('php://temp', 'w+');
    fwrite($source, 'namespaced-mcp-blob');
    rewind($source);

    $capture['procedural_upload'] = king_mcp_upload_from_stream(
        $procedural,
        'svc',
        'blob',
        'asset-multi-host',
        $source
    );
    $capture['procedural_upload_error'] = king_mcp_get_error();
    $capture['procedural_download'] = king_mcp_download_to_stream(
        $procedural,
        'svc',
        'blob',
        'asset-multi-host',
        $destination
    );
    $capture['procedural_download_error'] = king_mcp_get_error();

    rewind($destination);
    $capture['procedural_download_payload'] = stream_get_contents($destination);
    fclose($source);
    fclose($destination);

    $capture['procedural_close'] = king_mcp_close($procedural);
    $capture['procedural_close_error'] = king_mcp_get_error();
    $procedural = null;

    $oo = new King\MCP($host, $port);
    $capture['oo_request'] = $oo->request('svc', 'ping', '{"route":"multi-host-oo"}');
    $capture['oo_close'] = $oo->close();
    $capture['last_error'] = king_mcp_get_error();
    $oo = null;
} catch (Throwable $e) {
    $capture['exception_class'] = get_class($e);
    $capture['exception_message'] = $e->getMessage();
} finally {
    if (is_resource($procedural)) {
        @king_mcp_close($procedural);
    }
    if ($oo instanceof King\MCP) {
        try {
            $oo->close();
        } catch (Throwable) {
        }
    }

    file_put_contents(
        $capturePath,
        json_encode($capture, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)
    );
}

exit(isset($capture['exception_class']) ? 1 : 0);
