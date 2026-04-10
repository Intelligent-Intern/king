--TEST--
Repo-local Flow PHP object-store sink rejects cursor spool paths outside the local replay spool root
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require_once __DIR__ . '/../../demo/userland/flow-php/src/StreamingSink.php';

use King\Flow\ObjectStoreByteSink;
use King\Flow\SinkCursor;

function king_flow_object_store_sink_661_cleanup(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            king_flow_object_store_sink_661_cleanup($path);
            @rmdir($path);
            continue;
        }

        @unlink($path);
    }

    @rmdir($dir);
}

$root = sys_get_temp_dir() . '/king-flow-sink-object-store-cursor-hardening-661-' . getmypid();
king_flow_object_store_sink_661_cleanup($root);
@mkdir($root, 0700, true);

king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
    'max_storage_size_bytes' => 16 * 1024 * 1024,
]);

$sink = new ObjectStoreByteSink('cursor-spool-guarded.bin');
$sink->write('alpha');
$cursor = $sink->cursor()->toArray();

$tamperedPath = $root . '/outside-sensitive.txt';
file_put_contents($tamperedPath, 'secret');
$cursor['state']['spool_path'] = $tamperedPath;

$thrown = false;
$message = '';
try {
    new ObjectStoreByteSink('cursor-spool-guarded.bin', [], SinkCursor::fromArray($cursor));
} catch (Throwable $error) {
    $thrown = true;
    $message = $error->getMessage();
}

var_dump($thrown);
var_dump(str_contains($message, 'spool_path'));

$sink->abort();
king_flow_object_store_sink_661_cleanup($root);
?>
--EXPECT--
bool(true)
bool(true)
