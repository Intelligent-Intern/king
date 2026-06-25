# Async and Awaitables

King uses `King\Awaitable` for asynchronous HTTP, MCP, orchestrator, and
inference calls.
Procedural code can use `king_await()`, `king_awaitable_poll()`,
`king_awaitable_cancel()`, `king_awaitable_status()`,
`king_awaitable_any()`, and `king_awaitable_all()`. OO code calls the methods
directly on the awaitable or uses `King\Awaitable::any()` and
`King\Awaitable::all()` for aggregate waits.

Aggregate awaitables resolve to status envelopes with `key`, `status`,
`operation`, `value`, and, for rejected children, `error`. This keeps failed
children visible without losing the caller's original array keys.

## Internal Layout

The native object contract and procedural declarations live in
`extension/include/awaitable/awaitable.h` and are exported through
`extension/include/awaitable/index.h`. The implementation lives under
`extension/src/awaitable/`. PHP-facing registration metadata, class-method
entries, and object contracts live under `extension/include/awaitable/`; object
implementation and aggregate helpers remain under `extension/src/awaitable/`.

## Function, Example 1: Await an HTTP Request

```php
<?php
$awaitable = king_client_send_request_async(
    'http://127.0.0.1:8080/health',
    'GET',
    ['accept' => 'application/json'],
    null,
    ['timeout_ms' => 1500]
);

if (king_awaitable_status($awaitable) === 'pending') {
    king_awaitable_poll($awaitable, 10);
}

$response = king_await($awaitable, 2000);
echo $response['status'] . PHP_EOL;
echo $response['body'] . PHP_EOL;
```

## Function, Example 2: Cancel a Long Orchestrator Run

```php
<?php
king_pipeline_orchestrator_register_tool('normalize-invoice', [
    'kind' => 'php-handler',
]);

king_pipeline_orchestrator_register_handler('normalize-invoice', static function (array $ctx): array {
    $input = $ctx['input'];
    $input['normalized_at'] = date(DATE_ATOM);

    return ['output' => $input];
});

$awaitable = king_pipeline_orchestrator_run_async(
    ['invoice_id' => 'INV-1001'],
    [['tool' => 'normalize-invoice']],
    ['trace_id' => 'invoice-normalization-1001']
);

if (!king_awaitable_poll($awaitable, 50)) {
    king_awaitable_cancel($awaitable);
}

if (king_awaitable_status($awaitable) !== 'cancelled') {
    $result = king_await($awaitable, 2000);
    var_dump($result);
}
```

## Function, Example 3: Wait for the First Completed Operation

```php
<?php
$tenantProfile = king_client_send_request_async(
    'http://127.0.0.1:8080/api/tenants/42',
    'GET',
    ['accept' => 'application/json']
);
$invoiceStatus = king_client_send_request_async(
    'http://127.0.0.1:8080/api/invoices/INV-1001/status',
    'GET',
    ['accept' => 'application/json']
);

$first = king_awaitable_any([
    'tenant' => $tenantProfile,
    'invoice' => $invoiceStatus,
]);

if ($first->poll(25)) {
    $ready = king_await($first);

    if ($ready['status'] === 'resolved') {
        printf("%s finished first\n", $ready['key']);
        var_dump($ready['value']);
    }
}
```

## OO, Example 1: Awaitable Directly on the Object

```php
<?php
use King\Client\HttpClient;

$client = new HttpClient();
$awaitable = $client->requestAsync('GET', 'http://127.0.0.1:8080/status');

$response = $awaitable->await(2000);
echo $response->getStatusCode() . PHP_EOL;
echo $response->getBody() . PHP_EOL;

$client->close();
```

## OO, Example 2: Wrap Multiple Awaitables in Project Code

```php
<?php
use King\Awaitable;
use King\Client\Http2Client;
use King\PipelineOrchestrator;

final class AsyncWork
{
    /** @return list<Awaitable> */
    public function start(): array
    {
        $http = new Http2Client();

        return [
            $http->requestAsync('GET', 'https://invoice-api.internal.local/v1/tenant/42'),
            PipelineOrchestrator::runAsync(
                ['tenant_id' => 42],
                [['tool' => 'refresh-tenant-cache']],
                ['trace_id' => 'tenant-42-cache-refresh']
            ),
        ];
    }

    /** @param list<Awaitable> $awaitables */
    public function collect(array $awaitables): array
    {
        $results = [];

        foreach ($awaitables as $awaitable) {
            if ($awaitable->isPending()) {
                $awaitable->poll(25);
            }

            $results[] = $awaitable->await(3000);
        }

        return $results;
    }
}
```

## OO, Example 3: Collect All Results with Status Envelopes

```php
<?php
use King\Awaitable;
use King\Client\HttpClient;

$client = new HttpClient();

$aggregate = Awaitable::all([
    'catalog' => $client->requestAsync('GET', 'http://127.0.0.1:8080/catalog/import/status'),
    'stock' => $client->requestAsync('GET', 'http://127.0.0.1:8080/stock/sync/status'),
]);

if ($aggregate->poll(50)) {
    foreach ($aggregate->await() as $name => $ready) {
        if ($ready['status'] !== 'resolved') {
            error_log($name . ' failed: ' . ($ready['error'] ?? $ready['status']));
            continue;
        }

        echo $name . ': ' . $ready['value']->getStatusCode() . PHP_EOL;
    }
}

$client->close();
```
