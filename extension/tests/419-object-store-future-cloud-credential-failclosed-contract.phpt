--TEST--
King object-store future cloud backends preserve credential-required failures without speculative runtime downgrades
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$root = sys_get_temp_dir() . '/king_object_store_future_cloud_credentials_' . getmypid();
$cleanupTree = static function (string $path) use (&$cleanupTree): void {
    if ($path === '' || !file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        $entries = scandir($path);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $cleanupTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
        return;
    }

    @unlink($path);
};

$cleanupTree($root);
mkdir($root, 0700, true);

foreach (['cloud_gcs', 'cloud_azure'] as $backend) {
    var_dump(king_object_store_init([
        'storage_root_path' => $root,
        'primary_backend' => $backend,
    ]));

    $stats = king_object_store_get_stats()['object_store'];
    var_dump($stats['runtime_primary_backend_contract'] === 'simulated');
    var_dump($stats['runtime_primary_adapter_status'] === 'simulated');
    var_dump(str_contains(
        $stats['runtime_primary_adapter_error'],
        'Cloud credentials are required to enable native cloud backend operation.'
    ));

    try {
        king_object_store_put('primary-' . $backend, 'payload');
        echo "no-exception\n";
    } catch (Throwable $e) {
        var_dump($e instanceof King\SystemException);
        var_dump(str_contains(
            $e->getMessage(),
            'Cloud credentials are required to enable native cloud backend operation.'
        ));
    }

    $stats = king_object_store_get_stats()['object_store'];
    var_dump($stats['runtime_primary_adapter_status'] === 'failed');
    var_dump(str_contains(
        $stats['runtime_primary_adapter_error'],
        'Cloud credentials are required to enable native cloud backend operation.'
    ));

    var_dump(king_object_store_get('primary-' . $backend) === false);
    $stats = king_object_store_get_stats()['object_store'];
    var_dump($stats['runtime_primary_adapter_status'] === 'failed');
    var_dump(str_contains(
        $stats['runtime_primary_adapter_error'],
        'Cloud credentials are required to enable native cloud backend operation.'
    ));
}

foreach (['cloud_gcs', 'cloud_azure'] as $backend) {
    var_dump(king_object_store_init([
        'storage_root_path' => $root,
        'primary_backend' => 'local_fs',
        'backup_backend' => $backend,
    ]));

    try {
        king_object_store_put('backup-' . $backend, 'payload');
        echo "no-exception\n";
    } catch (Throwable $e) {
        var_dump($e instanceof King\SystemException);
        var_dump(str_contains($e->getMessage(), 'backup operation failed'));
    }

    $stats = king_object_store_get_stats()['object_store'];
    var_dump($stats['runtime_primary_adapter_status'] === 'failed');
    var_dump(str_contains($stats['runtime_primary_adapter_error'], 'backup operation failed'));
    var_dump($stats['runtime_backup_adapter_contract'] === 'simulated');
    var_dump($stats['runtime_backup_adapter_status'] === 'failed');
    var_dump(str_contains(
        $stats['runtime_backup_adapter_error'],
        'Cloud credentials are required to enable native cloud backup backends.'
    ));
    var_dump(king_object_store_get('backup-' . $backend) === 'payload');
}

$cleanupTree($root);
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
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
