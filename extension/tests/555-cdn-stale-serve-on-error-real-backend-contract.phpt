--TEST--
King smart_cdn full reads serve retained stale bodies on real cloud backend failures while head-only warm entries still fail honestly
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

function king_cdn_stale_555_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function king_cdn_stale_555_cleanup_tree(string $path): void
{
    if ($path === '' || !file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            king_cdn_stale_555_cleanup_tree($path . '/' . $entry);
        }

        @chmod($path, 0700);
        @rmdir($path);
        return;
    }

    @chmod($path, 0600);
    @unlink($path);
}

function king_cdn_stale_555_backend_config(
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
            'default_ttl_seconds' => 1,
            'cache_size_mb' => 64,
        ],
        'cloud_credentials' => [
            'api_endpoint' => $endpoint,
            'verify_tls' => false,
        ],
    ];

    if ($backend === 'cloud_s3') {
        $config['cloud_credentials']['bucket'] = 'cdn-stale-s3';
        $config['cloud_credentials']['access_key'] = 'access';
        $config['cloud_credentials']['secret_key'] = 'secret';
        $config['cloud_credentials']['region'] = 'us-east-1';
        $config['cloud_credentials']['path_style'] = true;
    } elseif ($backend === 'cloud_gcs') {
        $config['cloud_credentials']['bucket'] = 'cdn-stale-gcs';
        $config['cloud_credentials']['access_token'] = 'gcs-token';
        $config['cloud_credentials']['path_style'] = true;
    } else {
        $config['cloud_credentials']['container'] = 'cdn-stale-azure';
        $config['cloud_credentials']['access_token'] = 'azure-token';
    }

    return $config;
}

function king_cdn_stale_555_mock_options(string $provider, array $forcedResponses = []): array
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

function king_cdn_stale_555_object_target(string $backend, string $objectId): string
{
    return match ($backend) {
        'cloud_s3' => '/cdn-stale-s3/' . $objectId,
        'cloud_gcs' => '/cdn-stale-gcs/' . $objectId,
        'cloud_azure' => '/cdn-stale-azure/' . $objectId,
        default => throw new InvalidArgumentException('unknown backend ' . $backend),
    };
}

function king_cdn_stale_555_failure_code(string $provider): string
{
    return match ($provider) {
        's3' => 'SlowDown',
        'gcs' => 'TooManyRequests',
        'azure' => 'ServerBusy',
        default => 'ServerBusy',
    };
}

function king_cdn_stale_555_wait_until_expired(int $timeoutMs = 4000): array
{
    $deadline = microtime(true) + ($timeoutMs / 1000);
    $stats = [];

    do {
        $stats = king_object_store_get_stats()['cdn'];
        if (($stats['cached_object_count'] ?? -1) === 0 && ($stats['cached_bytes'] ?? -1) === 0) {
            return $stats;
        }

        usleep(100000);
    } while (microtime(true) < $deadline);

    return $stats;
}

function king_cdn_stale_555_count_events(array $capture, string $objectId, string $method): int
{
    return count(array_filter(
        $capture['events'] ?? [],
        static fn(array $event): bool =>
            ($event['object_id'] ?? '') === $objectId
            && ($event['method'] ?? '') === $method
    ));
}

function king_cdn_stale_555_count_forced_status(array $capture, string $objectId, int $status): int
{
    return count(array_filter(
        $capture['events'] ?? [],
        static fn(array $event): bool =>
            ($event['object_id'] ?? '') === $objectId
            && (int) ($event['forced_status'] ?? 0) === $status
    ));
}

