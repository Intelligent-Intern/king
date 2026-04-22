--TEST--
King client HTTP/3 LSQUIC byte stats are derived from live connection counters
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

$runtime = read_source($root . '/extension/src/client/http3/lsquic_runtime.inc');
$response = read_source($root . '/extension/src/client/http3/request_response.inc');
$refreshBody = extract_function_body($runtime, 'king_http3_lsquic_refresh_transport_stats');
$estimateBody = extract_function_body($runtime, 'king_http3_lsquic_estimate_packet_bytes');

require_contains('Refresh reads LSQUIC sent byte counter', $refreshBody, 'info.lci_bytes_sent');
require_contains('Lost bytes use live sent bytes', $refreshBody, 'runtime->quic_lost_bytes = king_http3_lsquic_estimate_packet_bytes(');
require_contains('Retransmit bytes use live sent bytes', $refreshBody, 'runtime->quic_stream_retransmitted_bytes = king_http3_lsquic_estimate_packet_bytes(');
require_contains('Lost bytes use live lost packet counter', $refreshBody, 'info.lci_pkts_lost');
require_contains('Retransmit bytes use live retransmit packet counter', $refreshBody, 'info.lci_pkts_retx');
require_contains('Byte estimator scales by total sent packet count', $estimateBody, 'total_bytes / total_packets');
require_contains('Byte estimator avoids zero for observed events', $estimateBody, 'estimated = 1');
require_contains('Byte estimator clamps to zend long', $estimateBody, 'king_http3_lsquic_uint64_to_zend_long(estimated)');

foreach (['quic_lost_bytes', 'quic_stream_retransmitted_bytes'] as $field) {
    require_contains('Response exposes ' . $field, $response, '"' . $field . '"');
    if (str_contains($refreshBody, 'runtime->' . $field . ' = 0')) {
        throw new RuntimeException('LSQUIC refresh must not zero ' . $field . '.');
    }
}

echo "OK\n";
?>
--EXPECT--
OK
