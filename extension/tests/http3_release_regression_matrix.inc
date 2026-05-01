<?php

return [
    'issue' => '#Q-11',
    'scope' => 'Full HTTP/3 Regression Against New Stack',
    'checklist' => [
        'client_one_shot_request_response' => [
            'label' => 'Client one-shot request/response tests are green.',
            'tests' => [
                [
                    'path' => 'extension/tests/190-http3-request-send-roundtrip.phpt',
                    'needles' => [
                        'real local HTTPS roundtrips directly and over the dispatcher',
                        'king_http3_request_send(',
                        'king_client_send_request(',
                        'lsquic_h3',
                    ],
                ],
                [
                    'path' => 'extension/tests/338-http3-one-shot-churn-isolation.phpt',
                    'needles' => [
                        'one-shot runtime keeps timeout churn from poisoning later healthy direct and dispatcher requests',
                        'stable-direct-http3',
                        'stable-dispatch-http3',
                    ],
                ],
            ],
        ],
        'oo_http3_client_exception_matrix' => [
            'label' => 'OO `Http3Client` exception matrix is green.',
            'tests' => [
                [
                    'path' => 'extension/tests/531-oo-http3-client-public-exception-mapping-contract.phpt',
                    'needles' => [
                        'Http3Client maps remote QUIC aborts into stable public exception classes',
                        'King\\Client\\Http3Client',
                        'King\\QuicException',
                        'King\\ProtocolException',
                    ],
                ],
                [
                    'path' => 'extension/tests/536-oo-http3-client-error-mapping-matrix-contract.phpt',
                    'needles' => [
                        'Http3Client keeps QUIC TLS transport protocol timeout and cancellation failures',
                        'king_http3_start_silent_udp_error_map_server',
                        'King\\TimeoutException',
                    ],
                ],
            ],
        ],
        'server_one_shot_listener' => [
            'label' => 'Server one-shot listener tests are green.',
            'tests' => [
                [
                    'path' => 'extension/tests/384-http3-server-listen-on-wire-runtime.phpt',
                    'needles' => [
                        'LSQUIC one-shot listener accepts real direct and dispatcher clients',
                        'king_http3_server_wire_start_server',
                        "'protocol'] === 'http/3'",
                    ],
                ],
                [
                    'path' => 'extension/tests/680-server-http3-lsquic-behavior-contract.phpt',
                    'needles' => [
                        'LSQUIC listener preserves request, body, hints, response, and CORS semantics',
                        'king_server_http3_lsquic_read_request',
                        'LSQUIC handler gate waits for request FIN',
                    ],
                ],
                [
                    'path' => 'extension/tests/681-server-http3-lsquic-lifecycle-contract.phpt',
                    'needles' => [
                        'LSQUIC listener preserves TLS reload, cancel, and shutdown lifecycle',
                        'LSQUIC TLS context lifecycle',
                        'king_server_http3_runtime_destroy(&runtime);',
                    ],
                ],
            ],
        ],
        'session_ticket_and_zero_rtt' => [
            'label' => 'Session-ticket and 0-RTT tests are green.',
            'tests' => [
                [
                    'path' => 'extension/tests/380-http3-session-ticket-reuse-contract.phpt',
                    'needles' => [
                        'reuses session tickets through the shared ring',
                        'tls_has_session_ticket',
                        'tls_session_resumed',
                    ],
                ],
                [
                    'path' => 'extension/tests/503-http3-early-data-session-ticket-contract.phpt',
                    'needles' => [
                        'early-data accept, reject, and fallback semantics on resumed ticket paths',
                        'tls_enable_early_data',
                        'tls_request_sent_in_early_data',
                    ],
                ],
                [
                    'path' => 'extension/tests/535-http3-quic-zero-rtt-acceptance-and-fallback-contract.phpt',
                    'needles' => [
                        'zero-RTT acceptance and server-disabled fallback',
                        'tls_request_sent_in_early_data',
                        'x-king-early-data-phase',
                    ],
                ],
            ],
        ],
        'stream_reset_stop_cancel_timeout' => [
            'label' => 'Stream lifecycle, reset, stop-sending, cancel, and timeout tests are green.',
            'tests' => [
                [
                    'path' => 'extension/tests/527-http3-quic-idle-timeout-and-application-close-contract.phpt',
                    'needles' => [
                        'idle-timeout and application-close propagation against real peers',
                        'idle_timeout',
                        'application_close',
                    ],
                ],
                [
                    'path' => 'extension/tests/528-http3-quic-stream-lifecycle-contract.phpt',
                    'needles' => [
                        'stream lifecycle proves open body finish and read-drain behavior',
                        'stream-lifecycle',
                        'transport_backend',
                    ],
                ],
                [
                    'path' => 'extension/tests/529-http3-quic-reset-and-stop-sending-contract.phpt',
                    'needles' => [
                        'reset and stop-sending lifecycle against real peers',
                        'reset_stream',
                        'stop_sending_sent',
                    ],
                ],
                [
                    'path' => 'extension/tests/530-http3-quic-userland-cancel-propagation-contract.phpt',
                    'needles' => [
                        'propagate userland CancelToken aborts into active QUIC transport state',
                        'CancelToken',
                        'cancelled by userland CancelToken',
                    ],
                ],
            ],
        ],
        'loss_retransmit_congestion_flow_soak' => [
            'label' => 'Packet loss, retransmit, congestion control, flow control, and long-duration soak are green.',
            'tests' => [
                [
                    'path' => 'extension/tests/487-http3-sustained-fairness-contract.phpt',
                    'needles' => [
                        'sustained staggered load on one QUIC session',
                        'king_http3_request_send_multi',
                        'maxActiveStreams',
                    ],
                ],
                [
                    'path' => 'extension/tests/488-http3-long-duration-soak-contract.phpt',
                    'needles' => [
                        'survives repeated mixed-load bursts and keeps later healthy sessions clean',
                        '$roundCount = 6',
                        'stable-dispatch-http3',
                    ],
                ],
                [
                    'path' => 'extension/tests/504-http3-packet-loss-recovery-contract.phpt',
                    'needles' => [
                        'recover from injected packet loss with visible retransmit stats',
                        'quic_packets_lost',
                        'quic_packets_retransmitted',
                    ],
                ],
                [
                    'path' => 'extension/tests/533-http3-quic-congestion-control-constrained-link-contract.phpt',
                    'needles' => [
                        'congestion-control algorithms live under sustained constrained links',
                        "'algorithm' => 'cubic'",
                        "'algorithm' => 'bbr'",
                    ],
                ],
                [
                    'path' => 'extension/tests/534-http3-quic-flow-control-recovery-contract.phpt',
                    'needles' => [
                        'recover after QUIC flow-control exhaustion',
                        'flow_control_recovery',
                        'flow-control-ack',
                    ],
                ],
                [
                    'path' => 'extension/tests/645-http3-quic-stress-and-partial-failure-matrix-contract.phpt',
                    'needles' => [
                        'sustained stress rounds and repeated partial-failure recovery rounds',
                        'king_http3_645_run_stress_round',
                        'king_http3_645_run_partial_failure_round',
                    ],
                ],
            ],
        ],
        'websocket_over_http3' => [
            'label' => 'WebSocket-over-HTTP3 relevant slices are green.',
            'tests' => [
                [
                    'path' => 'extension/tests/542-server-websocket-http3-local-honesty.phpt',
                    'needles' => [
                        'HTTP/3 server-side websocket upgrade keeps an honest in-process local frame runtime',
                        'king_server_upgrade_to_websocket',
                        'http3-upgrade',
                    ],
                ],
                [
                    'path' => 'extension/tests/682-server-websocket-http3-onwire-honesty-contract.phpt',
                    'needles' => [
                        'WebSocket-over-HTTP3 keeps local honesty and rejects fake on-wire Extended CONNECT',
                        'king_server_websocket_reject_unsupported_on_wire_upgrade',
                        'does not support on-wire HTTP/3 WebSocket Extended CONNECT upgrades yet.',
                    ],
                ],
            ],
        ],
        'performance_baseline_documented' => [
            'label' => 'Performance baseline against the previous Quiche state is documented.',
            'documents' => [
                [
                    'path' => 'documentation/http3-regression-evidence.md',
                    'needles' => [
                        'HTTP/3 Regression Evidence',
                        'previous Quiche state is preserved as a release baseline',
                        'no regression below the preserved Quiche-era contract level',
                    ],
                ],
            ],
        ],
    ],
];
