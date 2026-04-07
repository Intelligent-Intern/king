<?php

declare(strict_types=1);

function fail_migration(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

if ($argc < 2) {
    fail_migration('Missing required migration mode argument (expected "write" or "read").');
}

$mode = $argv[1];
$objectStoreRoot = getenv('KING_PERSIST_OBJECT_STORE_ROOT');

if (!is_string($objectStoreRoot) || $objectStoreRoot === '') {
    fail_migration('Missing KING_PERSIST_OBJECT_STORE_ROOT.');
}

if ($mode !== 'write' && $mode !== 'read') {
    fail_migration('Expected migration mode write or read.');
}

$semanticConfig = [
    'enabled' => true,
    'bind_address' => '127.0.0.1',
    'dns_port' => 5453,
    'service_discovery_max_ips_per_response' => 8,
    'semantic_mode_enable' => true,
    'mothernode_uri' => 'mother://migration-seed',
];

if ($mode === 'write') {
    king_object_store_init([
        'storage_root_path' => $objectStoreRoot,
        'primary_backend' => 'local_fs',
        'max_storage_size_bytes' => 1048576,
    ]);

    if (!king_object_store_put('migration-alpha', 'alpha-payload')) {
        fail_migration('Failed to persist migration-alpha.');
    }
    if (!king_object_store_put('migration-beta', 'beta-payload')) {
        fail_migration('Failed to persist migration-beta.');
    }

    $summarizer_handler = static function(array $context): array {
        $input = $context['input'] ?? null;
        if (!is_array($input)) {
            throw new RuntimeException('unexpected orchestrator input');
        }

        return ['output' => $input];
    };

    $metadata = king_object_store_get_metadata('migration-alpha');
    if (($metadata['content_length'] ?? null) !== 13) {
        fail_migration('Unexpected content length for migration-alpha.');
    }

    if (!king_pipeline_orchestrator_register_tool('summarizer', [
        'model' => 'gpt-sim',
        'max_tokens' => 64,
    ])) {
        fail_migration('Failed to register orchestrator tool.');
    }
    if (!king_pipeline_orchestrator_register_handler('summarizer', $summarizer_handler)) {
        fail_migration('Failed to register orchestrator handler.');
    }

    $result = king_pipeline_orchestrator_run(
        ['text' => 'persisted text'],
        [['tool' => 'summarizer', 'params' => ['ratio' => 0.5]]],
        ['trace_id' => 'persist-migration-1']
    );
    if (($result['text'] ?? null) !== 'persisted text') {
        fail_migration('Unexpected orchestrator write result.');
    }

    if (!king_semantic_dns_init($semanticConfig)) {
        fail_migration('Semantic DNS init failed in write mode.');
    }
    if (!king_semantic_dns_start_server()) {
        fail_migration('Semantic DNS start failed in write mode.');
    }
    if (!king_semantic_dns_register_service([
        'service_id' => 'migration-api-1',
        'service_name' => 'migration-api',
        'service_type' => 'pipeline_orchestrator',
        'status' => 'healthy',
        'hostname' => 'migration-api-1.internal',
        'port' => 8443,
        'current_load_percent' => 20,
        'active_connections' => 5,
        'total_requests' => 41,
    ])) {
        fail_migration('Semantic DNS service registration failed in write mode.');
    }
    if (!king_semantic_dns_register_mother_node([
        'node_id' => 'migration-mother-a',
        'hostname' => 'migration-mother-a.internal',
        'port' => 9553,
        'status' => 'healthy',
        'managed_services_count' => 1,
        'trust_score' => 0.8,
    ])) {
        fail_migration('Semantic DNS mother-node registration failed in write mode.');
    }

    echo "write ok\n";
    exit(0);
}

king_object_store_init([
    'storage_root_path' => $objectStoreRoot,
    'primary_backend' => 'local_fs',
    'max_storage_size_bytes' => 1048576,
]);

if (king_object_store_get('migration-alpha') !== 'alpha-payload') {
    fail_migration('migration-alpha payload did not survive migration.');
}
if (king_object_store_get('migration-beta') !== 'beta-payload') {
    fail_migration('migration-beta payload did not survive migration.');
}

$metadata = king_object_store_get_metadata('migration-beta');
if (($metadata['content_length'] ?? null) !== 12) {
    fail_migration('Unexpected content length for migration-beta after migration.');
}

$orchestrator = king_system_get_component_info('pipeline_orchestrator');
if (($orchestrator['configuration']['recovered_from_state'] ?? null) !== true) {
    fail_migration('Orchestrator did not recover from persisted state.');
}
if (($orchestrator['configuration']['tool_count'] ?? null) !== 1) {
    fail_migration('Unexpected recovered orchestrator tool count.');
}
if (($orchestrator['configuration']['run_history_count'] ?? null) !== 1) {
    fail_migration('Unexpected recovered orchestrator run history count.');
}

if (!king_semantic_dns_init($semanticConfig)) {
    fail_migration('Semantic DNS init failed in read mode.');
}
if (!king_semantic_dns_start_server()) {
    fail_migration('Semantic DNS start failed in read mode.');
}

$topology = king_semantic_dns_get_service_topology();
$discovery = king_semantic_dns_discover_service('pipeline_orchestrator');
$route = king_semantic_dns_get_optimal_route('migration-api');

if (($topology['statistics']['total_services'] ?? null) !== 1) {
    fail_migration('Unexpected recovered semantic DNS service count.');
}
if (($topology['statistics']['mother_nodes'] ?? null) !== 1) {
    fail_migration('Unexpected recovered semantic DNS mother-node count.');
}
if (($discovery['service_count'] ?? null) !== 1) {
    fail_migration('Unexpected recovered semantic DNS discovery count.');
}
if (($route['service_id'] ?? null) !== 'migration-api-1') {
    fail_migration('Unexpected recovered semantic DNS route.');
}

echo "read ok\n";
exit(0);
