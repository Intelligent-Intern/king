# MCP

MCP connects King to a remote peer. Procedural code uses
`king_mcp_connect()`, `king_mcp_request()`, stream upload/download, and
`king_mcp_close()`. OO code uses `King\MCP`.

## Function, Example 1: Unary Request

```php
<?php
$connection = king_mcp_connect('127.0.0.1', 9090, [
    'mcp.timeout_ms' => 1500,
]);

if ($connection === false) {
    throw new RuntimeException(king_mcp_get_error());
}

$payload = json_encode(['invoice_id' => 'INV-1001'], JSON_THROW_ON_ERROR);
$response = king_mcp_request($connection, 'invoice.lookup', 'get', $payload, [
    'timeout_ms' => 1500,
]);

if ($response === false) {
    throw new RuntimeException(king_mcp_get_error());
}

var_dump(json_decode($response, true, flags: JSON_THROW_ON_ERROR));
king_mcp_close($connection);
```

## Function, Example 2: Stream Upload and Download

```php
<?php
$connection = king_mcp_connect('127.0.0.1', 9090, [], ['timeout_ms' => 2000]);

$source = fopen(__DIR__ . '/invoice.xml', 'rb');
king_mcp_upload_from_stream(
    $connection,
    'invoice.archive',
    'put',
    'tenant-42/INV-1001.xml',
    $source,
    ['timeout_ms' => 5000]
);
fclose($source);

$target = fopen(__DIR__ . '/downloaded-invoice.xml', 'wb');
king_mcp_download_to_stream(
    $connection,
    'invoice.archive',
    'get',
    'tenant-42/INV-1001.xml',
    $target,
    ['timeout_ms' => 5000]
);
fclose($target);

king_mcp_close($connection);
```

## OO, Example 1: King\MCP Request

```php
<?php
use King\MCP;

$mcp = new MCP('127.0.0.1', 9090);

$response = $mcp->request(
    'invoice.lookup',
    'get',
    json_encode(['invoice_id' => 'INV-1002'], JSON_THROW_ON_ERROR)
);

var_dump(json_decode($response, true, flags: JSON_THROW_ON_ERROR));
$mcp->close();
```

## OO, Example 2: Async Request with CancelToken

```php
<?php
use King\CancelToken;
use King\MCP;

$mcp = new MCP('127.0.0.1', 9090);
$cancel = new CancelToken();

$awaitable = $mcp->requestAsync(
    'invoice.validation',
    'run',
    json_encode(['object_id' => 'tenant-42/INV-1003.xml'], JSON_THROW_ON_ERROR),
    $cancel,
    ['timeout_ms' => 5000]
);

if (!$awaitable->poll(100)) {
    $cancel->cancel();
    $awaitable->cancel();
}

if (!$awaitable->isCancelled()) {
    echo $awaitable->await(5000) . PHP_EOL;
}

$mcp->close();
```
