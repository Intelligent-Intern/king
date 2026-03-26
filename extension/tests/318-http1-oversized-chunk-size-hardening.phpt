--TEST--
King HTTP/1 rejects oversized chunk-size lines before chunk payload arithmetic can overflow
--FILE--
<?php
function king_http1_start_oversized_chunk_server(): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-http1-oversized-chunk-');
    $chunkLineExport = var_export(str_repeat('F', PHP_INT_SIZE * 2), true);
    file_put_contents($script, "<?php\n"
        . "\$chunkLine = {$chunkLineExport};\n"
        . "\$port = (int) \$argv[1];\n"
        . "\$server = stream_socket_server(\"tcp://127.0.0.1:\$port\", \$errno, \$errstr);\n"
        . "if (\$server === false) {\n"
        . "    fwrite(STDERR, \"bind failed: \$errstr\\n\");\n"
        . "    exit(2);\n"
        . "}\n"
        . "fwrite(STDOUT, \"READY\\n\");\n"
        . "\$conn = @stream_socket_accept(\$server, 5);\n"
        . "if (\$conn === false) {\n"
        . "    fwrite(STDERR, \"accept failed\\n\");\n"
        . "    exit(3);\n"
        . "}\n"
        . "stream_set_timeout(\$conn, 5);\n"
        . "\$request = '';\n"
        . "while (!str_contains(\$request, \"\\r\\n\\r\\n\")) {\n"
        . "    \$chunk = fread(\$conn, 8192);\n"
        . "    if (\$chunk === '' || \$chunk === false) {\n"
        . "        break;\n"
        . "    }\n"
        . "    \$request .= \$chunk;\n"
        . "}\n"
        . "\$response = \"HTTP/1.1 200 OK\\r\\n\"\n"
        . "    . \"Transfer-Encoding: chunked\\r\\n\"\n"
        . "    . \"Connection: close\\r\\n\\r\\n\"\n"
        . "    . \$chunkLine . \"\\r\\n\"\n"
        . "    . \"x\\r\\n\"\n"
        . "    . \"0\\r\\n\\r\\n\";\n"
        . "fwrite(\$conn, \$response);\n"
        . "fclose(\$conn);\n"
        . "fclose(\$server);\n");

    $command = escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($script) . ' ' . (int) $port;
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($script);
        throw new RuntimeException('failed to launch local oversized-chunk test server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('local oversized-chunk test server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, (int) $port];
}

function king_http1_stop_oversized_chunk_server(array $server): void
{
    [$process, $pipes, $script] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($process);
    @unlink($script);
}

$server = king_http1_start_oversized_chunk_server();
try {
    king_http1_request_send('http://127.0.0.1:' . $server[3] . '/oversized-direct');
    echo "no-exception-1\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'received an oversized HTTP/1 chunk size.'));
} finally {
    king_http1_stop_oversized_chunk_server($server);
}

$server = king_http1_start_oversized_chunk_server();
try {
    king_client_send_request('http://127.0.0.1:' . $server[3] . '/oversized-dispatch');
    echo "no-exception-2\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'received an oversized HTTP/1 chunk size.'));
} finally {
    king_http1_stop_oversized_chunk_server($server);
}

$server = king_http1_start_oversized_chunk_server();
try {
    $context = king_http1_request_send(
        'http://127.0.0.1:' . $server[3] . '/oversized-stream',
        'GET',
        null,
        null,
        [
            'response_stream' => true,
            'timeout_ms' => 2000,
        ]
    );
    king_receive_response($context);
    echo "no-exception-3\n";
} catch (Throwable $e) {
    var_dump(get_class($e));
    var_dump(str_contains($e->getMessage(), 'received an oversized HTTP/1 chunk size.'));
} finally {
    king_http1_stop_oversized_chunk_server($server);
}
?>
--EXPECT--
string(22) "King\ProtocolException"
bool(true)
string(22) "King\ProtocolException"
bool(true)
string(22) "King\ProtocolException"
bool(true)
