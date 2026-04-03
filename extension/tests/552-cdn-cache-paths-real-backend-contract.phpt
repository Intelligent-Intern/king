--TEST--
King CDN cache paths stay honest across local_fs distributed and real cloud object-store backends
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

function king_cdn_cache_paths_552_cleanup_tree(string $path): void
{
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

                king_cdn_cache_paths_552_cleanup_tree($path . '/' . $entry);
            }
        }

        @chmod($path, 0700);
        @rmdir($path);
        return;
    }

    @chmod($path, 0600);
    @unlink($path);
}

function king_cdn_cache_paths_552_run_local_backend(string $backend): array
{
    $root = sys_get_temp_dir() . '/king_cdn_cache_paths_552_' . $backend . '_' . getmypid();
    $objectId = 'doc-' . str_replace('_', '-', $backend);
    $payload = $backend . '-cache';

    king_cdn_cache_paths_552_cleanup_tree($root);
    mkdir($root, 0700, true);

    $payloadPath = $backend === 'distributed'
        ? $root . '/.king-distributed/objects/' . $objectId
        : $root . '/' . $objectId;

    try {
        $result = [
            'init' => king_object_store_init([
                'storage_root_path' => $root,
                'primary_backend' => $backend,
                'cdn_config' => [
                    'enabled' => true,
                    'default_ttl_seconds' => 90,
                    'cache_size_mb' => 64,
                ],
            ]),
            'put' => king_object_store_put($objectId, $payload, [
                'content_type' => 'text/plain',
                'object_type' => 'cache_entry',
                'cache_policy' => 'smart_cdn',
            ]),
        ];

        @chmod($payloadPath, 0000);
        clearstatcache();

        $result['cache'] = king_cdn_cache_object($objectId, ['ttl_sec' => 45]);

        $stats = king_object_store_get_stats()['cdn'];
        $meta = king_object_store_get_metadata($objectId);
        $result['cached_object_count'] = $stats['cached_object_count'];
        $result['cached_bytes'] = $stats['cached_bytes'];
        $result['latest_cached_at_is_int'] = is_int($stats['latest_cached_at']);
        $result['meta_is_distributed'] = $meta['is_distributed'] ?? null;
        $result['meta_distribution_peer_count'] = $meta['distribution_peer_count'] ?? null;
        $result['invalidate'] = king_cdn_invalidate_cache($objectId);
        $result['after_count'] = king_object_store_get_stats()['cdn']['cached_object_count'];

        return $result;
    } finally {
        @chmod($payloadPath, 0600);
        king_cdn_cache_paths_552_cleanup_tree($root);
    }
}

function king_cdn_cache_paths_552_run_cloud_backend(string $backend, string $provider): array
{
    $root = sys_get_temp_dir() . '/king_cdn_cache_paths_552_' . $backend . '_' . getmypid();
    $objectId = 'doc-' . $provider;
    $payload = $backend . '-cache';
    $metaPath = $root . '/' . $objectId . '.meta';
    $mockOptions = ['provider' => $provider];

    if ($provider === 'gcs') {
        $mockOptions['expected_access_token'] = 'gcs-token';
    } elseif ($provider === 'azure') {
        $mockOptions['expected_access_token'] = 'azure-token';
    }

    king_cdn_cache_paths_552_cleanup_tree($root);
    mkdir($root, 0700, true);
    $mock = king_object_store_s3_mock_start_server(null, '127.0.0.1', $mockOptions);
    $stateDirectory = $mock['state_directory'];

    try {
        $config = [
            'storage_root_path' => $root,
            'primary_backend' => $backend,
            'cdn_config' => [
                'enabled' => true,
                'default_ttl_seconds' => 90,
                'cache_size_mb' => 64,
            ],
            'cloud_credentials' => [
                'api_endpoint' => $mock['endpoint'],
                'verify_tls' => false,
            ],
        ];

        if ($backend === 'cloud_s3') {
            $config['cloud_credentials']['bucket'] = 'cdn-cache-path-s3';
            $config['cloud_credentials']['access_key'] = 'access';
            $config['cloud_credentials']['secret_key'] = 'secret';
            $config['cloud_credentials']['region'] = 'us-east-1';
            $config['cloud_credentials']['path_style'] = true;
        } elseif ($backend === 'cloud_gcs') {
            $config['cloud_credentials']['bucket'] = 'cdn-cache-path-gcs';
            $config['cloud_credentials']['access_token'] = 'gcs-token';
            $config['cloud_credentials']['path_style'] = true;
        } else {
            $config['cloud_credentials']['container'] = 'cdn-cache-path-azure';
            $config['cloud_credentials']['access_token'] = 'azure-token';
        }

        $result = [
            'init' => king_object_store_init($config),
            'put' => king_object_store_put($objectId, $payload, [
                'content_type' => 'text/plain',
                'object_type' => 'cache_entry',
                'cache_policy' => 'smart_cdn',
            ]),
        ];

        @unlink($metaPath);
        clearstatcache();

        $result['cache'] = king_cdn_cache_object($objectId, ['ttl_sec' => 45]);

        $stats = king_object_store_get_stats()['cdn'];
        $meta = king_object_store_get_metadata($objectId);
        $result['cached_object_count'] = $stats['cached_object_count'];
        $result['cached_bytes'] = $stats['cached_bytes'];
        $result['latest_cached_at_is_int'] = is_int($stats['latest_cached_at']);
        $result['meta_is_distributed'] = $meta['is_distributed'] ?? null;
        $result['meta_distribution_peer_count'] = $meta['distribution_peer_count'] ?? null;
        $result['invalidate'] = king_cdn_invalidate_cache($objectId);
        $result['after_count'] = king_object_store_get_stats()['cdn']['cached_object_count'];

        $capture = king_object_store_s3_mock_stop_server($mock);
        $mock = null;
        $result['head_seen'] = count(array_filter(
            $capture['events'] ?? [],
            static fn(array $event): bool =>
                ($event['method'] ?? '') === 'HEAD'
                && ($event['object_id'] ?? '') === $objectId
        )) >= 1;
        $result['get_seen'] = count(array_filter(
            $capture['events'] ?? [],
            static fn(array $event): bool =>
                ($event['method'] ?? '') === 'GET'
                && ($event['object_id'] ?? '') === $objectId
        )) >= 1;

        return $result;
    } finally {
        if (isset($mock) && is_array($mock)) {
            king_object_store_s3_mock_stop_server($mock);
        }
        king_object_store_s3_mock_cleanup_state_directory($stateDirectory);
        king_cdn_cache_paths_552_cleanup_tree($root);
    }
}

