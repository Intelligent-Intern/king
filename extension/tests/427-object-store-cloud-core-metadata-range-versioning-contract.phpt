--TEST--
King object-store core metadata, range, and overwrite/versioning semantics stay consistent across real cloud backends
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

function king_object_store_cloud_core_427_cleanup_dir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        @unlink($dir . '/' . $entry);
    }

    @rmdir($dir);
}

function king_object_store_cloud_core_427_run(string $backend, string $provider): array
{
    $expiresAt = '2099-01-01T00:00:00Z';
    $root = sys_get_temp_dir() . '/king_object_store_cloud_core_427_' . $backend . '_' . getmypid();
    if (!is_dir($root)) {
        mkdir($root, 0700, true);
    }

    $mockOptions = ['provider' => $provider];
    if ($provider === 'gcs') {
        $mockOptions['expected_access_token'] = 'gcs-token';
    } elseif ($provider === 'azure') {
        $mockOptions['expected_access_token'] = 'azure-token';
    }

    $mock = king_object_store_s3_mock_start_server(null, '127.0.0.1', $mockOptions);

    $config = [
        'storage_root_path' => $root,
        'primary_backend' => $backend,
        'cloud_credentials' => [
            'api_endpoint' => $mock['endpoint'],
            'verify_tls' => false,
        ],
    ];

    if ($backend === 'cloud_s3') {
        $config['cloud_credentials']['bucket'] = 'core-s3';
        $config['cloud_credentials']['access_key'] = 'access';
        $config['cloud_credentials']['secret_key'] = 'secret';
        $config['cloud_credentials']['region'] = 'us-east-1';
        $config['cloud_credentials']['path_style'] = true;
        $presenceKey = 'cloud_s3_present';
        $metadataHeader = 'x-amz-meta-object-type';
    } elseif ($backend === 'cloud_gcs') {
        $config['cloud_credentials']['bucket'] = 'core-gcs';
        $config['cloud_credentials']['access_token'] = 'gcs-token';
        $config['cloud_credentials']['path_style'] = true;
        $presenceKey = 'cloud_gcs_present';
        $metadataHeader = 'x-goog-meta-object-type';
    } else {
        $config['cloud_credentials']['container'] = 'core-azure';
        $config['cloud_credentials']['access_token'] = 'azure-token';
        $presenceKey = 'cloud_azure_present';
        $metadataHeader = 'x-ms-meta-object-type';
    }

    $objectId = 'doc-' . $provider;
    $payload = 'abcdefghi';

    $result = [
        'init' => king_object_store_init($config),
        'put' => king_object_store_put($objectId, $payload, [
            'content_type' => 'text/plain',
            'content_encoding' => 'gzip',
            'cache_ttl_sec' => 77,
            'expires_at' => $expiresAt,
            'object_type' => 'document',
            'cache_policy' => 'smart_cdn',
        ]),
    ];

    $meta = king_object_store_get_metadata($objectId);
    $list = king_object_store_list();
    $result['range'] = king_object_store_get($objectId, [
        'offset' => 2,
        'length' => 4,
    ]);
    $result['meta_content_type'] = $meta['content_type'];
    $result['meta_object_type_name'] = $meta['object_type_name'];
    $result['meta_cache_policy_name'] = $meta['cache_policy_name'];
    $result['meta_presence'] = ($meta[$presenceKey] ?? 0) === 1;
    $result['list_content_type'] = $list[0]['content_type'];
    $result['list_object_type_name'] = $list[0]['object_type_name'];
    $result['list_cache_policy_name'] = $list[0]['cache_policy_name'];
    $result['list_presence'] = ($list[0][$presenceKey] ?? 0) === 1;

    $result['overwrite'] = king_object_store_put($objectId, 'omega', [
        'if_match' => $meta['etag'],
        'expected_version' => $meta['version'],
        'object_type' => 'cache_entry',
        'cache_policy' => 'etag',
    ]);

    $updated = king_object_store_get_metadata($objectId);
    $result['updated_version'] = $updated['version'];
    $result['updated_object_type_name'] = $updated['object_type_name'];
    $result['updated_cache_policy_name'] = $updated['cache_policy_name'];

    $capture = king_object_store_s3_mock_stop_server($mock);
    $result['range_header_seen'] = count(array_filter(
        $capture['events'],
        static fn(array $event): bool =>
            $event['method'] === 'GET'
            && $event['object_id'] === $objectId
            && (($event['headers']['range'] ?? '') === 'bytes=2-5')
    )) >= 1;
    $result['metadata_header_seen'] = count(array_filter(
        $capture['events'],
        static fn(array $event): bool =>
            $event['method'] === 'PUT'
            && $event['object_id'] === $objectId
            && isset($event['headers'][$metadataHeader])
    )) >= 1;

    king_object_store_cloud_core_427_cleanup_dir($root);
    king_object_store_s3_mock_cleanup_state_directory($mock['state_directory']);

    return $result;
}

foreach ([
    ['cloud_s3', 's3'],
    ['cloud_gcs', 'gcs'],
    ['cloud_azure', 'azure'],
] as [$backend, $provider]) {
    $result = king_object_store_cloud_core_427_run($backend, $provider);
    var_dump($backend);
    var_dump($result['init']);
    var_dump($result['put']);
    var_dump($result['range']);
    var_dump($result['meta_content_type']);
    var_dump($result['meta_object_type_name']);
    var_dump($result['meta_cache_policy_name']);
    var_dump($result['meta_presence']);
    var_dump($result['list_content_type']);
    var_dump($result['list_object_type_name']);
    var_dump($result['list_cache_policy_name']);
    var_dump($result['list_presence']);
    var_dump($result['overwrite']);
    var_dump($result['updated_version']);
    var_dump($result['updated_object_type_name']);
    var_dump($result['updated_cache_policy_name']);
    var_dump($result['range_header_seen']);
    var_dump($result['metadata_header_seen']);
}
?>
--EXPECT--
string(8) "cloud_s3"
bool(true)
bool(true)
string(4) "cdef"
string(10) "text/plain"
string(8) "document"
string(9) "smart_cdn"
bool(true)
string(10) "text/plain"
string(8) "document"
string(9) "smart_cdn"
bool(true)
bool(true)
int(2)
string(11) "cache_entry"
string(4) "etag"
bool(true)
bool(true)
string(9) "cloud_gcs"
bool(true)
bool(true)
string(4) "cdef"
string(10) "text/plain"
string(8) "document"
string(9) "smart_cdn"
bool(true)
string(10) "text/plain"
string(8) "document"
string(9) "smart_cdn"
bool(true)
bool(true)
int(2)
string(11) "cache_entry"
string(4) "etag"
bool(true)
bool(true)
string(11) "cloud_azure"
bool(true)
bool(true)
string(4) "cdef"
string(10) "text/plain"
string(8) "document"
string(9) "smart_cdn"
bool(true)
string(10) "text/plain"
string(8) "document"
string(9) "smart_cdn"
bool(true)
bool(true)
int(2)
string(11) "cache_entry"
string(4) "etag"
bool(true)
bool(true)
