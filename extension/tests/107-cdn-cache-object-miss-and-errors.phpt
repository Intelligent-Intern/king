--TEST--
King CDN cache-object preserves miss and validation contracts in the current runtime
--FILE--
<?php
var_dump(king_cdn_cache_object('missing-object'));

$stats = king_object_store_get_stats();
var_dump($stats['cdn']['cached_object_count']);
var_dump($stats['cdn']['cached_bytes']);
var_dump($stats['cdn']['latest_cached_at']);

try {
    king_cdn_cache_object('');
} catch (King\Exception $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

try {
    king_cdn_cache_object('missing-object', ['ttl_sec' => -1]);
} catch (King\Exception $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}
?>
--EXPECT--
bool(false)
int(0)
int(0)
NULL
string(24) "King\ValidationException"
string(25) "Object ID cannot be empty"
string(24) "King\ValidationException"
string(46) "CDN cache option 'ttl_sec' cannot be negative."
