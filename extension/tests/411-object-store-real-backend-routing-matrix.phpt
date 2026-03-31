--TEST--
King object-store routes metadata reads and inventory honestly across real local_fs and cloud_s3 backends sharing one storage root
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

$root = sys_get_temp_dir() . '/king_object_store_routing_matrix_' . getmypid();
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
mkdir($root, 0700, true);

$mock = king_object_store_s3_mock_start_server();
$cloudCredentials = [
    'api_endpoint' => $mock['endpoint'],
    'bucket' => 'routing-matrix',
    'access_key' => 'access',
    'secret_key' => 'secret',
    'region' => 'us-east-1',
    'path_style' => true,
    'verify_tls' => false,
];
$localConfig = [
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
];
$cloudConfig = [
    'storage_root_path' => $root,
    'primary_backend' => 'cloud_s3',
    'cloud_credentials' => $cloudCredentials,
];
$hybridConfig = [
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
    'backup_backend' => 'cloud_s3',
    'cloud_credentials' => $cloudCredentials,
];

var_dump(king_object_store_init($localConfig));
var_dump(king_object_store_put('local-only', 'local'));

var_dump(king_object_store_init($cloudConfig));
var_dump(king_object_store_put('cloud-only', 'cloud!'));
var_dump(king_object_store_get('local-only'));
var_dump(king_object_store_get_metadata('local-only'));
$cloudList = array_column(king_object_store_list(), 'object_id');
sort($cloudList);
var_dump($cloudList);
$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_primary_backend_contract']);
var_dump($stats['object_count']);
var_dump($stats['stored_bytes']);

var_dump(king_object_store_init($hybridConfig));
var_dump(king_object_store_put('hybrid-doc', 'hybrid!'));
$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_primary_backend_contract']);
var_dump($stats['runtime_backup_adapter_status']);
var_dump($stats['object_count']);
var_dump($stats['stored_bytes']);

var_dump(king_object_store_init($cloudConfig));
var_dump(king_object_store_get('cloud-only'));
var_dump(king_object_store_get('hybrid-doc'));
var_dump(king_object_store_get('local-only'));
var_dump(king_object_store_get_metadata('local-only'));
$hybridMeta = king_object_store_get_metadata('hybrid-doc');
var_dump($hybridMeta['object_id']);
var_dump($hybridMeta['is_backed_up']);
$cloudList = array_column(king_object_store_list(), 'object_id');
sort($cloudList);
var_dump($cloudList);
$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_primary_backend_contract']);
var_dump($stats['object_count']);
var_dump($stats['stored_bytes']);

var_dump(king_object_store_init($hybridConfig));
var_dump(king_object_store_get('local-only'));
var_dump(king_object_store_get('hybrid-doc'));
var_dump(king_object_store_get('cloud-only'));
var_dump(king_object_store_get_metadata('cloud-only'));
$localList = array_column(king_object_store_list(), 'object_id');
sort($localList);
var_dump($localList);
$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_primary_backend_contract']);
var_dump($stats['runtime_backup_adapter_status']);
var_dump($stats['object_count']);
var_dump($stats['stored_bytes']);

$capture = king_object_store_s3_mock_stop_server($mock);
$targets = array_map(
    static fn(array $event): string => $event['method'] . ' ' . $event['target'],
    $capture['events']
);
var_dump(in_array('PUT /routing-matrix/cloud-only', $targets, true));
var_dump(in_array('PUT /routing-matrix/hybrid-doc', $targets, true));
var_dump(in_array('GET /routing-matrix/hybrid-doc', $targets, true));
var_dump(in_array('GET /routing-matrix?list-type=2', $targets, true));

$cleanupTree($root);
king_object_store_s3_mock_cleanup_state_directory($mock['state_directory']);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(false)
array(1) {
  [0]=>
  string(10) "cloud-only"
}
string(5) "cloud"
int(1)
int(6)
bool(true)
bool(true)
string(5) "local"
string(2) "ok"
int(2)
int(12)
bool(true)
string(6) "cloud!"
string(7) "hybrid!"
bool(false)
bool(false)
string(10) "hybrid-doc"
int(1)
array(2) {
  [0]=>
  string(10) "cloud-only"
  [1]=>
  string(10) "hybrid-doc"
}
string(5) "cloud"
int(2)
int(13)
bool(true)
string(5) "local"
string(7) "hybrid!"
bool(false)
bool(false)
array(2) {
  [0]=>
  string(10) "hybrid-doc"
  [1]=>
  string(10) "local-only"
}
string(5) "local"
string(2) "ok"
int(2)
int(12)
bool(true)
bool(true)
bool(true)
bool(true)
