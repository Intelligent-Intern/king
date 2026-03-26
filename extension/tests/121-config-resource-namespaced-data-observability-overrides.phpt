--TEST--
King\Config applies namespaced storage cdn dns and otel overrides across the bound runtime session snapshot
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$cfg = king_new_config([
    'storage.enable' => true,
    'storage.default_redundancy_mode' => 'replication',
    'cdn.enable' => true,
    'cdn.cache_mode' => 'memory',
    'dns.server_enable' => true,
    'dns.mode' => 'service_discovery',
    'otel.enable' => false,
    'otel.service_name' => 'king_assessment',
]);

$session = king_connect('127.0.0.1', 443, $cfg);
$stats = king_get_stats($session);

var_dump($stats['config_storage_enable']);
var_dump($stats['config_storage_default_redundancy_mode']);
var_dump($stats['config_cdn_enable']);
var_dump($stats['config_cdn_cache_mode']);
var_dump($stats['config_dns_server_enable']);
var_dump($stats['config_dns_mode']);
var_dump($stats['config_otel_enable']);
var_dump($stats['config_otel_service_name']);
var_dump($stats['config_option_count']);
?>
--EXPECT--
bool(true)
string(11) "replication"
bool(true)
string(6) "memory"
bool(true)
string(17) "service_discovery"
bool(false)
string(15) "king_assessment"
int(8)
