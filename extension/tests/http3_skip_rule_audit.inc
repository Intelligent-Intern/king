<?php

$runtimeGates = ['KING_LSQUIC_LIBRARY', 'KING_HTTP3_BACKEND_LSQUIC'];
$helperGates = ['KING_LSQUIC_LIBRARY', 'KING_HTTP3_BACKEND_LSQUIC', 'infra/scripts/build-http3-test-helpers.sh build'];

$runtimeOnly = [
    'extension/tests/204-http3-connect-timeout-direct-and-dispatch.phpt',
    'extension/tests/377-http3-handshake-failure-contract.phpt',
];

$helperBacked = [
    'extension/tests/378-http3-transport-close-contract.phpt',
    'extension/tests/380-http3-session-ticket-reuse-contract.phpt',
    'extension/tests/485-http3-timeout-slow-peer-contract.phpt',
    'extension/tests/486-http3-multi-backpressure-contract.phpt',
    'extension/tests/487-http3-sustained-fairness-contract.phpt',
    'extension/tests/503-http3-early-data-session-ticket-contract.phpt',
    'extension/tests/504-http3-packet-loss-recovery-contract.phpt',
    'extension/tests/532-http3-quic-event-loop-wake-idle-timeout-contract.phpt',
    'extension/tests/534-http3-quic-flow-control-recovery-contract.phpt',
    'extension/tests/535-http3-quic-zero-rtt-acceptance-and-fallback-contract.phpt',
    'extension/tests/536-oo-http3-client-error-mapping-matrix-contract.phpt',
    'extension/tests/537-http3-quic-stats-live-counter-contract.phpt',
    'extension/tests/538-http3-quic-network-interruption-rewake-contract.phpt',
    'extension/tests/645-http3-quic-stress-and-partial-failure-matrix-contract.phpt',
];

$tests = [];
foreach ($runtimeOnly as $path) {
    $tests[$path] = [
        'skip_class' => 'new_stack_lsquic_runtime_gate',
        'legacy_skip_literals' => [],
        'future_gates' => $runtimeGates,
        'missing_new_stack_counts_as_success' => false,
        'blocks_done' => false,
    ];
}
foreach ($helperBacked as $path) {
    $tests[$path] = [
        'skip_class' => 'new_stack_c_helper_gate',
        'legacy_skip_literals' => [],
        'future_gates' => $helperGates,
        'missing_new_stack_counts_as_success' => false,
        'blocks_done' => false,
    ];
}
ksort($tests);

return [
    'issue' => '#Q-8',
    'scope' => 'http3_behavior_preservation_matrix',
    'missing_new_stack_counts_as_success' => false,
    'done_checkbox' => 'HTTP/3 tests prove the new stack without Quiche or Cargo bootstrap.',
    'legacy_skip_literals' => [],
    'acceptable_environment_skip_literals' => [
        'command -v openssl',
        "extension_loaded('pcntl')",
        "extension_loaded('posix')",
    ],
    'required_future_gates' => [
        'KING_LSQUIC_LIBRARY',
        'KING_HTTP3_BACKEND_LSQUIC',
        'infra/scripts/build-http3-test-helpers.sh build',
    ],
    'tests' => $tests,
];
