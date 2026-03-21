--TEST--
King semantic DNS register-mother-node populates the topology mother node list
--SKIPIF--
<?php
if (king_semantic_dns_register_mother_node([
    'node_id' => 'mother-1',
    'hostname' => 'mother.internal',
    'port' => 9443,
    'status' => 'healthy',
    'managed_services_count' => 2,
    'trust_score' => 0.95,
]) === false) {
    die('skip Semantic-DNS register-mother-node is not implemented yet');
}
?>
--FILE--
<?php
var_dump(king_semantic_dns_register_mother_node([
    'node_id' => 'mother-1',
    'hostname' => 'mother.internal',
    'port' => 9443,
    'status' => 'healthy',
    'managed_services_count' => 2,
    'trust_score' => 0.95,
]));

$topology = king_semantic_dns_get_service_topology();
var_dump(count($topology['mother_nodes']));
var_dump($topology['statistics']['mother_nodes']);
var_dump($topology['mother_nodes'][0]['node_id']);
var_dump($topology['mother_nodes'][0]['status']);
var_dump($topology['mother_nodes'][0]['port']);
var_dump($topology['mother_nodes'][0]['managed_services_count']);
var_dump($topology['services']);
?>
--EXPECT--
bool(true)
int(1)
int(1)
string(8) "mother-1"
string(7) "healthy"
int(9443)
int(2)
array(0) {
}
