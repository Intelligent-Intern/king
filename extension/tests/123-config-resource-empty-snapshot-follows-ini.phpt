--TEST--
King\Config empty snapshots inherit current INI-backed module globals across the active runtime surface
--INI--
king.transport_cc_algorithm=bbr
king.tls_verify_peer=0
king.http2_max_concurrent_streams=32
king.tcp_enable=0
king.storage_default_redundancy_mode=replication
king.cdn_cache_mode=memory
king.dns_mode=authoritative
king.otel_service_name=king_assessment
king.cluster_autoscale_provider=hetzner
king.cluster_autoscale_max_nodes=5
king.mcp_default_request_timeout_ms=41000
king.mcp_enable_request_caching=1
king.orchestrator_enable_distributed_tracing=0
king.geometry_default_vector_dimensions=1024
king.geometry_calculation_precision=float32
king.smartcontract_enable=1
king.smartcontract_dlt_provider=solana
king.ssh_gateway_enable=1
king.ssh_gateway_auth_mode=mcp_token
--FILE--
<?php
$cfg = king_new_config([]);
$session = king_connect('127.0.0.1', 443, $cfg);
$stats = king_get_stats($session);

var_dump($stats['config_binding']);
var_dump($stats['config_is_frozen']);
var_dump($stats['config_userland_overrides_applied']);
var_dump($stats['config_option_count']);
var_dump($stats['config_quic_cc_algorithm']);
var_dump($stats['config_tls_verify_peer']);
var_dump($stats['config_http2_max_concurrent_streams']);
var_dump($stats['config_tcp_enable']);
var_dump($stats['config_storage_default_redundancy_mode']);
var_dump($stats['config_cdn_cache_mode']);
var_dump($stats['config_dns_mode']);
var_dump($stats['config_otel_service_name']);
var_dump($stats['config_autoscale_provider']);
var_dump($stats['config_autoscale_max_nodes']);
var_dump($stats['config_mcp_enable_request_caching']);
var_dump($stats['config_mcp_default_request_timeout_ms']);
var_dump($stats['config_orchestrator_enable_distributed_tracing']);
var_dump($stats['config_geometry_default_vector_dimensions']);
var_dump($stats['config_geometry_calculation_precision']);
var_dump($stats['config_smartcontract_enable']);
var_dump($stats['config_smartcontract_dlt_provider']);
var_dump($stats['config_ssh_gateway_enable']);
var_dump($stats['config_ssh_gateway_auth_mode']);
?>
--EXPECT--
string(8) "resource"
bool(true)
bool(false)
int(0)
string(3) "bbr"
bool(false)
int(32)
bool(false)
string(11) "replication"
string(6) "memory"
string(13) "authoritative"
string(15) "king_assessment"
string(7) "hetzner"
int(5)
bool(true)
int(41000)
bool(false)
int(1024)
string(7) "float32"
bool(true)
string(6) "solana"
bool(true)
string(9) "mcp_token"
