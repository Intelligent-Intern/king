--TEST--
King object-store delete semantics stay honest across real local_fs and cloud_s3 copies
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

$root = sys_get_temp_dir() . '/king_object_store_delete_real_' . getmypid();
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

$mock = king_object_store_s3_mock_start_server(
    null,
    '127.0.0.1',
    [
        'forced_responses' => [
            [
                'method' => 'DELETE',
                'target' => '/delete-semantics/doc-fail',
                'times' => 1,
                'status' => 503,
                'error_code' => 'SlowDown',
                'error_message' => 'delete throttled',
            ],
        ],
    ]
);

$localConfig = [
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
    'backup_backend' => 'cloud_s3',
    'replication_factor' => 2,
    'cloud_credentials' => [
        'api_endpoint' => $mock['endpoint'],
        'bucket' => 'delete-semantics',
        'access_key' => 'access',
        'secret_key' => 'secret',
        'region' => 'us-east-1',
        'path_style' => true,
        'verify_tls' => false,
    ],
];

$cloudConfig = $localConfig;
$cloudConfig['primary_backend'] = 'cloud_s3';
$cloudConfig['backup_backend'] = 'memory_cache';
$cloudConfig['replication_factor'] = 1;

$payloadPath = $root . '/doc-missing-local';
$metaPath = $root . '/doc-missing-local.meta';

var_dump(king_object_store_init($localConfig));

var_dump(king_object_store_put('doc-ok', 'alpha'));
var_dump(king_object_store_delete('doc-ok'));
var_dump(king_object_store_get('doc-ok'));
var_dump(king_object_store_init($cloudConfig));
var_dump(king_object_store_get('doc-ok'));
$list = king_object_store_list();
var_dump(count($list));
$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['object_count']);
var_dump($stats['stored_bytes']);

var_dump(king_object_store_init($localConfig));
var_dump(king_object_store_put('doc-missing-local', 'bravo'));
clearstatcache(false, $payloadPath);
clearstatcache(false, $metaPath);
var_dump(is_file($payloadPath));
var_dump(is_file($metaPath));
var_dump(@unlink($payloadPath));
clearstatcache(false, $payloadPath);
var_dump(is_file($payloadPath));
var_dump(king_object_store_delete('doc-missing-local'));
var_dump(king_object_store_get('doc-missing-local'));
clearstatcache(false, $metaPath);
var_dump(is_file($metaPath));
var_dump(king_object_store_init($cloudConfig));
var_dump(king_object_store_get('doc-missing-local'));
$list = king_object_store_list();
var_dump(count($list));

var_dump(king_object_store_init($localConfig));
var_dump(king_object_store_put('doc-fail', 'charlie'));
try {
    king_object_store_delete('doc-fail');
    echo "no-delete-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'backup removal failed'));
}
$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_primary_adapter_status']);
var_dump(str_contains($stats['runtime_primary_adapter_error'], 'backup removal failed'));
var_dump($stats['runtime_backup_adapter_status']);
var_dump(str_contains($stats['runtime_backup_adapter_error'], 'throttled'));
var_dump(king_object_store_get('doc-fail'));
$meta = king_object_store_get_metadata('doc-fail');
var_dump($meta['is_backed_up']);
var_dump($meta['replication_status']);

var_dump(king_object_store_init($cloudConfig));
var_dump(king_object_store_get('doc-fail'));
$list = king_object_store_list();
var_dump(count($list));

var_dump(king_object_store_init($localConfig));
var_dump(king_object_store_delete('doc-fail'));
var_dump(king_object_store_get('doc-fail'));
var_dump(king_object_store_init($cloudConfig));
var_dump(king_object_store_get('doc-fail'));
$list = king_object_store_list();
var_dump(count($list));
$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['object_count']);
var_dump($stats['stored_bytes']);

$capture = king_object_store_s3_mock_stop_server($mock);
$targets = array_map(
    static fn(array $event): string => $event['method'] . ' ' . $event['target'],
    $capture['events']
);
var_dump(count(array_filter(
    $targets,
    static fn(string $target): bool => $target === 'DELETE /delete-semantics/doc-ok'
)) === 1);
var_dump(count(array_filter(
    $targets,
    static fn(string $target): bool => $target === 'DELETE /delete-semantics/doc-missing-local'
)) === 1);
var_dump(count(array_filter(
    $targets,
    static fn(string $target): bool => $target === 'DELETE /delete-semantics/doc-fail'
)) === 2);

$cleanupTree($root);
king_object_store_s3_mock_cleanup_state_directory($mock['state_directory']);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
bool(false)
int(0)
int(0)
int(0)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(true)
bool(false)
bool(false)
bool(true)
bool(false)
int(0)
bool(true)
bool(true)
string(20) "King\SystemException"
bool(true)
string(6) "failed"
bool(true)
string(6) "failed"
bool(true)
string(7) "charlie"
int(1)
int(2)
bool(true)
string(7) "charlie"
int(1)
bool(true)
bool(true)
bool(false)
bool(true)
bool(false)
int(0)
int(0)
int(0)
bool(true)
bool(true)
bool(true)
