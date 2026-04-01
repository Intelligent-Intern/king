--TEST--
King object store backup and restore respect the exclusive mutation lock
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

function king_object_store_466_cleanup_dir(string $dir): void
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
            king_object_store_466_cleanup_dir($path);
            @rmdir($path);
            continue;
        }

        @unlink($path);
    }

    @rmdir($dir);
}

function king_object_store_466_finish_process($process, array $pipes): array
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

$root = sys_get_temp_dir() . '/king_object_store_backup_locking_466_' . getmypid();
$backup_dir = $root . '/backups';
$restore_dir = $root . '/restore-source';
$child_script = sys_get_temp_dir() . '/king_object_store_backup_locking_child_466_' . getmypid() . '.php';
$fifo_path = sys_get_temp_dir() . '/king_object_store_backup_locking_fifo_466_' . getmypid();
$extensionPath = dirname(__DIR__) . '/modules/king.so';
$lockPath = $root . '/.king_object_locks/locked-local.lock';

king_object_store_466_cleanup_dir($root);
@mkdir($backup_dir, 0700, true);
@mkdir($restore_dir, 0700, true);
file_put_contents($restore_dir . '/locked-local', 'restored-value');

@unlink($fifo_path);
posix_mkfifo($fifo_path, 0600);

file_put_contents($child_script, <<<'PHP'
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
fclose($stream);
PHP
);

var_dump(king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
    'max_storage_size_bytes' => 1024 * 1024,
]));
var_dump(king_object_store_put('locked-local', 'old-value'));

// Timing constants for lock polling behavior in this test
const KING_OBJECT_STORE_466_MAX_LOCK_POLL_ATTEMPTS = 200;
const KING_OBJECT_STORE_466_LOCK_ACQUIRED_DELAY_USEC = 50000;
const KING_OBJECT_STORE_466_LOCK_POLL_INTERVAL_USEC = 10000;

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
for ($i = 0; $i < KING_OBJECT_STORE_466_MAX_LOCK_POLL_ATTEMPTS; $i++) {
    clearstatcache();
    if (file_exists($lockPath)) {
        $lockObserved = true;
        usleep(KING_OBJECT_STORE_466_LOCK_ACQUIRED_DELAY_USEC);
        break;
    }
    usleep(KING_OBJECT_STORE_466_LOCK_POLL_INTERVAL_USEC);
}

var_dump($lockObserved);
var_dump(king_object_store_backup_object('locked-local', $backupDir));
var_dump(file_exists($backupDir . '/locked-local'));
var_dump(king_object_store_restore_object('locked-local', $restoreDir));
var_dump(king_object_store_get('locked-local'));

fwrite($fifoWriter, 'value');
fclose($fifoWriter);
fclose($pipes[0]);

$childResult = king_object_store_466_finish_process($process, $pipes);
var_dump($childResult['status'] === 0);
var_dump(trim($childResult['stdout']) === 'bool(true)');
var_dump(trim($childResult['stderr']) === '');

var_dump(king_object_store_backup_object('locked-local', $backupDir));
var_dump(file_exists($backupDir . '/locked-local'));
var_dump(king_object_store_delete('locked-local'));
var_dump(king_object_store_restore_object('locked-local', $restoreDir));
var_dump(king_object_store_get('locked-local'));

@unlink($childScript);
@unlink($fifoPath);
king_object_store_466_cleanup_dir($root);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(false)
bool(false)
bool(false)
string(9) "old-value"
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(14) "restored-value"
