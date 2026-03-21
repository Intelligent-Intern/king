--TEST--
King CDN edge-node getter returns a stable empty list in the skeleton build
--FILE--
<?php
$nodes = king_cdn_get_edge_nodes();
var_dump($nodes);
var_dump(array_is_list($nodes));
var_dump(count($nodes));
?>
--EXPECT--
array(0) {
}
bool(true)
int(0)
