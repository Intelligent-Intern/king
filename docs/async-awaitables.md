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

## Execution and deadlines

Buffered HTTP/1.1 and HTTP/2 requests use a request-scoped native libcurl multi
loop. `requestAsync()` registers its transfer immediately and performs a
nonblocking initial kick. Submit several requests before awaiting a result;
waiting on one advances every registered HTTP transfer. HTTP/2 requests can
multiplex on a reused connection. This is cooperative I/O in the calling PHP
thread, without PHP worker threads or a background daemon. The runtime needs
libcurl; nonblocking DNS also depends on libcurl's asynchronous DNS support.

| API | Backend |
| --- | --- |
| `king_http1_request_send_async()` | Native shared HTTP loop |
| `king_http2_request_send_async()` | Native shared HTTP loop, strict HTTP/2 |
| `king_http2_request_send_multi_async()` | Native shared HTTP loop, ordered response list |
| `king_client_send_request_async()` / `king_send_request_async()` | Native HTTP/1.1 by default; HTTP/2 with `preferred_protocol` |
| `HttpClient::requestAsync()`, `Http1Client::requestAsync()`, `Http2Client::requestAsync()` | Native shared HTTP loop |
| HTTP/3, MCP, WebSocket, orchestrator and inference async wrappers | Legacy deferred synchronous runners |

For native HTTP, `poll(0)` advances ready I/O without waiting. Positive
`poll(ms)` and `await(ms)` use a monotonic wait budget. A wait timeout leaves
transfers active; await again or explicitly cancel. The HTTP `timeout_ms`
option is a separate transport deadline and rejects that transfer with
`King\TimeoutException`. TLS and network failures retain their exception class.
HTTP error status codes resolve normally so callers can inspect the response.

`any()` chooses the first observed completion rather than array order; this
includes rejected and cancelled children. It does not cancel losing requests.
`all()` preserves input keys and returns each child's status envelope. Nested
aggregates share the outer wait budget. Cancelling an aggregate stops that
aggregate wait; independent children remain active unless their shared
`CancelToken` is cancelled. An HTTP multi-batch owns private child transfers:
cancelling or dropping the batch detaches unfinished children, and a transport
failure rejects the batch and detaches its remaining children.

Legacy deferred runners execute their synchronous operation when driven,
including the historical `poll(0)` behavior. They cannot be preempted by an
await timeout and do not become parallel merely by wrapping them in `all()`.
Thrown exceptions settle a deferred awaitable exactly once; subsequent awaits
rethrow the original Throwable, and aggregates retain its failure envelope.
Use their operation-level transport deadlines. Keep them out of latency-sensitive
native HTTP wait groups when bounded polling is required.

Async HTTP currently buffers responses with an 8 MiB body and 128 KiB header
limit per transfer. It rejects `response_stream`, automatic `follow_redirects`,
and `capture_push` explicitly; the synchronous APIs retain those features.
The native loop caps active transfers at 1024 and open connections at 64 per
PHP request. These are protective limits, not a recommended workload size.
Use a smaller application-level concurrency window appropriate to memory,
provider rate limits and CPU-bound preprocessing. Request shutdown and object
destruction detach outstanding handles and release their buffers.

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

## Keep a bounded concurrency window

This pattern refills a slot as soon as a response arrives. Remove the completed
entry before calling `any()` again, since settled awaitables retain their result.

```php
<?php
$urls = new ArrayIterator($pendingUrls);
$active = [];
$nextId = 0;
$limit = 8;

while ($urls->valid() || $active !== []) {
    while ($urls->valid() && count($active) < $limit) {
        $active[$nextId++] = king_http1_request_send_async(
            $urls->current(), 'GET', null, null, ['timeout_ms' => 15000]
        );
        $urls->next();
    }
    $ready = King\Awaitable::any($active)->await();
    unset($active[$ready['key']]);
    if ($ready['status'] === 'resolved') {
        consumeResponse($ready['value']);
    } else {
        recordFailure($ready['key'], $ready['error'] ?? $ready['status']);
    }
}
```

Binary request bodies are passed as PHP strings without a JSON or Base64
wrapper. Choose the content type required by the receiving endpoint; binary
transport does not change that endpoint's protocol.
