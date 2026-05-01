--TEST--
King Config OO setters honor override policy and exposes QUIC inventory reads
--INI--
king.transport_cc_algorithm=cubic
--FILE--
<?php
$config = new King\Config();

var_dump($config->get('quic.cc_algorithm'));
var_dump($config->get('quic.pacing_enable'));

try {
    $config->set('quic.cc_algorithm', 'bbr');
    echo "no-exception-1\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

?>
--EXPECTF--
string(5) "cubic"
bool(true)
string(21) "King\RuntimeException"
string(%d) "Configuration override is disabled by system policy."
