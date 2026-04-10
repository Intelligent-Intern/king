--TEST--
Repo-local Flow PHP MCP sink rejects cursor spool paths outside the local replay spool root
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require_once __DIR__ . '/../../demo/userland/flow-php/src/StreamingSink.php';

use King\Flow\McpByteSink;
use King\Flow\SinkCursor;

function king_flow_mcp_sink_662_cleanup(string $dir): void
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
            king_flow_mcp_sink_662_cleanup($path);
            @rmdir($path);
            continue;
        }

        @unlink($path);
    }

    @rmdir($dir);
}

$root = sys_get_temp_dir() . '/king-flow-sink-mcp-cursor-hardening-662-' . getmypid();
king_flow_mcp_sink_662_cleanup($root);
@mkdir($root, 0700, true);

$sink = new McpByteSink(null, 'svc', 'blob', 'cursor-spool-guarded');
$sink->write('alpha');
$cursor = $sink->cursor()->toArray();

$tamperedPath = $root . '/outside-sensitive.txt';
file_put_contents($tamperedPath, 'secret');
$cursor['state']['spool_path'] = $tamperedPath;

$thrown = false;
$message = '';
try {
    new McpByteSink(null, 'svc', 'blob', 'cursor-spool-guarded', SinkCursor::fromArray($cursor));
} catch (Throwable $error) {
    $thrown = true;
    $message = $error->getMessage();
}

var_dump($thrown);
var_dump(str_contains($message, 'spool_path'));

$sink->abort();
king_flow_mcp_sink_662_cleanup($root);
?>
--EXPECT--
bool(true)
bool(true)
