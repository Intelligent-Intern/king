--TEST--
King object-store exposes an honest local_fs plus cloud_s3 v1 backend contract
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/object_store_s3_mock_helper.inc';

$root = sys_get_temp_dir() . '/king_object_store_contract_' . getmypid();
if (!is_dir($root)) {
    mkdir($root, 0700, true);
}
$mock = king_object_store_s3_mock_start_server();

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
        'api_endpoint' => $mock['endpoint'],
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
$capture = king_object_store_s3_mock_stop_server($mock);
var_dump(count($capture['events']) >= 4);

foreach (scandir($root) as $file) {
    if ($file !== '.' && $file !== '..') {
        @unlink($root . '/' . $file);
    }
}
@rmdir($root);
king_object_store_s3_mock_cleanup_state_directory($mock['state_directory']);
?>
--EXPECT--
bool(true)
bool(true)
string(7) "payload"
bool(true)
bool(true)
string(13) "cloud payload"
string(8) "cloud_s3"
string(5) "cloud"
string(26) "local_fs+cloud_s3_sidecars"
string(22) "memory_cache->local_fs"
string(33) "distributed,cloud_gcs,cloud_azure"
string(2) "ok"
string(26) "local_fs+cloud_s3_sidecars"
string(22) "memory_cache->local_fs"
string(33) "distributed,cloud_gcs,cloud_azure"
bool(true)
