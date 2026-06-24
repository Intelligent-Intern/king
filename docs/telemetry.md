# Telemetry

Telemetry erfasst Spans, Metrics und Logs und kann Trace-Kontext weitergeben.
Die native API ist procedural `king_telemetry_*`. Eine native OO-Klasse gibt
es aktuell nicht, die OO-Beispiele sind userland Adapter.

## Function, Beispiel 1: Span, Metric, Log

```php
<?php
king_telemetry_init([
    'service_name' => 'invoice-platform',
    'exporter' => 'otlp',
    'endpoint' => 'http://127.0.0.1:4318',
]);

$span = king_telemetry_start_span('invoice.receive', [
    'tenant_id' => '42',
    'invoice_id' => 'INV-1001',
]);

king_telemetry_record_metric('invoice_received_total', 1, ['tenant' => '42']);
king_telemetry_log('info', 'invoice received', ['invoice_id' => 'INV-1001']);

king_telemetry_end_span($span, ['status' => 'accepted']);
$flush = king_telemetry_flush();

var_dump($flush);
```

## Function, Beispiel 2: Trace-Kontext an HTTP weitergeben

```php
<?php
$span = king_telemetry_start_span('nav.submit', ['invoice_id' => 'INV-1002']);

$headers = king_telemetry_inject_context([
    'content-type' => 'application/json',
    'accept' => 'application/json',
]);

$response = king_client_send_request(
    'http://127.0.0.1:8080/nav/submit',
    'POST',
    $headers,
    json_encode(['invoice_id' => 'INV-1002'], JSON_THROW_ON_ERROR),
    ['timeout_ms' => 3000]
);

king_telemetry_record_metric('nav_submit_total', 1, [
    'status' => (string) ($response['status'] ?? 0),
]);

king_telemetry_end_span($span);
king_telemetry_flush();
```

## OO, Beispiel 1: userland Tracer

```php
<?php
final class Tracer
{
    public function start(string $name, array $attributes = []): string
    {
        return king_telemetry_start_span($name, $attributes);
    }

    public function end(string $spanId, array $attributes = []): void
    {
        king_telemetry_end_span($spanId, $attributes);
    }
}

$tracer = new Tracer();
$span = $tracer->start('invoice.map', ['invoice_id' => 'INV-1003']);
$tracer->end($span, ['result' => 'ok']);
```

## OO, Beispiel 2: instrumentierter Service

```php
<?php
final class InstrumentedInvoiceSubmitter
{
    public function __construct(private Tracer $tracer) {}

    public function submit(array $invoice): array
    {
        $span = $this->tracer->start('invoice.submit', [
            'invoice_id' => (string) $invoice['id'],
        ]);

        try {
            king_telemetry_record_metric('invoice_submit_attempt_total', 1);

            $response = king_client_send_request(
                'http://127.0.0.1:8080/invoices',
                'POST',
                ['content-type' => 'application/json'],
                json_encode($invoice, JSON_THROW_ON_ERROR)
            );

            $this->tracer->end($span, ['http.status_code' => $response['status'] ?? 0]);
            return $response;
        } catch (Throwable $e) {
            $this->tracer->end($span, ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
```
