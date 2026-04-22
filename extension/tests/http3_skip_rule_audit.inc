<?php

$libraryOnly = ['KING_QUICHE_LIBRARY'];
$libraryAndCargo = ['KING_QUICHE_LIBRARY', 'command -v cargo'];
$serverAndLibrary = ['KING_QUICHE_SERVER', 'KING_QUICHE_LIBRARY'];
$serverLibraryAndCargo = ['KING_QUICHE_SERVER', 'KING_QUICHE_LIBRARY', 'command -v cargo'];

$runtimeGates = ['KING_LSQUIC_LIBRARY', 'KING_HTTP3_BACKEND_LSQUIC'];
$helperGates = ['KING_LSQUIC_LIBRARY', 'KING_HTTP3_BACKEND_LSQUIC', 'infra/scripts/build-http3-test-helpers.sh --verify-plan'];

return [
    'issue' => '#Q-8',
    'scope' => 'http3_behavior_preservation_matrix',
    'missing_new_stack_counts_as_success' => false,
    'done_checkbox' => 'HTTP/3 tests prove the new stack without Quiche or Cargo bootstrap.',
    'legacy_skip_literals' => ['KING_QUICHE_LIBRARY', 'KING_QUICHE_SERVER', 'command -v cargo'],
    'acceptable_environment_skip_literals' => [
        'command -v openssl',
        "extension_loaded('pcntl')",
        "extension_loaded('posix')",
    ],
    'required_future_gates' => [
        'KING_LSQUIC_LIBRARY',
        'KING_HTTP3_BACKEND_LSQUIC',
        'infra/scripts/build-http3-test-helpers.sh --verify-plan',
    ],
    'tests' => [
        'extension/tests/204-http3-connect-timeout-direct-and-dispatch.phpt' => [
            'skip_class' => 'legacy_quiche_runtime_blocker',
            'legacy_skip_literals' => $libraryOnly,
            'future_gates' => $runtimeGates,
            'missing_new_stack_counts_as_success' => false,
            'blocks_done' => true,
        ],
        'extension/tests/377-http3-handshake-failure-contract.phpt' => [
            'skip_class' => 'legacy_quiche_server_runtime_blocker',
            'legacy_skip_literals' => $serverAndLibrary,
            'future_gates' => $helperGates,
            'missing_new_stack_counts_as_success' => false,
            'blocks_done' => true,
        ],
        'extension/tests/378-http3-transport-close-contract.phpt' => [
            'skip_class' => 'legacy_quiche_cargo_helper_blocker',
            'legacy_skip_literals' => $libraryAndCargo,
            'future_gates' => $helperGates,
            'missing_new_stack_counts_as_success' => false,
            'blocks_done' => true,
        ],
        'extension/tests/380-http3-session-ticket-reuse-contract.phpt' => [
            'skip_class' => 'legacy_quiche_cargo_helper_blocker',
            'legacy_skip_literals' => $libraryAndCargo,
            'future_gates' => $helperGates,
            'missing_new_stack_counts_as_success' => false,
            'blocks_done' => true,
        ],
        'extension/tests/485-http3-timeout-slow-peer-contract.phpt' => [
            'skip_class' => 'legacy_quiche_cargo_helper_blocker',
            'legacy_skip_literals' => $libraryAndCargo,
            'future_gates' => $helperGates,
            'missing_new_stack_counts_as_success' => false,
            'blocks_done' => true,
        ],
        'extension/tests/486-http3-multi-backpressure-contract.phpt' => [
            'skip_class' => 'legacy_quiche_cargo_helper_blocker',
            'legacy_skip_literals' => $libraryAndCargo,
            'future_gates' => $helperGates,
            'missing_new_stack_counts_as_success' => false,
            'blocks_done' => true,
        ],
        'extension/tests/487-http3-sustained-fairness-contract.phpt' => [
            'skip_class' => 'legacy_quiche_cargo_helper_blocker',
            'legacy_skip_literals' => $libraryAndCargo,
            'future_gates' => $helperGates,
            'missing_new_stack_counts_as_success' => false,
            'blocks_done' => true,
        ],
        'extension/tests/503-http3-early-data-session-ticket-contract.phpt' => [
            'skip_class' => 'legacy_quiche_cargo_helper_blocker',
            'legacy_skip_literals' => $libraryAndCargo,
            'future_gates' => $helperGates,
            'missing_new_stack_counts_as_success' => false,
            'blocks_done' => true,
        ],
        'extension/tests/504-http3-packet-loss-recovery-contract.phpt' => [
            'skip_class' => 'legacy_quiche_cargo_helper_blocker',
            'legacy_skip_literals' => $libraryAndCargo,
            'future_gates' => $helperGates,
            'missing_new_stack_counts_as_success' => false,
            'blocks_done' => true,
        ],
        'extension/tests/532-http3-quic-event-loop-wake-idle-timeout-contract.phpt' => [
            'skip_class' => 'legacy_quiche_cargo_helper_blocker',
            'legacy_skip_literals' => $libraryAndCargo,
            'future_gates' => $helperGates,
            'missing_new_stack_counts_as_success' => false,
            'blocks_done' => true,
        ],
        'extension/tests/534-http3-quic-flow-control-recovery-contract.phpt' => [
            'skip_class' => 'legacy_quiche_cargo_helper_blocker',
            'legacy_skip_literals' => $libraryAndCargo,
            'future_gates' => $helperGates,
            'missing_new_stack_counts_as_success' => false,
            'blocks_done' => true,
        ],
        'extension/tests/535-http3-quic-zero-rtt-acceptance-and-fallback-contract.phpt' => [
            'skip_class' => 'legacy_quiche_cargo_helper_blocker',
            'legacy_skip_literals' => $libraryAndCargo,
            'future_gates' => $helperGates,
            'missing_new_stack_counts_as_success' => false,
            'blocks_done' => true,
        ],
        'extension/tests/536-oo-http3-client-error-mapping-matrix-contract.phpt' => [
            'skip_class' => 'legacy_quiche_server_cargo_helper_blocker',
            'legacy_skip_literals' => $serverLibraryAndCargo,
            'future_gates' => $helperGates,
            'missing_new_stack_counts_as_success' => false,
            'blocks_done' => true,
        ],
        'extension/tests/537-http3-quic-stats-live-counter-contract.phpt' => [
            'skip_class' => 'legacy_quiche_cargo_helper_blocker',
            'legacy_skip_literals' => $libraryAndCargo,
            'future_gates' => $helperGates,
            'missing_new_stack_counts_as_success' => false,
            'blocks_done' => true,
        ],
        'extension/tests/538-http3-quic-network-interruption-rewake-contract.phpt' => [
            'skip_class' => 'legacy_quiche_cargo_helper_blocker',
            'legacy_skip_literals' => $libraryAndCargo,
            'future_gates' => $helperGates,
            'missing_new_stack_counts_as_success' => false,
            'blocks_done' => true,
        ],
        'extension/tests/645-http3-quic-stress-and-partial-failure-matrix-contract.phpt' => [
            'skip_class' => 'legacy_quiche_cargo_helper_blocker',
            'legacy_skip_literals' => $libraryAndCargo,
            'future_gates' => $helperGates,
            'missing_new_stack_counts_as_success' => false,
            'blocks_done' => true,
        ],
    ],
];
