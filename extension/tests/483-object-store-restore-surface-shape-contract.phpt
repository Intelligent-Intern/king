--TEST--
King object-store restore surfaces stay single-object partial or committed batch replay only
--INI--
king.security_allow_config_override=1
--FILE--
<?php

function king_object_store_483_cleanup_dir(string $dir): void
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
            king_object_store_483_cleanup_dir($path);
            @rmdir($path);
            continue;
        }

        @unlink($path);
    }

    @rmdir($dir);
}

$root = sys_get_temp_dir() . '/king_object_store_restore_shape_483_' . getmypid();
$singleBackupDir = $root . '/single';
$fullBackupDir = $root . '/full';

king_object_store_483_cleanup_dir($root);
@mkdir($root, 0700, true);

var_dump(king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 'local_fs',
]));

$restoreAll = new ReflectionFunction('king_object_store_restore_all_objects');
$restoreOne = new ReflectionFunction('king_object_store_restore_object');

var_dump($restoreAll->getNumberOfParameters());
var_dump($restoreAll->getNumberOfRequiredParameters());
var_dump($restoreOne->getNumberOfParameters());

var_dump(king_object_store_put('alpha', 'alpha-v1'));
var_dump(king_object_store_put('beta', 'beta-v1'));
var_dump(king_object_store_put('gamma', 'gamma-live'));

var_dump(king_object_store_backup_object('alpha', $singleBackupDir));
var_dump(king_object_store_backup_all_objects($fullBackupDir));

var_dump(king_object_store_put('alpha', 'alpha-live-mutated'));
var_dump(king_object_store_delete('beta'));
var_dump(king_object_store_put('delta', 'delta-live-only'));

var_dump(king_object_store_restore_object('alpha', $singleBackupDir));
var_dump(king_object_store_get('alpha'));
var_dump(king_object_store_get('beta'));
var_dump(king_object_store_get('delta'));

var_dump(king_object_store_restore_all_objects($fullBackupDir));
var_dump(king_object_store_get('alpha'));
var_dump(king_object_store_get('beta'));
var_dump(king_object_store_get('gamma'));
var_dump(king_object_store_get('delta'));

king_object_store_483_cleanup_dir($root);
?>
--EXPECT--
bool(true)
int(1)
int(1)
int(2)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(8) "alpha-v1"
bool(false)
string(15) "delta-live-only"
bool(true)
string(8) "alpha-v1"
string(7) "beta-v1"
string(10) "gamma-live"
string(15) "delta-live-only"
