# HTTP/2

HTTP/2 nutzt die libcurl-basierte Runtime. Einzelrequests laufen ueber
`king_http2_request_send()`, Multiplexing ueber
`king_http2_request_send_multi()`. Im OO-Code steht `King\Client\Http2Client`
zur Verfuegung.

## Function, Beispiel 1: einzelner Request

```php
<?php
$response = king_http2_request_send(
    'https://invoice-api.internal.local/v1/invoices/INV-1001',
    'GET',
    ['accept' => 'application/json'],
    null,
    [
        'timeout_ms' => 2000,
        'ca_file' => __DIR__ . '/certs/ca.pem',
    ]
);

if ($response === false) {
    throw new RuntimeException(king_get_last_error());
}

echo $response['status'] . PHP_EOL;
echo $response['protocol'] . PHP_EOL;
```

## Function, Beispiel 2: Multiplex mit gleicher Origin

```php
<?php
$responses = king_http2_request_send_multi([
    [
        'url' => 'https://invoice-api.internal.local/v1/invoices/INV-1001',
        'method' => 'GET',
        'headers' => ['accept' => 'application/json'],
    ],
    [
        'url' => 'https://invoice-api.internal.local/v1/invoices/INV-1002',
        'method' => 'GET',
        'headers' => ['accept' => 'application/json'],
    ],
], [
    'timeout_ms' => 3000,
    'capture_push' => true,
    'ca_file' => __DIR__ . '/certs/ca.pem',
]);

if ($responses === false) {
    throw new RuntimeException(king_get_last_error());
}

foreach ($responses as $response) {
    printf("%d %s\n", $response['status'], $response['effective_url']);
}
```

## OO, Beispiel 1: Http2Client

```php
<?php
use King\Client\Http2Client;
use King\Config;

$client = new Http2Client(new Config([
    'tls.ca_file' => __DIR__ . '/certs/ca.pem',
]));

$response = $client->request(
    'GET',
    'https://invoice-api.internal.local/v1/tenants/42',
    ['accept' => 'application/json']
);

echo $response->getStatusCode() . PHP_EOL;
$client->close();
```

## OO, Beispiel 2: Async HTTP/2 mit Fehlerbehandlung

```php
<?php
use King\Client\Http2Client;
use King\Config;
use King\TimeoutException;

$client = new Http2Client(new Config([
    'tls.ca_file' => __DIR__ . '/certs/ca.pem',
]));

$awaitable = $client->requestAsync(
    'POST',
    'https://invoice-api.internal.local/v1/invoices',
    ['content-type' => 'application/json'],
    json_encode(['id' => 'INV-1003'], JSON_THROW_ON_ERROR)
);

try {
    $response = $awaitable->await(3000);
    echo $response->getStatusCode() . PHP_EOL;
    echo $response->getBody() . PHP_EOL;
} catch (TimeoutException $e) {
    $awaitable->cancel();
    throw $e;
} finally {
    $client->close();
}
```
