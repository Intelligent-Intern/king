--TEST--
King object-store init validates local runtime config input in the skeleton build
--FILE--
<?php
try {
    king_object_store_init(['primary_backend' => 'unknown']);
} catch (King\Exception $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

try {
    king_object_store_init(['storage_root_path' => '']);
} catch (King\Exception $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

try {
    king_object_store_init(['max_storage_size_bytes' => -1]);
} catch (King\Exception $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

try {
    king_object_store_init(['replication_factor' => 0]);
} catch (King\Exception $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

try {
    king_object_store_init(['chunk_size_kb' => 0]);
} catch (King\Exception $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

try {
    king_object_store_init(['cdn_config' => ['default_ttl_seconds' => -1]]);
} catch (King\Exception $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}
?>
--EXPECTF--
string(24) "King\ValidationException"
string(129) "Object-store init option 'primary_backend' must be one of: local_fs, distributed, cloud_s3, cloud_gcs, cloud_azure, memory_cache."
string(24) "King\ValidationException"
string(61) "Object-store init option 'storage_root_path' cannot be empty."
string(24) "King\ValidationException"
string(69) "Object-store init option 'max_storage_size_bytes' cannot be negative."
string(24) "King\ValidationException"
string(69) "Object-store init option 'replication_factor' must be greater than 0."
string(24) "King\ValidationException"
string(64) "Object-store init option 'chunk_size_kb' must be greater than 0."
string(24) "King\ValidationException"
string(77) "Object-store init option 'cdn_config.default_ttl_seconds' cannot be negative."
