--TEST--
King: Object Store Stress and Edge Cases (Capacity, Churn, Persistence)
--INI--
king.security_allow_config_override=1
--SKIPIF--
<?php if (!extension_loaded("king")) print "skip"; ?>
--FILE--
<?php
$storage_root = __DIR__ . "/stress_storage";
if (!is_dir($storage_root)) mkdir($storage_root);

// 1. Capacity Enforcement Stress
echo "--- Testing Capacity Enforcement ---\n";
king_object_store_init([
    "storage_root_path" => $storage_root,
    "max_storage_size_bytes" => 1024 * 10 // 10KB Limit
]);

$payload = str_repeat("A", 1024); // 1KB
for ($i = 0; $i < 10; $i++) {
    king_object_store_put("obj_$i", $payload);
}
$stats = king_object_store_get_stats();
echo "Stored 10 x 1KB. Total: " . $stats["object_store"]["stored_bytes"] . " bytes\n";

try {
    king_object_store_put("obj_overflow", $payload);
    echo "ERROR: Should have failed capacity check!\n";
} catch (King\ValidationException $e) {
    echo "Caught expected: " . $e->getMessage() . "\n";
}

// 2. Churn / Overwrite Stress
echo "--- Testing Overwrite Churn ---\n";
king_object_store_init([
    "storage_root_path" => $storage_root,
    "max_storage_size_bytes" => 1024 * 100 // 100KB Limit
]);
for ($i = 0; $i < 100; $i++) {
    king_object_store_put("churn", str_repeat("B", $i % 512));
}
$stats = king_object_store_get_stats();
echo "Final churn size: " . strlen(king_object_store_get("churn")) . "\n";
echo "Active stored bytes: " . $stats["object_store"]["stored_bytes"] . "\n";

// 3. Object ID Edge Cases
echo "--- Testing ID Edge Cases ---\n";
$long_id = str_repeat("Z", 127);
king_object_store_put($long_id, "Long ID data");
echo "Long ID (127): " . (king_object_store_get($long_id) === "Long ID data" ? "OK" : "FAIL") . "\n";

try {
    king_object_store_put(str_repeat("!", 200), "Too long");
    echo "ERROR: Should have failed ID length check!\n";
} catch (King\ValidationException $e) {
    echo "Caught expected (Too long ID): " . $e->getMessage() . "\n";
}

// 4. Persistence / Rehydration Stress
echo "--- Testing Rehydration Stress ---\n";
king_object_store_init([
    "storage_root_path" => $storage_root,
    "max_storage_size_bytes" => 1024 * 100
]);
$stats = king_object_store_get_stats();
echo "Rehydrated objects: " . $stats["object_store"]["object_count"] . "\n";

// Cleanup
foreach (glob($storage_root . "/*") as $file) unlink($file);
rmdir($storage_root);

echo "Done.\n";
?>
--EXPECTF--
--- Testing Capacity Enforcement ---
Stored 10 x 1KB. Total: 10240 bytes
Caught expected: Object-store runtime capacity exceeded.
--- Testing Overwrite Churn ---
Final churn size: 99
Active stored bytes: 10339
--- Testing ID Edge Cases ---
Long ID (127): OK
Caught expected (Too long ID): Object ID must be between 1 and 127 bytes.
--- Testing Rehydration Stress ---
Rehydrated objects: 12
Done.
