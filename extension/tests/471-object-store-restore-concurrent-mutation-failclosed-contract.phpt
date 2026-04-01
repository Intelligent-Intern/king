--TEST--
King object-store batch restore fails closed while live mutations are active
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

function king_object_store_471_cleanup_dir(string $dir): void
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
            king_object_store_471_cleanup_dir($path);
            @rmdir($path);
            continue;
        }

        @unlink($path);
    }

    @rmdir($dir);
}

function king_object_store_471_finish_process($process, array $pipes): array
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

$base = sys_get_temp_dir() . '/king_object_store_restore_concurrent_471_' . getmypid();
$liveRoot = $base . '/live-store';
$snapshotDir = $liveRoot . '/snapshots/full';
$childScript = sys_get_temp_dir() . '/king_object_store_restore_concurrent_child_471_' . getmypid() . '.php';
$fifoPath = sys_get_temp_dir() . '/king_object_store_restore_concurrent_fifo_471_' . getmypid();
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$lockPath = $liveRoot . '/.king_object_locks/busy-live.lock';

king_object_store_471_cleanup_dir($base);
@mkdir($base, 0700, true);
@unlink($fifoPath);
posix_mkfifo($fifoPath, 0600);

file_put_contents($childScript, <<<'PHP'
<?php
$root = $argv[1];
$fifoPath = $argv[2];
king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
    'max_storage_size_bytes' => 16 * 1024 * 1024,
]);
$stream = fopen($fifoPath, 'rb');
var_dump(king_object_store_put_from_stream('busy-live', $stream));
PHP
);

var_dump(king_object_store_init([
    'storage_root_path' => $liveRoot,
    'primary_backend' => 'local_fs',
    'max_storage_size_bytes' => 16 * 1024 * 1024,
]));
var_dump(king_object_store_put('locked-local', 'restored-value'));
var_dump(king_object_store_backup_all_objects($snapshotDir));
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
    $liveRoot,
    $fifoPath,
];
$process = proc_open($command, $descriptors, $pipes);

$fifoWriter = fopen($fifoPath, 'wb');
fwrite($fifoWriter, 'live-');
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
var_dump(king_object_store_restore_all_objects($snapshotDir));
var_dump(king_object_store_get('locked-local'));

fwrite($fifoWriter, 'mutation');
fclose($fifoWriter);
fclose($pipes[0]);

$childResult = king_object_store_471_finish_process($process, $pipes);
var_dump($childResult['status'] === 0);
var_dump(trim($childResult['stdout']) === 'bool(true)');
var_dump(trim($childResult['stderr']) === '');

var_dump(king_object_store_restore_all_objects($snapshotDir));
var_dump(king_object_store_get('locked-local'));

@unlink($childScript);
@unlink($fifoPath);
king_object_store_471_cleanup_dir($base);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(false)
string(9) "old-value"
bool(true)
bool(true)
bool(true)
bool(true)
string(14) "restored-value"
