--TEST--
King object-store keeps metadata semantics honest across real local_fs and cloud_s3 backend migration
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

$root = sys_get_temp_dir() . '/king_object_store_migration_metadata_' . getmypid();
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

$parseMeta = static function (string $path): array {
    $meta = [];
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return $meta;
    }

    foreach ($lines as $line) {
        $separator = strpos($line, '=');
        if ($separator === false) {
            continue;
        }

        $meta[substr($line, 0, $separator)] = substr($line, $separator + 1);
    }

    return $meta;
};

$writeMeta = static function (string $path, array $meta): void {
    $orderedKeys = [
        'object_id',
        'content_type',
        'content_encoding',
        'etag',
        'content_length',
        'created_at',
        'modified_at',
        'expires_at',
        'object_type',
        'cache_policy',
        'cache_ttl_seconds',
        'local_fs_present',
        'cloud_s3_present',
        'is_backed_up',
        'replication_status',
        'is_distributed',
        'distribution_peer_count',
    ];
    $serialized = '';

    foreach ($orderedKeys as $key) {
        $serialized .= $key . '=' . (string) ($meta[$key] ?? '') . "\n";
    }

    file_put_contents($path, $serialized);
};

$assertMetaEquals = static function (array $actual, array $expected, array $keys): void {
    foreach ($keys as $key) {
        var_dump(($actual[$key] ?? null) === (string) $expected[$key]);
    }
};

$assertPublicMetaEquals = static function (array $actual, array $expected): void {
    var_dump($actual['object_id'] === $expected['object_id']);
    var_dump($actual['content_length'] === (int) $expected['content_length']);
    var_dump($actual['created_at'] === (int) $expected['created_at']);
    var_dump($actual['is_backed_up'] === (int) $expected['is_backed_up']);
    var_dump($actual['replication_status'] === (int) $expected['replication_status']);
    var_dump($actual['is_distributed'] === (int) $expected['is_distributed']);
    var_dump($actual['distribution_peer_count'] === (int) $expected['distribution_peer_count']);
};

$cleanupTree($root);
mkdir($root, 0700, true);

$mock = king_object_store_s3_mock_start_server();

$common = [
    'storage_root_path' => $root,
    'cloud_credentials' => [
        'api_endpoint' => $mock['endpoint'],
        'bucket' => 'migration-meta',
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

$localPayload = "local-meta\0payload";
$cloudPayload = "cloud-meta\0payload";

$localExpected = [
    'object_id' => 'doc-local-meta',
    'content_type' => 'application/x-king-local',
    'content_encoding' => 'identity',
    'etag' => 'local-meta-etag',
    'content_length' => (string) strlen($localPayload),
    'created_at' => '1700000001',
    'modified_at' => '1700000101',
    'expires_at' => '1700003601',
    'object_type' => '3',
    'cache_policy' => '4',
    'cache_ttl_seconds' => '777',
    'local_fs_present' => '1',
    'cloud_s3_present' => '0',
    'is_backed_up' => '0',
    'replication_status' => '2',
    'is_distributed' => '1',
    'distribution_peer_count' => '4',
];

$cloudExpected = [
    'object_id' => 'doc-cloud-meta',
    'content_type' => 'application/x-king-cloud',
    'content_encoding' => 'gzip',
    'etag' => 'cloud-meta-etag',
    'content_length' => (string) strlen($cloudPayload),
    'created_at' => '1700000002',
    'modified_at' => '1700000102',
    'expires_at' => '1700007202',
    'object_type' => '5',
    'cache_policy' => '2',
    'cache_ttl_seconds' => '333',
    'local_fs_present' => '0',
    'cloud_s3_present' => '1',
    'is_backed_up' => '0',
    'replication_status' => '3',
    'is_distributed' => '1',
    'distribution_peer_count' => '7',
];

var_dump(king_object_store_init($localConfig));
var_dump(king_object_store_put('doc-local-meta', $localPayload));
$writeMeta($root . '/doc-local-meta.meta', $localExpected);
var_dump(king_object_store_backup_object('doc-local-meta', $exportLocal));
$assertMetaEquals(
    $parseMeta($exportLocal . '/doc-local-meta.meta'),
    $localExpected,
    [
        'content_type',
        'content_encoding',
        'etag',
        'content_length',
        'created_at',
        'expires_at',
        'object_type',
        'cache_policy',
        'cache_ttl_seconds',
        'local_fs_present',
        'cloud_s3_present',
        'is_backed_up',
        'replication_status',
        'is_distributed',
        'distribution_peer_count',
    ]
);

var_dump(king_object_store_init($cloudConfig));
var_dump(king_object_store_restore_object('doc-local-meta', $exportLocal));
$localMigrated = $parseMeta($root . '/doc-local-meta.meta');
$assertMetaEquals(
    $localMigrated,
    array_merge($localExpected, [
        'local_fs_present' => '1',
        'cloud_s3_present' => '1',
    ]),
    [
        'content_type',
        'content_encoding',
        'etag',
        'content_length',
        'created_at',
        'expires_at',
        'object_type',
        'cache_policy',
        'cache_ttl_seconds',
        'local_fs_present',
        'cloud_s3_present',
        'is_backed_up',
        'replication_status',
        'is_distributed',
        'distribution_peer_count',
    ]
);
$assertPublicMetaEquals(
    king_object_store_get_metadata('doc-local-meta'),
    $localExpected
);

var_dump(king_object_store_put('doc-cloud-meta', $cloudPayload));
$writeMeta($root . '/doc-cloud-meta.meta', $cloudExpected);
var_dump(king_object_store_backup_object('doc-cloud-meta', $exportCloud));
$assertMetaEquals(
    $parseMeta($exportCloud . '/doc-cloud-meta.meta'),
    $cloudExpected,
    [
        'content_type',
        'content_encoding',
        'etag',
        'content_length',
        'created_at',
        'expires_at',
        'object_type',
        'cache_policy',
        'cache_ttl_seconds',
        'local_fs_present',
        'cloud_s3_present',
        'is_backed_up',
        'replication_status',
        'is_distributed',
        'distribution_peer_count',
    ]
);

var_dump(king_object_store_init($localConfig));
var_dump(king_object_store_restore_object('doc-cloud-meta', $exportCloud));
$cloudMigrated = $parseMeta($root . '/doc-cloud-meta.meta');
$assertMetaEquals(
    $cloudMigrated,
    array_merge($cloudExpected, [
        'local_fs_present' => '1',
        'cloud_s3_present' => '1',
    ]),
    [
        'content_type',
        'content_encoding',
        'etag',
        'content_length',
        'created_at',
        'expires_at',
        'object_type',
        'cache_policy',
        'cache_ttl_seconds',
        'local_fs_present',
        'cloud_s3_present',
        'is_backed_up',
        'replication_status',
        'is_distributed',
        'distribution_peer_count',
    ]
);
$assertPublicMetaEquals(
    king_object_store_get_metadata('doc-cloud-meta'),
    $cloudExpected
);

$capture = king_object_store_s3_mock_stop_server($mock);
$targets = array_map(
    static fn(array $event): string => $event['method'] . ' ' . $event['target'],
    $capture['events']
);
var_dump(in_array('PUT /migration-meta/doc-local-meta', $targets, true));
var_dump(in_array('PUT /migration-meta/doc-cloud-meta', $targets, true));
var_dump(in_array('GET /migration-meta/doc-cloud-meta', $targets, true));

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
