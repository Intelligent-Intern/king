--TEST--
King HTTP/1 reuseport SKIPIF probes stay warning-free
--FILE--
<?php
$paths = [
    __DIR__ . '/740-http1-listener-exclusive-bind-contract.phpt',
    __DIR__ . '/741-http1-listener-reuseport-opt-in-contract.phpt',
];

function king_extract_skipif(string $path): string
{
    $source = (string) file_get_contents($path);
    if (!preg_match('/--SKIPIF--\R(.*?)\R--FILE--/s', $source, $matches)) {
        throw new RuntimeException('missing SKIPIF in ' . basename($path));
    }
    return $matches[1];
}

function king_run_skipif_isolated(string $skipif): array
{
    $warnings = [];
    $process = proc_open(
        [
            PHP_BINARY,
            '-d',
            'disable_functions=',
            '-d',
            'display_errors=1',
            '-d',
            'display_startup_errors=1',
            '-d',
            'error_reporting=' . E_ALL,
        ],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );

    if (!is_resource($process)) {
        return ['', ['failed to execute isolated SKIPIF process']];
    }

    fwrite($pipes[0], $skipif);
    fclose($pipes[0]);

    $stdout = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $exit = proc_close($process);
    if ($stderr !== '') {
        $warnings[] = trim($stderr);
    }
    if ($exit !== 0) {
        $warnings[] = 'isolated SKIPIF process exited with code ' . $exit;
    }

    return [trim($stdout), $warnings];
}

foreach ($paths as $path) {
    $skipif = king_extract_skipif($path);

    var_dump(!str_contains($skipif, "['command', '-v', 'python3']"));
    var_dump(!str_contains($skipif, 'command -v python3'));
    var_dump((bool) preg_match('~@proc_open\s*\(\s*\[\s*\$candidate\s*,\s*[\'"]-c[\'"]\s*,~s', $skipif));

    [$output, $warnings] = king_run_skipif_isolated($skipif);

    var_dump($warnings === []);
    var_dump($output === '' || str_starts_with($output, 'skip '));
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
