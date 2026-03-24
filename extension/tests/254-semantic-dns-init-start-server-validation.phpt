--TEST--
King semantic DNS init and start-server validate local runtime state and config
--INI--
king.security_allow_config_override=1
--FILE--
<?php
try {
    king_semantic_dns_start_server();
} catch (King\Exception $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'king_semantic_dns_init'));
}

foreach ([
    ['enabled' => 'yes'],
    ['dns_port' => 0],
    ['bind_address' => ''],
    ['service_discovery_max_ips_per_response' => 0],
    ['mothernode_uri' => ''],
] as $config) {
    try {
        king_semantic_dns_init($config);
    } catch (King\Exception $e) {
        var_dump(get_class($e));
        var_dump($e instanceof King\ValidationException);
    }
}

var_dump(king_semantic_dns_init(['enabled' => false]));

try {
    king_semantic_dns_start_server();
} catch (King\Exception $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'disabled'));
}
?>
--EXPECT--
string(21) "King\RuntimeException"
bool(true)
string(24) "King\ValidationException"
bool(true)
string(24) "King\ValidationException"
bool(true)
string(24) "King\ValidationException"
bool(true)
string(24) "King\ValidationException"
bool(true)
string(24) "King\ValidationException"
bool(true)
bool(true)
string(21) "King\RuntimeException"
bool(true)
