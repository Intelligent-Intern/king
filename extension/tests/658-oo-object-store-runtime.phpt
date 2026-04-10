--TEST--
King ObjectStore OO facade shares the same public object-store runtime contract
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$dir = sys_get_temp_dir() . '/king_oo_object_store_' . getmypid() . '_' . uniqid('', true);

$cleanup = static function (string $path): void {
    if (!is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $entry) {
        if ($entry->isDir()) {
            @rmdir($entry->getPathname());
        } else {
            @unlink($entry->getPathname());
        }
    }

    @rmdir($path);
};

@mkdir($dir, 0777, true);

try {
    var_dump(King\ObjectStore::init([
        'storage_root_path' => $dir,
        'max_storage_size_bytes' => 0,
    ]));

    var_dump(King\ObjectStore::put('oo-alpha', 'alpha'));
    var_dump(King\ObjectStore::put('oo-beta', 'beta'));

    var_dump(king_object_store_get('oo-alpha'));
    var_dump(King\ObjectStore::get('oo-beta'));

    $objects = King\ObjectStore::listObjects();
    $objectIds = array_map(
        static fn(array $entry): string => (string) ($entry['object_id'] ?? ''),
        $objects
    );
    sort($objectIds);
    var_dump($objectIds);

    $stats = King\ObjectStore::getStats();
    var_dump($stats['object_store']['object_count']);
} finally {
    $cleanup($dir);
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
string(5) "alpha"
string(4) "beta"
array(2) {
  [0]=>
  string(8) "oo-alpha"
  [1]=>
  string(7) "oo-beta"
}
int(2)
