--TEST--
King full-object CDN HTTP origin fallback honors timeout budgets, avoids hidden retries, and serves stale bytes only when they were already retained
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

function king_cdn_origin_558_cleanup_tree(string $path): void
{
    if ($path === '' || !file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            king_cdn_origin_558_cleanup_tree($path . '/' . $entry);
        }

        @chmod($path, 0700);
        @rmdir($path);
        return;
    }

    @chmod($path, 0600);
    @unlink($path);
}

$root = sys_get_temp_dir() . '/king_cdn_origin_558_' . getmypid();
$originObjectId = 'origin-doc';
$noStaleObjectId = 'no-stale-doc';
$originBody = 'origin-fresh-body';
$originPath = '/origin/' . rawurlencode($originObjectId);
$noStalePath = '/origin/' . rawurlencode($noStaleObjectId);
$capture = [];

king_cdn_origin_558_cleanup_tree($root);
mkdir($root, 0700, true);

$server = king_cdn_origin_http_test_start_server([
    ['path' => $originPath, 'status' => 200, 'body' => $originBody],
    ['path' => $originPath, 'hang_ms' => 700],
    ['path' => $originPath, 'hang_ms' => 700],
    ['path' => $noStalePath, 'hang_ms' => 700],
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
            'origin_request_timeout_ms' => 200,
        ],
    ]));

    foreach ([
        [$originObjectId, 'backend-seed'],
        [$noStaleObjectId, 'no-stale-seed'],
    ] as [$objectId, $payload]) {
        var_dump(king_object_store_put($objectId, $payload, [
            'content_type' => 'text/plain',
            'object_type' => 'cache_entry',
            'cache_policy' => 'smart_cdn',
        ]));
        @unlink($root . '/' . $objectId);
    }
    clearstatcache();

    $firstRead = king_object_store_get($originObjectId);
    $statsAfterFirstRead = king_object_store_get_stats()['cdn'];

    $staleRead = king_object_store_get($originObjectId);

    $stream = fopen('php://temp', 'w+');
    $streamReadOk = king_object_store_get_to_stream($originObjectId, $stream);
    rewind($stream);
    $streamReadBody = stream_get_contents($stream);
    fclose($stream);

    try {
        king_object_store_get($noStaleObjectId);
        $failureClass = 'no-exception';
        $failureTimedOut = false;
    } catch (Throwable $e) {
        $failureClass = get_class($e);
        $failureTimedOut = str_contains($e->getMessage(), 'timed out after 200 ms');
    }
} finally {
    $capture = king_cdn_origin_http_test_stop_server($server);
    king_cdn_origin_558_cleanup_tree($root);
}

$originEvents = array_values(array_filter(
    $capture['events'] ?? [],
    static fn(array $event): bool => ($event['path'] ?? '') === $originPath
));
$noStaleEvents = array_values(array_filter(
    $capture['events'] ?? [],
    static fn(array $event): bool => ($event['path'] ?? '') === $noStalePath
));

var_dump($firstRead);
var_dump($statsAfterFirstRead['cached_object_count']);
var_dump($statsAfterFirstRead['cached_bytes']);
var_dump($staleRead);
var_dump($streamReadOk);
var_dump($streamReadBody);
var_dump($failureClass);
var_dump($failureTimedOut);
var_dump(count($capture['events'] ?? []));
var_dump(count($originEvents));
var_dump(count($noStaleEvents));
var_dump(array_column($capture['events'] ?? [], 'action'));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
string(17) "origin-fresh-body"
int(1)
int(17)
string(17) "origin-fresh-body"
bool(true)
string(17) "origin-fresh-body"
string(20) "King\SystemException"
bool(true)
int(4)
int(3)
int(1)
array(4) {
  [0]=>
  string(8) "response"
  [1]=>
  string(4) "hang"
  [2]=>
  string(4) "hang"
  [3]=>
  string(4) "hang"
}
