<?php

return [
    'issue' => '#Q-8',
    'behaviors' => [
        'handshake_failure' => [
            'strategy_capability' => 'handshake_failure',
            'tests' => [
                [
                    'path' => 'extension/tests/377-http3-handshake-failure-contract.phpt',
                    'needles' => [
                        'active QUIC/TLS handshake-failure contract',
                        'king_http3_request_send() failed during the QUIC/TLS handshake',
                        'king_client_send_request() failed during the QUIC/TLS handshake',
                        'TlsException',
                    ],
                ],
                [
                    'path' => 'extension/tests/536-oo-http3-client-error-mapping-matrix-contract.phpt',
                    'needles' => [
                        'QUIC TLS transport protocol timeout and cancellation failures',
                        'HttpClient::request() failed during the QUIC/TLS handshake',
                        'TlsException',
                    ],
                ],
            ],
        ],
        'transport_close' => [
            'strategy_capability' => 'transport_close',
            'tests' => [
                [
                    'path' => 'extension/tests/378-http3-transport-close-contract.phpt',
                    'needles' => [
                        'active QUIC transport-close contract',
                        'king_http3_request_send() received a QUIC transport close',
                        'king_client_send_request() received a QUIC transport close',
                        'transport_close',
                    ],
                ],
                [
                    'path' => 'extension/tests/536-oo-http3-client-error-mapping-matrix-contract.phpt',
                    'needles' => [
                        'transport_close',
                        'QuicException',
                        'test transport abort',
                    ],
                ],
            ],
        ],
        'timeout' => [
            'strategy_capability' => 'timeout',
            'tests' => [
                [
                    'path' => 'extension/tests/204-http3-connect-timeout-direct-and-dispatch.phpt',
                    'needles' => [
                        'active QUIC connect-timeout contract',
                        'timed out while establishing the QUIC connection',
                        'TimeoutException',
                    ],
                ],
                [
                    'path' => 'extension/tests/485-http3-timeout-slow-peer-contract.phpt',
                    'needles' => [
                        'real slow-reader and slow-writer peers',
                        'timed out while waiting for the HTTP/3 response',
                        'slow_response',
                        'slow_reader',
                    ],
                ],
                [
                    'path' => 'extension/tests/532-http3-quic-event-loop-wake-idle-timeout-contract.phpt',
                    'needles' => [
                        'times out on sustained silence',
                        'timed out while waiting for the HTTP/3 response',
                        'directTimeoutElapsedMs',
                        'dispatchTimeoutElapsedMs',
                    ],
                ],
            ],
        ],
        'flow_control' => [
            'strategy_capability' => 'flow_control',
            'tests' => [
                [
                    'path' => 'extension/tests/534-http3-quic-flow-control-recovery-contract.phpt',
                    'needles' => [
                        'QUIC flow-control exhaustion',
                        'flow_control_recovery',
                        'flow_control_pause_observed',
                        'flow_control_resume_observed',
                    ],
                ],
            ],
        ],
        'packet_loss' => [
            'strategy_capability' => 'packet_loss',
            'tests' => [
                [
                    'path' => 'extension/tests/504-http3-packet-loss-recovery-contract.phpt',
                    'needles' => [
                        'recover from injected packet loss',
                        'quic_packets_lost',
                        'quic_packets_retransmitted',
                        'quic_lost_bytes',
                    ],
                ],
                [
                    'path' => 'extension/tests/537-http3-quic-stats-live-counter-contract.phpt',
                    'needles' => [
                        'live QUIC counters',
                        'quic_packets_lost',
                        'quic_packets_retransmitted',
                        'quic_stream_retransmitted_bytes',
                    ],
                ],
                [
                    'path' => 'extension/tests/538-http3-quic-network-interruption-rewake-contract.phpt',
                    'needles' => [
                        'temporary QUIC network interruption',
                        'quic_packets_retransmitted',
                        'quic_stream_retransmitted_bytes',
                    ],
                ],
                [
                    'path' => 'extension/tests/645-http3-quic-stress-and-partial-failure-matrix-contract.phpt',
                    'needles' => [
                        'partial-failure recovery rounds',
                        'quic_packets_lost',
                        'quic_packets_retransmitted',
                    ],
                ],
            ],
        ],
        'zero_rtt' => [
            'strategy_capability' => 'zero_rtt',
            'tests' => [
                [
                    'path' => 'extension/tests/535-http3-quic-zero-rtt-acceptance-and-fallback-contract.phpt',
                    'needles' => [
                        'zero-RTT acceptance',
                        'server-disabled fallback',
                        'tls_request_sent_in_early_data',
                        'x-king-early-data-phase',
                    ],
                ],
                [
                    'path' => 'extension/tests/503-http3-early-data-session-ticket-contract.phpt',
                    'needles' => [
                        'early-data accept, reject, and fallback semantics',
                        'tls_enable_early_data',
                        'tls_request_sent_in_early_data',
                    ],
                ],
            ],
        ],
        'session_tickets' => [
            'strategy_capability' => 'session_tickets',
            'tests' => [
                [
                    'path' => 'extension/tests/380-http3-session-ticket-reuse-contract.phpt',
                    'needles' => [
                        'reuses session tickets through the shared ring',
                        'tls_has_session_ticket',
                        'tls_session_resumed',
                        'tls_ticket_source',
                    ],
                ],
                [
                    'path' => 'extension/tests/503-http3-early-data-session-ticket-contract.phpt',
                    'needles' => [
                        'resumed ticket paths',
                        'tls_session_resumed',
                        'tls_ticket_source',
                    ],
                ],
                [
                    'path' => 'extension/tests/535-http3-quic-zero-rtt-acceptance-and-fallback-contract.phpt',
                    'needles' => [
                        'zero-rtt lifecycle proof',
                        'tls_session_resumed',
                        'ticket',
                    ],
                ],
            ],
        ],
        'multi_stream_fairness' => [
            'strategy_capability' => 'multi_stream_fairness',
            'tests' => [
                [
                    'path' => 'extension/tests/486-http3-multi-backpressure-contract.phpt',
                    'needles' => [
                        'fast streams progressing while a slow sibling stream is still active',
                        'king_http3_request_send_multi',
                        'finishOrder',
                        'maxActiveStreams',
                    ],
                ],
                [
                    'path' => 'extension/tests/487-http3-sustained-fairness-contract.phpt',
                    'needles' => [
                        'sustained staggered load on one QUIC session',
                        'king_http3_request_send_multi',
                        'finishOrder',
                        'maxActiveStreams',
                    ],
                ],
                [
                    'path' => 'extension/tests/645-http3-quic-stress-and-partial-failure-matrix-contract.phpt',
                    'needles' => [
                        'sustained stress rounds',
                        'king_http3_request_send_multi',
                        'maxActiveStreams',
                    ],
                ],
            ],
        ],
    ],
];
