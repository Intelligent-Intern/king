# HTTP/3

HTTP/3 uses QUIC and returns additional transport and TLS ticket metadata.
Procedural code uses `king_http3_request_send()` and
`king_http3_request_send_multi()`. OO code uses `King\Client\Http3Client`.

## Function, Example 1: Single HTTP/3 Request

```php
<?php
$response = king_http3_request_send(
    'https://invoice-api.internal.local/v1/status',
    'GET',
    ['accept' => 'application/json'],
    null,
    [
        'timeout_ms' => 2500,
        'ca_file' => __DIR__ . '/certs/ca.pem',
    ]
);

if ($response === false) {
    throw new RuntimeException(king_get_last_error());
}

printf(
    "status=%d resumed=%s lost=%d\n",
    $response['status'],
    ($response['tls_session_resumed'] ?? false) ? 'yes' : 'no',
    $response['quic_packets_lost'] ?? 0
);
```

## Function, Example 2: HTTP/3 Multiplex

```php
<?php
$responses = king_http3_request_send_multi([
    [
        'url' => 'https://invoice-api.internal.local/v1/invoices/INV-1001',
        'method' => 'GET',
        'headers' => ['accept' => 'application/json'],
    ],
    [
        'url' => 'https://invoice-api.internal.local/v1/invoices/INV-1001/events',
        'method' => 'GET',
        'headers' => ['accept' => 'application/json'],
    ],
], [
    'timeout_ms' => 4000,
    'ca_file' => __DIR__ . '/certs/ca.pem',
]);

if ($responses === false) {
    throw new RuntimeException(king_get_last_error());
}

foreach ($responses as $response) {
    echo $response['status'] . ' ' . ($response['stream_kind'] ?? 'h3') . PHP_EOL;
}
```

## OO, Example 1: Http3Client

```php
<?php
use King\Client\Http3Client;
use King\Config;

$client = new Http3Client(new Config([
    'tls.ca_file' => __DIR__ . '/certs/ca.pem',
    'quic.ping_interval_ms' => 500,
]));

$response = $client->request('GET', 'https://invoice-api.internal.local/v1/status');
echo $response->getStatusCode() . PHP_EOL;

$client->close();
```

## OO, Example 2: Cancelable HTTP/3 Call

```php
<?php
use King\CancelToken;
use King\Client\Http3Client;
use King\Config;

$cancel = new CancelToken();
$client = new Http3Client(new Config([
    'tls.ca_file' => __DIR__ . '/certs/ca.pem',
]));

$awaitable = $client->requestAsync(
    'POST',
    'https://invoice-api.internal.local/v1/reports',
    ['content-type' => 'application/json'],
    json_encode(['from' => '2026-01-01', 'to' => '2026-01-31'], JSON_THROW_ON_ERROR),
    $cancel
);

if (!$awaitable->poll(100)) {
    $cancel->cancel();
    $awaitable->cancel();
}

if (!$awaitable->isCancelled()) {
    $response = $awaitable->await(3000);
    echo $response->getBody() . PHP_EOL;
}

$client->close();
```

## Decoded header resource limits

King requires LSQUIC 4.9.0 or newer. The server and client enforce a cumulative
128 KiB field-section budget while receiving headers, before retaining each
field. The charge is the decoded name length plus value length plus 32 bytes
per field, including duplicates and pseudo-headers (RFC 9114, section 4.2.2).
The budget is not reset for another header block on the same request/response.

QPACK scratch allocations are independently bounded before allocation to the
field-section ceiling and the LSXPACK length representation. The scratch buffer
is reused rather than retained for each repeated reference. LSQUIC buffers at
most one completed header set per server stream and two per client stream
awaiting application processing, preserving interim-response buffering.
`header_bytes` telemetry continues to count decoded name/value bytes only.

Dependency archives and build caches use fixed version and commit identities;
the LSQUIC bootstrap and runtime admission do not compute content checksums.
