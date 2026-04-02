--TEST--
King Smart-DNS serves bounded on-wire UDP DNS queries with timeout, truncation, and recovery behavior
--INI--
king.security_allow_config_override=1
--FILE--
<?php
require __DIR__ . '/semantic_dns_wire_helper.inc';

$port = king_semantic_dns_wire_allocate_udp_port();

var_dump(king_semantic_dns_init([
    'enabled' => true,
    'bind_address' => '127.0.0.1',
    'dns_port' => $port,
    'service_discovery_max_ips_per_response' => 64,
    'default_record_ttl_sec' => 60,
    'semantic_mode_enable' => false,
    'mothernode_uri' => 'mother://wire-proof',
]));
var_dump(king_semantic_dns_start_server());

var_dump(king_semantic_dns_register_service([
    'service_id' => 'video-a',
    'service_name' => 'video',
    'service_type' => 'http_server',
    'hostname' => '127.0.0.11',
    'port' => 8080,
    'status' => 'healthy',
    'current_load_percent' => 3,
    'active_connections' => 8,
    'total_requests' => 100,
]));
var_dump(king_semantic_dns_register_service([
    'service_id' => 'video-b',
    'service_name' => 'video',
    'service_type' => 'http_server',
    'hostname' => '127.0.0.12',
    'port' => 8080,
    'status' => 'healthy',
    'current_load_percent' => 18,
    'active_connections' => 42,
    'total_requests' => 280,
]));

$first = king_semantic_dns_wire_query('127.0.0.1', $port, 'video');
$firstParsed = king_semantic_dns_wire_parse_response($first['response']);

var_dump($first['timed_out'] === false);
var_dump($firstParsed['rcode'] === 0);
var_dump($firstParsed['tc'] === false);
var_dump($firstParsed['answer_count'] === 2);
var_dump($firstParsed['answers'][0] === '127.0.0.11');
var_dump($firstParsed['answers'][1] === '127.0.0.12');

$missing = king_semantic_dns_wire_query('127.0.0.1', $port, 'missing');
$missingParsed = king_semantic_dns_wire_parse_response($missing['response']);

var_dump($missing['timed_out'] === false);
var_dump($missingParsed['rcode'] === 3);
var_dump($missingParsed['answer_count'] === 0);

$timeout = king_semantic_dns_wire_send_packet('127.0.0.1', $port, "\x12\x34\x01\x00\x00", 200);
var_dump($timeout['timed_out'] === true);

$recovery = king_semantic_dns_wire_query('127.0.0.1', $port, 'video');
$recoveryParsed = king_semantic_dns_wire_parse_response($recovery['response']);

var_dump($recovery['timed_out'] === false);
var_dump($recoveryParsed['rcode'] === 0);
var_dump($recoveryParsed['answers'][0] === '127.0.0.11');

for ($i = 1; $i <= 40; $i++) {
    var_dump(king_semantic_dns_register_service([
        'service_id' => 'wide-' . $i,
        'service_name' => 'wide',
        'service_type' => 'http_server',
        'hostname' => '127.0.1.' . $i,
        'port' => 8080,
        'status' => 'healthy',
        'current_load_percent' => $i,
        'active_connections' => $i,
        'total_requests' => $i,
    ]));
}

$truncated = king_semantic_dns_wire_query('127.0.0.1', $port, 'wide');
$truncatedParsed = king_semantic_dns_wire_parse_response($truncated['response']);

var_dump($truncated['timed_out'] === false);
var_dump($truncatedParsed['rcode'] === 0);
var_dump($truncatedParsed['tc'] === true);
var_dump($truncatedParsed['answer_count'] > 0);
var_dump($truncatedParsed['answer_count'] < 40);

$postTruncation = king_semantic_dns_wire_query('127.0.0.1', $port, 'video');
$postTruncationParsed = king_semantic_dns_wire_parse_response($postTruncation['response']);

var_dump($postTruncation['timed_out'] === false);
var_dump($postTruncationParsed['rcode'] === 0);
var_dump($postTruncationParsed['answers'][0] === '127.0.0.11');
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
