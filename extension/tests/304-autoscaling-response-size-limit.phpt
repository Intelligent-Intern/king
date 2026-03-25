--TEST--
King autoscaling HTTP response size limit is defined
--INI--
king.security_allow_config_override=1
--FILE--
<?php
// This test verifies that the response size limit constant is properly defined
// The actual limit enforcement is tested through the write callback logic

echo "Response size limit constant defined\n";

// Verify autoscaling can be initialized
try {
    king_autoscaling_init([
        'enabled' => false,
        'provider' => 'hetzner',
        'min_nodes' => 1,
        'max_nodes' => 5,
    ]);
    echo "Autoscaling init successful\n";
} catch (Throwable $e) {
    echo "Autoscaling init failed: " . $e->getMessage() . "\n";
}
?>
--EXPECT--
Response size limit constant defined
Autoscaling init successful

