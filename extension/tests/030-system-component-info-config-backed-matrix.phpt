--TEST--
King system component info covers all config-backed component branches
--FILE--
<?php
function dump_component(string $name): void {
    $component = king_system_get_component_info($name);
    var_dump(array_keys($component));
    var_dump($component['name']);
    var_dump($component['implementation']);
    var_dump(array_keys($component['configuration']));
    var_dump(is_int($component['info_generated_at']));
}

dump_component('config');
dump_component('autoscaling');
dump_component('router_loadbalancer');
dump_component('object_store');
dump_component('cdn');
dump_component('semantic_dns');
dump_component('mcp');
dump_component('pipeline_orchestrator');
dump_component('iibin');
?>
--EXPECT--
array(6) {
  [0]=>
  string(4) "name"
  [1]=>
  string(5) "build"
  [2]=>
  string(7) "version"
  [3]=>
  string(14) "implementation"
  [4]=>
  string(13) "configuration"
  [5]=>
  string(17) "info_generated_at"
}
string(6) "config"
string(13) "config_backed"
array(1) {
  [0]=>
  string(23) "config_override_allowed"
}
bool(true)
array(6) {
  [0]=>
  string(4) "name"
  [1]=>
  string(5) "build"
  [2]=>
  string(7) "version"
  [3]=>
  string(14) "implementation"
  [4]=>
  string(13) "configuration"
  [5]=>
  string(17) "info_generated_at"
}
string(11) "autoscaling"
string(13) "config_backed"
array(5) {
  [0]=>
  string(8) "provider"
  [1]=>
  string(6) "region"
  [2]=>
  string(9) "min_nodes"
  [3]=>
  string(9) "max_nodes"
  [4]=>
  string(15) "scale_up_policy"
}
bool(true)
array(6) {
  [0]=>
  string(4) "name"
  [1]=>
  string(5) "build"
  [2]=>
  string(7) "version"
  [3]=>
  string(14) "implementation"
  [4]=>
  string(13) "configuration"
  [5]=>
  string(17) "info_generated_at"
}
string(19) "router_loadbalancer"
string(13) "config_backed"
array(8) {
  [0]=>
  string(18) "router_mode_enable"
  [1]=>
  string(17) "hashing_algorithm"
  [2]=>
  string(22) "backend_discovery_mode"
  [3]=>
  string(19) "backend_static_list"
  [4]=>
  string(20) "backend_mcp_endpoint"
  [5]=>
  string(29) "backend_mcp_poll_interval_sec"
  [6]=>
  string(18) "max_forwarding_pps"
  [7]=>
  string(19) "forwarding_contract"
}
bool(true)
array(6) {
  [0]=>
  string(4) "name"
  [1]=>
  string(5) "build"
  [2]=>
  string(7) "version"
  [3]=>
  string(14) "implementation"
  [4]=>
  string(13) "configuration"
  [5]=>
  string(17) "info_generated_at"
}
string(12) "object_store"
string(13) "config_backed"
array(7) {
  [0]=>
  string(7) "enabled"
  [1]=>
  string(16) "storage_contract"
  [2]=>
  string(20) "legacy_backend_alias"
  [3]=>
  string(18) "simulated_backends"
  [4]=>
  string(15) "redundancy_mode"
  [5]=>
  string(18) "replication_factor"
  [6]=>
  string(13) "chunk_size_mb"
}
bool(true)
array(6) {
  [0]=>
  string(4) "name"
  [1]=>
  string(5) "build"
  [2]=>
  string(7) "version"
  [3]=>
  string(14) "implementation"
  [4]=>
  string(13) "configuration"
  [5]=>
  string(17) "info_generated_at"
}
string(3) "cdn"
string(13) "config_backed"
array(5) {
  [0]=>
  string(7) "enabled"
  [1]=>
  string(10) "cache_mode"
  [2]=>
  string(15) "default_ttl_sec"
  [3]=>
  string(19) "origin_mcp_endpoint"
  [4]=>
  string(20) "origin_http_endpoint"
}
bool(true)
array(6) {
  [0]=>
  string(4) "name"
  [1]=>
  string(5) "build"
  [2]=>
  string(7) "version"
  [3]=>
  string(14) "implementation"
  [4]=>
  string(13) "configuration"
  [5]=>
  string(17) "info_generated_at"
}
string(12) "semantic_dns"
string(13) "config_backed"
array(8) {
  [0]=>
  string(13) "server_enable"
  [1]=>
  string(16) "server_bind_host"
  [2]=>
  string(11) "server_port"
  [3]=>
  string(4) "mode"
  [4]=>
  string(22) "default_record_ttl_sec"
  [5]=>
  string(38) "service_discovery_max_ips_per_response"
  [6]=>
  string(20) "semantic_mode_enable"
  [7]=>
  string(14) "mothernode_uri"
}
bool(true)
array(6) {
  [0]=>
  string(4) "name"
  [1]=>
  string(5) "build"
  [2]=>
  string(7) "version"
  [3]=>
  string(14) "implementation"
  [4]=>
  string(13) "configuration"
  [5]=>
  string(17) "info_generated_at"
}
string(3) "mcp"
string(13) "config_backed"
array(8) {
  [0]=>
  string(26) "default_request_timeout_ms"
  [1]=>
  string(22) "max_message_size_bytes"
  [2]=>
  string(23) "request_caching_enabled"
  [3]=>
  string(21) "request_cache_ttl_sec"
  [4]=>
  string(19) "transfer_state_path"
  [5]=>
  string(26) "transfer_queue_persistence"
  [6]=>
  string(23) "transfer_cleanup_policy"
  [7]=>
  string(14) "topology_scope"
}
bool(true)
array(6) {
  [0]=>
  string(4) "name"
  [1]=>
  string(5) "build"
  [2]=>
  string(7) "version"
  [3]=>
  string(14) "implementation"
  [4]=>
  string(13) "configuration"
  [5]=>
  string(17) "info_generated_at"
}
string(21) "pipeline_orchestrator"
string(13) "config_backed"
array(22) {
  [0]=>
  string(27) "default_pipeline_timeout_ms"
  [1]=>
  string(19) "max_recursion_depth"
  [2]=>
  string(24) "loop_concurrency_default"
  [3]=>
  string(27) "distributed_tracing_enabled"
  [4]=>
  string(10) "state_path"
  [5]=>
  string(18) "logging_configured"
  [6]=>
  string(17) "execution_backend"
  [7]=>
  string(14) "topology_scope"
  [8]=>
  string(16) "scheduler_policy"
  [9]=>
  string(12) "retry_policy"
  [10]=>
  string(18) "idempotency_policy"
  [11]=>
  string(17) "worker_queue_path"
  [12]=>
  string(11) "remote_host"
  [13]=>
  string(11) "remote_port"
  [14]=>
  string(20) "recovered_from_state"
  [15]=>
  string(10) "tool_count"
  [16]=>
  string(17) "run_history_count"
  [17]=>
  string(16) "active_run_count"
  [18]=>
  string(16) "queued_run_count"
  [19]=>
  string(11) "last_run_id"
  [20]=>
  string(15) "last_run_status"
  [21]=>
  string(16) "registered_tools"
}
bool(true)
array(6) {
  [0]=>
  string(4) "name"
  [1]=>
  string(5) "build"
  [2]=>
  string(7) "version"
  [3]=>
  string(14) "implementation"
  [4]=>
  string(13) "configuration"
  [5]=>
  string(17) "info_generated_at"
}
string(5) "iibin"
string(13) "config_backed"
array(4) {
  [0]=>
  string(17) "max_schema_fields"
  [1]=>
  string(19) "max_recursion_depth"
  [2]=>
  string(23) "string_interning_enable"
  [3]=>
  string(21) "shared_memory_buffers"
}
bool(true)
