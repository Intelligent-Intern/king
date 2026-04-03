--TEST--
King object-store init validates local runtime config input in the current runtime
--INI--
king.security_allow_config_override=1
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

try {
    king_object_store_init(['cdn_config' => ['origin_request_timeout_ms' => 0]]);
} catch (King\Exception $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}
?>
--EXPECTF--
string(24) "King\ValidationException"
string(70) "Object-store config 'primary_backend' must be a valid storage backend."
string(24) "King\ValidationException"
string(67) "Object-store config 'storage_root_path' must be a non-empty string."
string(24) "King\ValidationException"
string(76) "Object-store config 'max_storage_size_bytes' must be a non-negative integer."
string(24) "King\ValidationException"
string(68) "Object-store config 'replication_factor' must be a positive integer."
string(24) "King\ValidationException"
string(63) "Object-store config 'chunk_size_kb' must be a positive integer."
string(24) "King\ValidationException"
string(84) "Object-store config 'cdn_config.default_ttl_seconds' must be a non-negative integer."
string(24) "King\ValidationException"
string(86) "Object-store config 'cdn_config.origin_request_timeout_ms' must be a positive integer."
