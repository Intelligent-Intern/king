# System Runtime

Die System Runtime koordiniert Komponenten wie Client, Server, Object Store,
CDN, Telemetry, Autoscaling, MCP, IIBIN und Orchestrator. Die native API ist
procedural `king_system_*`. Eine native OO-Klasse gibt es aktuell nicht, die
OO-Beispiele sind userland Adapter.

## Function, Beispiel 1: Init, Status, Shutdown

```php
<?php
king_system_init([
    'cluster_id' => 'local-dev',
    'node_id' => 'node-1',
    'state_root_path' => __DIR__ . '/var/system',
    'components' => ['client', 'server', 'object_store', 'pipeline_orchestrator'],
]);

$status = king_system_get_status();

if (($status['lifecycle'] ?? '') !== 'ready') {
    var_dump($status['readiness_blockers'] ?? []);
}

king_system_shutdown();
```

## Function, Beispiel 2: Request durch Runtime und Recovery

```php
<?php
$response = king_system_process_request([
    'type' => 'invoice.accept',
    'tenant_id' => 42,
    'invoice_id' => 'INV-1001',
]);

if (($response['ok'] ?? false) !== true) {
    king_system_fail_component('pipeline_orchestrator');
    king_system_recover();
}

$report = king_system_get_performance_report();
var_dump($response, $report);
```

## OO, Beispiel 1: Runtime Adapter

```php
<?php
final class KingRuntime
{
    public function init(array $config): void
    {
        if (!king_system_init($config)) {
            throw new RuntimeException(king_get_last_error());
        }
    }

    public function status(): array
    {
        return king_system_get_status();
    }
}

$runtime = new KingRuntime();
$runtime->init(['cluster_id' => 'local-dev', 'node_id' => 'node-1']);
var_dump($runtime->status());
```

## OO, Beispiel 2: Health Gate fuer Worker

```php
<?php
final class RuntimeAdmission
{
    public function assertWorkerCanClaim(): void
    {
        $status = king_system_get_status();
        $admission = $status['admission'] ?? [];

        if (($admission['file_worker_claims'] ?? false) !== true) {
            throw new RuntimeException('file worker claims are not admitted');
        }
    }

    public function component(string $name): array
    {
        $info = king_system_get_component_info($name);
        if ($info === false) {
            throw new RuntimeException("Unknown component: $name");
        }

        return $info;
    }
}

$admission = new RuntimeAdmission();
$admission->assertWorkerCanClaim();
var_dump($admission->component('pipeline_orchestrator'));
```
