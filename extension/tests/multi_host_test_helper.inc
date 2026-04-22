<?php

/* Shared helper utilities for repo-wide multi-host namespace and host-bound server harnesses. */

function king_multi_host_test_format_tcp_endpoint(string $host, int $port): string
{
    if (strpos($host, ':') !== false && !preg_match('/^\[.*\]$/', $host)) {
        return 'tcp://[' . $host . ']:' . $port;
    }

    return 'tcp://' . $host . ':' . $port;
}

function king_multi_host_test_start_server_script(
    string $scriptPath,
    ?int $port = null,
    array $extraArgs = [],
    string $bindHost = '127.0.0.1'
): array
{
    if ($port === null) {
        $lastError = 'unknown startup error';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return king_multi_host_test_start_server_script(
                    $scriptPath,
                    random_int(40000, 49999),
                    $extraArgs,
                    $bindHost
                );
            } catch (RuntimeException $e) {
                $lastError = $e->getMessage();
            }
        }

        throw new RuntimeException('failed to launch multi-host test server after retries: ' . $lastError);
    }

    $capture = tempnam(sys_get_temp_dir(), 'king-multi-host-capture-');
    $extensionPath = dirname(__DIR__) . '/modules/king.so';
    $command = [
        PHP_BINARY,
        '-n',
        '-d', 'extension=' . $extensionPath,
        $scriptPath,
        $capture,
        (string) ((int) $port),
        $bindHost,
    ];
    foreach ($extraArgs as $arg) {
        $command[] = (string) $arg;
    }

    $process = proc_open($command, [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);

    if (!is_resource($process)) {
        @unlink($capture);
        throw new RuntimeException('failed to launch multi-host test server');
    }

    $ready = fgets($pipes[1]);
    if ($ready !== "READY\n") {
        $stderr = stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }
        proc_close($process);
        @unlink($capture);
        throw new RuntimeException('multi-host test server failed: ' . trim($stderr));
    }

    return [
        'process' => $process,
        'pipes' => $pipes,
        'capture' => $capture,
        'port' => (int) $port,
        'host' => $bindHost,
    ];
}

function king_multi_host_test_finalize_server(array $server): array
{
    if (isset($server['pipes'][1]) && is_resource($server['pipes'][1])) {
        stream_set_blocking($server['pipes'][1], false);
    }
    if (isset($server['pipes'][2]) && is_resource($server['pipes'][2])) {
        stream_set_blocking($server['pipes'][2], false);
    }

    $stdout = isset($server['pipes'][1]) ? stream_get_contents($server['pipes'][1]) : '';
    $stderr = isset($server['pipes'][2]) ? stream_get_contents($server['pipes'][2]) : '';

    foreach ($server['pipes'] as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }

    $exitCode = proc_close($server['process']);
    $capture = [];
    if (is_file($server['capture'])) {
        $capture = json_decode((string) file_get_contents($server['capture']), true);
        if (!is_array($capture)) {
            $capture = [];
        }
        @unlink($server['capture']);
    }

    if ($capture === [] && ($stdout !== '' || $stderr !== '' || $exitCode !== 0)) {
        $capture = [
            '__stdout' => $stdout,
            '__stderr' => $stderr,
            '__exit_code' => $exitCode,
        ];
    }

    return $capture;
}

function king_multi_host_test_detect_non_loopback_ipv4(): string
{
    $output = trim((string) shell_exec('ip -4 -o addr show up scope global 2>/dev/null'));

    if ($output === '') {
        $output = trim((string) shell_exec('ip -4 -o addr show up 2>/dev/null'));
    }

    if ($output === '') {
        throw new RuntimeException('failed to detect a non-loopback IPv4 address for the multi-host harness');
    }

    foreach (preg_split('/\R+/', $output) as $line) {
        if (preg_match('/\sinet\s+([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)\//', $line, $matches) !== 1) {
            continue;
        }

        if (str_starts_with($matches[1], '127.')) {
            continue;
        }

        return $matches[1];
    }

    throw new RuntimeException('failed to find a usable non-loopback IPv4 address for the multi-host harness');
}

