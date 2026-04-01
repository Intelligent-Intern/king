--TEST--
King object-store batch restore blocks new live mutations until the committed replay finishes
--SKIPIF--
<?php
if (!function_exists('proc_open')) {
    echo "skip proc_open is required";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php

function king_object_store_472_cleanup_dir(string $dir): void
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
            king_object_store_472_cleanup_dir($path);
            @rmdir($path);
            continue;
        }

        @unlink($path);
    }

    @rmdir($dir);
}

function king_object_store_472_finish_process($process, array $pipes): array
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

$base = sys_get_temp_dir() . '/king_object_store_restore_barrier_472_' . getmypid();
$liveRoot = $base . '/live-store';
$snapshotDir = $liveRoot . '/snapshots/full';
$childScript = sys_get_temp_dir() . '/king_object_store_restore_barrier_child_472_' . getmypid() . '.php';
$extensionPath = dirname(__DIR__) . '/modules/king.so';

king_object_store_472_cleanup_dir($base);
@mkdir($base, 0700, true);

file_put_contents($childScript, <<<'PHP'
<?php
$root = $argv[1];
$snapshotDir = $argv[2];
king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
    'max_storage_size_bytes' => 128 * 1024 * 1024,
]);
var_dump(king_object_store_restore_all_objects($snapshotDir));
PHP
);

var_dump(king_object_store_init([
    'storage_root_path' => $liveRoot,
    'primary_backend' => 'local_fs',
    'max_storage_size_bytes' => 128 * 1024 * 1024,
]));

for ($i = 0; $i < 512; $i++) {
    $objectId = sprintf('snapshot-%04d', $i);
    $payload = str_repeat(chr(65 + ($i % 26)), 8192);
    if (!king_object_store_put($objectId, $payload)) {
        echo "snapshot-seed-failed\n";
        break;
    }
}

var_dump(king_object_store_backup_all_objects($snapshotDir));

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
    $snapshotDir,
];
$process = proc_open($command, $descriptors, $pipes);
fclose($pipes[0]);

$mutationBlocked = false;
for ($i = 0; $i < 500; $i++) {
    $status = proc_get_status($process);

    try {
        king_object_store_put('probe-live', 'value');
        king_object_store_delete('probe-live');
    } catch (Throwable $e) {
        if (str_contains($e->getMessage(), 'restore')) {
            $mutationBlocked = true;
            break;
        }
    }

    if (!$status['running']) {
        break;
    }

    usleep(10000);
}

$childResult = king_object_store_472_finish_process($process, $pipes);

var_dump($mutationBlocked);
var_dump($childResult['status'] === 0);
var_dump(trim($childResult['stdout']) === 'bool(true)');
var_dump(trim($childResult['stderr']) === '');
var_dump(king_object_store_put('probe-live', 'value'));
var_dump(king_object_store_get('snapshot-0000') === str_repeat('A', 8192));

@unlink($childScript);
king_object_store_472_cleanup_dir($base);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
