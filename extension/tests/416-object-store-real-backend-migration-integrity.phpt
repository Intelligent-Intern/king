--TEST--
King object-store preserves binary payload integrity across real local_fs and cloud_s3 backend migration
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

$root = sys_get_temp_dir() . '/king_object_store_migration_integrity_' . getmypid();
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
        'bucket' => 'migration-integrity',
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

$exportLocal = $root . '/export-local';
$exportCloud = $root . '/export-cloud';
mkdir($exportLocal, 0700, true);
mkdir($exportCloud, 0700, true);

$localPayload = pack('C*', 0, 1, 2, 255, 13, 10, 65, 66) . str_repeat("abc\0\xffXYZ", 1700) . pack('C*', 16, 17, 18);
$cloudPayload = pack('C*', 127, 128, 129, 0, 250, 251) . str_repeat("delta\0\x7f\xe0", 1900) . pack('C*', 42, 43);
$localHash = hash('sha256', $localPayload);
$cloudHash = hash('sha256', $cloudPayload);
$localLength = strlen($localPayload);
$cloudLength = strlen($cloudPayload);

var_dump(king_object_store_init($localConfig));
var_dump(king_object_store_put('doc-local-binary', $localPayload));
var_dump(hash('sha256', king_object_store_get('doc-local-binary')) === $localHash);
var_dump(king_object_store_backup_object('doc-local-binary', $exportLocal));
var_dump(hash_file('sha256', $exportLocal . '/doc-local-binary') === $localHash);
var_dump(filesize($exportLocal . '/doc-local-binary') === $localLength);

var_dump(king_object_store_init($cloudConfig));
var_dump(king_object_store_restore_object('doc-local-binary', $exportLocal));
$migratedLocal = king_object_store_get('doc-local-binary');
var_dump(hash('sha256', $migratedLocal) === $localHash);
var_dump(strlen($migratedLocal) === $localLength);
$meta = king_object_store_get_metadata('doc-local-binary');
var_dump($meta['content_length'] === $localLength);

var_dump(king_object_store_put('doc-cloud-binary', $cloudPayload));
var_dump(hash('sha256', king_object_store_get('doc-cloud-binary')) === $cloudHash);
var_dump(king_object_store_backup_object('doc-cloud-binary', $exportCloud));
var_dump(hash_file('sha256', $exportCloud . '/doc-cloud-binary') === $cloudHash);
var_dump(filesize($exportCloud . '/doc-cloud-binary') === $cloudLength);

var_dump(king_object_store_init($localConfig));
var_dump(king_object_store_restore_object('doc-cloud-binary', $exportCloud));
$migratedCloud = king_object_store_get('doc-cloud-binary');
var_dump(hash('sha256', $migratedCloud) === $cloudHash);
var_dump(strlen($migratedCloud) === $cloudLength);
$meta = king_object_store_get_metadata('doc-cloud-binary');
var_dump($meta['content_length'] === $cloudLength);

$capture = king_object_store_s3_mock_stop_server($mock);
$targets = array_map(
    static fn(array $event): string => $event['method'] . ' ' . $event['target'],
    $capture['events']
);
var_dump(in_array('PUT /migration-integrity/doc-local-binary', $targets, true));
var_dump(in_array('PUT /migration-integrity/doc-cloud-binary', $targets, true));
var_dump(in_array('GET /migration-integrity/doc-local-binary', $targets, true));
var_dump(in_array('GET /migration-integrity/doc-cloud-binary', $targets, true));

$cleanupTree($root);
king_object_store_s3_mock_cleanup_state_directory($mock['state_directory']);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
