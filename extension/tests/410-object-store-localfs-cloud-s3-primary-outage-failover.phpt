--TEST--
King object-store local_fs primary survives a live primary-root outage by serving metadata from cache and healing payloads from a real cloud_s3 backup
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

$root = sys_get_temp_dir() . '/king_object_store_primary_outage_' . getmypid();
$offlineRoot = $root . '.offline';

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

$cleanupTree($root);
$cleanupTree($offlineRoot);
mkdir($root, 0700, true);

$mock = king_object_store_s3_mock_start_server();
$config = [
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
    'backup_backend' => 'cloud_s3',
    'cloud_credentials' => [
        'api_endpoint' => $mock['endpoint'],
        'bucket' => 'primary-outage',
        'access_key' => 'access',
        'secret_key' => 'secret',
        'region' => 'us-east-1',
        'path_style' => true,
        'verify_tls' => false,
    ],
];

$payloadPath = $root . '/doc-s3';
$metaPath = $root . '/doc-s3.meta';

var_dump(king_object_store_init($config));
var_dump(king_object_store_put('doc-s3', 'alpha'));
var_dump(@rename($root, $offlineRoot));
clearstatcache();
var_dump(is_dir($root));
var_dump(is_dir($offlineRoot));
var_dump(is_file($offlineRoot . '/doc-s3'));
var_dump(is_file($offlineRoot . '/doc-s3.meta'));

$metaDuringOutage = king_object_store_get_metadata('doc-s3');
var_dump(is_array($metaDuringOutage));
var_dump($metaDuringOutage['object_id']);
var_dump($metaDuringOutage['content_length']);
var_dump($metaDuringOutage['is_backed_up']);

$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['object_count']);
var_dump($stats['stored_bytes']);

var_dump(king_object_store_get('doc-s3'));
clearstatcache();
var_dump(is_dir($root));
var_dump(is_file($payloadPath));
var_dump(is_file($metaPath));

$metaAfterHeal = king_object_store_get_metadata('doc-s3');
var_dump($metaAfterHeal['object_id']);
var_dump($metaAfterHeal['content_length']);
var_dump($metaAfterHeal['is_backed_up']);

$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_primary_adapter_status']);
var_dump($stats['runtime_backup_adapter_status']);
var_dump($stats['object_count']);
var_dump($stats['stored_bytes']);

$capture = king_object_store_s3_mock_stop_server($mock);
$targets = array_map(
    static fn(array $event): string => $event['method'] . ' ' . $event['target'],
    $capture['events']
);
var_dump(count(array_filter(
    $targets,
    static fn(string $target): bool => $target === 'PUT /primary-outage/doc-s3'
)) === 1);
var_dump(count(array_filter(
    $targets,
    static fn(string $target): bool => $target === 'GET /primary-outage/doc-s3'
)) === 1);

$cleanupTree($root);
$cleanupTree($offlineRoot);
king_object_store_s3_mock_cleanup_state_directory($mock['state_directory']);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
bool(true)
string(6) "doc-s3"
int(5)
int(1)
int(1)
int(5)
string(5) "alpha"
bool(true)
bool(true)
bool(true)
string(6) "doc-s3"
int(5)
int(1)
string(2) "ok"
string(2) "ok"
int(1)
int(5)
bool(true)
bool(true)
