--TEST--
King HTTP/1 runtime decodes chunked transfer responses
--FILE--
<?php
function king_http1_start_chunked_server(string $payload): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-http1-chunked-');
    $payloadExport = var_export($payload, true);
    file_put_contents($script, "<?php\n"
        . "\$payload = {$payloadExport};\n"
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
        . "\$chunks = [substr(\$payload, 0, 8), substr(\$payload, 8, 5), substr(\$payload, 13)];\n"
        . "\$response = \"HTTP/1.1 200 OK\\r\\n\"\n"
        . "    . \"Transfer-Encoding: chunked\\r\\n\"\n"
        . "    . \"Connection: close\\r\\n\\r\\n\";\n"
        . "foreach (\$chunks as \$chunk) {\n"
        . "    if (\$chunk === '') {\n"
        . "        continue;\n"
        . "    }\n"
        . "    \$response .= dechex(strlen(\$chunk)) . \"\\r\\n\" . \$chunk . \"\\r\\n\";\n"
        . "}\n"
        . "\$response .= \"0\\r\\n\\r\\n\";\n"
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
        throw new RuntimeException('failed to launch local HTTP/1 chunked test server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('local HTTP/1 chunked test server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, (int) $port];
}

function king_http1_stop_chunked_server(array $server): void
{
    [$process, $pipes, $script] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($process);
    @unlink($script);
}

$server = king_http1_start_chunked_server('chunked-response-body');
try {
    $response = king_http1_request_send(
        'http://127.0.0.1:' . $server[3] . '/chunked?mode=direct'
    );
} finally {
    king_http1_stop_chunked_server($server);
}

var_dump($response['status']);
var_dump($response['headers']['transfer-encoding']);
var_dump($response['protocol']);
var_dump($response['transport_backend']);
var_dump($response['body']);
var_dump(str_ends_with($response['effective_url'], '/chunked?mode=direct'));
?>
--EXPECT--
int(200)
string(7) "chunked"
string(8) "http/1.1"
string(10) "tcp_socket"
string(21) "chunked-response-body"
bool(true)
