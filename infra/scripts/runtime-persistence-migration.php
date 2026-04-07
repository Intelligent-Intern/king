<?php

declare(strict_types=1);

const MIGRATION_MAX_STORAGE_BYTES = 1048576;
const MIGRATION_MIN_ORCHESTRATOR_RUN_HISTORY_COUNT = 4;

function fail_migration(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

/**
 * Initialize and start semantic DNS for the current migration mode.
 */
function initialize_semantic_dns(string $mode, array $config, string $configInfo): void
{
    if (!king_semantic_dns_init($config)) {
        fail_migration(
            'Semantic DNS init failed in ' . $mode . ' mode. Mode: ' . $mode . '. Config: ' . $configInfo .
            '. Check semantic DNS environment, module initialization rights, and config consistency.'
        );
    }

    if (!king_semantic_dns_start_server()) {
        $dnsPort = $config['dns_port'] ?? 'unknown';
        fail_migration(
            'Semantic DNS start failed in ' . $mode . ' mode on port ' . (string) $dnsPort .
            '. Verify the process has bind permissions and that no other process occupies the address/port.'
        );
    }
}

/**
 * @param array<int, array<string, mixed>> $tools
 * @param string $traceId
 *
 * @return array<string, mixed>
 */
function run_persistence_orchestrator(array $input, array $tools, string $traceId): array
{
    $result = king_pipeline_orchestrator_run(
        $input,
        $tools,
        ['trace_id' => $traceId]
    );

    if (!is_array($result)) {
        fail_migration(
            'Orchestrator did not return an array for trace id ' . $traceId . '.'
        );
    }

    return $result;
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
    fail_migration('Expected migration mode write or read, got: ' . $mode);
}

/**
 * @return array<string, mixed>
 */
function make_persistence_migration_semantic_config(): array
{
    return [
        'enabled' => true,
        'bind_address' => '127.0.0.1',
        'dns_port' => 5453,
        'service_discovery_max_ips_per_response' => 8,
        'semantic_mode_enable' => true,
        'mothernode_uri' => 'mother://migration-seed',
    ];
}

$semanticConfig = make_persistence_migration_semantic_config();
$semanticConfigInfo = json_encode($semanticConfig);
$semanticConfigInfo = $semanticConfigInfo !== false ? $semanticConfigInfo : '<unserializable semantic config>';

if ($mode === 'write') {
    king_object_store_init([
        'storage_root_path' => $objectStoreRoot,
        'primary_backend' => 'local_fs',
        'max_storage_size_bytes' => MIGRATION_MAX_STORAGE_BYTES,
    ]);

    if (!king_object_store_put('migration-alpha', 'alpha-payload')) {
        fail_migration('Failed to persist migration-alpha.');
    }
    if (!king_object_store_put('migration-beta', 'beta-payload')) {
        fail_migration('Failed to persist migration-beta.');
    }

    $summarizerHandler = static function(array $context): array {
        $input = $context['input'] ?? null;
        if (!is_array($input)) {
            throw new RuntimeException('Unexpected orchestrator input: expected array.');
        }

        return [
            'text' => $input['text'] ?? '',
            'output' => $input,
            'meta' => $input['meta'] ?? null,
        ];
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
    if (!king_pipeline_orchestrator_register_handler('summarizer', $summarizerHandler)) {
        fail_migration('Failed to register orchestrator handler.');
    }

    $orchestratorTools = [['tool' => 'summarizer', 'params' => ['ratio' => 0.5]]];
    $result = run_persistence_orchestrator(
        ['text' => 'persisted text'],
        $orchestratorTools,
        'persist-migration-1'
    );
    if (($result['text'] ?? null) !== 'persisted text') {
        $actualText = $result['text'] ?? null;
        fail_migration(
            'Expected orchestrator result text "persisted text", got: ' . ($actualText === null ? 'null' : (string) $actualText)
        );
    }

    run_persistence_orchestrator(
        [],
        $orchestratorTools,
        'persist-migration-empty-input'
    );

    run_persistence_orchestrator(
        ['other_key' => 'value'],
        $orchestratorTools,
        'persist-migration-missing-text'
    );

    $complexInput = [
        'text' => 'persisted complex text',
        'meta' => [
            'tags' => ['migration', 'persistence', 'orchestrator'],
            'nested' => [
                'level' => 2,
                'data' => ['alpha', 'beta', 'gamma'],
            ],
        ],
    ];
    $complexResult = run_persistence_orchestrator(
        $complexInput,
        $orchestratorTools,
        'persist-migration-complex-input'
    );
    if (!array_key_exists('meta', $complexResult)) {
        fail_migration('Orchestrator result for complex input is missing expected "meta" key.');
    }

    initialize_semantic_dns($mode, $semanticConfig, $semanticConfigInfo);
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
    'max_storage_size_bytes' => MIGRATION_MAX_STORAGE_BYTES,
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
if (($orchestrator['configuration']['run_history_count'] ?? null) < MIGRATION_MIN_ORCHESTRATOR_RUN_HISTORY_COUNT) {
    $runHistoryCount = $orchestrator['configuration']['run_history_count'] ?? null;
    if (!is_int($runHistoryCount)) {
        fail_migration('Unexpected recovered orchestrator run history count format.');
    }
    fail_migration(
        'Recovered orchestrator run history count is too low. Expected at least ' .
        MIGRATION_MIN_ORCHESTRATOR_RUN_HISTORY_COUNT .
        ', got: ' .
        $runHistoryCount
    );
}

initialize_semantic_dns($mode, $semanticConfig, $semanticConfigInfo);

$topology = king_semantic_dns_get_service_topology();
if (!is_array($topology) || !isset($topology['statistics']) || !is_array($topology['statistics'])) {
    fail_migration('Failed to retrieve semantic DNS topology: unexpected result structure.');
}
$discovery = king_semantic_dns_discover_service('pipeline_orchestrator');
if (!is_array($discovery)) {
    fail_migration('Failed to retrieve semantic DNS discovery information for "pipeline_orchestrator": unexpected result structure.');
}
$route = king_semantic_dns_get_optimal_route('migration-api');
if (!is_array($route)) {
    fail_migration('Failed to retrieve semantic DNS optimal route for "migration-api": unexpected result structure.');
}

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
