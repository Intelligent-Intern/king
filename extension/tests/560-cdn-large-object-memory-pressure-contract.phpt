--TEST--
King large CDN cache markers stay metadata-only under memory pressure without flushing smaller retained entries
--INI--
king.security_allow_config_override=1
king.cdn_cache_memory_limit_mb=1
--FILE--
<?php
function king_cdn_large_560_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function king_cdn_large_560_cleanup_tree(string $path): void
{
    if ($path === '' || !file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            king_cdn_large_560_cleanup_tree($path . '/' . $entry);
        }

        @chmod($path, 0700);
        @rmdir($path);
        return;
    }

    @chmod($path, 0600);
    @unlink($path);
}

$root = sys_get_temp_dir() . '/king_cdn_large_560_' . getmypid();
$gone = $root . '.gone';
$smallSize = 300 * 1024;
$largeSize = 2 * 1024 * 1024;
$smallA = str_repeat('A', $smallSize);
$smallB = str_repeat('B', $smallSize);
$large = str_repeat('C', $largeSize);
$residentBytes = $smallSize * 2;

foreach ([$gone, $root] as $path) {
    king_cdn_large_560_cleanup_tree($path);
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

    foreach ([
        ['small-a', $smallA],
        ['small-b', $smallB],
        ['large', $large],
    ] as [$objectId, $payload]) {
        var_dump(king_object_store_put($objectId, $payload, [
            'content_type' => 'text/plain',
            'object_type' => 'cache_entry',
            'cache_policy' => 'smart_cdn',
        ]));
    }

    king_cdn_large_560_assert(
        king_object_store_get('small-a') === $smallA,
        'small-a did not warm through the full-read path'
    );
    king_cdn_large_560_assert(
        king_object_store_get('small-b') === $smallB,
        'small-b did not warm through the full-read path'
    );

    $beforeLarge = king_object_store_get_stats()['cdn'];
    king_cdn_large_560_assert(
        ($beforeLarge['cached_object_count'] ?? null) === 2
            && ($beforeLarge['cached_bytes'] ?? null) === $residentBytes,
        'small entries were not resident before the large-object phase'
    );

    $manualLargeAdmitted = king_cdn_cache_object('large');
    $afterManualLarge = king_object_store_get_stats()['cdn'];
    king_cdn_large_560_assert(
        $manualLargeAdmitted === true,
        'manual large-object metadata warm unexpectedly failed'
    );
    king_cdn_large_560_assert(
        ($afterManualLarge['cached_object_count'] ?? null) === 3
            && ($afterManualLarge['cached_bytes'] ?? null) === ($residentBytes + $largeSize),
        'manual large-object metadata warm did not stay as a metadata-only cache marker'
    );

    king_cdn_large_560_assert(
        king_object_store_get('large') === $large,
        'large object did not still read through the primary backend'
    );
    $afterLargeRead = king_object_store_get_stats()['cdn'];
    king_cdn_large_560_assert(
        ($afterLargeRead['cached_object_count'] ?? null) === 2
            && ($afterLargeRead['cached_bytes'] ?? null) === $residentBytes,
        'large read-through displaced smaller admitted entries under memory pressure'
    );

    var_dump(rename($root, $gone));
    clearstatcache();

    $smallAStale = king_object_store_get('small-a') === $smallA;
    $smallBStale = king_object_store_get('small-b') === $smallB;

    try {
        king_object_store_get('large');
        $largeFailureClass = 'no-exception';
    } catch (Throwable $e) {
        $largeFailureClass = get_class($e);
    }

    var_dump($afterLargeRead['cached_object_count']);
    var_dump($afterLargeRead['cached_bytes']);
    var_dump($smallAStale);
    var_dump($smallBStale);
    var_dump($largeFailureClass);
} finally {
    if (is_dir($gone) && !is_dir($root)) {
        @rename($gone, $root);
    }

    foreach ([$gone, $root] as $path) {
        king_cdn_large_560_cleanup_tree($path);
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
int(614400)
bool(true)
bool(true)
string(20) "King\SystemException"
