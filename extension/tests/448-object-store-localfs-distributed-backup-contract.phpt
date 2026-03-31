--TEST--
King object-store local_fs primary can use distributed as a real backup route and delete it consistently
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$root = sys_get_temp_dir() . '/king_object_store_localfs_distributed_backup_448_' . getmypid();

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

var_dump(king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
    'backup_backend' => 'distributed',
]));

var_dump(king_object_store_put('backup-doc', 'backup payload'));
$metadata = king_object_store_get_metadata('backup-doc');
var_dump($metadata['local_fs_present']);
var_dump($metadata['distributed_present']);
var_dump($metadata['is_backed_up']);

var_dump(king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'distributed',
]));
var_dump(king_object_store_get('backup-doc'));

var_dump(king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
    'backup_backend' => 'distributed',
]));
var_dump(king_object_store_delete('backup-doc'));

var_dump(king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'distributed',
]));
var_dump(king_object_store_get('backup-doc'));

$cleanupTree($root);
?>
--EXPECT--
bool(true)
bool(true)
int(1)
int(1)
int(1)
bool(true)
string(14) "backup payload"
bool(true)
bool(true)
bool(true)
bool(false)
