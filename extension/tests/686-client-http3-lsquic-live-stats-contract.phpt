--TEST--
King client HTTP/3 LSQUIC response stats bind to live connection counters
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

$client = read_source($root . '/extension/src/client/http3.c');
$loader = read_source($root . '/extension/src/client/http3/lsquic_loader.inc');
$runtime = read_source($root . '/extension/src/client/http3/lsquic_runtime.inc');
$diagnostics = read_source($root . '/extension/src/client/http3/lsquic_option_diagnostics.inc');
$response = read_source($root . '/extension/src/client/http3/request_response.inc');
$refreshBody = extract_function_body($runtime, 'king_http3_lsquic_refresh_transport_stats');

require_contains('Client API stores conn_get_info pointer', $client, 'lsquic_conn_get_info_fn');
require_contains('Client loader binds conn_get_info symbol', $loader, 'lsquic_conn_get_info');
require_contains('Client enables bandwidth sampler for conn info', $diagnostics, 'es_enable_bw_sampler = 1');
require_contains('Refresh reads live conn info', $refreshBody, 'struct lsquic_conn_info info');
require_contains('Refresh calls live conn info', $refreshBody, 'lsquic_conn_get_info_fn(runtime->lsquic_conn, &info)');

$liveMappings = [
    'quic_packets_sent' => 'info.lci_pkts_sent',
    'quic_packets_received' => 'info.lci_pkts_rcvd',
    'quic_packets_lost' => 'info.lci_pkts_lost',
    'quic_packets_retransmitted' => 'info.lci_pkts_retx',
];

foreach ($liveMappings as $responseField => $sourceField) {
    require_contains('Refresh maps ' . $responseField, $refreshBody, $sourceField);
    require_contains('Response exposes ' . $responseField, $response, '"' . $responseField . '"');
}

if (str_contains($refreshBody, 'runtime->quic_packets_lost = 0')
    || str_contains($refreshBody, 'runtime->quic_packets_retransmitted = 0')) {
    throw new RuntimeException('LSQUIC refresh must not zero live loss/retransmit packet counters.');
}

echo "OK\n";
?>
--EXPECT--
OK
