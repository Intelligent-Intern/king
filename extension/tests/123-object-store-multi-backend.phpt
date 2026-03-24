--TEST--
King object-store: multi-backend regression (S3, Memcached)
--INI--
king.security_allow_config_override=1
--FILE--
<?php

$root = sys_get_temp_dir() . '/king_backends_' . getmypid();
if (!is_dir($root)) mkdir($root, 0755, true);

// 1. Test S3 backend (primary_backend = 2)
king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 2, // KING_STORAGE_BACKEND_CLOUD_S3
]);

king_object_store_put('s3_doc', 'cloud data');
var_dump(king_object_store_get('s3_doc')); 

// Verify it's actually in the s3 subfolder
var_dump(file_exists("$root/s3/s3_doc"));

// 2. Test Distributed backend (primary_backend = 1)
king_object_store_init([
    'storage_root_path' => $root,
    'primary_backend' => 1, // KING_STORAGE_BACKEND_DISTRIBUTED
]);

king_object_store_put('mem_doc', 'fast data');
// We don't have simulated read for distributed yet in the C code switch, 
// but we can verify file existence.
var_dump(file_exists("$root/memcached/mem_doc"));

// Cleanup
foreach (['s3', 'memcached'] as $sub) {
    $d = "$root/$sub";
    if (is_dir($d)) {
        foreach (scandir($d) as $f) { if ($f !== '.' && $f !== '..') @unlink("$d/$f"); }
        @rmdir($d);
    }
}
@rmdir($root);
?>
--EXPECT--
string(10) "cloud data"
bool(true)
bool(true)
