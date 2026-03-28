--TEST--
King object-store HA: replication and real cloud_s3 backup semantics
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/object_store_s3_mock_helper.inc';

$dir = sys_get_temp_dir() . '/king_ha_' . getmypid();
if (!is_dir($dir)) mkdir($dir, 0755, true);
$mock = king_object_store_s3_mock_start_server();

// replication + in-tree backup backend
king_object_store_init([
    'storage_root_path' => $dir,
    'replication_factor' => 2,
    'backup_backend' => 'memory_cache',
]);

king_object_store_put('high_avail_doc', 'safe in memory cache');

$meta = king_object_store_get_metadata('high_avail_doc');
var_dump($meta['object_id']);
var_dump($meta['is_backed_up']);
var_dump($meta['replication_status']);

// cloud backup backend should now use the real S3-compatible runtime
king_object_store_init([
    'storage_root_path' => $dir,
    'backup_backend' => 'cloud_s3',
    'cloud_credentials' => [
        'api_endpoint' => $mock['endpoint'],
        'bucket' => 'ha-test',
        'access_key' => 'access',
        'secret_key' => 'secret',
        'region' => 'us-east-1',
        'path_style' => true,
        'verify_tls' => false,
    ],
]);

var_dump(king_object_store_put('cloud_backup_doc', 'replicated to cloud backup'));
$meta = king_object_store_get_metadata('cloud_backup_doc');
var_dump($meta['object_id']);
var_dump($meta['is_backed_up']);

$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_backup_adapter_contract']);
var_dump($stats['runtime_backup_adapter_status']);
$capture = king_object_store_s3_mock_stop_server($mock);
var_dump(count($capture['events']) >= 2);
var_dump($capture['events'][0]['method']);
var_dump($capture['events'][1]['method']);

// Cleanup
foreach (scandir($dir) as $f) { if ($f !== '.' && $f !== '..') @unlink("$dir/$f"); }
@rmdir($dir);
king_object_store_s3_mock_cleanup_state_directory($mock['state_directory']);
?>
--EXPECT--
string(14) "high_avail_doc"
int(0)
int(2)
bool(true)
string(16) "cloud_backup_doc"
int(1)
string(5) "cloud"
string(2) "ok"
bool(true)
string(4) "HEAD"
string(3) "PUT"
