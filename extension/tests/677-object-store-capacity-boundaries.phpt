--TEST--
King object-store capacity boundaries are enforced during put
--INI--
king.security_allow_config_override=1
--FILE--
<?php

$dir = sys_get_temp_dir() . '/king_os_capacity_' . getmypid();

king_object_store_init([
    'storage_root_path'     => $dir,
    'max_storage_size_bytes' => 50,
]);

// First put: 20 bytes - should succeed
$r = king_object_store_put('obj1', str_repeat('A', 20));
var_dump($r);

// Second put: 20 bytes - total 40, still OK
$r = king_object_store_put('obj2', str_repeat('B', 20));
var_dump($r);

// Third put: 20 bytes - total would be 60, exceeds cap of 50
try {
    king_object_store_put('obj3', str_repeat('C', 20));
    echo "FAIL: should have thrown\n";
} catch (\King\ValidationException $e) {
    echo "caught capacity exception\n";
}

// Overwrite obj1 with exactly 10 bytes => total stays at 30, fits
$r = king_object_store_put('obj1', str_repeat('D', 10));
var_dump($r);

// Cleanup
foreach (scandir($dir) as $f) {
    if ($f !== '.' && $f !== '..') @unlink("$dir/$f");
}
@rmdir($dir);

?>
--EXPECT--
bool(true)
bool(true)
caught capacity exception
bool(true)
