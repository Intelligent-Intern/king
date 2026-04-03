<?php

/* Namespaced UDP client for the Semantic-DNS distributed-topology contract. */

require __DIR__ . '/semantic_dns_wire_helper.inc';

if ($argc < 5) {
    fwrite(
        STDERR,
        "usage: semantic_dns_multi_host_client.inc <capture-path> <host> <port> <service-name>\n"
    );
    exit(1);
}

$capturePath = $argv[1];
$host = $argv[2];
$port = (int) $argv[3];
$serviceName = $argv[4];
$capture = [];

try {
    $capture['target_host'] = $host;
    $capture['target_port'] = $port;
    $capture['service_name'] = $serviceName;

    $response = king_semantic_dns_wire_query($host, $port, $serviceName);
    $capture['timed_out'] = $response['timed_out'];
    $capture['parsed'] = king_semantic_dns_wire_parse_response($response['response']);
} catch (Throwable $e) {
    $capture['exception_class'] = get_class($e);
    $capture['exception_message'] = $e->getMessage();
} finally {
    file_put_contents(
        $capturePath,
        json_encode($capture, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE)
    );
}

exit(isset($capture['exception_class']) ? 1 : 0);
