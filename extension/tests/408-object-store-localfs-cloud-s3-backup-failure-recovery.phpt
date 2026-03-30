--TEST--
King object-store local_fs primary recovers cleanly after a partial real cloud_s3 backup failure
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

$root = sys_get_temp_dir() . '/king_object_store_backup_recovery_' . getmypid();
if (!is_dir($root)) {
    mkdir($root, 0700, true);
}

$mock = king_object_store_s3_mock_start_server(
    null,
    '127.0.0.1',
    [
        'forced_responses' => [
            [
                'method' => 'PUT',
                'target' => '/backup-recovery/cloud-backup-doc',
                'times' => 1,
                'persist_object_before_reply' => true,
                'close_connection_without_response' => true,
            ],
        ],
    ]
);

$config = [
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
    'backup_backend' => 'cloud_s3',
    'cloud_credentials' => [
        'api_endpoint' => $mock['endpoint'],
        'bucket' => 'backup-recovery',
        'access_key' => 'access',
        'secret_key' => 'secret',
        'region' => 'us-east-1',
        'path_style' => true,
        'verify_tls' => false,
    ],
];

$metaPath = $root . '/cloud-backup-doc.meta';

var_dump(king_object_store_init($config));

try {
    king_object_store_put('cloud-backup-doc', 'alpha');
    echo "no-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'backup operation failed'));
}

$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_primary_adapter_status']);
var_dump(str_contains($stats['runtime_primary_adapter_error'], 'backup operation failed'));
var_dump($stats['runtime_backup_adapter_status']);
var_dump(str_contains($stats['runtime_backup_adapter_error'], 'network I/O with the configured endpoint failed'));

var_dump(king_object_store_get('cloud-backup-doc'));
$meta = king_object_store_get_metadata('cloud-backup-doc');
var_dump($meta['object_id']);
var_dump($meta['content_length']);
var_dump($meta['is_backed_up']);
var_dump(is_file($metaPath));

$list = king_object_store_list();
var_dump(count($list));
var_dump($list[0]['object_id']);
var_dump($list[0]['size_bytes']);

$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_primary_adapter_status']);
var_dump($stats['runtime_primary_adapter_error'] === '');
var_dump($stats['runtime_backup_adapter_status']);
var_dump(str_contains($stats['runtime_backup_adapter_error'], 'network I/O with the configured endpoint failed'));
var_dump($stats['object_count']);
var_dump($stats['stored_bytes']);

var_dump(king_object_store_put('cloud-backup-doc', 'bravo!'));
var_dump(king_object_store_get('cloud-backup-doc'));
$meta = king_object_store_get_metadata('cloud-backup-doc');
var_dump($meta['content_length']);
var_dump($meta['is_backed_up']);
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
$forcedDisconnects = array_values(array_filter(
    $capture['events'],
    static fn(array $event): bool => !empty($event['forced_disconnect']) && !empty($event['persisted_before_forced_reply'])
));
var_dump(count($forcedDisconnects) === 1);
var_dump(count(array_filter($targets, static fn(string $target): bool => $target === 'PUT /backup-recovery/cloud-backup-doc')) >= 2);
var_dump(in_array('HEAD /backup-recovery/cloud-backup-doc', $targets, true));

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
string(20) "King\SystemException"
bool(true)
string(6) "failed"
bool(true)
string(6) "failed"
bool(true)
string(5) "alpha"
string(16) "cloud-backup-doc"
int(5)
int(0)
bool(true)
int(1)
string(16) "cloud-backup-doc"
int(5)
string(2) "ok"
bool(true)
string(6) "failed"
bool(true)
int(1)
int(5)
bool(true)
string(6) "bravo!"
int(6)
int(1)
string(2) "ok"
string(2) "ok"
int(1)
int(6)
bool(true)
bool(true)
bool(true)
