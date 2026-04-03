--TEST--
King CDN invalidation stays exact under sustained warm and invalidate churn across local and real cloud object-store backends
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

function king_cdn_invalidation_554_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function king_cdn_invalidation_554_cleanup_tree(string $path): void
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

                king_cdn_invalidation_554_cleanup_tree($path . '/' . $entry);
            }
        }

        @chmod($path, 0700);
        @rmdir($path);
        return;
    }

    @chmod($path, 0600);
    @unlink($path);
}

function king_cdn_invalidation_554_build_objects(string $prefix): array
{
    $objects = [];

    for ($i = 0; $i < 6; $i++) {
        $objectId = sprintf('%s-%02d', $prefix, $i);
        $payload = $prefix . '-payload-' . str_repeat((string) (($i + 3) % 10), $i + 1);

        $objects[] = [
            'id' => $objectId,
            'payload' => $payload,
            'size' => strlen($payload),
        ];
    }

    return $objects;
}

function king_cdn_invalidation_554_invalidation_order(int $round, int $objectCount): array
{
    if ($round === 0) {
        return array_reverse(range(0, $objectCount - 1));
    }

    if ($round === 1) {
        return [1, 3, 5, 0, 2, 4];
    }

    return [4, 2, 0, 5, 3, 1];
}

function king_cdn_invalidation_554_run_rounds(
    array $objects,
    callable $warmDirect,
    callable $warmReadThrough
): void {
    $objectCount = count($objects);
    $expectedBytes = 0;

    foreach ($objects as $object) {
        $expectedBytes += $object['size'];
    }

    for ($round = 0; $round < 3; $round++) {
        foreach ($objects as $index => $object) {
            if ((($index + $round) % 2) === 0) {
                king_cdn_invalidation_554_assert(
                    $warmDirect($object),
                    'round ' . $round . ' direct warm failed for ' . $object['id']
                );
                continue;
            }

            king_cdn_invalidation_554_assert(
                $warmReadThrough($object),
                'round ' . $round . ' read-through warm failed for ' . $object['id']
            );
        }

        $stats = king_object_store_get_stats()['cdn'];
        king_cdn_invalidation_554_assert(
            $stats['cached_object_count'] === $objectCount,
            'round ' . $round . ' cached object count drifted after warm'
        );
        king_cdn_invalidation_554_assert(
            $stats['cached_bytes'] === $expectedBytes,
            'round ' . $round . ' cached bytes drifted after warm'
        );

        $remainingCount = $objectCount;
        $remainingBytes = $expectedBytes;

        foreach (king_cdn_invalidation_554_invalidation_order($round, $objectCount) as $index) {
            $object = $objects[$index];

            king_cdn_invalidation_554_assert(
                king_cdn_invalidate_cache($object['id']) === 1,
                'round ' . $round . ' first invalidate did not remove ' . $object['id']
            );
            king_cdn_invalidation_554_assert(
                king_cdn_invalidate_cache($object['id']) === 0,
                'round ' . $round . ' second invalidate did not stay empty for ' . $object['id']
            );

            $remainingCount--;
            $remainingBytes -= $object['size'];
            $stats = king_object_store_get_stats()['cdn'];

            king_cdn_invalidation_554_assert(
                $stats['cached_object_count'] === $remainingCount,
                'round ' . $round . ' cached object count drifted after invalidating ' . $object['id']
            );
            king_cdn_invalidation_554_assert(
                $stats['cached_bytes'] === $remainingBytes,
                'round ' . $round . ' cached bytes drifted after invalidating ' . $object['id']
            );
        }

        king_cdn_invalidation_554_assert(
            king_cdn_invalidate_cache() === 0,
            'round ' . $round . ' flush-all invalidate was non-empty after the round drained cleanly'
        );
    }
}

function king_cdn_invalidation_554_run_local_backend(string $backend): void
{
    $root = sys_get_temp_dir() . '/king_cdn_invalidation_554_' . $backend . '_' . getmypid();
    $objects = king_cdn_invalidation_554_build_objects(str_replace('_', '-', $backend));

    king_cdn_invalidation_554_cleanup_tree($root);
    mkdir($root, 0700, true);

    try {
        king_cdn_invalidation_554_assert(
            king_object_store_init([
                'storage_root_path' => $root,
                'primary_backend' => $backend,
                'cdn_config' => [
                    'enabled' => true,
                    'default_ttl_seconds' => 120,
                    'cache_size_mb' => 64,
                ],
            ]) === true,
            $backend . ' init failed'
        );

        king_cdn_invalidation_554_assert(
            king_cdn_invalidate_cache() === 0,
            $backend . ' did not start with an empty CDN registry'
        );

        foreach ($objects as $object) {
            king_cdn_invalidation_554_assert(
                king_object_store_put($object['id'], $object['payload'], [
                    'content_type' => 'text/plain',
                    'object_type' => 'cache_entry',
                    'cache_policy' => 'smart_cdn',
                ]) === true,
                $backend . ' put failed for ' . $object['id']
            );
        }

        king_cdn_invalidation_554_run_rounds(
            $objects,
            static function (array $object): bool {
                return king_cdn_cache_object($object['id'], ['ttl_sec' => 60]) === true;
            },
            static function (array $object): bool {
                return king_object_store_get($object['id']) === $object['payload'];
            }
        );
    } finally {
        king_cdn_invalidation_554_cleanup_tree($root);
    }
}

