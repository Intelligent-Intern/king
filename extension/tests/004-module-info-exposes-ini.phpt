--TEST--
King module info exposes INI directives through phpinfo
--FILE--
<?php
ob_start();
phpinfo(INFO_MODULES);
$info = ob_get_clean();

var_dump(strpos($info, 'king.security_allow_config_override') !== false);
var_dump(strpos($info, 'king.admin_api_enable') !== false);
var_dump(strpos($info, 'king.socket_enable_timestamping') !== false);
var_dump(strpos($info, 'Active runtimes') !== false);
var_dump(strpos($info, 'Stubbed API groups') !== false);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
