--TEST--
King CDN cache memory limits evict older entries under sustained full-read load before the process-local registry grows past budget
--INI--
king.security_allow_config_override=1
king.cdn_cache_memory_limit_mb=1
--FILE--
<?php
function king_cdn_memory_559_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function king_cdn_memory_559_cleanup_tree(string $path): void
{
    if ($path === '' || !file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            king_cdn_memory_559_cleanup_tree($path . '/' . $entry);
        }

        @chmod($path, 0700);
        @rmdir($path);
        return;
    }

    @chmod($path, 0600);
    @unlink($path);
}

$root = sys_get_temp_dir() . '/king_cdn_memory_559_' . getmypid();
$gone = $root . '.gone';
$payloadSize = 400 * 1024;
$objects = [
    'doc-a' => str_repeat('A', $payloadSize),
    'doc-b' => str_repeat('B', $payloadSize),
    'doc-c' => str_repeat('C', $payloadSize),
];

foreach ([$gone, $root] as $path) {
    king_cdn_memory_559_cleanup_tree($path);
}
mkdir($root, 0700, true);

try {
    var_dump(king_object_store_init([
        'storage_root_path' => $root,
        'primary_backend' => 'local_fs',
        'cdn_config' => [
            'enabled' => true,
            'cache_size_mb' => 64,
            'default_ttl_seconds' => 300,
        ],
    ]));

    foreach ($objects as $objectId => $payload) {
        var_dump(king_object_store_put($objectId, $payload, [
            'content_type' => 'text/plain',
            'object_type' => 'cache_entry',
            'cache_policy' => 'smart_cdn',
        ]));
    }

    $snapshots = [];
    foreach ($objects as $objectId => $payload) {
        king_cdn_memory_559_assert(
            king_object_store_get($objectId) === $payload,
            $objectId . ' did not round-trip on the full-read warm path'
        );
        $snapshots[$objectId] = king_object_store_get_stats()['cdn'];
    }

    $finalStats = king_object_store_get_stats()['cdn'];
    $expectedResidentBytes = $payloadSize * 2;

    king_cdn_memory_559_assert(
        ($snapshots['doc-a']['cached_object_count'] ?? null) === 1
            && ($snapshots['doc-a']['cached_bytes'] ?? null) === $payloadSize,
        'first warm did not create a single cached entry'
    );
    king_cdn_memory_559_assert(
        ($snapshots['doc-b']['cached_object_count'] ?? null) === 2
            && ($snapshots['doc-b']['cached_bytes'] ?? null) === ($payloadSize * 2),
        'second warm did not keep two cached entries'
    );
    king_cdn_memory_559_assert(
        ($snapshots['doc-c']['cached_object_count'] ?? null) === 2
            && ($snapshots['doc-c']['cached_bytes'] ?? null) === $expectedResidentBytes,
        'third warm did not evict the oldest entry under the memory budget'
    );
    king_cdn_memory_559_assert(
        ($finalStats['cached_object_count'] ?? null) === 2
            && ($finalStats['cached_bytes'] ?? null) === $expectedResidentBytes,
        'final cache stats exceeded the bounded resident set'
    );

    var_dump(rename($root, $gone));
    clearstatcache();

    try {
        king_object_store_get('doc-a');
        $docAClass = 'no-exception';
    } catch (Throwable $e) {
        $docAClass = get_class($e);
    }

    $docBServedStale = king_object_store_get('doc-b') === $objects['doc-b'];
    $docCServedStale = king_object_store_get('doc-c') === $objects['doc-c'];

    var_dump($finalStats['cached_object_count']);
    var_dump($finalStats['cached_bytes']);
    var_dump($docAClass);
    var_dump($docBServedStale);
    var_dump($docCServedStale);
} finally {
    if (is_dir($gone) && !is_dir($root)) {
        @rename($gone, $root);
    }

    foreach ([$gone, $root] as $path) {
        king_cdn_memory_559_cleanup_tree($path);
    }
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(2)
int(819200)
string(20) "King\SystemException"
bool(true)
bool(true)
