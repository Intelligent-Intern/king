# Semantic DNS

Semantic DNS registers services with load and status data and returns
discovery and routing decisions. The native API is procedural
`king_semantic_dns_*`. A native OO class is not exported yet; the OO examples
are userland adapters.

## Internal Layout

The Semantic DNS runtime lives under `extension/src/semantic_dns/` with public
contracts in `extension/include/semantic_dns/`. Its PHP arginfo and
function-table entries live beside the runtime and are included by the
extension bootstrap.

## Function, Example 1: Register and Discover a Service

```php
<?php
king_semantic_dns_init([
    'bind' => '127.0.0.1',
    'port' => 5353,
    'ttl_seconds' => 30,
]);

king_semantic_dns_register_service([
    'service_id' => 'invoice-api-fra-1',
    'service_name' => 'invoice-api',
    'service_type' => 'http',
    'hostname' => '10.0.0.10',
    'port' => 8080,
    'status' => 'healthy',
    'current_load_percent' => 20,
]);

$discovery = king_semantic_dns_discover_service('http', [
    'service_name' => 'invoice-api',
]);

var_dump($discovery);
```

## Function, Example 2: Status Update and Optimal Route

```php
<?php
king_semantic_dns_update_service_status('invoice-api-fra-1', 'degraded', [
    'current_load_percent' => 85,
    'active_connections' => 120,
]);

king_semantic_dns_register_service([
    'service_id' => 'invoice-api-fra-2',
    'service_name' => 'invoice-api',
    'service_type' => 'http',
    'hostname' => '10.0.0.11',
    'port' => 8080,
    'status' => 'healthy',
    'current_load_percent' => 15,
]);

$route = king_semantic_dns_get_optimal_route('invoice-api', [
    'region' => 'eu-central',
]);

var_dump($route);
```

## OO, Example 1: Registry Adapter

```php
<?php
final class SemanticDnsRegistry
{
    public function registerHttp(string $id, string $name, string $host, int $port): void
    {
        king_semantic_dns_register_service([
            'service_id' => $id,
            'service_name' => $name,
            'service_type' => 'http',
            'hostname' => $host,
            'port' => $port,
            'status' => 'healthy',
        ]);
    }

    public function discover(string $type): array
    {
        return king_semantic_dns_discover_service($type);
    }
}

$registry = new SemanticDnsRegistry();
$registry->registerHttp('portal-api-1', 'portal-api', '10.0.1.10', 8080);
var_dump($registry->discover('http'));
```

## OO, Example 2: Router Service

```php
<?php
final class SemanticRouter
{
    public function route(string $serviceName, array $clientInfo = []): array
    {
        $route = king_semantic_dns_get_optimal_route($serviceName, $clientInfo);
        if (($route['status'] ?? '') === 'no_route') {
            throw new RuntimeException("No route for $serviceName");
        }

        return $route;
    }
}

$router = new SemanticRouter();
$route = $router->route('invoice-api', ['tenant_id' => 42]);

printf("http://%s:%d\n", $route['hostname'], $route['port']);
```
