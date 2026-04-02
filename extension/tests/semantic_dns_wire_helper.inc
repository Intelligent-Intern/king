<?php

/*
 * UDP DNS-wire helper for Smart-DNS contracts. Builds one-question A queries,
 * sends them to the live Semantic-DNS listener, and parses the bounded answer
 * slice needed by the on-wire listener tests.
 */

function king_semantic_dns_wire_allocate_udp_port(): int
{
    $socket = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
    if (!is_resource($socket)) {
        throw new RuntimeException('Could not allocate UDP port: ' . $errstr);
    }

    $name = stream_socket_get_name($socket, false);
    fclose($socket);

    if (!is_string($name) || !str_contains($name, ':')) {
        throw new RuntimeException('Could not resolve UDP port from bound socket.');
    }

    return (int) substr($name, strrpos($name, ':') + 1);
}

function king_semantic_dns_wire_encode_name(string $name): string
{
    $wire = '';
    $labels = explode('.', $name);

    foreach ($labels as $label) {
        if ($label === '' || strlen($label) > 63) {
            throw new RuntimeException('Invalid DNS label in query name.');
        }

        $wire .= chr(strlen($label)) . $label;
    }

    return $wire . "\0";
}

function king_semantic_dns_wire_build_query(string $name, int $id = 0x1234): string
{
    return pack('nnnnnn', $id, 0x0100, 1, 0, 0, 0)
        . king_semantic_dns_wire_encode_name($name)
        . pack('nn', 1, 1);
}

function king_semantic_dns_wire_send_packet(
    string $host,
    int $port,
    string $packet,
    int $timeout_ms = 500
): array {
    $socket = stream_socket_client(
        sprintf('udp://%s:%d', $host, $port),
        $errno,
        $errstr,
        1,
        STREAM_CLIENT_CONNECT
    );
    if (!is_resource($socket)) {
        throw new RuntimeException('Could not open UDP DNS client: ' . $errstr);
    }

    stream_set_timeout($socket, intdiv($timeout_ms, 1000), ($timeout_ms % 1000) * 1000);
    fwrite($socket, $packet);
    $response = fread($socket, 512);
    $meta = stream_get_meta_data($socket);
    fclose($socket);

    return [
        'response' => $response === false ? '' : $response,
        'timed_out' => (bool) ($meta['timed_out'] ?? false),
    ];
}

function king_semantic_dns_wire_query(
    string $host,
    int $port,
    string $name,
    int $timeout_ms = 500,
    int $id = 0x1234
): array {
    return king_semantic_dns_wire_send_packet(
        $host,
        $port,
        king_semantic_dns_wire_build_query($name, $id),
        $timeout_ms
    );
}

function king_semantic_dns_wire_skip_name(string $packet, int $offset): int
{
    $length = strlen($packet);

    while ($offset < $length) {
        $label_len = ord($packet[$offset]);
        if (($label_len & 0xc0) === 0xc0) {
            return $offset + 2;
        }
        $offset++;
        if ($label_len === 0) {
            return $offset;
        }
        $offset += $label_len;
    }

    throw new RuntimeException('Malformed DNS name in response.');
}

function king_semantic_dns_wire_parse_response(string $packet): array
{
    if (strlen($packet) < 12) {
        throw new RuntimeException('DNS response too short.');
    }

    $header = unpack('nid/nflags/nqdcount/nancount/nnscount/narcount', substr($packet, 0, 12));
    $offset = 12;
    $answers = [];

    for ($i = 0; $i < $header['qdcount']; $i++) {
        $offset = king_semantic_dns_wire_skip_name($packet, $offset);
        $offset += 4;
    }

    for ($i = 0; $i < $header['ancount']; $i++) {
        $offset = king_semantic_dns_wire_skip_name($packet, $offset);
        $type = unpack('n', substr($packet, $offset, 2))[1];
        $class = unpack('n', substr($packet, $offset + 2, 2))[1];
        $rdlength = unpack('n', substr($packet, $offset + 8, 2))[1];
        $rdata = substr($packet, $offset + 10, $rdlength);
        $offset += 10 + $rdlength;

        if ($type === 1 && $class === 1 && $rdlength === 4) {
            $answers[] = inet_ntop($rdata);
        }
    }

    return [
        'id' => $header['id'],
        'rcode' => $header['flags'] & 0x000f,
        'tc' => (bool) ($header['flags'] & 0x0200),
        'answer_count' => $header['ancount'],
        'answers' => $answers,
    ];
}
