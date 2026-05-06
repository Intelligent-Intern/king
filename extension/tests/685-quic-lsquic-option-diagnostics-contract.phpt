--TEST--
King LSQUIC HTTP/3 paths fail closed on unsupported non-default quic.* options
--FILE--
<?php
$root = dirname(__DIR__, 2);

function read_source(string $path): string
{
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException('Cannot read source file: ' . $path);
    }
    return $source;
}

function require_contains(string $label, string $source, string $needle): void
{
    if (!str_contains($source, $needle)) {
        throw new RuntimeException($label . ' must contain ' . $needle);
    }
}

function require_order(string $label, string $source, array $needles): void
{
    $offset = 0;
    foreach ($needles as $needle) {
        $pos = strpos($source, $needle, $offset);
        if ($pos === false) {
            throw new RuntimeException($label . ' must contain in order: ' . $needle);
        }
        $offset = $pos + strlen($needle);
    }
}

$clientDiag = read_source($root . '/extension/src/client/http3/lsquic_option_diagnostics.inc');
$clientRuntime = read_source($root . '/extension/src/client/http3/lsquic_runtime.inc');
$serverDiag = read_source($root . '/extension/src/server/http3/lsquic_option_diagnostics.inc');
$serverRuntime = read_source($root . '/extension/src/server/http3/lsquic_runtime.inc');

$unsupportedShared = [
    'cc_initial_cwnd_packets',
    'cc_min_cwnd_packets',
    'cc_enable_hystart_plus_plus',
    'pacing_max_burst_packets',
    'max_ack_delay_ms',
    'ack_delay_exponent',
    'pto_timeout_ms_initial',
    'pto_timeout_ms_max',
    'max_pto_probes',
    'active_connection_id_limit',
    'dgram_recv_queue_len',
    'dgram_send_queue_len',
];

foreach ($unsupportedShared as $field) {
    require_contains('Client unsupported diagnostic for ' . $field, $clientDiag, '"' . $field . '"');
    require_contains('Server unsupported diagnostic for ' . $field, $serverDiag, '"' . $field . '"');
}

require_contains('Client-only stateless retry diagnostic', $clientDiag, '"stateless_retry_enable"');
require_contains('Client fail-closed message', $clientDiag, 'cannot apply quic.%s=" ZEND_LONG_FMT " with the active LSQUIC HTTP/3 backend');
require_contains('Server fail-closed message', $serverDiag, 'cannot apply quic.%s=" ZEND_LONG_FMT " with the active LSQUIC HTTP/3 server backend');
require_contains('Client whole-second ping diagnostic', $clientDiag, 'LSQUIC exposes ping period in whole seconds');
require_contains('Server whole-second ping diagnostic', $serverDiag, 'LSQUIC exposes ping period in whole seconds');

$mappedSettings = [
    'cc_algorithm' => 'es_cc_algo',
    'pacing_enable' => 'es_pace_packets',
    'ping_interval_ms' => 'es_ping_period',
    'initial_max_data' => 'es_init_max_data',
    'initial_max_stream_data_bidi_local' => 'es_init_max_stream_data_bidi_local',
    'initial_max_stream_data_bidi_remote' => 'es_init_max_stream_data_bidi_remote',
    'initial_max_stream_data_uni' => 'es_init_max_stream_data_uni',
    'initial_max_streams_bidi' => 'es_init_max_streams_bidi',
    'initial_max_streams_uni' => 'es_init_max_streams_uni',
    'grease_enable' => 'es_grease_quic_bit',
    'datagrams_enable' => 'es_datagrams',
];

foreach ($mappedSettings as $field => $setting) {
    require_contains('Client LSQUIC applies ' . $field, $clientDiag, 'options->quic_' . $field);
    require_contains('Client LSQUIC setting ' . $field, $clientDiag, $setting);
    require_contains('Server LSQUIC applies ' . $field, $serverDiag, 'options->quic_' . $field);
    require_contains('Server LSQUIC setting ' . $field, $serverDiag, $setting);
}

require_contains('Server stateless retry maps to LSQUIC SREJ', $serverDiag, 'options->quic_stateless_retry_enable');
require_contains('Server stateless retry setting', $serverDiag, 'es_support_srej');

require_order('Client validates before LSQUIC settings check', $clientRuntime, [
    'lsquic_engine_init_settings_fn(&runtime->lsquic_settings, engine_flags)',
    'king_http3_lsquic_apply_quic_options(runtime, options, function_name)',
    'lsquic_engine_check_settings_fn(',
]);

require_order('Server validates before LSQUIC settings check', $serverRuntime, [
    'lsquic_engine_init_settings_fn(&runtime->lsquic_settings, engine_flags)',
    'king_server_http3_lsquic_apply_quic_options(runtime, options, function_name)',
    'lsquic_engine_check_settings_fn(',
]);

echo "OK\n";
?>
--EXPECT--
OK