function king_multi_host_test_run_namespaced_php_script(
    string $scriptPath,
    int $port,
    array $extraArgs = [],
    array $phpIni = [],
    string $label = 'multi-host harness'
): array
{
    $workDir = sys_get_temp_dir() . '/king-multi-host-netns-' . bin2hex(random_bytes(8));
    $outboundAddr = king_multi_host_test_detect_non_loopback_ipv4();
    $gatewayHost = $outboundAddr;
    $extensionPath = dirname(__DIR__) . '/modules/king.so';
    $ldLibraryPath = getenv('LD_LIBRARY_PATH');
    $namespaceProcess = null;
    $slirpProcess = null;
    $namespacePipes = [];
    $slirpPipes = [];
    $capture = [];
    $resolvedPhpIni = array_map(
        static function (string $entry) use ($gatewayHost, $outboundAddr): string {
            return str_replace(
                ['{gateway_host}', '{outbound_addr}'],
                [$gatewayHost, $outboundAddr],
                $entry
            );
        },
        array_merge(['king.security_allow_config_override=1'], $phpIni)
    );
    $phpIniArgs = implode(
        ' ',
        array_map(
            static fn (string $entry): string => '-d ' . escapeshellarg($entry),
            $resolvedPhpIni
        )
    );

    if (!mkdir($workDir, 0700, true) && !is_dir($workDir)) {
        throw new RuntimeException('failed to create ' . $label . ' workspace');
    }

    $namespaceScript = <<<'BASH'
set -e
workdir="$1"
gateway_host="$2"
port="$3"
script_path="$4"
ld_library_path="$5"
shift 5

echo $$ > "$workdir/pid"
ip link set lo up

while [ ! -f "$workdir/go" ]; do
    sleep 0.05
done

if [ -n "$ld_library_path" ]; then
    export LD_LIBRARY_PATH="$ld_library_path"
fi

set +e
# Keep the PHP invocation on one logical shell line so the script path never
# falls out as a separate executable when optional ini overrides are present.
"PHP_BINARY_PLACEHOLDER" -n \
    -d "extension=EXTENSION_PLACEHOLDER" PHP_INI_ARGS_PLACEHOLDER \
    "$script_path" \
    "$workdir/capture.json" \
    "$gateway_host" \
    "$port" \
    "$@" > "$workdir/stdout" 2> "$workdir/stderr"
status=$?
set -e

printf '%s' "$status" > "$workdir/exit.status"
exit "$status"
BASH;

    $namespaceScript = str_replace(
        ['PHP_BINARY_PLACEHOLDER', 'EXTENSION_PLACEHOLDER', 'PHP_INI_ARGS_PLACEHOLDER'],
        [PHP_BINARY, $extensionPath, $phpIniArgs],
        $namespaceScript
    );

    $namespaceCommand = array_merge(
        [
            'unshare',
            '-Urn',
            'bash',
            '-lc',
            $namespaceScript,
            '_',
            $workDir,
            $gatewayHost,
            (string) $port,
            $scriptPath,
            is_string($ldLibraryPath) ? $ldLibraryPath : '',
        ],
        array_map(static fn ($arg): string => (string) $arg, $extraArgs)
    );

    try {
        $namespaceProcess = proc_open(
            $namespaceCommand,
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $namespacePipes
        );
        if (!is_resource($namespaceProcess)) {
            throw new RuntimeException('failed to launch the ' . $label . ' client process');
        }

        $pidPath = $workDir . '/pid';
        $deadline = microtime(true) + 5.0;
        while (!is_file($pidPath) && microtime(true) < $deadline) {
            usleep(50000);
        }

        if (!is_file($pidPath)) {
            throw new RuntimeException($label . ' client did not publish its pid');
        }

        $namespacePid = trim((string) file_get_contents($pidPath));
        if ($namespacePid === '' || preg_match('/^\d+$/', $namespacePid) !== 1) {
            throw new RuntimeException($label . ' client published an invalid pid');
        }

        $slirpProcess = proc_open(
            [
                'slirp4netns',
                '--configure',
                '--disable-host-loopback',
                '--outbound-addr=' . $outboundAddr,
                $namespacePid,
                'tap0',
            ],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $slirpPipes
        );
        if (!is_resource($slirpProcess)) {
            throw new RuntimeException('failed to launch slirp4netns for the ' . $label . ' client');
        }

        usleep(1000000);
        touch($workDir . '/go');

        $exitPath = $workDir . '/exit.status';
        $deadline = microtime(true) + 20.0;
        while (!is_file($exitPath) && microtime(true) < $deadline) {
            usleep(50000);
        }

        if (!is_file($exitPath)) {
            throw new RuntimeException($label . ' client did not finish within the timeout budget');
        }

        $capture = is_file($workDir . '/capture.json')
            ? json_decode((string) file_get_contents($workDir . '/capture.json'), true)
            : [];
        if (!is_array($capture)) {
            $capture = [];
        }

        $capture['__exit_code'] = (int) trim((string) file_get_contents($exitPath));
        $capture['__stdout'] = is_file($workDir . '/stdout')
            ? (string) file_get_contents($workDir . '/stdout')
            : '';
        $capture['__stderr'] = is_file($workDir . '/stderr')
            ? (string) file_get_contents($workDir . '/stderr')
            : '';
        $capture['gateway_host'] = $gatewayHost;
        $capture['outbound_addr'] = $outboundAddr;
    } finally {
        if (isset($slirpPipes[1]) && is_resource($slirpPipes[1])) {
            fclose($slirpPipes[1]);
        }
        if (isset($slirpPipes[2]) && is_resource($slirpPipes[2])) {
            fclose($slirpPipes[2]);
        }
        if (is_resource($slirpProcess)) {
            proc_terminate($slirpProcess);
            proc_close($slirpProcess);
        }

        if (isset($namespacePipes[1]) && is_resource($namespacePipes[1])) {
            fclose($namespacePipes[1]);
        }
        if (isset($namespacePipes[2]) && is_resource($namespacePipes[2])) {
            fclose($namespacePipes[2]);
        }
        if (is_resource($namespaceProcess)) {
            proc_terminate($namespaceProcess);
            proc_close($namespaceProcess);
        }

        @unlink($workDir . '/capture.json');
        @unlink($workDir . '/stdout');
        @unlink($workDir . '/stderr');
        @unlink($workDir . '/exit.status');
        @unlink($workDir . '/go');
        @unlink($workDir . '/pid');
        @rmdir($workDir);
    }

    return $capture;
}
