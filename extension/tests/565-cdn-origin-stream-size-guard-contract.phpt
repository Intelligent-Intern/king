--TEST--
King CDN HTTP origin readthrough keeps the streaming path under the object-store body size guard
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
require __DIR__ . '/cdn_origin_http_test_helper.inc';

function king_cdn_origin_565_cleanup_tree(string $path): void
{
    if ($path === '' || !file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            king_cdn_origin_565_cleanup_tree($path . '/' . $entry);
        }

        @chmod($path, 0700);
        @rmdir($path);
        return;
    }

    @chmod($path, 0600);
    @unlink($path);
}

$root = sys_get_temp_dir() . '/king_cdn_origin_565_' . getmypid();
$objectId = 'oversized-stream-doc';
$originPath = '/origin/' . rawurlencode($objectId);
$oversizedBytes = (16 * 1024 * 1024) + 1;
$capture = [];

king_cdn_origin_565_cleanup_tree($root);
mkdir($root, 0700, true);

$server = king_cdn_origin_http_test_start_server([
    [
        'path' => $originPath,
        'status' => 200,
        'body_size' => $oversizedBytes,
        'body_byte' => 'z',
    ],
]);

try {
    var_dump(king_object_store_init([
        'storage_root_path' => $root,
        'primary_backend' => 'local_fs',
        'cdn_config' => [
            'enabled' => true,
            'default_ttl_seconds' => 90,
            'cache_size_mb' => 64,
            'origin_http_endpoint' => $server['endpoint'] . '/origin',
            'origin_request_timeout_ms' => 5000,
        ],
    ]));

    var_dump(king_object_store_put($objectId, 'seed', [
        'content_type' => 'text/plain',
        'object_type' => 'cache_entry',
        'cache_policy' => 'smart_cdn',
    ]));
    @unlink($root . '/' . $objectId);
    clearstatcache();

    $stream = fopen('php://temp', 'w+');
    try {
        king_object_store_get_to_stream($objectId, $stream);
        $exceptionClassMatches = false;
        $exceptionMessageMatches = false;
    } catch (Throwable $e) {
        $exceptionClassMatches = get_class($e) === 'King\\SystemException';
        $exceptionMessageMatches = str_contains(
            $e->getMessage(),
            'CDN HTTP origin response body exceeded the object-store size guard.'
        );
    }
    rewind($stream);
    $streamBody = stream_get_contents($stream);
    fclose($stream);
} finally {
    $capture = king_cdn_origin_http_test_stop_server($server);
    king_cdn_origin_565_cleanup_tree($root);
}

$events = $capture['events'] ?? [];

var_dump($exceptionClassMatches);
var_dump($exceptionMessageMatches);
var_dump($streamBody);
var_dump(count($events));
var_dump(($events[0]['action'] ?? null) === 'response');
var_dump(($events[0]['body_size'] ?? 0) === $oversizedBytes);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
string(0) ""
int(1)
bool(true)
bool(true)
