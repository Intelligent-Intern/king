--TEST--
King client dispatcher returns decoded chunked HTTP/1 responses
--FILE--
<?php
function king_http1_start_dispatch_chunked_server(string $label): array
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve test port: $errstr");
    }

    $serverName = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $serverName, 2);

    $script = tempnam(sys_get_temp_dir(), 'king-http1-dispatch-chunked-');
    $labelExport = var_export($label, true);
    file_put_contents($script, "<?php\n"
        . "\$label = {$labelExport};\n"
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
        . "[\$head] = array_pad(explode(\"\\r\\n\\r\\n\", \$request, 2), 1, '');\n"
        . "\$requestLine = explode(\"\\r\\n\", \$head)[0] ?? '';\n"
        . "\$parts = explode(' ', \$requestLine, 3);\n"
        . "\$payload = json_encode([\n"
        . "    'label' => \$label,\n"
        . "    'path' => \$parts[1] ?? '',\n"
        . "], JSON_UNESCAPED_SLASHES);\n"
        . "\$chunks = [substr(\$payload, 0, 6), substr(\$payload, 6, 9), substr(\$payload, 15)];\n"
        . "\$response = \"HTTP/1.1 200 OK\\r\\n\"\n"
        . "    . \"Content-Type: application/json\\r\\n\"\n"
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
        throw new RuntimeException('failed to launch local HTTP/1 dispatch chunked server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        throw new RuntimeException('local HTTP/1 dispatch chunked server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, (int) $port];
}

function king_http1_stop_dispatch_chunked_server(array $server): void
{
    [$process, $pipes, $script] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($process);
    @unlink($script);
}

$server = king_http1_start_dispatch_chunked_server('dispatcher-chunked');
try {
    $response = king_client_send_request(
        'http://127.0.0.1:' . $server[3] . '/chunked-dispatch',
        'GET',
        ['X-Mode' => 'chunked-dispatch']
    );
} finally {
    king_http1_stop_dispatch_chunked_server($server);
}

$payload = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);

var_dump($response['status']);
var_dump($response['protocol']);
var_dump($response['transport_backend']);
var_dump($payload['label']);
var_dump($payload['path']);
var_dump(str_ends_with($response['effective_url'], '/chunked-dispatch'));
?>
--EXPECT--
int(200)
string(8) "http/1.1"
string(10) "tcp_socket"
string(18) "dispatcher-chunked"
string(17) "/chunked-dispatch"
bool(true)
