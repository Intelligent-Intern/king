--TEST--
King\Config rejects non-string override keys and conflicting alias keys
--INI--
king.security_allow_config_override=1
--FILE--
<?php
try {
    king_new_config([0 => true]);
    var_dump('no-exception-1');
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

try {
    king_new_config([
        'quic.cc_algorithm' => 'bbr',
        'cc_algorithm' => 'cubic',
    ]);
    var_dump('no-exception-2');
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}
?>
--EXPECT--
string(24) "InvalidArgumentException"
string(45) "Configuration overrides must use string keys."
string(24) "InvalidArgumentException"
string(154) "Duplicate runtime config override 'cc_algorithm' resolves to normalized key 'cc_algorithm', which was already provided. Use one canonical key per setting."
