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

foreach ($paths as $path) {
    $skipif = king_extract_skipif($path);

    var_dump(!str_contains($skipif, "['command', '-v', 'python3']"));
    var_dump(!str_contains($skipif, 'command -v python3'));
    var_dump(str_contains($skipif, '@proc_open('));

    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        $warnings[] = $message;
        return true;
    });
    ob_start();
    try {
        eval('?>' . $skipif);
    } finally {
        $output = trim((string) ob_get_clean());
        restore_error_handler();
    }

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
