<?php
echo "Test batch functions:\n";
// Define schema
\King\IIBIN::defineSchema("TestRec", [
    "id" => ["type" => "uint32", "tag" => 1],
    "name" => ["type" => "string", "tag" => 2],
]);

$records = [
    ["id" => 1, "name" => "one"],
    ["id" => 2, "name" => "two"],
    ["id" => 3, "name" => "three"],
];

// Test batch encode
$encoded = \King\IIBIN::encodeBatch("TestRec", $records);
echo "Batch encode: " . count($encoded) . " records\n";

// Test batch decode  
$decoded = \King\IIBIN::decodeBatch("TestRec", $encoded);
echo "Batch decode: " . count($decoded) . " records\n";

// Verify content
 echo "First: id=" . $decoded[0]["id"] . " name=" . $decoded[0]["name"] . "\n";

echo "OK\n";
?>