--TEST--
King XSLT explicit missing runtime fails closed with a usable status
--SKIPIF--
<?php
if (!extension_loaded('king') || !function_exists('king_xslt_engine_status')) die('skip King XSLT unavailable');
?>
--ENV--
KING_SAXONC_LIBRARY=/king-deliberately-missing/libsaxonc-core-he.so
--FILE--
<?php
$status=king_xslt_engine_status();
var_dump($status['available']);
echo str_contains($status['error'],'Cannot load KING_SAXONC_LIBRARY')?'explicit runtime rejected':'WRONG ERROR',"\n";
try{king_xslt_transform_file(__FILE__,__FILE__);}
catch(King\RuntimeException $e){echo "transform fails closed\n";}
?>
--EXPECT--
bool(false)
explicit runtime rejected
transform fails closed
