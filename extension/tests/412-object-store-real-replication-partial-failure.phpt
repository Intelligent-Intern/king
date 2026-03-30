--TEST--
King object-store marks real replication shortfalls honestly when requested copies exceed the current real backend topology
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

$localRoot = sys_get_temp_dir() . '/king_object_store_replication_local_' . getmypid();
$cloudRoot = sys_get_temp_dir() . '/king_object_store_replication_cloud_' . getmypid();
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

$mock = king_object_store_s3_mock_start_server();
$cloudCredentials = [
    'api_endpoint' => $mock['endpoint'],
    'bucket' => 'replication-partial',
    'access_key' => 'access',
    'secret_key' => 'secret',
    'region' => 'us-east-1',
    'path_style' => true,
    'verify_tls' => false,
];
$cloudPrimaryCredentials = $cloudCredentials;
$cloudPrimaryCredentials['bucket'] = 'replication-partial-cloud';

var_dump(king_object_store_init([
    'storage_root_path' => $localRoot,
    'primary_backend' => 'local_fs',
    'backup_backend' => 'cloud_s3',
    'replication_factor' => 3,
    'cloud_credentials' => $cloudCredentials,
]));

try {
    king_object_store_put('partial-local', 'alpha');
    echo "no-exception\n";
} catch (King\Exception $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'requested replication_factor 3 but runtime achieved only 2 real copies'));
}

$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_primary_adapter_status']);
var_dump($stats['runtime_backup_adapter_status']);
var_dump($stats['object_count']);
var_dump($stats['stored_bytes']);

$meta = king_object_store_get_metadata('partial-local');
var_dump($meta['object_id']);
var_dump($meta['is_backed_up']);
var_dump($meta['replication_status']);
var_dump(king_object_store_get('partial-local'));

var_dump(king_object_store_init([
    'storage_root_path' => $cloudRoot,
    'primary_backend' => 'cloud_s3',
    'replication_factor' => 2,
    'cloud_credentials' => $cloudPrimaryCredentials,
]));

try {
    king_object_store_put('partial-cloud', 'omega');
    echo "no-exception\n";
} catch (King\Exception $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'requested replication_factor 2 but runtime achieved only 1 real copies'));
}

$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_primary_adapter_status']);
var_dump($stats['object_count']);
var_dump($stats['stored_bytes']);

$meta = king_object_store_get_metadata('partial-cloud');
var_dump($meta['object_id']);
var_dump($meta['is_backed_up']);
var_dump($meta['replication_status']);
var_dump(king_object_store_get('partial-cloud'));

$capture = king_object_store_s3_mock_stop_server($mock);
$targets = array_map(
    static fn(array $event): string => $event['method'] . ' ' . $event['target'],
    $capture['events']
);
var_dump(in_array('PUT /replication-partial/partial-local', $targets, true));
var_dump(in_array('PUT /replication-partial-cloud/partial-cloud', $targets, true));
var_dump(in_array('GET /replication-partial-cloud/partial-cloud', $targets, true));

$cleanupTree($localRoot);
$cleanupTree($cloudRoot);
king_object_store_s3_mock_cleanup_state_directory($mock['state_directory']);
?>
--EXPECT--
bool(true)
string(20) "King\SystemException"
bool(true)
string(6) "failed"
string(2) "ok"
int(1)
int(5)
string(13) "partial-local"
int(1)
int(3)
string(5) "alpha"
bool(true)
string(20) "King\SystemException"
bool(true)
string(6) "failed"
int(2)
int(10)
string(13) "partial-cloud"
int(0)
int(3)
string(5) "omega"
bool(true)
bool(true)
bool(true)
