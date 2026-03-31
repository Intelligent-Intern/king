--TEST--
King object-store local_fs enforces exclusive mutations while readers keep seeing the last committed payload
--SKIPIF--
<?php
if (!function_exists('proc_open') || !function_exists('posix_mkfifo')) {
    echo "skip proc_open and posix_mkfifo are required";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php
function king_object_store_438_cleanup_dir(string $dir): void
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
            king_object_store_438_cleanup_dir($path);
            @rmdir($path);
            continue;
        }

        @unlink($path);
    }

    @rmdir($dir);
}

function king_object_store_438_finish_process($process, array $pipes): array
{
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'stdout' => $stdout,
        'stderr' => $stderr,
        'status' => proc_close($process),
    ];
}

$root = sys_get_temp_dir() . '/king_object_store_locking_438_' . getmypid();
$childScript = sys_get_temp_dir() . '/king_object_store_locking_child_438_' . getmypid() . '.php';
$fifoPath = sys_get_temp_dir() . '/king_object_store_locking_fifo_438_' . getmypid();
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$lockPath = $root . '/.king_object_locks/locked-local.lock';

@mkdir($root, 0700, true);
@unlink($fifoPath);
posix_mkfifo($fifoPath, 0600);

file_put_contents($childScript, <<<'PHP'
<?php
$root = $argv[1];
$fifoPath = $argv[2];
king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
    'max_storage_size_bytes' => 1024 * 1024,
]);
$stream = fopen($fifoPath, 'rb');
var_dump(king_object_store_put_from_stream('locked-local', $stream));
PHP
);

var_dump(king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
    'max_storage_size_bytes' => 1024 * 1024,
]));
var_dump(king_object_store_put('locked-local', 'old-value'));

$descriptors = [
    0 => ['pipe', 'w'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$command = [
    PHP_BINARY,
    '-n',
    '-d', 'extension=' . $extensionPath,
    '-d', 'king.security_allow_config_override=1',
    $childScript,
    $root,
    $fifoPath,
];
$process = proc_open($command, $descriptors, $pipes);

$fifoWriter = fopen($fifoPath, 'wb');
fwrite($fifoWriter, 'new-');
fflush($fifoWriter);

$lockObserved = false;
for ($i = 0; $i < 200; $i++) {
    clearstatcache();
    if (file_exists($lockPath)) {
        $lockObserved = true;
        usleep(50000);
        break;
    }
    usleep(10000);
}

var_dump($lockObserved);
var_dump(king_object_store_get('locked-local'));

try {
    king_object_store_put('locked-local', 'parent-write');
    echo "no-exception\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'active mutation'));
}

var_dump(king_object_store_delete('locked-local'));

fwrite($fifoWriter, 'value');
fclose($fifoWriter);
fclose($pipes[0]);

$childResult = king_object_store_438_finish_process($process, $pipes);
var_dump($childResult['status'] === 0);
var_dump(trim($childResult['stdout']) === 'bool(true)');
var_dump(trim($childResult['stderr']) === '');
var_dump(king_object_store_get('locked-local'));

@unlink($childScript);
@unlink($fifoPath);
king_object_store_438_cleanup_dir($root);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
string(9) "old-value"
string(21) "King\RuntimeException"
bool(true)
bool(false)
bool(true)
bool(true)
bool(true)
string(9) "new-value"
