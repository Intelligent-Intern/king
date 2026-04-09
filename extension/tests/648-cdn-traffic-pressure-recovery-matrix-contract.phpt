--TEST--
King CDN verifies fill invalidation TTL recovery stale-on-error and memory-pressure behavior under sustained traffic across local and real cloud backends
--SKIPIF--
<?php
if (!function_exists('proc_open') || !function_exists('stream_socket_server')) {
    echo "skip proc_open and stream_socket_server are required";
}
?>
--INI--
king.security_allow_config_override=1
king.cdn_cache_memory_limit_mb=1
--FILE--
<?php
require __DIR__ . '/object_store_s3_mock_helper.inc';

function king_cdn_648_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function king_cdn_648_cleanup_tree(string $path): void
{
    if ($path === '' || !file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            king_cdn_648_cleanup_tree($path . '/' . $entry);
        }

        @chmod($path, 0700);
        @rmdir($path);
        return;
    }

    @chmod($path, 0600);
    @unlink($path);
}

function king_cdn_648_build_objects(string $prefix): array
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

function king_cdn_648_invalidation_order(int $round, int $objectCount): array
{
    if ($round === 0) {
        return array_reverse(range(0, $objectCount - 1));
    }

    if ($round === 1) {
        return [1, 3, 5, 0, 2, 4];
    }

    return [4, 2, 0, 5, 3, 1];
}

function king_cdn_648_wait_until_expired(int $timeoutMs = 4000): array
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

function king_cdn_648_count_forced_status(array $capture, string $objectId, int $status): int
{
    return count(array_filter(
        $capture['events'] ?? [],
        static fn(array $event): bool =>
            ($event['object_id'] ?? '') === $objectId
            && (int) ($event['forced_status'] ?? 0) === $status
    ));
}

