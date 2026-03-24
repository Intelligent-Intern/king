--TEST--
King Config OO setters honor override policy and reads stay inside the active parity slice
--INI--
king.transport_cc_algorithm=cubic
--FILE--
<?php
$config = new King\Config();

var_dump($config->get('quic.cc_algorithm'));

try {
    $config->set('quic.cc_algorithm', 'bbr');
    echo "no-exception-1\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

try {
    $config->get('quic.pacing_enable');
    echo "no-exception-2\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}
?>
--EXPECTF--
string(5) "cubic"
string(21) "King\RuntimeException"
string(%d) "Configuration override is disabled by system policy."
string(24) "InvalidArgumentException"
string(%d) "Config::get() does not yet expose runtime key 'quic.pacing_enable' outside the active OO parity slice."
