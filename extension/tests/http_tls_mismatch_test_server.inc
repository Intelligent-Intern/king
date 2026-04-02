<?php

/* Harness server that speaks plain TCP to exercise TLS-mismatch client failures. */

$port = (int) ($argv[1] ?? 0);
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "bind failed: $errstr\n");
    exit(2);
}

fwrite(STDOUT, "READY\n");

$conn = @stream_socket_accept($server, 10);
if ($conn !== false) {
    stream_set_timeout($conn, 1);

    /*
     * Speak plain HTTP immediately so HTTPS clients deterministically fail
     * during TLS setup instead of depending on the ambient state of port 80.
     */
    fwrite(
        $conn,
        "HTTP/1.1 200 OK\r\n"
        . "Content-Length: 2\r\n"
        . "Connection: close\r\n\r\n"
        . "ok"
    );
    fclose($conn);
}

fclose($server);