function king_cdn_648_run_load_ttl_restart_local(): void
{
    $root = sys_get_temp_dir() . '/king_cdn_648_local_' . getmypid();
    $objects = king_cdn_648_build_objects('load');
    $expectedBytes = array_sum(array_column($objects, 'size'));
    $config = [
        'storage_root_path' => $root,
        'primary_backend' => 'local_fs',
        'cdn_config' => [
            'enabled' => true,
            'default_ttl_seconds' => 1,
            'cache_size_mb' => 64,
            'serve_stale_on_error' => true,
        ],
    ];

    king_cdn_648_cleanup_tree($root);
    mkdir($root, 0700, true);

    try {
        king_cdn_648_assert(king_object_store_init($config) === true, 'local load init failed');
        king_cdn_648_assert(king_cdn_invalidate_cache() === 0, 'local load did not start with an empty CDN registry');

        foreach ($objects as $object) {
            king_cdn_648_assert(
                king_object_store_put($object['id'], $object['payload'], [
                    'content_type' => 'text/plain',
                    'object_type' => 'cache_entry',
                    'cache_policy' => 'smart_cdn',
                ]) === true,
                'local load put failed for ' . $object['id']
            );
        }

        for ($round = 0; $round < 3; $round++) {
            foreach ($objects as $index => $object) {
                if ((($index + $round) % 2) === 0) {
                    king_cdn_648_assert(
                        king_cdn_cache_object($object['id'], ['ttl_sec' => 60]) === true,
                        'local load direct warm failed for ' . $object['id'] . ' in round ' . $round
                    );
                    continue;
                }

                king_cdn_648_assert(
                    king_object_store_get($object['id']) === $object['payload'],
                    'local load read-through warm failed for ' . $object['id'] . ' in round ' . $round
                );
            }

            $stats = king_object_store_get_stats()['cdn'];
            king_cdn_648_assert(
                ($stats['cached_object_count'] ?? -1) === 6,
                'local load cached object count drifted after warm in round ' . $round
            );
            king_cdn_648_assert(
                ($stats['cached_bytes'] ?? -1) === $expectedBytes,
                'local load cached bytes drifted after warm in round ' . $round
            );

            $remainingCount = 6;
            $remainingBytes = $expectedBytes;

            foreach (king_cdn_648_invalidation_order($round, 6) as $index) {
                $object = $objects[$index];
                king_cdn_648_assert(
                    king_cdn_invalidate_cache($object['id']) === 1,
                    'local load first invalidate did not remove ' . $object['id'] . ' in round ' . $round
                );
                king_cdn_648_assert(
                    king_cdn_invalidate_cache($object['id']) === 0,
                    'local load second invalidate was not empty for ' . $object['id'] . ' in round ' . $round
                );

                $remainingCount--;
                $remainingBytes -= $object['size'];
                $stats = king_object_store_get_stats()['cdn'];
                king_cdn_648_assert(
                    ($stats['cached_object_count'] ?? -1) === $remainingCount,
                    'local load cached count drifted while draining round ' . $round
                );
                king_cdn_648_assert(
                    ($stats['cached_bytes'] ?? -1) === $remainingBytes,
                    'local load cached bytes drifted while draining round ' . $round
                );
            }

            king_cdn_648_assert(
                king_cdn_invalidate_cache() === 0,
                'local load flush-all was non-empty after round ' . $round
            );
        }

        $ttlObjectId = 'ttl-doc';
        $ttlPayload = 'ttl-payload';
        king_cdn_648_assert(
            king_object_store_put($ttlObjectId, $ttlPayload, [
                'content_type' => 'text/plain',
                'object_type' => 'cache_entry',
                'cache_policy' => 'smart_cdn',
            ]) === true,
            'local ttl put failed'
        );
        king_cdn_648_assert(
            king_object_store_get($ttlObjectId) === $ttlPayload,
            'local ttl warm read failed'
        );

        $expiredStats = king_cdn_648_wait_until_expired();
        king_cdn_648_assert(
            ($expiredStats['cached_object_count'] ?? -1) === 0 && ($expiredStats['cached_bytes'] ?? -1) === 0,
            'local ttl expiry did not drain the cache state'
        );

        $restartObjectId = 'restart-doc';
        $restartPayload = 'restart-payload';
        king_cdn_648_assert(
            king_object_store_put($restartObjectId, $restartPayload, [
                'content_type' => 'text/plain',
                'object_type' => 'cache_entry',
                'cache_policy' => 'smart_cdn',
            ]) === true,
            'local restart put failed'
        );
        king_cdn_648_assert(
            king_object_store_get($restartObjectId) === $restartPayload,
            'local restart warm read failed'
        );
        $beforeRestart = king_object_store_get_stats()['cdn'];
        king_cdn_648_assert(
            ($beforeRestart['cached_object_count'] ?? -1) === 1 && ($beforeRestart['cached_bytes'] ?? -1) === strlen($restartPayload),
            'local restart warm stats drifted before re-init'
        );

        king_cdn_648_assert(
            king_object_store_init($config) === true,
            'local restart re-init failed'
        );
        $afterRestart = king_object_store_get_stats()['cdn'];
        king_cdn_648_assert(
            ($afterRestart['cached_object_count'] ?? -1) === 0 && ($afterRestart['cached_bytes'] ?? -1) === 0,
            'local restart did not start with an empty runtime CDN cache'
        );
        king_cdn_648_assert(
            array_key_exists('latest_cached_at', $afterRestart),
            'local restart stats lost latest_cached_at field'
        );
        king_cdn_648_assert(
            king_object_store_get($restartObjectId) === $restartPayload,
            'local restart recovery read failed'
        );
        $afterRecovery = king_object_store_get_stats()['cdn'];
        king_cdn_648_assert(
            ($afterRecovery['cached_object_count'] ?? -1) === 1 && ($afterRecovery['cached_bytes'] ?? -1) === strlen($restartPayload),
            'local restart recovery did not re-warm the expected cache footprint'
        );
    } finally {
        king_cdn_648_cleanup_tree($root . '.gone');
        king_cdn_648_cleanup_tree($root);
    }
}

