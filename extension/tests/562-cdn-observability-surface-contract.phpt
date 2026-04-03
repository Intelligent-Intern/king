--TEST--
King CDN observability surface stays honest across local and real cloud object-store backends
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

function king_cdn_observability_562_cleanup_tree(string $path): void
{
    if ($path === '' || !file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            king_cdn_observability_562_cleanup_tree($path . '/' . $entry);
        }

        @chmod($path, 0700);
        @rmdir($path);
        return;
    }

    @chmod($path, 0600);
    @unlink($path);
}

function king_cdn_observability_562_run_local_backend(string $backend): array
{
    $root = sys_get_temp_dir() . '/king_cdn_observability_562_' . $backend . '_' . getmypid();
    $retainedId = 'retained-' . str_replace('_', '-', $backend);
    $metaId = 'meta-' . str_replace('_', '-', $backend);
    $retainedPayload = $backend . '-retained-body';
    $metaPayload = $backend . '-meta-body';

    king_cdn_observability_562_cleanup_tree($root);
    mkdir($root, 0700, true);

    try {
        king_cdn_invalidate_cache();
        $baseline = king_object_store_get_stats()['cdn'];
        $result = [
            'init' => king_object_store_init([
                'storage_root_path' => $root,
                'primary_backend' => $backend,
                'cdn_config' => [
                    'enabled' => true,
                    'default_ttl_seconds' => 300,
                    'cache_size_mb' => 64,
                ],
            ]),
            'put_retained' => king_object_store_put($retainedId, $retainedPayload, [
                'content_type' => 'text/plain',
                'object_type' => 'cache_entry',
                'cache_policy' => 'smart_cdn',
            ]),
            'put_meta' => king_object_store_put($metaId, $metaPayload, [
                'content_type' => 'text/plain',
                'object_type' => 'cache_entry',
                'cache_policy' => 'smart_cdn',
            ]),
        ];

        $result['read_retained'] = king_object_store_get($retainedId) === $retainedPayload;
        $result['cache_meta'] = king_cdn_cache_object($metaId);

        $stats = king_object_store_get_stats()['cdn'];
        $result['cached_object_count'] = $stats['cached_object_count'];
        $result['cached_bytes'] = $stats['cached_bytes'];
        $result['retained_object_count'] = $stats['retained_object_count'];
        $result['metadata_only_object_count'] = $stats['metadata_only_object_count'];
        $result['retained_bytes'] = $stats['retained_bytes'];
        $result['served_count_delta'] = $stats['served_count'] - $baseline['served_count'];
        $result['latest_cached_at_is_int'] = is_int($stats['latest_cached_at']);
        $result['latest_served_at_is_int'] = is_int($stats['latest_served_at']);
        $result['stale_serve_count_delta'] = $stats['stale_serve_count'] - $baseline['stale_serve_count'];
        $result['eviction_count_delta'] = $stats['eviction_count'] - $baseline['eviction_count'];
        $result['expiration_count_delta'] = $stats['expiration_count'] - $baseline['expiration_count'];
        $result['invalidation_count_delta'] = $stats['invalidation_count'] - $baseline['invalidation_count'];

        $result['invalidate_meta'] = king_cdn_invalidate_cache($metaId);
        $afterInvalidate = king_object_store_get_stats()['cdn'];
        $result['after_invalidate_cached_object_count'] = $afterInvalidate['cached_object_count'];
        $result['after_invalidate_retained_object_count'] = $afterInvalidate['retained_object_count'];
        $result['after_invalidate_metadata_only_object_count'] = $afterInvalidate['metadata_only_object_count'];
        $result['after_invalidate_invalidation_count_delta'] = $afterInvalidate['invalidation_count'] - $baseline['invalidation_count'];

        return $result;
    } finally {
        king_cdn_observability_562_cleanup_tree($root);
    }
}

