--TEST--
Repo-local Flow PHP HTTP source fails closed on repeated empty reads without end-of-body progress
--SKIPIF--
<?php
if (!function_exists('proc_open') || !function_exists('proc_get_status')) {
    echo "skip proc_open and proc_get_status are required";
}
?>
--FILE--
<?php
$sourcePath = realpath(__DIR__ . '/../../demo/userland/flow-php/src/StreamingSource.php');
if ($sourcePath === false) {
    throw new RuntimeException('failed to resolve StreamingSource.php');
}

$script = tempnam(sys_get_temp_dir(), 'king-flow-http-empty-read-');
if ($script === false) {
    throw new RuntimeException('failed to allocate temp script');
}

$child = <<<'PHP'
<?php
declare(strict_types=1);

namespace King {
    final class Response
    {
        public function read(int $maxBytes): string
        {
            return '';
        }

        public function isEndOfBody(): bool
        {
            return false;
        }
    }
}

namespace {
    function king_client_send_request(...$args)
    {
        return fopen('php://temp', 'r+');
    }

    function king_receive_response($context): \King\Response
    {
        return new \King\Response();
    }

    require __STREAMING_SOURCE_PATH__;

    $source = new \King\Flow\HttpByteSource(
        'http://example.invalid/payload',
        'GET',
        [],
        null,
        32,
        ['response_stream' => true]
    );

    try {
        $source->pumpBytes(
            static function (string $chunk, \King\Flow\SourceCursor $cursor, bool $complete): bool {
                return true;
            }
        );
        echo "NO_EXCEPTION\n";
    } catch (\RuntimeException $e) {
        echo 'RUNTIME:' . $e->getMessage() . "\n";
    } catch (\Throwable $e) {
        echo 'THROWABLE:' . get_class($e) . ':' . $e->getMessage() . "\n";
    }
}
PHP;

$child = str_replace('__STREAMING_SOURCE_PATH__', var_export($sourcePath, true), $child);
file_put_contents($script, $child);

$process = proc_open(
    [PHP_BINARY, '-n', $script],
    [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    __DIR__
);

if (!is_resource($process)) {
    @unlink($script);
    throw new RuntimeException('failed to launch child process');
}

fclose($pipes[0]);

$timedOut = false;
$deadline = microtime(true) + 4.0;
while (true) {
    $status = proc_get_status($process);
    if (!$status['running']) {
        break;
    }

    if (microtime(true) >= $deadline) {
        $timedOut = true;
        proc_terminate($process);
        break;
    }

    usleep(20_000);
}

$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($process);
@unlink($script);

var_dump($timedOut);
var_dump(str_starts_with(trim($stdout), 'RUNTIME:http source stalled without making progress while reading the response body.'));
var_dump(trim($stderr) === '');
?>
--EXPECT--
bool(false)
bool(true)
bool(true)