function king_cdn_648_run_cloud_stale_s3(): void
{
    $root = sys_get_temp_dir() . '/king_cdn_648_cloud_s3_' . getmypid();
    $stateDirectory = sys_get_temp_dir() . '/king_cdn_648_cloud_s3_state_' . getmypid();
    $staleObjectId = 'stale-s3';
    $headOnlyObjectId = 'head-only-s3';
    $stalePayload = 'cloud-stale-payload';
    $headOnlyPayload = 'cloud-head-only-payload';
    $failureStatus = 503;

    king_cdn_648_cleanup_tree($root);
    king_cdn_648_cleanup_tree($stateDirectory);
    mkdir($root, 0700, true);

    $warmServer = king_object_store_s3_mock_start_server($stateDirectory, '127.0.0.1');

    try {
        $config = [
            'storage_root_path' => $root,
            'primary_backend' => 'cloud_s3',
            'cdn_config' => [
                'enabled' => true,
                'default_ttl_seconds' => 1,
                'cache_size_mb' => 64,
                'serve_stale_on_error' => true,
            ],
            'cloud_credentials' => [
                'api_endpoint' => $warmServer['endpoint'],
                'bucket' => 'cdn-batch18-s3',
                'access_key' => 'access',
                'secret_key' => 'secret',
                'region' => 'us-east-1',
                'path_style' => true,
                'verify_tls' => false,
            ],
        ];

        king_cdn_648_assert(king_object_store_init($config) === true, 'cloud warm init failed');
        king_cdn_648_assert(king_cdn_invalidate_cache() === 0, 'cloud warm did not start with an empty CDN registry');

        king_cdn_648_assert(
            king_object_store_put($staleObjectId, $stalePayload, [
                'content_type' => 'text/plain',
                'object_type' => 'cache_entry',
                'cache_policy' => 'smart_cdn',
            ]) === true,
            'cloud stale object put failed'
        );
        king_cdn_648_assert(
            king_object_store_put($headOnlyObjectId, $headOnlyPayload, [
                'content_type' => 'text/plain',
                'object_type' => 'cache_entry',
                'cache_policy' => 'smart_cdn',
            ]) === true,
            'cloud head-only object put failed'
        );

        @unlink($root . '/' . $staleObjectId . '.meta');
        @unlink($root . '/' . $headOnlyObjectId . '.meta');
        clearstatcache();

        king_cdn_648_assert(
            king_object_store_get($staleObjectId) === $stalePayload,
            'cloud stale object warm read failed'
        );
        king_cdn_648_assert(
            king_cdn_cache_object($headOnlyObjectId, ['ttl_sec' => 1]) === true,
            'cloud head-only object direct warm failed'
        );

        king_object_store_s3_mock_stop_server($warmServer);
        $warmServer = null;

        $expiredStats = king_cdn_648_wait_until_expired();
        king_cdn_648_assert(
            ($expiredStats['cached_object_count'] ?? -1) === 0 && ($expiredStats['cached_bytes'] ?? -1) === 0,
            'cloud expiry did not drain runtime cache stats before failure phase'
        );

        $failureServer = king_object_store_s3_mock_start_server(
            $stateDirectory,
            '127.0.0.1',
            [
                'forced_responses' => [
                    [
                        'method' => 'GET',
                        'target' => '/cdn-batch18-s3/' . $staleObjectId,
                        'status' => $failureStatus,
                        'error_code' => 'SlowDown',
                        'error_message' => 'Backend temporarily unavailable for stale fallback proof.',
                    ],
                    [
                        'method' => 'GET',
                        'target' => '/cdn-batch18-s3/' . $headOnlyObjectId,
                        'status' => $failureStatus,
                        'error_code' => 'SlowDown',
                        'error_message' => 'Backend temporarily unavailable for head-only failure proof.',
                    ],
                ],
            ]
        );

        try {
            $config['cloud_credentials']['api_endpoint'] = $failureServer['endpoint'];
            king_cdn_648_assert(
                king_object_store_init($config) === true,
                'cloud failure-phase init failed'
            );

            king_cdn_648_assert(
                king_object_store_get($staleObjectId) === $stalePayload,
                'cloud stale fallback did not serve the retained stale payload'
            );

            $stream = fopen('php://temp', 'w+');
            king_cdn_648_assert(
                king_object_store_get_to_stream($staleObjectId, $stream) === true,
                'cloud stale stream fallback failed'
            );
            rewind($stream);
            king_cdn_648_assert(
                stream_get_contents($stream) === $stalePayload,
                'cloud stale stream fallback payload drifted'
            );
            fclose($stream);

            try {
                king_object_store_get($headOnlyObjectId);
                throw new RuntimeException('cloud head-only object unexpectedly served stale payload without retained bytes');
            } catch (Throwable $e) {
                king_cdn_648_assert(
                    $e instanceof King\SystemException,
                    'cloud head-only failure did not stay a public system exception'
                );
            }

            $afterFailureStats = king_object_store_get_stats()['cdn'];
            king_cdn_648_assert(
                ($afterFailureStats['cached_object_count'] ?? -1) === 0 && ($afterFailureStats['cached_bytes'] ?? -1) === 0,
                'cloud stale fallback incorrectly repopulated hot-cache counters'
            );

            king_cdn_648_assert(
                king_cdn_invalidate_cache($staleObjectId) === 1,
                'cloud stale object invalidate failed'
            );
            king_cdn_648_assert(
                king_cdn_invalidate_cache($headOnlyObjectId) === 1,
                'cloud head-only object invalidate failed'
            );
        } finally {
            $failureCapture = king_object_store_s3_mock_stop_server($failureServer);
        }

        king_cdn_648_assert(
            king_cdn_648_count_forced_status($failureCapture, $staleObjectId, $failureStatus) >= 2,
            'cloud stale object did not hit the failing backend before stale fallback returned'
        );
        king_cdn_648_assert(
            king_cdn_648_count_forced_status($failureCapture, $headOnlyObjectId, $failureStatus) >= 1,
            'cloud head-only object did not hit the failing backend before error surfaced'
        );
    } finally {
        if (isset($warmServer) && is_array($warmServer)) {
            king_object_store_s3_mock_stop_server($warmServer);
        }
        king_object_store_s3_mock_cleanup_state_directory($stateDirectory);
        king_cdn_648_cleanup_tree($root);
    }
}

