--TEST--
King object-store invalid range reads do not leak file descriptors on local_fs or distributed backends
--SKIPIF--
<?php
if (!is_dir('/proc/self/fd')) {
    echo "skip /proc/self/fd is required";
}
?>
--INI--
king.security_allow_config_override=1
--FILE--
<?php

function king_object_store_473_cleanup_dir(string $dir): void
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
            king_object_store_473_cleanup_dir($path);
            @rmdir($path);
            continue;
        }

        @unlink($path);
    }

    @rmdir($dir);
}

function king_object_store_473_fd_count(): int
{
    return max(0, count(scandir('/proc/self/fd')) - 2);
}

function king_object_store_473_assert_no_fd_leak(string $backend, string $root): array
{
    $result = [];

    king_object_store_473_cleanup_dir($root);
    mkdir($root, 0700, true);

    $result['init'] = king_object_store_init([
        'storage_root_path' => $root,
        'primary_backend' => $backend,
        'max_storage_size_bytes' => 16 * 1024 * 1024,
    ]);
    $result['put'] = king_object_store_put('doc', 'alpha');

    $before = king_object_store_473_fd_count();
    $validationCount = 0;
    for ($i = 0; $i < 64; $i++) {
        try {
            king_object_store_get('doc', ['offset' => 99]);
        } catch (Throwable $e) {
            if ($e instanceof King\ValidationException
                && str_contains($e->getMessage(), 'range starts past the end')) {
                $validationCount++;
            }
        }
    }
    $after = king_object_store_473_fd_count();

    $result['validation_count'] = $validationCount;
    $result['fd_delta'] = $after - $before;

    king_object_store_473_cleanup_dir($root);
    return $result;
}

$local = king_object_store_473_assert_no_fd_leak(
    'local_fs',
    sys_get_temp_dir() . '/king_object_store_fd_473_local_' . getmypid()
);
$distributed = king_object_store_473_assert_no_fd_leak(
    'distributed',
    sys_get_temp_dir() . '/king_object_store_fd_473_distributed_' . getmypid()
);

foreach ([$local, $distributed] as $result) {
    var_dump($result['init']);
    var_dump($result['put']);
    var_dump($result['validation_count'] === 64);
    var_dump($result['fd_delta']);
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
int(0)
bool(true)
bool(true)
bool(true)
int(0)
