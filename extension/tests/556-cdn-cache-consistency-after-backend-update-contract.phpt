--TEST--
King CDN stays consistent after backend updates across local and real cloud object-store backends
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

function king_cdn_update_556_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function king_cdn_update_556_cleanup_tree(string $path): void
{
    if ($path === '' || !file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            king_cdn_update_556_cleanup_tree($path . '/' . $entry);
        }

        @chmod($path, 0700);
        @rmdir($path);
        return;
    }

    @chmod($path, 0600);
    @unlink($path);
}

function king_cdn_update_556_local_backend(string $backend): void
{
    $root = sys_get_temp_dir() . '/king_cdn_update_556_' . $backend . '_' . getmypid();
    $objectId = 'doc-' . str_replace('_', '-', $backend);
    $initialPayload = $backend . '-alpha';
    $updatedPayload = $backend . '-bravo-updated';

    king_cdn_update_556_cleanup_tree($root);
    mkdir($root, 0700, true);

    try {
        king_cdn_update_556_assert(
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
        king_cdn_update_556_assert(
            king_cdn_invalidate_cache() === 0,
            $backend . ' did not start with an empty CDN registry'
        );

        king_cdn_update_556_assert(
            king_object_store_put($objectId, $initialPayload, [
                'content_type' => 'text/plain',
                'object_type' => 'cache_entry',
                'cache_policy' => 'smart_cdn',
            ]) === true,
            $backend . ' initial put failed'
        );
        king_cdn_update_556_assert(
            king_object_store_get($objectId) === $initialPayload,
            $backend . ' initial read did not warm the expected payload'
        );

        $stats = king_object_store_get_stats()['cdn'];
        king_cdn_update_556_assert(
            $stats['cached_object_count'] === 1 && $stats['cached_bytes'] === strlen($initialPayload),
            $backend . ' initial cache stats drifted'
        );

        king_cdn_update_556_assert(
            king_object_store_put($objectId, $updatedPayload, [
                'content_type' => 'text/plain',
                'object_type' => 'cache_entry',
                'cache_policy' => 'smart_cdn',
            ]) === true,
            $backend . ' overwrite failed'
        );

        $stats = king_object_store_get_stats()['cdn'];
        king_cdn_update_556_assert(
            $stats['cached_object_count'] === 0 && $stats['cached_bytes'] === 0,
            $backend . ' overwrite did not invalidate the cached entry'
        );

        king_cdn_update_556_assert(
            king_object_store_get($objectId) === $updatedPayload,
            $backend . ' next read did not return the updated payload'
        );

        $stats = king_object_store_get_stats()['cdn'];
        king_cdn_update_556_assert(
            $stats['cached_object_count'] === 1 && $stats['cached_bytes'] === strlen($updatedPayload),
            $backend . ' updated payload did not repopulate the cache with the new size'
        );

        king_cdn_update_556_assert(
            king_cdn_invalidate_cache($objectId) === 1,
            $backend . ' final invalidate failed'
        );
    } finally {
        king_cdn_update_556_cleanup_tree($root);
    }
}

function king_cdn_update_556_cloud_config(
    string $backend,
    string $provider,
    string $root,
    string $endpoint
): array {
    $config = [
        'storage_root_path' => $root,
        'primary_backend' => $backend,
        'cdn_config' => [
            'enabled' => true,
            'default_ttl_seconds' => 120,
            'cache_size_mb' => 64,
        ],
        'cloud_credentials' => [
            'api_endpoint' => $endpoint,
            'verify_tls' => false,
        ],
    ];

    if ($backend === 'cloud_s3') {
        $config['cloud_credentials']['bucket'] = 'cdn-update-s3';
        $config['cloud_credentials']['access_key'] = 'access';
        $config['cloud_credentials']['secret_key'] = 'secret';
        $config['cloud_credentials']['region'] = 'us-east-1';
        $config['cloud_credentials']['path_style'] = true;
    } elseif ($backend === 'cloud_gcs') {
        $config['cloud_credentials']['bucket'] = 'cdn-update-gcs';
        $config['cloud_credentials']['access_token'] = 'gcs-token';
        $config['cloud_credentials']['path_style'] = true;
    } else {
        $config['cloud_credentials']['container'] = 'cdn-update-azure';
        $config['cloud_credentials']['access_token'] = 'azure-token';
    }

    return $config;
}

function king_cdn_update_556_cloud_options(string $provider, array $forcedResponses = []): array
{
    $options = [
        'provider' => $provider,
        'forced_responses' => $forcedResponses,
    ];

    if ($provider === 'gcs') {
        $options['expected_access_token'] = 'gcs-token';
    } elseif ($provider === 'azure') {
        $options['expected_access_token'] = 'azure-token';
    }

    return $options;
}

function king_cdn_update_556_cloud_target(string $backend, string $objectId): string
{
    return match ($backend) {
        'cloud_s3' => '/cdn-update-s3/' . $objectId,
        'cloud_gcs' => '/cdn-update-gcs/' . $objectId,
        'cloud_azure' => '/cdn-update-azure/' . $objectId,
        default => throw new InvalidArgumentException('unknown backend ' . $backend),
    };
}

function king_cdn_update_556_failure_code(string $provider): string
{
    return match ($provider) {
        's3' => 'SlowDown',
        'gcs' => 'TooManyRequests',
        'azure' => 'ServerBusy',
        default => 'ServerBusy',
    };
}

function king_cdn_update_556_count_events(array $capture, string $objectId, string $method): int
{
    return count(array_filter(
        $capture['events'] ?? [],
        static fn(array $event): bool =>
            ($event['object_id'] ?? '') === $objectId
            && ($event['method'] ?? '') === $method
    ));
}

function king_cdn_update_556_count_forced_status(array $capture, string $objectId, int $status): int
{
    return count(array_filter(
        $capture['events'] ?? [],
        static fn(array $event): bool =>
            ($event['object_id'] ?? '') === $objectId
            && (int) ($event['forced_status'] ?? 0) === $status
    ));
}

function king_cdn_update_556_cloud_backend(string $backend, string $provider): void
{
    $root = sys_get_temp_dir() . '/king_cdn_update_556_' . $backend . '_' . getmypid();
    $stateDirectory = sys_get_temp_dir() . '/king_cdn_update_state_556_' . $backend . '_' . getmypid();
    $objectId = 'doc-' . $provider;
    $initialPayload = $backend . '-alpha';
    $updatedPayload = $backend . '-bravo-updated';
    $failureStatus = 503;

    king_cdn_update_556_cleanup_tree($root);
    king_cdn_update_556_cleanup_tree($stateDirectory);
    mkdir($root, 0700, true);

    $warmServer = king_object_store_s3_mock_start_server(
        $stateDirectory,
        '127.0.0.1',
        king_cdn_update_556_cloud_options($provider)
    );

    try {
        king_cdn_update_556_assert(
            king_object_store_init(
                king_cdn_update_556_cloud_config($backend, $provider, $root, $warmServer['endpoint'])
            ) === true,
            $backend . ' init failed'
        );
        king_cdn_update_556_assert(
            king_cdn_invalidate_cache() === 0,
            $backend . ' did not start with an empty CDN registry'
        );

        king_cdn_update_556_assert(
            king_object_store_put($objectId, $initialPayload, [
                'content_type' => 'text/plain',
                'object_type' => 'cache_entry',
                'cache_policy' => 'smart_cdn',
            ]) === true,
            $backend . ' initial put failed'
        );
        king_cdn_update_556_assert(
            king_object_store_get($objectId) === $initialPayload,
            $backend . ' initial warm read failed'
        );

        $stats = king_object_store_get_stats()['cdn'];
        king_cdn_update_556_assert(
            $stats['cached_object_count'] === 1 && $stats['cached_bytes'] === strlen($initialPayload),
            $backend . ' initial cache stats drifted'
        );

        king_cdn_update_556_assert(
            king_object_store_put($objectId, $updatedPayload, [
                'content_type' => 'text/plain',
                'object_type' => 'cache_entry',
                'cache_policy' => 'smart_cdn',
            ]) === true,
            $backend . ' overwrite failed'
        );

        $stats = king_object_store_get_stats()['cdn'];
        king_cdn_update_556_assert(
            $stats['cached_object_count'] === 0 && $stats['cached_bytes'] === 0,
            $backend . ' overwrite did not invalidate the cached entry'
        );

        $warmCapture = king_object_store_s3_mock_stop_server($warmServer);
        $warmServer = null;
        king_cdn_update_556_assert(
            king_cdn_update_556_count_events($warmCapture, $objectId, 'PUT') >= 2,
            $backend . ' backend did not observe both writes'
        );
        king_cdn_update_556_assert(
            king_cdn_update_556_count_events($warmCapture, $objectId, 'GET') >= 1,
            $backend . ' backend did not observe the initial warm read'
        );

        $failureServer = king_object_store_s3_mock_start_server(
            $stateDirectory,
            '127.0.0.1',
            king_cdn_update_556_cloud_options($provider, [[
                'method' => 'GET',
                'target' => king_cdn_update_556_cloud_target($backend, $objectId),
                'status' => $failureStatus,
                'error_code' => king_cdn_update_556_failure_code($provider),
                'error_message' => 'Backend temporarily unavailable after overwrite.',
            ]])
        );

        try {
            king_cdn_update_556_assert(
                king_object_store_init(
                    king_cdn_update_556_cloud_config($backend, $provider, $root, $failureServer['endpoint'])
                ) === true,
                $backend . ' failure-phase init failed'
            );

            try {
                king_object_store_get($objectId);
                throw new RuntimeException($backend . ' served a stale pre-update body after the overwrite invalidated cache state');
            } catch (Throwable $exception) {
                king_cdn_update_556_assert(
                    $exception instanceof King\SystemException,
                    $backend . ' overwrite failure did not stay a public system exception'
                );
            }
        } finally {
            $failureCapture = king_object_store_s3_mock_stop_server($failureServer);
        }

        king_cdn_update_556_assert(
            king_cdn_update_556_count_forced_status($failureCapture, $objectId, $failureStatus) >= 1,
            $backend . ' failure phase did not hit the real backend after overwrite'
        );

        $freshServer = king_object_store_s3_mock_start_server(
            $stateDirectory,
            '127.0.0.1',
            king_cdn_update_556_cloud_options($provider)
        );

        try {
            king_cdn_update_556_assert(
                king_object_store_init(
                    king_cdn_update_556_cloud_config($backend, $provider, $root, $freshServer['endpoint'])
                ) === true,
                $backend . ' refresh-phase init failed'
            );
            king_cdn_update_556_assert(
                king_object_store_get($objectId) === $updatedPayload,
                $backend . ' refresh read did not return the updated payload'
            );

            $stats = king_object_store_get_stats()['cdn'];
            king_cdn_update_556_assert(
                $stats['cached_object_count'] === 1 && $stats['cached_bytes'] === strlen($updatedPayload),
                $backend . ' updated payload did not repopulate the cache with the new size'
            );
            king_cdn_update_556_assert(
                king_cdn_invalidate_cache($objectId) === 1,
                $backend . ' final invalidate failed'
            );
        } finally {
            $freshCapture = king_object_store_s3_mock_stop_server($freshServer);
        }

        king_cdn_update_556_assert(
            king_cdn_update_556_count_events($freshCapture, $objectId, 'GET') >= 1,
            $backend . ' fresh phase did not read the updated origin object'
        );
    } finally {
        if (isset($warmServer) && is_array($warmServer)) {
            king_object_store_s3_mock_stop_server($warmServer);
        }
        king_cdn_update_556_cleanup_tree($stateDirectory);
        king_cdn_update_556_cleanup_tree($root);
    }
}

king_cdn_update_556_local_backend('local_fs');
king_cdn_update_556_local_backend('distributed');
king_cdn_update_556_cloud_backend('cloud_s3', 's3');
king_cdn_update_556_cloud_backend('cloud_gcs', 'gcs');
king_cdn_update_556_cloud_backend('cloud_azure', 'azure');

echo "OK\n";
?>
--EXPECT--
OK
