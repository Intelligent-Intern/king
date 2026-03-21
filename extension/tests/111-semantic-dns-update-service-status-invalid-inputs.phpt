--TEST--
King semantic DNS update-service-status rejects invalid inputs
--SKIPIF--
<?php
if (
    king_semantic_dns_register_service([
        'service_id' => 'api-1',
        'service_name' => 'api',
        'service_type' => 'pipeline_orchestrator',
        'status' => 'healthy',
        'hostname' => 'api-1.internal',
        'port' => 8443,
    ]) === false
    || king_semantic_dns_update_service_status(
        'api-1',
        'unhealthy',
        ['current_load_percent' => 90]
    ) === false
) {
    die('skip Semantic-DNS update-service-status is not implemented yet');
}
?>
--FILE--
<?php
try {
    king_semantic_dns_update_service_status('api-1', 'bogus');
} catch (King\Exception $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'Invalid status'));
}

try {
    king_semantic_dns_update_service_status('missing', 'healthy');
} catch (King\Exception $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'not found'));
}
?>
--EXPECT--
string(24) "King\ValidationException"
bool(true)
string(24) "King\ValidationException"
bool(true)