function king_cdn_invalidation_554_run_cloud_backend(string $backend, string $provider): void
{
    $root = sys_get_temp_dir() . '/king_cdn_invalidation_554_' . $backend . '_' . getmypid();
    $objects = king_cdn_invalidation_554_build_objects($provider);
    $mockOptions = ['provider' => $provider];
    $expectedHeadCount = 0;
    $expectedGetCount = 0;

    if ($provider === 'gcs') {
        $mockOptions['expected_access_token'] = 'gcs-token';
    } elseif ($provider === 'azure') {
        $mockOptions['expected_access_token'] = 'azure-token';
    }

    king_cdn_invalidation_554_cleanup_tree($root);
    mkdir($root, 0700, true);
    $mock = king_object_store_s3_mock_start_server(null, '127.0.0.1', $mockOptions);
    $stateDirectory = $mock['state_directory'];

    try {
        $config = [
            'storage_root_path' => $root,
            'primary_backend' => $backend,
            'cdn_config' => [
                'enabled' => true,
                'default_ttl_seconds' => 120,
                'cache_size_mb' => 64,
            ],
            'cloud_credentials' => [
                'api_endpoint' => $mock['endpoint'],
                'verify_tls' => false,
            ],
        ];

        if ($backend === 'cloud_s3') {
            $config['cloud_credentials']['bucket'] = 'cdn-invalidation-s3';
            $config['cloud_credentials']['access_key'] = 'access';
            $config['cloud_credentials']['secret_key'] = 'secret';
            $config['cloud_credentials']['region'] = 'us-east-1';
            $config['cloud_credentials']['path_style'] = true;
        } elseif ($backend === 'cloud_gcs') {
            $config['cloud_credentials']['bucket'] = 'cdn-invalidation-gcs';
            $config['cloud_credentials']['access_token'] = 'gcs-token';
            $config['cloud_credentials']['path_style'] = true;
        } else {
            $config['cloud_credentials']['container'] = 'cdn-invalidation-azure';
            $config['cloud_credentials']['access_token'] = 'azure-token';
        }

        king_cdn_invalidation_554_assert(
            king_object_store_init($config) === true,
            $backend . ' init failed'
        );

        king_cdn_invalidation_554_assert(
            king_cdn_invalidate_cache() === 0,
            $backend . ' did not start with an empty CDN registry'
        );

        foreach ($objects as $object) {
            king_cdn_invalidation_554_assert(
                king_object_store_put($object['id'], $object['payload'], [
                    'content_type' => 'text/plain',
                    'object_type' => 'cache_entry',
                    'cache_policy' => 'smart_cdn',
                ]) === true,
                $backend . ' put failed for ' . $object['id']
            );

            @unlink($root . '/' . $object['id'] . '.meta');
        }
        clearstatcache();

        king_cdn_invalidation_554_run_rounds(
            $objects,
            static function (array $object) use (&$expectedHeadCount): bool {
                $expectedHeadCount++;
                return king_cdn_cache_object($object['id'], ['ttl_sec' => 60]) === true;
            },
            static function (array $object) use (&$expectedGetCount): bool {
                $expectedGetCount++;
                return king_object_store_get($object['id']) === $object['payload'];
            }
        );

        $capture = king_object_store_s3_mock_stop_server($mock);
        $mock = null;

        $headCount = count(array_filter(
            $capture['events'] ?? [],
            static function (array $event) use ($objects): bool {
                if (($event['method'] ?? '') !== 'HEAD') {
                    return false;
                }

                return in_array($event['object_id'] ?? '', array_column($objects, 'id'), true);
            }
        ));
        $getCount = count(array_filter(
            $capture['events'] ?? [],
            static function (array $event) use ($objects): bool {
                if (($event['method'] ?? '') !== 'GET') {
                    return false;
                }

                return in_array($event['object_id'] ?? '', array_column($objects, 'id'), true);
            }
        ));

        king_cdn_invalidation_554_assert(
            $headCount >= $expectedHeadCount,
            $backend . ' direct warm path did not stay on a real HEAD-backed cache path under load'
        );
        king_cdn_invalidation_554_assert(
            $getCount >= $expectedGetCount,
            $backend . ' read-through path did not stay on a real GET-backed fill path under load'
        );
    } finally {
        if (isset($mock) && is_array($mock)) {
            king_object_store_s3_mock_stop_server($mock);
        }
        king_object_store_s3_mock_cleanup_state_directory($stateDirectory);
        king_cdn_invalidation_554_cleanup_tree($root);
    }
}

king_cdn_invalidation_554_run_local_backend('local_fs');
king_cdn_invalidation_554_run_local_backend('distributed');
king_cdn_invalidation_554_run_cloud_backend('cloud_s3', 's3');
king_cdn_invalidation_554_run_cloud_backend('cloud_gcs', 'gcs');
king_cdn_invalidation_554_run_cloud_backend('cloud_azure', 'azure');

echo "OK\n";
?>
--EXPECT--
OK
