--TEST--
King MoE training DSL emits a validated distributed control-plane contract
--FILE--
<?php
require dirname(__DIR__, 2) . '/demo/userland/training-php/tests/moe-pretrain-dsl-contract.php';
?>
--EXPECT--
moe training dsl contract ok
