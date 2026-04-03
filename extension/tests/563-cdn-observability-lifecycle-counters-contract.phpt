--TEST--
King CDN observability counters stay honest across stale serves invalidation expiry and memory eviction
--INI--
king.security_allow_config_override=1
king.cdn_cache_memory_limit_mb=1
--FILE--
<?php
function king_cdn_observability_563_cleanup_tree(string $path): void
{
    if ($path === '' || !file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            king_cdn_observability_563_cleanup_tree($path . '/' . $entry);
        }

        @chmod($path, 0700);
        @rmdir($path);
        return;
    }

    @chmod($path, 0600);
    @unlink($path);
}

function king_cdn_observability_563_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . '/king_cdn_observability_563_' . getmypid();
$gone = $root . '.gone';
$retainedPayload = 'retained-body';
$metaPayload = 'meta-only-body';
$ttlPayload = 'ttl-body';
$largePayloadSize = 400 * 1024;
$largePayloads = [
    'doc-a' => str_repeat('A', $largePayloadSize),
    'doc-b' => str_repeat('B', $largePayloadSize),
    'doc-c' => str_repeat('C', $largePayloadSize),
];

foreach ([$gone, $root] as $path) {
    king_cdn_observability_563_cleanup_tree($path);
}
mkdir($root, 0700, true);

try {
    king_cdn_observability_563_assert(king_object_store_init([
        'storage_root_path' => $root,
        'primary_backend' => 'local_fs',
        'cdn_config' => [
            'enabled' => true,
            'cache_size_mb' => 64,
            'default_ttl_seconds' => 300,
        ],
    ]), 'object-store init failed');

    foreach ([
        'retained' => $retainedPayload,
        'meta' => $metaPayload,
        'ttl' => $ttlPayload,
    ] + $largePayloads as $objectId => $payload) {
        king_cdn_observability_563_assert(king_object_store_put($objectId, $payload, [
            'content_type' => 'text/plain',
            'object_type' => 'cache_entry',
            'cache_policy' => 'smart_cdn',
        ]), $objectId . ' put failed');
    }

    king_cdn_observability_563_assert(
        king_object_store_get('retained') === $retainedPayload,
        'retained object did not warm through a full backend read'
    );
    king_cdn_observability_563_assert(
        king_cdn_cache_object('meta'),
        'metadata-only warm for meta failed'
    );
    king_cdn_observability_563_assert(
        king_cdn_cache_object('ttl', ['ttl_sec' => 1]),
        'short-lived metadata-only warm failed'
    );

    $afterWarm = king_object_store_get_stats()['cdn'];
    king_cdn_observability_563_assert(
        ($afterWarm['cached_object_count'] ?? null) === 3
            && ($afterWarm['retained_object_count'] ?? null) === 1
            && ($afterWarm['metadata_only_object_count'] ?? null) === 2,
        'warm stats drifted before lifecycle events'
    );
    king_cdn_observability_563_assert(
        ($afterWarm['retained_bytes'] ?? null) === strlen($retainedPayload),
        'retained_bytes did not reflect the retained payload only'
    );
    king_cdn_observability_563_assert(
        ($afterWarm['served_count'] ?? null) === 1
            && ($afterWarm['stale_serve_count'] ?? null) === 0
            && ($afterWarm['eviction_count'] ?? null) === 0
            && ($afterWarm['expiration_count'] ?? null) === 0
            && ($afterWarm['invalidation_count'] ?? null) === 0,
        'lifecycle counters were not clean after the initial warm'
    );

    var_dump(rename($root, $gone));
    clearstatcache();
    $retainedServedStale = king_object_store_get('retained') === $retainedPayload;
    var_dump($retainedServedStale);
    @rename($gone, $root);
    clearstatcache();

    var_dump(king_cdn_invalidate_cache('meta'));

    sleep(2);
    $cleanup = king_object_store_cleanup_expired_objects();
    var_dump(($cleanup['mode'] ?? null) === 'expiry_cleanup');

    foreach ($largePayloads as $objectId => $payload) {
        king_cdn_observability_563_assert(
            king_object_store_get($objectId) === $payload,
            $objectId . ' did not warm through the full backend read path'
        );
    }

    $afterPressure = king_object_store_get_stats()['cdn'];
    king_cdn_observability_563_assert(
        ($afterPressure['cached_object_count'] ?? null) === 2
            && ($afterPressure['retained_object_count'] ?? null) === 2
            && ($afterPressure['metadata_only_object_count'] ?? null) === 0,
        'memory pressure did not reduce the resident set to the two youngest retained entries'
    );
    king_cdn_observability_563_assert(
        ($afterPressure['cached_bytes'] ?? null) === ($largePayloadSize * 2)
            && ($afterPressure['retained_bytes'] ?? null) === ($largePayloadSize * 2),
        'resident byte counters drifted after eviction'
    );
    king_cdn_observability_563_assert(
        ($afterPressure['served_count'] ?? null) === 5
            && ($afterPressure['stale_serve_count'] ?? null) === 1
            && ($afterPressure['invalidation_count'] ?? null) === 1
            && ($afterPressure['expiration_count'] ?? null) === 1
            && ($afterPressure['eviction_count'] ?? null) >= 2,
        'lifecycle counters drifted after stale serve invalidation expiry and read pressure'
    );

    var_dump(rename($root, $gone));
    clearstatcache();

    try {
        king_object_store_get('doc-a');
        $docAClass = 'no-exception';
    } catch (Throwable $e) {
        $docAClass = get_class($e);
    }
    $docBServedStale = king_object_store_get('doc-b') === $largePayloads['doc-b'];
    $docCServedStale = king_object_store_get('doc-c') === $largePayloads['doc-c'];

    $finalStats = king_object_store_get_stats()['cdn'];

    var_dump($docAClass);
    var_dump($docBServedStale);
    var_dump($docCServedStale);
    var_dump(
        ($finalStats['served_count'] ?? null) === 7
        && ($finalStats['stale_serve_count'] ?? null) === 3
        && ($finalStats['eviction_count'] ?? null) >= 2
        && ($finalStats['expiration_count'] ?? null) === 1
        && ($finalStats['invalidation_count'] ?? null) === 1
    );
    var_dump(
        ($finalStats['cached_object_count'] ?? null) === 2
        && ($finalStats['retained_object_count'] ?? null) === 2
        && ($finalStats['metadata_only_object_count'] ?? null) === 0
        && ($finalStats['cached_bytes'] ?? null) === ($largePayloadSize * 2)
        && ($finalStats['retained_bytes'] ?? null) === ($largePayloadSize * 2)
    );
    var_dump(
        is_int($finalStats['latest_cached_at'] ?? null)
        && is_int($finalStats['latest_served_at'] ?? null)
    );
} finally {
    if (is_dir($gone) && !is_dir($root)) {
        @rename($gone, $root);
    }

    foreach ([$gone, $root] as $path) {
        king_cdn_observability_563_cleanup_tree($path);
    }
}
?>
--EXPECT--
bool(true)
bool(true)
int(1)
bool(true)
bool(true)
string(20) "King\SystemException"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
