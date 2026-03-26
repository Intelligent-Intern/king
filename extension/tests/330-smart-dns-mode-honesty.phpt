--TEST--
King Smart DNS v1 only accepts service_discovery mode
--INI--
king.security_allow_config_override=1
--FILE--
<?php
foreach (['authoritative', 'recursive_resolver'] as $mode) {
    try {
        king_new_config(['dns.mode' => $mode]);
        echo "no-exception-$mode\n";
    } catch (Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage());
    }
}

$cfg = king_new_config([
    'dns.mode' => 'service_discovery',
    'dns.server_enable' => true,
]);
$session = king_connect('127.0.0.1', 443, $cfg);
$stats = king_get_stats($session);
var_dump($stats['config_dns_mode']);
?>
--EXPECT--
string(24) "InvalidArgumentException"
string(64) "Smart-DNS v1 currently only supports dns.mode=service_discovery."
string(24) "InvalidArgumentException"
string(64) "Smart-DNS v1 currently only supports dns.mode=service_discovery."
string(17) "service_discovery"
