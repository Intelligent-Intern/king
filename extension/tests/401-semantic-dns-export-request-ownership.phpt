--TEST--
King semantic DNS exported read-model arrays stay request-owned across combined reads in one scope
--INI--
king.security_allow_config_override=1
--FILE--
<?php
function king_semantic_dns_benchmark_reset_state_dir(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    @rmdir($directory);
}

function king_semantic_dns_benchmark_shape_run(int $iteration): int
{
    $serviceId = 'api-bench';
    $serviceName = 'api-bench';

    king_semantic_dns_benchmark_reset_state_dir('/tmp/king_semantic_dns_state');

    var_dump(king_semantic_dns_init([
        'enabled' => true,
        'bind_address' => '127.0.0.1',
        'dns_port' => 8053,
        'default_record_ttl_sec' => 120,
        'service_discovery_max_ips_per_response' => 5,
        'semantic_mode_enable' => true,
        'mothernode_uri' => 'mother://bench-node',
        'routing_policies' => ['mode' => 'local'],
    ]));
    var_dump(king_semantic_dns_start_server());
    var_dump(king_semantic_dns_register_service([
        'service_id' => $serviceId,
        'service_name' => $serviceName,
        'service_type' => 'pipeline_orchestrator',
        'status' => 'healthy',
        'hostname' => 'api.internal',
        'port' => 8443,
    ]));

    $discovery = king_semantic_dns_discover_service('pipeline_orchestrator');
    $route = king_semantic_dns_get_optimal_route($serviceName, [
        'location' => [
            'latitude' => 52.52 + (($iteration % 100) / 1000),
            'longitude' => 13.405 + (($iteration % 100) / 1000),
        ],
    ]);
    $topology = king_semantic_dns_get_service_topology();

    return (int) ($discovery['service_count'] ?? 0)
        + (int) ($topology['statistics']['healthy_services'] ?? 0)
        + strlen($serviceId)
        + strlen((string) ($route['service_id'] ?? ''));
}

var_dump(king_semantic_dns_benchmark_shape_run(0));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
int(20)
