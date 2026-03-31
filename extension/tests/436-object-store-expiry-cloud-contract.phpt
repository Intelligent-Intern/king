--TEST--
King object-store expiry semantics stay consistent across real cloud backends
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

function king_object_store_expiry_436_cleanup_dir(string $dir): void
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

function king_object_store_expiry_436_run(string $backend, string $provider): array
{
    $root = sys_get_temp_dir() . '/king_object_store_expiry_cloud_436_' . $backend . '_' . getmypid();
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
        $config['cloud_credentials']['bucket'] = 'expiry-s3';
        $config['cloud_credentials']['access_key'] = 'access';
        $config['cloud_credentials']['secret_key'] = 'secret';
        $config['cloud_credentials']['region'] = 'us-east-1';
        $config['cloud_credentials']['path_style'] = true;
    } elseif ($backend === 'cloud_gcs') {
        $config['cloud_credentials']['bucket'] = 'expiry-gcs';
        $config['cloud_credentials']['access_token'] = 'gcs-token';
        $config['cloud_credentials']['path_style'] = true;
    } else {
        $config['cloud_credentials']['container'] = 'expiry-azure';
        $config['cloud_credentials']['access_token'] = 'azure-token';
    }

    $expiredAt = time() - 3600;
    $activeAt = time() + 3600;
    $expiredObjectId = 'expired-' . $provider;
    $activeObjectId = 'active-' . $provider;
    $expiredPayload = 'expired-10';
    $activePayload = 'active-09';

    $result = [
        'init' => king_object_store_init($config),
        'put_expired' => king_object_store_put($expiredObjectId, $expiredPayload, [
            'expires_at' => $expiredAt,
            'cache_ttl_sec' => 60,
        ]),
        'put_active' => king_object_store_put($activeObjectId, $activePayload, [
            'expires_at' => $activeAt,
            'cache_ttl_sec' => 60,
        ]),
    ];

    $expiredMeta = king_object_store_get_metadata($expiredObjectId);
    $result['expired_meta_is_array'] = is_array($expiredMeta);
    $result['expired_meta_flag'] = is_array($expiredMeta) && ($expiredMeta['is_expired'] ?? false) === true;
    $result['expired_meta_timestamp'] = is_array($expiredMeta) && ($expiredMeta['expires_at'] ?? null) === $expiredAt;

    $result['expired_get'] = king_object_store_get($expiredObjectId);
    $expiredDestination = fopen('php://temp', 'w+');
    $result['expired_get_to_stream'] = king_object_store_get_to_stream($expiredObjectId, $expiredDestination);
    rewind($expiredDestination);
    $result['expired_stream_contents'] = stream_get_contents($expiredDestination);

    $result['active_get'] = king_object_store_get($activeObjectId);

    $list = king_object_store_list();
    $result['list_count'] = count($list);
    $result['list_first_object_id'] = $list[0]['object_id'] ?? null;
    $result['list_first_is_expired'] = $list[0]['is_expired'] ?? null;

    $cleanup = king_object_store_cleanup_expired_objects();
    $result['cleanup_mode'] = $cleanup['mode'] ?? null;
    $result['cleanup_scanned_objects'] = $cleanup['scanned_objects'] ?? null;
    $result['cleanup_removed'] = $cleanup['expired_objects_removed'] ?? null;
    $result['cleanup_bytes_reclaimed'] = $cleanup['bytes_reclaimed'] ?? null;
    $result['cleanup_failures'] = $cleanup['removal_failures'] ?? null;

    $result['expired_meta_after_cleanup'] = king_object_store_get_metadata($expiredObjectId);
    $stats = king_object_store_get_stats()['object_store'];
    $result['object_count'] = $stats['object_count'];
    $result['stored_bytes'] = $stats['stored_bytes'];

    $capture = king_object_store_s3_mock_stop_server($mock);
    $result['expired_payload_get_seen'] = count(array_filter(
        $capture['events'],
        static fn(array $event): bool =>
            $event['method'] === 'GET'
            && $event['object_id'] === $expiredObjectId
    )) >= 1;
    $result['expired_delete_seen'] = count(array_filter(
        $capture['events'],
        static fn(array $event): bool =>
            $event['method'] === 'DELETE'
            && $event['object_id'] === $expiredObjectId
    )) >= 1;

    king_object_store_expiry_436_cleanup_dir($root);
    king_object_store_s3_mock_cleanup_state_directory($mock['state_directory']);

    return $result;
}

foreach ([
    ['cloud_s3', 's3'],
    ['cloud_gcs', 'gcs'],
    ['cloud_azure', 'azure'],
] as [$backend, $provider]) {
    $result = king_object_store_expiry_436_run($backend, $provider);
    var_dump($backend);
    var_dump($result['init']);
    var_dump($result['put_expired']);
    var_dump($result['put_active']);
    var_dump($result['expired_meta_is_array']);
    var_dump($result['expired_meta_flag']);
    var_dump($result['expired_meta_timestamp']);
    var_dump($result['expired_get']);
    var_dump($result['expired_get_to_stream']);
    var_dump($result['expired_stream_contents']);
    var_dump($result['active_get']);
    var_dump($result['list_count']);
    var_dump($result['list_first_object_id']);
    var_dump($result['list_first_is_expired']);
    var_dump($result['cleanup_mode']);
    var_dump($result['cleanup_scanned_objects']);
    var_dump($result['cleanup_removed']);
    var_dump($result['cleanup_bytes_reclaimed']);
    var_dump($result['cleanup_failures']);
    var_dump($result['expired_meta_after_cleanup']);
    var_dump($result['object_count']);
    var_dump($result['stored_bytes']);
    var_dump($result['expired_payload_get_seen']);
    var_dump($result['expired_delete_seen']);
}
?>
--EXPECT--
string(8) "cloud_s3"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(false)
string(0) ""
string(9) "active-09"
int(1)
string(9) "active-s3"
bool(false)
string(14) "expiry_cleanup"
int(2)
int(1)
int(10)
int(0)
bool(false)
int(1)
int(9)
bool(false)
bool(true)
string(9) "cloud_gcs"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(false)
string(0) ""
string(9) "active-09"
int(1)
string(10) "active-gcs"
bool(false)
string(14) "expiry_cleanup"
int(2)
int(1)
int(10)
int(0)
bool(false)
int(1)
int(9)
bool(false)
bool(true)
string(11) "cloud_azure"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(false)
string(0) ""
string(9) "active-09"
int(1)
string(12) "active-azure"
bool(false)
string(14) "expiry_cleanup"
int(2)
int(1)
int(10)
int(0)
bool(false)
int(1)
int(9)
bool(false)
bool(true)
