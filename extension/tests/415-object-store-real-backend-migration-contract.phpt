--TEST--
King object-store migrates objects between real local_fs and cloud_s3 backends through the export and restore format
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

$root = sys_get_temp_dir() . '/king_object_store_real_migration_' . getmypid();
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

$common = [
    'storage_root_path' => $root,
    'cloud_credentials' => [
        'api_endpoint' => $mock['endpoint'],
        'bucket' => 'migration-contract',
        'access_key' => 'access',
        'secret_key' => 'secret',
        'region' => 'us-east-1',
        'path_style' => true,
        'verify_tls' => false,
    ],
];

$localConfig = $common + [
    'primary_backend' => 'local_fs',
    'backup_backend' => 'local_fs',
];

$cloudConfig = $common + [
    'primary_backend' => 'cloud_s3',
    'backup_backend' => 'cloud_s3',
];

$localExport = $root . '/export-local';
$cloudExport = $root . '/export-cloud';
mkdir($localExport, 0700, true);
mkdir($cloudExport, 0700, true);

var_dump(king_object_store_init($localConfig));
var_dump(king_object_store_put('doc-local', 'alpha'));
var_dump(king_object_store_backup_all_objects($localExport));

var_dump(king_object_store_init($cloudConfig));
var_dump(king_object_store_get('doc-local'));
var_dump(king_object_store_restore_all_objects($localExport));
var_dump(king_object_store_get('doc-local'));
$list = king_object_store_list();
var_dump(count($list));
var_dump($list[0]['object_id']);

var_dump(king_object_store_put('doc-cloud', 'bravo'));
var_dump(king_object_store_backup_object('doc-cloud', $cloudExport));

var_dump(king_object_store_init($localConfig));
var_dump(king_object_store_get('doc-cloud'));
var_dump(king_object_store_restore_object('doc-cloud', $cloudExport));
var_dump(king_object_store_get('doc-cloud'));

$objectIds = array_map(
    static fn(array $entry): string => $entry['object_id'],
    king_object_store_list()
);
sort($objectIds);
var_dump($objectIds);

var_dump(king_object_store_init($cloudConfig));
var_dump(king_object_store_get('doc-local'));
var_dump(king_object_store_get('doc-cloud'));

$capture = king_object_store_s3_mock_stop_server($mock);
$targets = array_map(
    static fn(array $event): string => $event['method'] . ' ' . $event['target'],
    $capture['events']
);
var_dump(in_array('PUT /migration-contract/doc-local', $targets, true));
var_dump(in_array('PUT /migration-contract/doc-cloud', $targets, true));
var_dump(in_array('GET /migration-contract/doc-local', $targets, true));
var_dump(in_array('GET /migration-contract/doc-cloud', $targets, true));

$cleanupTree($root);
king_object_store_s3_mock_cleanup_state_directory($mock['state_directory']);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
string(5) "alpha"
int(1)
string(9) "doc-local"
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
string(5) "bravo"
array(2) {
  [0]=>
  string(9) "doc-cloud"
  [1]=>
  string(9) "doc-local"
}
bool(true)
string(5) "alpha"
string(5) "bravo"
bool(true)
bool(true)
bool(true)
bool(true)
