--TEST--
King object-store expiry semantics hide expired local_fs objects from ordinary reads and cleanup them with a stable summary
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$dir = sys_get_temp_dir() . '/king_object_store_expiry_local_435_' . getmypid();
if (!is_dir($dir)) {
    mkdir($dir, 0700, true);
}

$expiredAt = time() - 3600;
$activeAt = time() + 3600;
$expiredPayload = 'expired-10';
$activePayload = 'active-09';

var_dump(king_object_store_init([
    'storage_root_path' => $dir,
    'primary_backend' => 'local_fs',
    'max_storage_size_bytes' => 1024 * 1024,
]));

var_dump(king_object_store_put('expired-doc', $expiredPayload, [
    'expires_at' => $expiredAt,
    'cache_ttl_sec' => 60,
]));
var_dump(king_object_store_put('active-doc', $activePayload, [
    'expires_at' => $activeAt,
    'cache_ttl_sec' => 60,
]));

$expiredMeta = king_object_store_get_metadata('expired-doc');
var_dump(is_array($expiredMeta));
var_dump($expiredMeta['is_expired']);
var_dump($expiredMeta['expires_at'] === $expiredAt);

var_dump(king_object_store_get('expired-doc'));
$expiredDestination = fopen('php://temp', 'w+');
var_dump(king_object_store_get_to_stream('expired-doc', $expiredDestination));
rewind($expiredDestination);
var_dump(stream_get_contents($expiredDestination));

var_dump(king_object_store_get('active-doc'));

$list = king_object_store_list();
var_dump(count($list));
var_dump($list[0]['object_id']);
var_dump($list[0]['is_expired']);

$cleanup = king_object_store_cleanup_expired_objects();
var_dump($cleanup['mode']);
var_dump($cleanup['scanned_objects']);
var_dump($cleanup['expired_objects_removed']);
var_dump($cleanup['bytes_reclaimed']);
var_dump($cleanup['removal_failures']);

var_dump(king_object_store_get_metadata('expired-doc'));
var_dump(king_object_store_get('expired-doc'));

$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['object_count']);
var_dump($stats['stored_bytes']);

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
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
bool(false)
string(0) ""
string(9) "active-09"
int(1)
string(10) "active-doc"
bool(false)
string(14) "expiry_cleanup"
int(2)
int(1)
int(10)
int(0)
bool(false)
bool(false)
int(1)
int(9)
