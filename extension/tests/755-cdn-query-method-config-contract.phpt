--TEST--
King CDN config accepts RFC 10008 QUERY in allowed HTTP methods
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$config = King\Config::new([
    'cdn.enable' => true,
    'cdn.allowed_http_methods' => 'GET, QUERY,HEAD',
]);

var_dump($config->get('cdn.allowed_http_methods'));

$snapshot = $config->toArray();
var_dump($snapshot['cdn.allowed_http_methods']);

$session = king_connect('127.0.0.1', 443, $config);
$stats = king_get_stats($session);

var_dump($stats['config_binding']);
var_dump($stats['config_cdn_enable']);
var_dump($stats['config_cdn_allowed_http_methods']);

king_close($session);

try {
    King\Config::new(['cdn.allowed_http_methods' => 'GET,TRACE']);
    var_dump('no-exception');
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}
?>
--EXPECTF--
string(15) "GET, QUERY,HEAD"
string(15) "GET, QUERY,HEAD"
string(8) "resource"
bool(true)
string(15) "GET, QUERY,HEAD"
string(24) "InvalidArgumentException"
string(%d) "Invalid value provided. The value contains an unsupported algorithm or format."
