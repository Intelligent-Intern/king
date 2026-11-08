--TEST--
King LSQUIC bootstrap rejects a structurally valid but untrusted source archive before extraction
--SKIPIF--
<?php
if (PHP_OS_FAMILY === 'Windows') die('skip requires POSIX shell tools');
?>
--FILE--
<?php
$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/king-source-integrity-' . bin2hex(random_bytes(8));
mkdir($tmp . '/bin', 0700, true);
file_put_contents($tmp . '/payload.txt', "Untrusted replacement source\n");
$oldPath = getenv('PATH');
$oldCache = getenv('KING_LSQUIC_SOURCE_CACHE');
$oldSource = getenv('KING_LSQUIC_SOURCE_DIR');
$oldMirror = getenv('KING_LSQUIC_ARCHIVE_MIRROR_BASE');
$oldFixture = getenv('KING_TEST_TAMPERED_SOURCE');
try {
    exec('tar -czf ' . escapeshellarg($tmp . '/replacement.tar.gz') . ' -C ' . escapeshellarg($tmp) . ' payload.txt', $unused, $tarStatus);
    if ($tarStatus !== 0) throw new RuntimeException('Could not build test archive');
    file_put_contents($tmp . '/bin/curl', <<<'SH'
#!/bin/sh
if [ "$1" = "--help" ]; then
    printf '%s\n' '--retry-all-errors'
    exit 0
fi
while [ "$#" -gt 0 ]; do
    if [ "$1" = "-o" ]; then
        cp "$KING_TEST_TAMPERED_SOURCE" "$2"
        exit 0
    fi
    shift
done
exit 1
SH);
    chmod($tmp . '/bin/curl', 0700);
    putenv('PATH=' . $tmp . '/bin:' . $oldPath);
    putenv('KING_LSQUIC_SOURCE_CACHE=' . $tmp . '/cache');
    putenv('KING_LSQUIC_SOURCE_DIR=' . $tmp . '/cache/src');
    putenv('KING_LSQUIC_ARCHIVE_MIRROR_BASE=https://example.invalid');
    putenv('KING_TEST_TAMPERED_SOURCE=' . $tmp . '/replacement.tar.gz');
    exec('bash ' . escapeshellarg($root . '/infra/scripts/bootstrap-lsquic.sh') . ' 2>&1', $output, $status);
    $log = implode("\n", $output);
    var_dump($status !== 0);
    var_dump(str_contains($log, 'Unexpected lsquic archive SHA-256'));
    var_dump(str_contains($log, 'Failed to fetch lsquic archive from all deterministic candidates'));
    var_dump(!is_dir($tmp . '/cache/src'));
    var_dump(glob($tmp . '/cache/archives/*') === []);
} finally {
    foreach (['PATH' => $oldPath, 'KING_LSQUIC_SOURCE_CACHE' => $oldCache,
        'KING_LSQUIC_SOURCE_DIR' => $oldSource, 'KING_LSQUIC_ARCHIVE_MIRROR_BASE' => $oldMirror,
        'KING_TEST_TAMPERED_SOURCE' => $oldFixture] as $key => $value) {
        putenv($value === false ? $key : $key . '=' . $value);
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname());
        else unlink($file->getPathname());
    }
    rmdir($tmp);
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
