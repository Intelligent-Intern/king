--TEST--
King telemetry failover harness preserves queued metrics across exporter outage and recovery
--INI--
king.security_allow_config_override=1
--FILE--
<?php
function king_telemetry_pick_unused_local_port(): int
{
    $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        throw new RuntimeException("failed to reserve local port: $errstr");
    }

    $address = stream_socket_get_name($probe, false);
    fclose($probe);
    [, $port] = explode(':', $address, 2);
    return (int) $port;
}

function king_telemetry_start_failover_server(): array
{
    $port = king_telemetry_pick_unused_local_port();
    $script = tempnam(sys_get_temp_dir(), 'king-telemetry-failover-');
    $capture = tempnam(sys_get_temp_dir(), 'king-telemetry-failover-body-');

    file_put_contents($script, "<?php\n"
        . "\$capture = \$argv[1];\n"
        . "\$port = (int) \$argv[2];\n"
        . "\$server = stream_socket_server(\"tcp://127.0.0.1:\$port\", \$errno, \$errstr);\n"
        . "if (\$server === false) {\n"
        . "    fwrite(STDERR, \"bind failed: \$errstr\\n\");\n"
        . "    exit(2);\n"
        . "}\n"
        . "fwrite(STDOUT, \"READY\\n\");\n"
        . "\$conn = @stream_socket_accept(\$server, 10);\n"
        . "if (\$conn === false) {\n"
        . "    fwrite(STDERR, \"accept failed\\n\");\n"
        . "    exit(3);\n"
        . "}\n"
        . "stream_set_timeout(\$conn, 10);\n"
        . "\$buffer = '';\n"
        . "while (!str_contains(\$buffer, \"\\r\\n\\r\\n\")) {\n"
        . "    \$chunk = fread(\$conn, 8192);\n"
        . "    if (\$chunk === '' || \$chunk === false) {\n"
        . "        break;\n"
        . "    }\n"
        . "    \$buffer .= \$chunk;\n"
        . "}\n"
        . "[\$head, \$body] = array_pad(explode(\"\\r\\n\\r\\n\", \$buffer, 2), 2, '');\n"
        . "\$contentLength = 0;\n"
        . "foreach (explode(\"\\r\\n\", \$head) as \$line) {\n"
        . "    if (stripos(\$line, 'Content-Length:') === 0) {\n"
        . "        \$contentLength = (int) trim(substr(\$line, strlen('Content-Length:')));\n"
        . "        break;\n"
        . "    }\n"
        . "}\n"
        . "while (strlen(\$body) < \$contentLength) {\n"
        . "    \$chunk = fread(\$conn, \$contentLength - strlen(\$body));\n"
        . "    if (\$chunk === '' || \$chunk === false) {\n"
        . "        break;\n"
        . "    }\n"
        . "    \$body .= \$chunk;\n"
        . "}\n"
        . "file_put_contents(\$capture, \$body);\n"
        . "\$response = \"HTTP/1.1 200 OK\\r\\nContent-Length: 2\\r\\nConnection: close\\r\\n\\r\\nok\";\n"
        . "fwrite(\$conn, \$response);\n"
        . "fclose(\$conn);\n"
        . "fclose(\$server);\n");

    $command = escapeshellarg(PHP_BINARY)
        . ' -n '
        . escapeshellarg($script)
        . ' '
        . escapeshellarg($capture)
        . ' '
        . $port;
    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($script);
        @unlink($capture);
        throw new RuntimeException('failed to launch telemetry failover server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($script);
        @unlink($capture);
        throw new RuntimeException('telemetry failover server failed: ' . trim($stderr));
    }

    return [$process, $pipes, $script, $capture, $port];
}

function king_telemetry_stop_failover_server(array $server): void
{
    [$process, $pipes, $script] = $server;
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    proc_close($process);
    @unlink($script);
}

$failurePort = king_telemetry_pick_unused_local_port();
king_telemetry_init([
    'otel_exporter_endpoint' => 'http://127.0.0.1:' . $failurePort,
]);

king_telemetry_record_metric('failover.requests_total', 7.0, null, 'counter');

var_dump(king_telemetry_flush());
$status = king_telemetry_get_status();
var_dump($status['queue_size']);
var_dump($status['export_failure_count']);
var_dump($status['export_success_count']);
var_dump($status['flush_count']);

$server = king_telemetry_start_failover_server();
$capture = $server[3];

try {
    king_telemetry_init([
        'otel_exporter_endpoint' => 'http://127.0.0.1:' . $server[4],
    ]);

    var_dump(king_telemetry_flush());
    $status = king_telemetry_get_status();
    var_dump($status['queue_size']);
    var_dump($status['export_failure_count']);
    var_dump($status['export_success_count']);
    var_dump($status['flush_count']);
} finally {
    king_telemetry_stop_failover_server($server);
}

$body = file_get_contents($capture);
@unlink($capture);

var_dump(str_contains($body, '"name":"failover.requests_total"'));
var_dump(str_contains($body, '"asInt":"7"'));
?>
--EXPECT--
bool(true)
int(1)
int(1)
int(0)
int(1)
bool(true)
int(0)
int(1)
int(1)
int(1)
bool(true)
bool(true)
