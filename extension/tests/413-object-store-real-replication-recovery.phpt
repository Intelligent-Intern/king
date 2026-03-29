--TEST--
King object-store heals real replication shortfalls after a later write meets the requested real-backend topology
--SKIPIF--
<?php
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) {
    echo "skip proc_open and stream_socket_server are required";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/object_store_s3_mock_helper.inc';

$localRoot = sys_get_temp_dir() . '/king_object_store_replication_recovery_local_' . getmypid();
$cloudRoot = sys_get_temp_dir() . '/king_object_store_replication_recovery_cloud_' . getmypid();
$cleanupTree = static function (string $path) use (&$cleanupTree): void {
    if ($path === '' || !file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        $entries = scandir($path);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $cleanupTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
        return;
    }

    @unlink($path);
};

$cleanupTree($localRoot);
$cleanupTree($cloudRoot);
mkdir($localRoot, 0700, true);
mkdir($cloudRoot, 0700, true);

$localMock = king_object_store_s3_mock_start_server();
$localCredentials = [
    'api_endpoint' => $localMock['endpoint'],
    'bucket' => 'replication-recovery-local',
    'access_key' => 'access',
    'secret_key' => 'secret',
    'region' => 'us-east-1',
    'path_style' => true,
    'verify_tls' => false,
];

var_dump(king_object_store_init([
    'storage_root_path' => $localRoot,
    'primary_backend' => 'local_fs',
    'backup_backend' => 'cloud_s3',
    'replication_factor' => 3,
    'cloud_credentials' => $localCredentials,
]));

try {
    king_object_store_put('recover-local', 'alpha');
    echo "no-exception\n";
} catch (King\Exception $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'requested replication_factor 3 but runtime achieved only 2 real copies'));
}

$meta = king_object_store_get_metadata('recover-local');
var_dump($meta['replication_status']);
var_dump(king_object_store_get('recover-local'));

var_dump(king_object_store_init([
    'storage_root_path' => $localRoot,
    'primary_backend' => 'local_fs',
    'backup_backend' => 'cloud_s3',
    'replication_factor' => 2,
    'cloud_credentials' => $localCredentials,
]));
var_dump(king_object_store_put('recover-local', 'bravo!'));
$meta = king_object_store_get_metadata('recover-local');
var_dump($meta['object_id']);
var_dump($meta['content_length']);
var_dump($meta['is_backed_up']);
var_dump($meta['replication_status']);
var_dump(king_object_store_get('recover-local'));
$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_primary_adapter_status']);
var_dump($stats['runtime_backup_adapter_status']);
var_dump($stats['object_count']);
var_dump($stats['stored_bytes']);

$localCapture = king_object_store_s3_mock_stop_server($localMock);
$localTargets = array_map(
    static fn(array $event): string => $event['method'] . ' ' . $event['target'],
    $localCapture['events']
);
var_dump(count(array_filter(
    $localTargets,
    static fn(string $target): bool => $target === 'PUT /replication-recovery-local/recover-local'
)) >= 2);

$cloudMock = king_object_store_s3_mock_start_server();
$cloudCredentials = [
    'api_endpoint' => $cloudMock['endpoint'],
    'bucket' => 'replication-recovery-cloud',
    'access_key' => 'access',
    'secret_key' => 'secret',
    'region' => 'us-east-1',
    'path_style' => true,
    'verify_tls' => false,
];

var_dump(king_object_store_init([
    'storage_root_path' => $cloudRoot,
    'primary_backend' => 'cloud_s3',
    'replication_factor' => 2,
    'cloud_credentials' => $cloudCredentials,
]));

try {
    king_object_store_put('recover-cloud', 'omega');
    echo "no-exception\n";
} catch (King\Exception $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'requested replication_factor 2 but runtime achieved only 1 real copies'));
}

$meta = king_object_store_get_metadata('recover-cloud');
var_dump($meta['replication_status']);
var_dump(king_object_store_get('recover-cloud'));

var_dump(king_object_store_init([
    'storage_root_path' => $cloudRoot,
    'primary_backend' => 'cloud_s3',
    'replication_factor' => 1,
    'cloud_credentials' => $cloudCredentials,
]));
var_dump(king_object_store_put('recover-cloud', 'sigma!'));
$meta = king_object_store_get_metadata('recover-cloud');
var_dump($meta['object_id']);
var_dump($meta['content_length']);
var_dump($meta['is_backed_up']);
var_dump($meta['replication_status']);
var_dump(king_object_store_get('recover-cloud'));
$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_primary_adapter_status']);
var_dump($stats['object_count']);
var_dump($stats['stored_bytes']);

$cloudCapture = king_object_store_s3_mock_stop_server($cloudMock);
$cloudTargets = array_map(
    static fn(array $event): string => $event['method'] . ' ' . $event['target'],
    $cloudCapture['events']
);
var_dump(count(array_filter(
    $cloudTargets,
    static fn(string $target): bool => $target === 'PUT /replication-recovery-cloud/recover-cloud'
)) >= 2);

$cleanupTree($localRoot);
$cleanupTree($cloudRoot);
king_object_store_s3_mock_cleanup_state_directory($localMock['state_directory']);
king_object_store_s3_mock_cleanup_state_directory($cloudMock['state_directory']);
?>
--EXPECT--
bool(true)
string(20) "King\SystemException"
bool(true)
int(3)
string(5) "alpha"
bool(true)
bool(true)
string(13) "recover-local"
int(6)
int(1)
int(2)
string(6) "bravo!"
string(2) "ok"
string(2) "ok"
int(1)
int(6)
bool(true)
bool(true)
string(20) "King\SystemException"
bool(true)
int(3)
string(5) "omega"
bool(true)
bool(true)
string(13) "recover-cloud"
int(6)
int(0)
int(2)
string(6) "sigma!"
string(2) "ok"
int(1)
int(6)
bool(true)
