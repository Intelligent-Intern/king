--TEST--
King local server listeners expose CORS metadata and wildcard policy state on the active session runtime
--FILE--
<?php
$captured = null;
$session = null;

var_dump(king_http1_server_listen(
    '127.0.0.1',
    8081,
    null,
    static function (array $request) use (&$captured, &$session): array {
        $captured = $request['cors'];
        $session = $request['session'];

        return ['status' => 204, 'headers' => [], 'body' => ''];
    }
));
var_dump(king_get_last_error());
var_dump($captured);

$stats = king_get_stats($session);
var_dump([
    'state' => $stats['state'],
    'server_cors_active' => $stats['server_cors_active'],
    'server_cors_allow_any_origin' => $stats['server_cors_allow_any_origin'],
    'server_cors_apply_count' => $stats['server_cors_apply_count'],
    'server_last_cors_allowed_origin_count' => $stats['server_last_cors_allowed_origin_count'],
    'server_last_cors_policy' => $stats['server_last_cors_policy'],
    'server_last_cors_allow_origin' => $stats['server_last_cors_allow_origin'],
    'config_security_cors_allowed_origins' => $stats['config_security_cors_allowed_origins'],
]);
?>
--EXPECT--
bool(true)
string(0) ""
array(7) {
  ["enabled"]=>
  bool(true)
  ["allow_any_origin"]=>
  bool(true)
  ["preflight"]=>
  bool(false)
  ["policy"]=>
  string(1) "*"
  ["origin"]=>
  NULL
  ["allow_origin"]=>
  string(1) "*"
  ["allowed_origins"]=>
  array(1) {
    [0]=>
    string(1) "*"
  }
}
array(8) {
  ["state"]=>
  string(6) "closed"
  ["server_cors_active"]=>
  bool(true)
  ["server_cors_allow_any_origin"]=>
  bool(true)
  ["server_cors_apply_count"]=>
  int(1)
  ["server_last_cors_allowed_origin_count"]=>
  int(1)
  ["server_last_cors_policy"]=>
  string(1) "*"
  ["server_last_cors_allow_origin"]=>
  string(1) "*"
  ["config_security_cors_allowed_origins"]=>
  string(1) "*"
}
