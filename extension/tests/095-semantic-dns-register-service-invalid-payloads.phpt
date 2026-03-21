--TEST--
King semantic DNS register-service rejects invalid payloads
--SKIPIF--
<?php
if (king_semantic_dns_register_service([
    'service_id' => 'api-1',
    'service_name' => 'api',
    'service_type' => 'pipeline_orchestrator',
    'status' => 'healthy',
    'hostname' => 'api.internal',
    'port' => 8443,
]) === false) {
    die('skip Semantic-DNS register-service is not implemented yet');
}
?>
--FILE--
<?php
try {
    king_semantic_dns_register_service([]);
} catch (King\Exception $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'service'));
}

try {
    king_semantic_dns_register_service([
        'service_id' => 'api-1',
        'service_name' => 'api',
        'service_type' => 'pipeline_orchestrator',
        'status' => 'bogus',
        'hostname' => 'api.internal',
        'port' => 8443,
    ]);
} catch (King\Exception $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'status'));
}

try {
    king_semantic_dns_register_service([
        'service_id' => 'api-1',
        'service_name' => 'api',
        'service_type' => 'pipeline_orchestrator',
        'status' => 'healthy',
        'hostname' => 'api.internal',
        'port' => 0,
    ]);
} catch (King\Exception $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'port'));
}
?>
--EXPECT--
string(24) "King\ValidationException"
bool(true)
string(24) "King\ValidationException"
bool(true)
string(24) "King\ValidationException"
bool(true)
