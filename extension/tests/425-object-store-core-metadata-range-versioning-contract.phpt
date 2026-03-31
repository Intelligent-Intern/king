--TEST--
King object-store core exposes metadata, range, overwrite/versioning, and integrity write semantics on local_fs
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$dir = sys_get_temp_dir() . '/king_object_store_core_425_' . getmypid();
if (!is_dir($dir)) {
    mkdir($dir, 0700, true);
}

$payload = 'alpha-beta-gamma';
$sha = hash('sha256', $payload);

var_dump(king_object_store_init([
    'storage_root_path' => $dir,
    'primary_backend' => 'local_fs',
    'max_storage_size_bytes' => 1024 * 1024,
]));

var_dump(king_object_store_put('doc', $payload, [
    'content_type' => 'text/plain',
    'content_encoding' => 'gzip',
    'cache_ttl_sec' => 123,
    'expires_at' => '2026-04-03T00:00:00Z',
    'object_type' => 'document',
    'cache_policy' => 'smart_cdn',
    'integrity_sha256' => $sha,
]));

$meta = king_object_store_get_metadata('doc');
var_dump($meta['content_type']);
var_dump($meta['content_encoding']);
var_dump($meta['integrity_sha256'] === $sha);
var_dump($meta['object_type_name']);
var_dump($meta['cache_policy_name']);
var_dump($meta['cache_ttl_seconds']);
var_dump($meta['version']);

var_dump(king_object_store_get('doc', [
    'offset' => 6,
    'length' => 4,
]));
var_dump(king_object_store_get('doc', [
    'length' => 0,
]));

$list = king_object_store_list();
var_dump(count($list));
var_dump($list[0]['content_type']);
var_dump($list[0]['object_type_name']);
var_dump($list[0]['cache_policy_name']);
var_dump($list[0]['local_fs_present']);

try {
    king_object_store_put('doc', 'omega', [
        'if_none_match' => '*',
    ]);
    echo "no-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'if_none_match=*'));
}

try {
    king_object_store_put('bad-hash', 'alpha', [
        'integrity_sha256' => str_repeat('0', 64),
    ]);
    echo "no-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'integrity_sha256'));
}

var_dump(king_object_store_put('doc', 'delta', [
    'if_match' => $meta['etag'],
    'expected_version' => $meta['version'],
    'object_type' => 'cache_entry',
    'cache_policy' => 'etag',
]));

$updated = king_object_store_get_metadata('doc');
var_dump($updated['version']);
var_dump($updated['object_type_name']);
var_dump($updated['cache_policy_name']);
var_dump(king_object_store_get('doc'));

foreach (scandir($dir) as $entry) {
    if ($entry !== '.' && $entry !== '..') {
        @unlink($dir . '/' . $entry);
    }
}
@rmdir($dir);
?>
--EXPECT--
bool(true)
bool(true)
string(10) "text/plain"
string(4) "gzip"
bool(true)
string(8) "document"
string(9) "smart_cdn"
int(123)
int(1)
string(4) "beta"
string(0) ""
int(1)
string(10) "text/plain"
string(8) "document"
string(9) "smart_cdn"
int(1)
string(24) "King\ValidationException"
bool(true)
string(24) "King\ValidationException"
bool(true)
bool(true)
int(2)
string(11) "cache_entry"
string(4) "etag"
string(5) "delta"
