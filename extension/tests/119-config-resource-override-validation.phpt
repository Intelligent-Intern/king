--TEST--
King\Config validates supported runtime override keys and values
--INI--
king.security_allow_config_override=1
--FILE--
<?php
try {
    king_new_config(['tls_verify_peer' => 'nope']);
    var_dump('no-exception-1');
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}

try {
    king_new_config(['enable' => true]);
    var_dump('no-exception-2');
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump($e->getMessage());
}
?>
--EXPECTF--
string(24) "InvalidArgumentException"
string(%d) "Configuration parameter 'tls_verify_peer' must be a boolean (true or false), string given."
string(24) "InvalidArgumentException"
string(%d) "Unsupported runtime config override 'enable'. Use namespaced keys under quic., tls., http2., tcp., autoscale., mcp., orchestrator., geometry., smartcontract., ssh., storage., cdn., dns., or otel."
