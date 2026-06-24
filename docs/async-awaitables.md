# Async und Awaitables

King verwendet `King\Awaitable` fuer asynchrone HTTP-, MCP- und
Orchestrator-Aufrufe. Procedural Code kann `king_await()`,
`king_awaitable_poll()`, `king_awaitable_cancel()` und
`king_awaitable_status()` nutzen. OO-Code nutzt die Methoden direkt am
Awaitable.

## Function, Beispiel 1: HTTP Request awaiten

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

## Function, Beispiel 2: Abbruch eines langen Orchestrator-Laufs

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

## OO, Beispiel 1: Awaitable direkt am Objekt

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

## OO, Beispiel 2: mehrere Awaitables im Projektcode kapseln

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
