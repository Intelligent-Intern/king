--TEST--
King Smart DNS v1 rejects retired config knobs and only exposes active runtime settings
--INI--
king.security_allow_config_override=1
--FILE--
<?php
foreach ([
    ['dns.server_enable_tcp' => true],
    ['dns.static_zone_file_path' => '/tmp/zones.db'],
    ['dns.recursive_forwarders' => '1.1.1.1'],
    ['dns.health_agent_mcp_endpoint' => '127.0.0.1:9998'],
    ['dns.enable_dnssec_validation' => true],
    ['dns.edns_udp_payload_size' => 1400],
    ['dns.mothernode_sync_interval_sec' => 60],
] as $config) {
    try {
        king_new_config($config);
        echo "no-exception-config\n";
    } catch (Throwable $e) {
        echo get_class($e), "\n";
        echo $e->getMessage(), "\n";
    }
}

var_dump(ini_get('king.dns_server_enable_tcp'));
var_dump(ini_get('king.dns_static_zone_file_path'));
var_dump(ini_get('king.dns_enable_dnssec_validation'));
var_dump(ini_get('king.dns_edns_udp_payload_size'));
var_dump(ini_get('king.dns_mothernode_sync_interval_sec'));

foreach ([
    ['server_enable_tcp' => true],
    ['health_check_interval_ms' => 1000],
    ['mothernode_sync_interval_sec' => 60],
] as $config) {
    try {
        king_semantic_dns_init($config);
        echo "no-exception-init\n";
    } catch (Throwable $e) {
        echo get_class($e), "\n";
        echo $e->getMessage(), "\n";
    }
}

var_dump(king_semantic_dns_init([
    'enabled' => true,
    'bind_address' => '127.0.0.1',
    'dns_port' => 8053,
    'default_record_ttl_sec' => 120,
    'service_discovery_max_ips_per_response' => 5,
    'semantic_mode_enable' => true,
    'mothernode_uri' => 'mother://node-1',
]));

$component = king_system_get_component_info('semantic_dns');
var_dump(array_keys($component['configuration']));
?>
--EXPECT--
InvalidArgumentException
Smart-DNS v1 does not support dns.server_enable_tcp.
InvalidArgumentException
Smart-DNS v1 does not support dns.static_zone_file_path.
InvalidArgumentException
Smart-DNS v1 does not support dns.recursive_forwarders.
InvalidArgumentException
Smart-DNS v1 does not support dns.health_agent_mcp_endpoint.
InvalidArgumentException
Smart-DNS v1 does not support dns.enable_dnssec_validation.
InvalidArgumentException
Smart-DNS v1 does not support dns.edns_udp_payload_size.
InvalidArgumentException
Smart-DNS v1 does not support dns.mothernode_sync_interval_sec.
bool(false)
bool(false)
bool(false)
bool(false)
bool(false)
King\ValidationException
Semantic-DNS v1 does not support init option 'server_enable_tcp'.
King\ValidationException
Semantic-DNS v1 does not support init option 'health_check_interval_ms'.
King\ValidationException
Semantic-DNS v1 does not support init option 'mothernode_sync_interval_sec'.
bool(true)
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
