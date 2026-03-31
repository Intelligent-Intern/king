--TEST--
King object-store local_fs exposes bounded-memory stream ingress and egress contracts
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$dir = sys_get_temp_dir() . '/king_object_store_stream_local_428_' . getmypid();
if (!is_dir($dir)) {
    mkdir($dir, 0700, true);
}

$payload = str_repeat('alpha-beta-gamma-', 700) . 'tail';
$payloadHash = hash('sha256', $payload);

var_dump(king_object_store_init([
    'storage_root_path' => $dir,
    'primary_backend' => 'local_fs',
    'chunk_size_kb' => 1,
    'max_storage_size_bytes' => 1024 * 1024 * 8,
]));

$source = fopen('php://temp', 'w+');
fwrite($source, $payload);
rewind($source);

var_dump(king_object_store_put_from_stream('stream-local', $source, [
    'content_type' => 'application/octet-stream',
    'object_type' => 'binary_data',
    'cache_policy' => 'etag',
]));

$meta = king_object_store_get_metadata('stream-local');
var_dump($meta['content_length'] === strlen($payload));
var_dump($meta['integrity_sha256'] === $payloadHash);
var_dump($meta['object_type_name']);
var_dump($meta['cache_policy_name']);

$destination = fopen('php://temp', 'w+');
var_dump(king_object_store_get_to_stream('stream-local', $destination));
rewind($destination);
var_dump(stream_get_contents($destination) === $payload);

$rangeDestination = fopen('php://temp', 'w+');
var_dump(king_object_store_get_to_stream('stream-local', $rangeDestination, [
    'offset' => 1024,
    'length' => 33,
]));
rewind($rangeDestination);
var_dump(stream_get_contents($rangeDestination) === substr($payload, 1024, 33));

$zeroDestination = fopen('php://temp', 'w+');
var_dump(king_object_store_get_to_stream('stream-local', $zeroDestination, [
    'length' => 0,
]));
rewind($zeroDestination);
var_dump(stream_get_contents($zeroDestination) === '');

$stats = king_object_store_get_stats()['object_store'];
var_dump($stats['runtime_chunk_size_kb']);
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
string(11) "binary_data"
string(4) "etag"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
int(1)
int(1)
int(11904)