function king_cdn_648_run_memory_pressure_local(): void
{
    $root = sys_get_temp_dir() . '/king_cdn_648_memory_' . getmypid();
    $gone = $root . '.gone';
    $payloadSize = 400 * 1024;
    $objects = [
        'doc-a' => str_repeat('A', $payloadSize),
        'doc-b' => str_repeat('B', $payloadSize),
        'doc-c' => str_repeat('C', $payloadSize),
    ];

    foreach ([$gone, $root] as $path) {
        king_cdn_648_cleanup_tree($path);
    }
    mkdir($root, 0700, true);

    try {
        king_cdn_648_assert(
            king_object_store_init([
                'storage_root_path' => $root,
                'primary_backend' => 'local_fs',
                'cdn_config' => [
                    'enabled' => true,
                    'cache_size_mb' => 64,
                    'default_ttl_seconds' => 300,
                    'serve_stale_on_error' => true,
                ],
            ]) === true,
            'memory-pressure init failed'
        );

        foreach ($objects as $objectId => $payload) {
            king_cdn_648_assert(
                king_object_store_put($objectId, $payload, [
                    'content_type' => 'text/plain',
                    'object_type' => 'cache_entry',
                    'cache_policy' => 'smart_cdn',
                ]) === true,
                'memory-pressure put failed for ' . $objectId
            );
        }

        foreach ($objects as $objectId => $payload) {
            king_cdn_648_assert(
                king_object_store_get($objectId) === $payload,
                'memory-pressure warm read failed for ' . $objectId
            );
        }

        $finalStats = king_object_store_get_stats()['cdn'];
        king_cdn_648_assert(
            ($finalStats['cached_object_count'] ?? -1) === 2,
            'memory-pressure resident object count exceeded bounded target'
        );
        king_cdn_648_assert(
            ($finalStats['cached_bytes'] ?? -1) === ($payloadSize * 2),
            'memory-pressure resident byte count exceeded bounded target'
        );

        king_cdn_648_assert(rename($root, $gone), 'memory-pressure failed to take local backend offline');
        clearstatcache();

        try {
            king_object_store_get('doc-a');
            $docAClass = 'no-exception';
        } catch (Throwable $e) {
            $docAClass = get_class($e);
        }

        $docBServedStale = king_object_store_get('doc-b') === $objects['doc-b'];
        $docCServedStale = king_object_store_get('doc-c') === $objects['doc-c'];

        king_cdn_648_assert(
            $docAClass === 'King\\SystemException',
            'memory-pressure oldest entry did not fail closed after eviction and backend outage'
        );
        king_cdn_648_assert(
            $docBServedStale === true && $docCServedStale === true,
            'memory-pressure retained entries were not served stale during backend outage'
        );
    } finally {
        if (is_dir($gone) && !is_dir($root)) {
            @rename($gone, $root);
        }

        foreach ([$gone, $root] as $path) {
            king_cdn_648_cleanup_tree($path);
        }
    }
}

king_cdn_648_run_load_ttl_restart_local();
king_cdn_648_run_cloud_stale_s3();
king_cdn_648_run_memory_pressure_local();

echo "OK\n";
?>
--EXPECT--
OK
