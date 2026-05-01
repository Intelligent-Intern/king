--TEST--
King HTTP/3 LSQUIC option runtime maps congestion pacing flow control and idle timeout
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

function extract_function_body(string $source, string $functionName): string
{
    $needle = $functionName . '(';
    $start = strpos($source, $needle);
    if ($start === false) {
        throw new RuntimeException('Cannot find function ' . $functionName);
    }

    $brace = strpos($source, '{', $start);
    if ($brace === false) {
        throw new RuntimeException('Cannot find function body for ' . $functionName);
    }

    $depth = 0;
    $length = strlen($source);
    for ($i = $brace; $i < $length; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $brace, $i - $brace + 1);
            }
        }
    }

    throw new RuntimeException('Unterminated function body for ' . $functionName);
}

$clientDiag = read_source($root . '/extension/src/client/http3/lsquic_option_diagnostics.inc');
$clientRuntime = read_source($root . '/extension/src/client/http3/lsquic_runtime.inc');
$serverDiag = read_source($root . '/extension/src/server/http3/lsquic_option_diagnostics.inc');
$serverRuntime = read_source($root . '/extension/src/server/http3/lsquic_runtime.inc');

$clientApply = extract_function_body($clientDiag, 'king_http3_lsquic_apply_quic_options');
$serverApply = extract_function_body($serverDiag, 'king_server_http3_lsquic_apply_quic_options');
$clientInit = extract_function_body($clientRuntime, 'king_http3_lsquic_runtime_init');
$serverInit = extract_function_body($serverRuntime, 'king_server_http3_lsquic_runtime_init');

$ccMappings = [
    'KING_HTTP3_LSQUIC_CC_CUBIC' => '1U',
    'KING_HTTP3_LSQUIC_CC_BBR' => '2U',
    'KING_SERVER_HTTP3_LSQUIC_CC_CUBIC' => '1U',
    'KING_SERVER_HTTP3_LSQUIC_CC_BBR' => '2U',
];

foreach ($ccMappings as $constant => $value) {
    require_contains('Congestion-control constant ' . $constant, $clientDiag . $serverDiag, '#define ' . $constant . ' ' . $value);
}

$lsquicMappings = [
    'cc_algorithm' => 'es_cc_algo',
    'pacing_enable' => 'es_pace_packets',
    'initial_max_data' => 'es_init_max_data',
    'initial_max_stream_data_bidi_local' => 'es_init_max_stream_data_bidi_local',
    'initial_max_stream_data_bidi_remote' => 'es_init_max_stream_data_bidi_remote',
    'initial_max_stream_data_uni' => 'es_init_max_stream_data_uni',
    'initial_max_streams_bidi' => 'es_init_max_streams_bidi',
    'initial_max_streams_uni' => 'es_init_max_streams_uni',
];

foreach ($lsquicMappings as $option => $setting) {
    require_contains('Client maps quic.' . $option, $clientApply, 'options->quic_' . $option);
    require_contains('Client sets LSQUIC ' . $setting, $clientApply, 'runtime->lsquic_settings.' . $setting);
    require_contains('Server maps quic.' . $option, $serverApply, 'options->quic_' . $option);
    require_contains('Server sets LSQUIC ' . $setting, $serverApply, 'runtime->lsquic_settings.' . $setting);
}

require_order('Client maps congestion-control algorithm before setting LSQUIC profile', $clientApply, [
    'king_http3_lsquic_cc_algorithm_to_id(function_name, options->quic_cc_algorithm, &value)',
    'runtime->lsquic_settings.es_cc_algo = value',
]);
require_order('Server maps congestion-control algorithm before setting LSQUIC profile', $serverApply, [
    'king_server_http3_lsquic_cc_algorithm_to_id(function_name, options->quic_cc_algorithm, &value)',
    'runtime->lsquic_settings.es_cc_algo = value',
]);

require_contains('Client maps pacing enable to LSQUIC boolean', $clientApply, 'runtime->lsquic_settings.es_pace_packets = options->quic_pacing_enable ? 1 : 0');
require_contains('Server maps pacing enable to LSQUIC boolean', $serverApply, 'runtime->lsquic_settings.es_pace_packets = options->quic_pacing_enable ? 1 : 0');

foreach ([
    'idle_timeout_seconds = (unsigned) ((options->timeout_ms + 999) / 1000)',
    'idle_timeout_seconds = 1',
    'idle_timeout_seconds > 600',
    'runtime->lsquic_settings.es_idle_timeout = idle_timeout_seconds',
    'runtime->lsquic_settings.es_idle_conn_to = (unsigned long) options->timeout_ms * 1000UL',
] as $needle) {
    require_contains('Client idle-timeout LSQUIC mapping', $clientInit, $needle);
    require_contains('Server idle-timeout LSQUIC mapping', $serverInit, $needle);
}

require_order('Client validates complete LSQUIC settings profile after option and idle-timeout mapping', $clientInit, [
    'lsquic_engine_init_settings_fn(&runtime->lsquic_settings, engine_flags)',
    'king_http3_lsquic_apply_quic_options(runtime, options, function_name)',
    'runtime->lsquic_settings.es_idle_timeout = idle_timeout_seconds',
    'runtime->lsquic_settings.es_idle_conn_to = (unsigned long) options->timeout_ms * 1000UL',
    'lsquic_engine_check_settings_fn(',
    'runtime->lsquic_api.ea_settings = &runtime->lsquic_settings',
    'lsquic_engine_new_fn(',
]);

require_order('Server validates complete LSQUIC settings profile after option and idle-timeout mapping', $serverInit, [
    'lsquic_engine_init_settings_fn(&runtime->lsquic_settings, engine_flags)',
    'king_server_http3_lsquic_apply_quic_options(runtime, options, function_name)',
    'runtime->lsquic_settings.es_idle_timeout = idle_timeout_seconds',
    'runtime->lsquic_settings.es_idle_conn_to = (unsigned long) options->timeout_ms * 1000UL',
    'lsquic_engine_check_settings_fn(',
    'runtime->lsquic_api.ea_settings = &runtime->lsquic_settings',
    'lsquic_engine_new_fn(',
]);

echo "OK\n";
?>
--EXPECT--
OK
