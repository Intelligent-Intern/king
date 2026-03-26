--TEST--
King MCP and pipeline orchestrator expose honest same-host topology scope in component info
--FILE--
<?php
$mcp = king_system_get_component_info('mcp');
$orchestrator = king_system_get_component_info('pipeline_orchestrator');

var_dump($mcp['configuration']['topology_scope']);
var_dump($orchestrator['configuration']['topology_scope']);
var_dump($orchestrator['configuration']['execution_backend']);
?>
--EXPECT--
string(21) "same_host_remote_peer"
string(16) "local_in_process"
string(5) "local"
