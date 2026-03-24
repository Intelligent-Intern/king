--TEST--
King semantic DNS optimal-route getter exposes a stable no-hit snapshot in the current runtime
--FILE--
<?php
$route = king_semantic_dns_get_optimal_route('api');
var_dump(array_keys($route));
var_dump($route['service_id']);
var_dump($route['error']);
var_dump(is_int($route['routed_at']));

$routeWithClient = king_semantic_dns_get_optimal_route('pipeline_orchestrator', [
    'location' => [
        'latitude' => 52.52,
        'longitude' => 13.405,
    ],
]);
var_dump(array_keys($routeWithClient));
var_dump($routeWithClient['service_id']);
var_dump($routeWithClient['error']);
var_dump(is_int($routeWithClient['routed_at']));
?>
--EXPECT--
array(3) {
  [0]=>
  string(10) "service_id"
  [1]=>
  string(5) "error"
  [2]=>
  string(9) "routed_at"
}
NULL
string(24) "No healthy service found"
bool(true)
array(3) {
  [0]=>
  string(10) "service_id"
  [1]=>
  string(5) "error"
  [2]=>
  string(9) "routed_at"
}
NULL
string(24) "No healthy service found"
bool(true)
