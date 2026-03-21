--TEST--
King semantic DNS register-mother-node rejects invalid payloads
--SKIPIF--
<?php
if (king_semantic_dns_register_mother_node([
    'node_id' => 'mother-1',
    'hostname' => 'mother.internal',
    'port' => 9443,
    'status' => 'healthy',
]) === false) {
    die('skip Semantic-DNS register-mother-node is not implemented yet');
}
?>
--FILE--
<?php
try {
    king_semantic_dns_register_mother_node([]);
} catch (King\Exception $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'node'));
}

try {
    king_semantic_dns_register_mother_node([
        'node_id' => 'mother-1',
        'hostname' => 'mother.internal',
        'port' => 0,
        'status' => 'healthy',
    ]);
} catch (King\Exception $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'port'));
}

try {
    king_semantic_dns_register_mother_node([
        'node_id' => 'mother-1',
        'hostname' => 'mother.internal',
        'port' => 9443,
        'status' => 'bogus',
    ]);
} catch (King\Exception $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'status'));
}
?>
--EXPECT--
string(24) "King\ValidationException"
bool(true)
string(24) "King\ValidationException"
bool(true)
string(24) "King\ValidationException"
bool(true)
