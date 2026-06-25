# HTTP/1

HTTP/1 can be used directly through `king_http1_request_send()` or through
`King\Client\Http1Client`. The client can also send the HTTP `QUERY` method.

## Internal Layout

The PHP-visible client/session/stream/response object contracts live in
`extension/include/client/objects.h`. HTTP/1 request runtime code lives under
`extension/src/client/http1/`; shared client object glue lives in
`extension/src/client/object.inc`.

## Function, Example 1: Simple GET

```php
<?php
$response = king_http1_request_send(
    'http://127.0.0.1:8080/health',
    'GET',
    ['accept' => 'application/json'],
    null,
    ['timeout_ms' => 1500]
);

if ($response === false) {
    throw new RuntimeException(king_get_last_error());
}

echo $response['status'] . PHP_EOL;
echo $response['body'] . PHP_EOL;
```

## Function, Example 2: QUERY with Body and Streaming Response

```php
<?php
$context = king_http1_request_send(
    'http://127.0.0.1:8080/invoices/search',
    'QUERY',
    [
        'content-type' => 'application/query',
        'accept' => 'application/json',
    ],
    'tenant_id = 42 AND status = "accepted"',
    [
        'timeout_ms' => 3000,
        'response_stream' => true,
    ]
);

if ($context === false) {
    throw new RuntimeException(king_get_last_error());
}

$response = king_receive_response($context);
if ($response->getStatusCode() !== 200) {
    throw new RuntimeException($response->getBody());
}

while (!$response->isEndOfBody()) {
    echo $response->read(8192);
}
```

## OO, Example 1: Http1Client

```php
<?php
use King\Client\Http1Client;

$client = new Http1Client();
$response = $client->request('GET', 'http://127.0.0.1:8080/health', [
    'accept' => 'application/json',
]);

echo $response->getStatusCode() . PHP_EOL;
echo $response->getBody() . PHP_EOL;

$client->close();
```

## OO, Example 2: Request Service with CancelToken

```php
<?php
use King\CancelToken;
use King\Client\Http1Client;

final class InvoiceSearchClient
{
    public function __construct(private Http1Client $client) {}

    public function search(int $tenantId, string $status): array
    {
        $cancel = new CancelToken();

        $response = $this->client->request(
            'QUERY',
            'http://127.0.0.1:8080/invoices/search',
            ['content-type' => 'application/query', 'accept' => 'application/json'],
            sprintf('tenant_id = %d AND status = "%s"', $tenantId, $status),
            $cancel
        );

        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException($response->getBody());
        }

        return json_decode($response->getBody(), true, flags: JSON_THROW_ON_ERROR);
    }
}

$service = new InvoiceSearchClient(new Http1Client());
var_dump($service->search(42, 'accepted'));
```