function king_cdn_stale_555_run_cloud_backend(string $backend, string $provider): void
{
    $root = sys_get_temp_dir() . '/king_cdn_stale_555_' . $backend . '_' . getmypid();
    $stateDirectory = sys_get_temp_dir() . '/king_cdn_stale_state_555_' . $backend . '_' . getmypid();
    $staleObjectId = 'stale-' . $provider;
    $headOnlyObjectId = 'head-only-' . $provider;
    $stalePayload = $backend . '-stale-body';
    $headOnlyPayload = $backend . '-head-only';
    $failureStatus = 503;

    king_cdn_stale_555_cleanup_tree($root);
    king_cdn_stale_555_cleanup_tree($stateDirectory);
    mkdir($root, 0700, true);

    $warmServer = king_object_store_s3_mock_start_server(
        $stateDirectory,
        '127.0.0.1',
        king_cdn_stale_555_mock_options($provider)
    );

    try {
        king_cdn_stale_555_assert(
            king_object_store_init(
                king_cdn_stale_555_backend_config($backend, $provider, $root, $warmServer['endpoint'])
            ) === true,
            $backend . ' init failed'
        );
        king_cdn_stale_555_assert(
            king_cdn_invalidate_cache() === 0,
            $backend . ' did not start with an empty CDN registry'
        );

        foreach ([
            [$staleObjectId, $stalePayload],
            [$headOnlyObjectId, $headOnlyPayload],
        ] as [$objectId, $payload]) {
            king_cdn_stale_555_assert(
                king_object_store_put($objectId, $payload, [
                    'content_type' => 'text/plain',
                    'object_type' => 'cache_entry',
                    'cache_policy' => 'smart_cdn',
                ]) === true,
                $backend . ' put failed for ' . $objectId
            );
            @unlink($root . '/' . $objectId . '.meta');
        }
        clearstatcache();

        king_cdn_stale_555_assert(
            king_object_store_get($staleObjectId) === $stalePayload,
            $backend . ' did not retain the stale object body on the successful read path'
        );
        king_cdn_stale_555_assert(
            king_cdn_cache_object($headOnlyObjectId, ['ttl_sec' => 1]) === true,
            $backend . ' direct warm failed for the head-only object'
        );

        $warmCapture = king_object_store_s3_mock_stop_server($warmServer);
        $warmServer = null;

        king_cdn_stale_555_assert(
            king_cdn_stale_555_count_events($warmCapture, $staleObjectId, 'GET') >= 1,
            $backend . ' stale object did not perform a real backend GET before expiry'
        );
        king_cdn_stale_555_assert(
            king_cdn_stale_555_count_events($warmCapture, $headOnlyObjectId, 'HEAD') >= 1,
            $backend . ' head-only object did not perform a real backend HEAD before expiry'
        );

        $expiredStats = king_cdn_stale_555_wait_until_expired();
        king_cdn_stale_555_assert(
            ($expiredStats['cached_object_count'] ?? null) === 0,
            $backend . ' cache entries did not expire before the failure phase'
        );
        king_cdn_stale_555_assert(
            ($expiredStats['cached_bytes'] ?? null) === 0,
            $backend . ' expired entries still counted cached bytes before the failure phase'
        );

        $failureServer = king_object_store_s3_mock_start_server(
            $stateDirectory,
            '127.0.0.1',
            king_cdn_stale_555_mock_options($provider, [
                [
                    'method' => 'GET',
                    'target' => king_cdn_stale_555_object_target($backend, $staleObjectId),
                    'status' => $failureStatus,
                    'error_code' => king_cdn_stale_555_failure_code($provider),
                    'error_message' => 'Backend temporarily unavailable for stale fallback proof.',
                ],
                [
                    'method' => 'GET',
                    'target' => king_cdn_stale_555_object_target($backend, $headOnlyObjectId),
                    'status' => $failureStatus,
                    'error_code' => king_cdn_stale_555_failure_code($provider),
                    'error_message' => 'Backend temporarily unavailable for honest head-only proof.',
                ],
            ])
        );

        try {
            king_cdn_stale_555_assert(
                king_object_store_init(
                    king_cdn_stale_555_backend_config($backend, $provider, $root, $failureServer['endpoint'])
                ) === true,
                $backend . ' failure-phase init failed'
            );

            king_cdn_stale_555_assert(
                king_object_store_get($staleObjectId) === $stalePayload,
                $backend . ' did not serve the retained stale body on backend failure'
            );

            $stream = fopen('php://temp', 'w+');
            king_cdn_stale_555_assert(
                king_object_store_get_to_stream($staleObjectId, $stream) === true,
                $backend . ' stream stale fallback failed'
            );
            rewind($stream);
            king_cdn_stale_555_assert(
                stream_get_contents($stream) === $stalePayload,
                $backend . ' stream stale fallback returned the wrong payload'
            );
            fclose($stream);

            try {
                king_object_store_get($headOnlyObjectId);
                throw new RuntimeException($backend . ' head-only entry unexpectedly served stale without a retained body');
            } catch (Throwable $exception) {
                king_cdn_stale_555_assert(
                    $exception instanceof King\SystemException,
                    $backend . ' head-only failure did not stay a public system exception'
                );
                king_cdn_stale_555_assert(
                    str_starts_with(
                        $exception->getMessage(),
                        'Object-store primary backend throttled the operation; retry with backoff.'
                    )
                    || str_contains($exception->getMessage(), 'Primary object-store backend read failed.'),
                    $backend . ' head-only failure did not keep the public backend-failure classification'
                );
            }

            $afterFailureStats = king_object_store_get_stats()['cdn'];
            king_cdn_stale_555_assert(
                ($afterFailureStats['cached_object_count'] ?? null) === 0,
                $backend . ' stale service incorrectly made expired entries fresh again'
            );
            king_cdn_stale_555_assert(
                ($afterFailureStats['cached_bytes'] ?? null) === 0,
                $backend . ' stale service incorrectly restored expired bytes into hot-cache stats'
            );

            king_cdn_stale_555_assert(
                king_cdn_invalidate_cache($staleObjectId) === 1,
                $backend . ' explicit invalidation did not remove the expired stale entry'
            );
            king_cdn_stale_555_assert(
                king_cdn_invalidate_cache($headOnlyObjectId) === 1,
                $backend . ' explicit invalidation did not remove the expired head-only entry'
            );
        } finally {
            $failureCapture = king_object_store_s3_mock_stop_server($failureServer);
        }

        king_cdn_stale_555_assert(
            king_cdn_stale_555_count_forced_status($failureCapture, $staleObjectId, $failureStatus) >= 2,
            $backend . ' stale object did not hit the failing backend before stale fallback returned'
        );
        king_cdn_stale_555_assert(
            king_cdn_stale_555_count_forced_status($failureCapture, $headOnlyObjectId, $failureStatus) >= 1,
            $backend . ' head-only object did not hit the failing backend before the public exception surfaced'
        );
    } finally {
        if (isset($warmServer) && is_array($warmServer)) {
            king_object_store_s3_mock_stop_server($warmServer);
        }
        king_cdn_stale_555_cleanup_tree($stateDirectory);
        king_cdn_stale_555_cleanup_tree($root);
    }
}

king_cdn_stale_555_run_cloud_backend('cloud_s3', 's3');
king_cdn_stale_555_run_cloud_backend('cloud_gcs', 'gcs');
king_cdn_stale_555_run_cloud_backend('cloud_azure', 'azure');

echo "OK\n";
?>
--EXPECT--
OK
