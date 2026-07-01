# System Runtime

The system runtime coordinates components such as client, server, object
store, CDN, telemetry, autoscaling, MCP, IIBIN, and orchestrator. The native
API is procedural `king_system_*`. A native OO class is not exported yet; the
OO examples are userland adapters.

## Internal Layout

The coordinated system runtime lives in
`extension/src/integration/system_integration.c` with public contracts in
`extension/include/integration/system_integration.h`. The integration module
owns PHP-facing registration metadata under `extension/include/integration/`,
while core status helpers also contribute system introspection leaves.

## Function, Example 1: Init, Status, Shutdown

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

## Function, Example 2: Request Through Runtime and Recovery

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

## OO, Example 1: Runtime Adapter

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

## OO, Example 2: Health Gate for Worker

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