function king_cdn_observability_562_run_cloud_backend(string $backend, string $provider): array
{
    $root = sys_get_temp_dir() . '/king_cdn_observability_562_' . $provider . '_' . getmypid();
    $retainedId = 'retained-' . $provider;
    $metaId = 'meta-' . $provider;
    $retainedPayload = $backend . '-retained-body';
    $metaPayload = $backend . '-meta-body';
    $mockOptions = ['provider' => $provider];

    if ($provider === 'gcs') {
        $mockOptions['expected_access_token'] = 'gcs-token';
    } elseif ($provider === 'azure') {
        $mockOptions['expected_access_token'] = 'azure-token';
    }

    king_cdn_observability_562_cleanup_tree($root);
    mkdir($root, 0700, true);
    $mock = king_object_store_s3_mock_start_server(null, '127.0.0.1', $mockOptions);
    $stateDirectory = $mock['state_directory'];

    try {
        king_cdn_invalidate_cache();
        $baseline = king_object_store_get_stats()['cdn'];
        $config = [
            'storage_root_path' => $root,
            'primary_backend' => $backend,
            'cdn_config' => [
                'enabled' => true,
                'default_ttl_seconds' => 300,
                'cache_size_mb' => 64,
            ],
            'cloud_credentials' => [
                'api_endpoint' => $mock['endpoint'],
                'verify_tls' => false,
            ],
        ];

        if ($backend === 'cloud_s3') {
            $config['cloud_credentials']['bucket'] = 'cdn-observability-s3';
            $config['cloud_credentials']['access_key'] = 'access';
            $config['cloud_credentials']['secret_key'] = 'secret';
            $config['cloud_credentials']['region'] = 'us-east-1';
            $config['cloud_credentials']['path_style'] = true;
        } elseif ($backend === 'cloud_gcs') {
            $config['cloud_credentials']['bucket'] = 'cdn-observability-gcs';
            $config['cloud_credentials']['access_token'] = 'gcs-token';
            $config['cloud_credentials']['path_style'] = true;
        } else {
            $config['cloud_credentials']['container'] = 'cdn-observability-azure';
            $config['cloud_credentials']['access_token'] = 'azure-token';
        }

        $result = [
            'init' => king_object_store_init($config),
            'put_retained' => king_object_store_put($retainedId, $retainedPayload, [
                'content_type' => 'text/plain',
                'object_type' => 'cache_entry',
                'cache_policy' => 'smart_cdn',
            ]),
            'put_meta' => king_object_store_put($metaId, $metaPayload, [
                'content_type' => 'text/plain',
                'object_type' => 'cache_entry',
                'cache_policy' => 'smart_cdn',
            ]),
        ];

        $result['read_retained'] = king_object_store_get($retainedId) === $retainedPayload;
        $result['cache_meta'] = king_cdn_cache_object($metaId);

        $stats = king_object_store_get_stats()['cdn'];
        $result['cached_object_count'] = $stats['cached_object_count'];
        $result['cached_bytes'] = $stats['cached_bytes'];
        $result['retained_object_count'] = $stats['retained_object_count'];
        $result['metadata_only_object_count'] = $stats['metadata_only_object_count'];
        $result['retained_bytes'] = $stats['retained_bytes'];
        $result['served_count_delta'] = $stats['served_count'] - $baseline['served_count'];
        $result['latest_cached_at_is_int'] = is_int($stats['latest_cached_at']);
        $result['latest_served_at_is_int'] = is_int($stats['latest_served_at']);
        $result['stale_serve_count_delta'] = $stats['stale_serve_count'] - $baseline['stale_serve_count'];
        $result['eviction_count_delta'] = $stats['eviction_count'] - $baseline['eviction_count'];
        $result['expiration_count_delta'] = $stats['expiration_count'] - $baseline['expiration_count'];
        $result['invalidation_count_delta'] = $stats['invalidation_count'] - $baseline['invalidation_count'];

        $result['invalidate_meta'] = king_cdn_invalidate_cache($metaId);
        $afterInvalidate = king_object_store_get_stats()['cdn'];
        $result['after_invalidate_cached_object_count'] = $afterInvalidate['cached_object_count'];
        $result['after_invalidate_retained_object_count'] = $afterInvalidate['retained_object_count'];
        $result['after_invalidate_metadata_only_object_count'] = $afterInvalidate['metadata_only_object_count'];
        $result['after_invalidate_invalidation_count_delta'] = $afterInvalidate['invalidation_count'] - $baseline['invalidation_count'];

        return $result;
    } finally {
        if (isset($mock) && is_array($mock)) {
            king_object_store_s3_mock_stop_server($mock);
        }
        king_object_store_s3_mock_cleanup_state_directory($stateDirectory);
        king_cdn_observability_562_cleanup_tree($root);
    }
}

foreach ([
    ['local_fs', null],
    ['distributed', null],
    ['cloud_s3', 's3'],
    ['cloud_gcs', 'gcs'],
    ['cloud_azure', 'azure'],
] as [$backend, $provider]) {
    $result = $provider === null
        ? king_cdn_observability_562_run_local_backend($backend)
        : king_cdn_observability_562_run_cloud_backend($backend, $provider);

    $retainedPayload = $backend . '-retained-body';
    $metaPayload = $backend . '-meta-body';

    var_dump($backend);
    var_dump(
        $result['init'] === true
        && $result['put_retained'] === true
        && $result['put_meta'] === true
        && $result['read_retained'] === true
        && $result['cache_meta'] === true
    );
    var_dump(
        $result['cached_object_count'] === 2
        && $result['cached_bytes'] === strlen($retainedPayload) + strlen($metaPayload)
        && $result['retained_object_count'] === 1
        && $result['metadata_only_object_count'] === 1
    );
    var_dump($result['retained_bytes'] === strlen($retainedPayload));
    var_dump(
        $result['served_count_delta'] === 1
        && $result['latest_cached_at_is_int'] === true
        && $result['latest_served_at_is_int'] === true
    );
    var_dump(
        $result['stale_serve_count_delta'] === 0
        && $result['eviction_count_delta'] === 0
        && $result['expiration_count_delta'] === 0
        && $result['invalidation_count_delta'] === 0
    );
    var_dump(
        $result['invalidate_meta'] === 1
        && $result['after_invalidate_cached_object_count'] === 1
        && $result['after_invalidate_retained_object_count'] === 1
        && $result['after_invalidate_metadata_only_object_count'] === 0
        && $result['after_invalidate_invalidation_count_delta'] === 1
    );
}
?>
--EXPECT--
string(8) "local_fs"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(11) "distributed"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(8) "cloud_s3"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(9) "cloud_gcs"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(11) "cloud_azure"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