$localFs = king_cdn_cache_paths_552_run_local_backend('local_fs');
var_dump('local_fs');
var_dump($localFs['init']);
var_dump($localFs['put']);
var_dump($localFs['cache']);
var_dump($localFs['cached_object_count']);
var_dump($localFs['cached_bytes']);
var_dump($localFs['latest_cached_at_is_int']);
var_dump($localFs['meta_is_distributed']);
var_dump($localFs['meta_distribution_peer_count']);
var_dump($localFs['invalidate']);
var_dump($localFs['after_count']);

$distributed = king_cdn_cache_paths_552_run_local_backend('distributed');
var_dump('distributed');
var_dump($distributed['init']);
var_dump($distributed['put']);
var_dump($distributed['cache']);
var_dump($distributed['cached_object_count']);
var_dump($distributed['cached_bytes']);
var_dump($distributed['latest_cached_at_is_int']);
var_dump($distributed['meta_is_distributed']);
var_dump($distributed['meta_distribution_peer_count']);
var_dump($distributed['invalidate']);
var_dump($distributed['after_count']);

foreach ([
    ['cloud_s3', 's3'],
    ['cloud_gcs', 'gcs'],
    ['cloud_azure', 'azure'],
] as [$backend, $provider]) {
    $result = king_cdn_cache_paths_552_run_cloud_backend($backend, $provider);
    var_dump($backend);
    var_dump($result['init']);
    var_dump($result['put']);
    var_dump($result['cache']);
    var_dump($result['cached_object_count']);
    var_dump($result['cached_bytes']);
    var_dump($result['latest_cached_at_is_int']);
    var_dump($result['meta_is_distributed']);
    var_dump($result['meta_distribution_peer_count']);
    var_dump($result['head_seen']);
    var_dump($result['get_seen']);
    var_dump($result['invalidate']);
    var_dump($result['after_count']);
}
?>
--EXPECT--
string(8) "local_fs"
bool(true)
bool(true)
bool(true)
int(1)
int(14)
bool(true)
int(1)
int(1)
int(1)
int(0)
string(11) "distributed"
bool(true)
bool(true)
bool(true)
int(1)
int(17)
bool(true)
int(1)
int(1)
int(1)
int(0)
string(8) "cloud_s3"
bool(true)
bool(true)
bool(true)
int(1)
int(14)
bool(true)
int(1)
int(1)
bool(true)
bool(false)
int(1)
int(0)
string(9) "cloud_gcs"
bool(true)
bool(true)
bool(true)
int(1)
int(15)
bool(true)
int(1)
int(1)
bool(true)
bool(false)
int(1)
int(0)
string(11) "cloud_azure"
bool(true)
bool(true)
bool(true)
int(1)
int(17)
bool(true)
int(1)
int(1)
bool(true)
bool(false)
int(1)
int(0)
