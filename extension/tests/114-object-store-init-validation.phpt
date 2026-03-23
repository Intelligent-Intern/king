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
