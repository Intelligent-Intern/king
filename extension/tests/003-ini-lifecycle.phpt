--TEST--
King INI directives are registered with their expected defaults
--FILE--
<?php
var_dump(ini_get('king.security_allow_config_override'));
var_dump(ini_get('king.transport_cc_algorithm'));
var_dump(ini_get('king.tls_verify_peer'));
var_dump(ini_get('king.http2_max_concurrent_streams'));
var_dump(ini_get('king.tcp_enable'));
var_dump(ini_get('king.storage_default_redundancy_mode'));
var_dump(ini_get('king.cdn_cache_mode'));
var_dump(ini_get('king.dns_mode'));
var_dump(ini_get('king.otel_service_name'));
var_dump(ini_get('king.cluster_autoscale_provider'));
var_dump(ini_get('king.mcp_default_request_timeout_ms'));
var_dump(ini_get('king.mcp_transfer_state_path'));
var_dump(ini_get('king.geometry_calculation_precision'));
var_dump(ini_get('king.smartcontract_dlt_provider'));
var_dump(ini_get('king.ssh_gateway_auth_mode'));
?>
--EXPECT--
string(1) "0"
string(5) "cubic"
string(1) "1"
string(3) "100"
string(1) "1"
string(14) "erasure_coding"
string(4) "disk"
string(17) "service_discovery"
string(16) "king_application"
string(0) ""
string(5) "30000"
string(0) ""
string(7) "float64"
string(8) "ethereum"
string(4) "mtls"
