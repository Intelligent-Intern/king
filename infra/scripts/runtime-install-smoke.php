<?php

declare(strict_types=1);

$schema = 'InstallSmoke_' . getmypid();
$dnsPort = 10000 + (getmypid() % 40000);

if (!function_exists('king_connect')) {
    fwrite(STDERR, "King extension functions are unavailable.\n");
    exit(1);
}

if (!king_proto_define_schema($schema, [
    'name' => ['tag' => 3, 'type' => 'string'],
    'id' => ['tag' => 1, 'type' => 'int32', 'required' => true],
    'enabled' => ['tag' => 2, 'type' => 'bool'],
])) {
    fwrite(STDERR, "Failed to define proto smoke schema.\n");
    exit(1);
}

$encoded = king_proto_encode($schema, [
    'name' => 'install-smoke',
    'id' => 7,
    'enabled' => true,
]);
$decoded = king_proto_decode($schema, $encoded);
if (!is_array($decoded) || ($decoded['id'] ?? null) !== 7) {
    fwrite(STDERR, "Proto smoke roundtrip failed.\n");
    exit(1);
}

$root = sys_get_temp_dir() . '/king_install_smoke_' . getmypid();
if (!is_dir($root) && !mkdir($root, 0777, true) && !is_dir($root)) {
    fwrite(STDERR, "Failed to create object-store smoke directory.\n");
    exit(1);
}

try {
    king_object_store_init([
        'storage_root_path' => $root,
        'max_storage_size_bytes' => 1048576,
        'cdn_config' => [
            'enabled' => true,
            'default_ttl_seconds' => 60,
        ],
    ]);
    king_object_store_put('install-smoke', 'payload');
    if (king_object_store_get('install-smoke') !== 'payload') {
        fwrite(STDERR, "Object-store smoke roundtrip failed.\n");
        exit(1);
    }
    if (!king_cdn_cache_object('install-smoke') || !king_object_store_delete('install-smoke')) {
        fwrite(STDERR, "Object-store smoke cache/delete failed.\n");
        exit(1);
    }
} finally {
    foreach (scandir($root) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        @unlink($root . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($root);
}

if (!king_semantic_dns_init([
    'enabled' => true,
    'bind_address' => '127.0.0.1',
    'dns_port' => $dnsPort,
    'default_record_ttl_sec' => 120,
    'service_discovery_max_ips_per_response' => 5,
    'semantic_mode_enable' => true,
    'mothernode_uri' => 'mother://install-smoke',
    'routing_policies' => ['mode' => 'local'],
])) {
    fwrite(STDERR, "Semantic-DNS init smoke failed.\n");
    exit(1);
}
if (!king_semantic_dns_start_server()) {
    fwrite(STDERR, "Semantic-DNS start smoke failed.\n");
    exit(1);
}
if (!king_semantic_dns_register_service([
    'service_id' => 'install-smoke',
    'service_name' => 'install-smoke',
    'service_type' => 'pipeline_orchestrator',
    'status' => 'healthy',
    'hostname' => 'install.internal',
    'port' => 8443,
])) {
    fwrite(STDERR, "Semantic-DNS register smoke failed.\n");
    exit(1);
}

$discovery = king_semantic_dns_discover_service('pipeline_orchestrator');
$route = king_semantic_dns_get_optimal_route('install-smoke');
$topology = king_semantic_dns_get_service_topology();
if (($discovery['service_count'] ?? 0) < 1
    || ($route['service_id'] ?? null) !== 'install-smoke'
    || ($topology['statistics']['healthy_services'] ?? 0) < 1) {
    fwrite(STDERR, "Semantic-DNS smoke verification failed.\n");
    exit(1);
}

$session = king_connect('127.0.0.1', 443, [
    'sni' => 'install.example',
    'alpn' => ['h3'],
]);
if (!is_resource($session)) {
    fwrite(STDERR, "Session smoke failed to create a resource.\n");
    exit(1);
}

$stats = king_get_stats($session);
if (!is_array($stats) || ($stats['state'] ?? null) !== 'open') {
    fwrite(STDERR, "Session smoke failed to read stats.\n");
    exit(1);
}

if (!king_poll($session, 0) || !king_close($session)) {
    fwrite(STDERR, "Session smoke poll/close failed.\n");
    exit(1);
}

echo "install smoke ok\n";
