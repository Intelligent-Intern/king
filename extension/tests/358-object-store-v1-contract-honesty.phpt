--TEST--
King object-store exposes an honest local_fs plus cloud_s3 plus cloud_gcs v1 backend contract
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/object_store_s3_mock_helper.inc';

$root = sys_get_temp_dir() . '/king_object_store_contract_' . getmypid();
if (!is_dir($root)) {
    mkdir($root, 0700, true);
}
$s3Mock = king_object_store_s3_mock_start_server();
$gcsMock = king_object_store_s3_mock_start_server(
    null,
    '127.0.0.1',
    [
        'provider' => 'gcs',
        'expected_access_token' => 'gcs-token',
    ]
);

var_dump(king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'memory_cache',
]));

var_dump(king_object_store_put('doc-1', 'payload'));
var_dump(king_object_store_get('doc-1'));

var_dump(king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'cloud_s3',
    'cloud_credentials' => [
        'api_endpoint' => $s3Mock['endpoint'],
        'bucket' => 'contract-test',
        'access_key' => 'access',
        'secret_key' => 'secret',
        'region' => 'us-east-1',
        'path_style' => true,
        'verify_tls' => false,
    ],
]));
var_dump(king_object_store_put('doc-2', 'cloud payload'));
var_dump(king_object_store_get('doc-2'));

var_dump(king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'cloud_gcs',
    'cloud_credentials' => [
        'api_endpoint' => $gcsMock['endpoint'],
        'bucket' => 'contract-test-gcs',
        'access_token' => 'gcs-token',
        'path_style' => true,
        'verify_tls' => false,
    ],
]));
var_dump(king_object_store_put('doc-3', 'gcs payload'));
var_dump(king_object_store_get('doc-3'));

$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_primary_backend']);
var_dump($stats['runtime_primary_backend_contract']);
var_dump($stats['runtime_storage_contract']);
var_dump($stats['runtime_legacy_backend_alias']);
var_dump($stats['runtime_simulated_backends']);
var_dump($stats['runtime_primary_adapter_status']);

$component = king_system_get_component_info('object_store');
var_dump($component['configuration']['storage_contract']);
var_dump($component['configuration']['legacy_backend_alias']);
var_dump($component['configuration']['simulated_backends']);
$s3Capture = king_object_store_s3_mock_stop_server($s3Mock);
$gcsCapture = king_object_store_s3_mock_stop_server($gcsMock);
var_dump(count($s3Capture['events']) >= 4);
var_dump(count($gcsCapture['events']) >= 4);
var_dump(count(array_filter(
    $gcsCapture['events'],
    static fn(array $event): bool => ($event['authorization_bearer_token'] ?? '') === 'gcs-token'
)) >= 4);

foreach (scandir($root) as $file) {
    if ($file !== '.' && $file !== '..') {
        @unlink($root . '/' . $file);
    }
}
@rmdir($root);
king_object_store_s3_mock_cleanup_state_directory($s3Mock['state_directory']);
king_object_store_s3_mock_cleanup_state_directory($gcsMock['state_directory']);
?>
--EXPECT--
bool(true)
bool(true)
string(7) "payload"
bool(true)
bool(true)
string(13) "cloud payload"
bool(true)
bool(true)
string(11) "gcs payload"
string(9) "cloud_gcs"
string(5) "cloud"
string(36) "local_fs+cloud_s3+cloud_gcs_sidecars"
string(22) "memory_cache->local_fs"
string(23) "distributed,cloud_azure"
string(2) "ok"
string(36) "local_fs+cloud_s3+cloud_gcs_sidecars"
string(22) "memory_cache->local_fs"
string(23) "distributed,cloud_azure"
bool(true)
bool(true)
bool(true)
