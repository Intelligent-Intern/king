--TEST--
King QUIC config inventory maps every quic.* option through config and HTTP/3 snapshots
--INI--
king.security_allow_config_override=1
--FILE--
<?php
$root = dirname(__DIR__, 2);
$fields = [
    'cc_algorithm' => ['type' => 'string', 'value' => 'bbr'],
    'cc_initial_cwnd_packets' => ['type' => 'long', 'value' => 48],
    'cc_min_cwnd_packets' => ['type' => 'long', 'value' => 5],
    'cc_enable_hystart_plus_plus' => ['type' => 'bool', 'value' => false],
    'pacing_enable' => ['type' => 'bool', 'value' => false],
    'pacing_max_burst_packets' => ['type' => 'long', 'value' => 12],
    'max_ack_delay_ms' => ['type' => 'long', 'value' => 0],
    'ack_delay_exponent' => ['type' => 'long', 'value' => 4],
    'pto_timeout_ms_initial' => ['type' => 'long', 'value' => 1200],
    'pto_timeout_ms_max' => ['type' => 'long', 'value' => 45000],
    'max_pto_probes' => ['type' => 'long', 'value' => 7],
    'ping_interval_ms' => ['type' => 'long', 'value' => 0],
    'initial_max_data' => ['type' => 'long', 'value' => 20971520],
    'initial_max_stream_data_bidi_local' => ['type' => 'long', 'value' => 2097152],
    'initial_max_stream_data_bidi_remote' => ['type' => 'long', 'value' => 3145728],
    'initial_max_stream_data_uni' => ['type' => 'long', 'value' => 1048577],
    'initial_max_streams_bidi' => ['type' => 'long', 'value' => 150],
    'initial_max_streams_uni' => ['type' => 'long', 'value' => 80],
    'active_connection_id_limit' => ['type' => 'long', 'value' => 10],
    'stateless_retry_enable' => ['type' => 'bool', 'value' => true],
    'grease_enable' => ['type' => 'bool', 'value' => false],
    'datagrams_enable' => ['type' => 'bool', 'value' => false],
    'dgram_recv_queue_len' => ['type' => 'long', 'value' => 2048],
    'dgram_send_queue_len' => ['type' => 'long', 'value' => 4096],
];

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

$base = read_source($root . '/extension/include/config/quic_transport/base_layer.h');
$configApply = read_source($root . '/extension/src/config/quic_transport/config.c');
$defaults = read_source($root . '/extension/src/config/quic_transport/default.c');
$ini = read_source($root . '/extension/src/config/quic_transport/ini.c');
$routing = read_source($root . '/extension/src/config/internal/overrides/routing.inc');
$quicSnapshot = read_source($root . '/extension/src/config/internal/object/quic_snapshot.inc');
$client = read_source($root . '/extension/src/client/http3.c');
$clientOptions = read_source($root . '/extension/src/client/http3/errors_and_validation.inc');
$server = read_source($root . '/extension/src/server/http3.c');
$serverOptions = read_source($root . '/extension/src/server/http3/options_and_runtime.inc');
$serverLsquicRuntime = read_source($root . '/extension/src/server/http3/lsquic_runtime.inc');

preg_match_all('/^\s*(?:char\s+\*\s*|zend_long\s+|bool\s+)([a-z0-9_]+);/m', $base, $matches);
$structFields = $matches[1];
if ($structFields !== array_keys($fields)) {
    throw new RuntimeException('QUIC base-layer field order no longer matches the contract inventory.');
}

$overrides = [];
foreach ($fields as $field => $meta) {
    $key = 'quic.' . $field;
    $optionField = 'quic_' . $field;
    $overrides[$key] = $meta['value'];

    require_contains('QUIC default inventory for ' . $field, $defaults, 'king_quic_transport_config.' . $field);
    require_contains('QUIC userland override inventory for ' . $field, $configApply, '"' . $field . '"');
    require_contains('QUIC php.ini inventory for ' . $field, $ini, 'king.transport_' . $field);
    require_contains('QUIC routing inventory for ' . $field, $routing, '"' . $field . '"');
    require_contains('QUIC snapshot export/read inventory for ' . $field, $quicSnapshot, '"' . $key . '"');
    require_contains('Client HTTP/3 option snapshot for ' . $field, $client . $clientOptions, $optionField);
    require_contains('Server HTTP/3 option snapshot for ' . $field, $server . $serverOptions, $optionField);
}

$serverLsquicMapped = [
    'pacing_enable' => 'es_pace_packets',
    'initial_max_data' => 'es_init_max_data',
    'initial_max_stream_data_bidi_local' => 'es_init_max_stream_data_bidi_local',
    'initial_max_stream_data_bidi_remote' => 'es_init_max_stream_data_bidi_remote',
    'initial_max_stream_data_uni' => 'es_init_max_stream_data_uni',
    'initial_max_streams_bidi' => 'es_init_max_streams_bidi',
    'initial_max_streams_uni' => 'es_init_max_streams_uni',
    'grease_enable' => 'es_grease_quic_bit',
    'datagrams_enable' => 'es_datagrams',
];
foreach ($serverLsquicMapped as $field => $setting) {
    require_contains('Server LSQUIC runtime setting for ' . $field, $serverLsquicRuntime, 'options->quic_' . $field);
    require_contains('Server LSQUIC runtime setting for ' . $field, $serverLsquicRuntime, $setting);
}

$config = new King\Config($overrides);
$snapshot = $config->toArray();

foreach ($fields as $field => $meta) {
    $key = 'quic.' . $field;
    if (!array_key_exists($key, $snapshot)) {
        throw new RuntimeException('King\\Config::toArray() omitted ' . $key);
    }

    $fromGet = $config->get($key);
    $expected = $meta['value'];
    if ($snapshot[$key] !== $expected || $fromGet !== $expected) {
        throw new RuntimeException('King\\Config did not preserve override value for ' . $key);
    }
}

if ($config->get('cc_algorithm') !== 'bbr') {
    throw new RuntimeException('Legacy flat QUIC key did not canonicalize to quic.cc_algorithm.');
}

if (count($fields) !== 24) {
    throw new RuntimeException('QUIC option inventory must account for all 24 active fields.');
}

echo "OK\n";
?>
--EXPECT--
OK
