--TEST--
King release-package verifier rejects unsafe archive entries before extraction
--FILE--
<?php
$root = sys_get_temp_dir() . '/king_verify_release_package_' . getmypid();
$script = dirname(__DIR__) . '/scripts/verify-release-package.sh';
$traversalArchive = $root . '/traversal.tar.gz';
$symlinkArchive = $root . '/symlink.tar.gz';

function king_verify_release_cleanup(string $path): void
{
    if (!file_exists($path) && !is_link($path)) {
        return;
    }

    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }

    foreach (scandir($path) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        king_verify_release_cleanup($path . '/' . $entry);
    }
    @rmdir($path);
}

@mkdir($root, 0700, true);

file_put_contents($root . '/payload', "unsafe\n");
$buildOutput = [];
$cmd = sprintf(
    'cd %s && tar -czf %s --transform=%s %s',
    escapeshellarg($root),
    escapeshellarg($traversalArchive),
    escapeshellarg('s#payload#king-bad/../escape#'),
    escapeshellarg('payload')
);
exec($cmd, $buildOutput, $buildStatus);
var_dump($buildStatus === 0);
file_put_contents(
    $traversalArchive . '.sha256',
    hash_file('sha256', $traversalArchive) . '  ' . basename($traversalArchive) . PHP_EOL
);

$cmd = sprintf(
    'bash %s --archive %s 2>&1',
    escapeshellarg($script),
    escapeshellarg($traversalArchive)
);
$output = [];
exec($cmd, $output, $status);
var_dump($status !== 0);
var_dump((bool) array_filter($output, static fn(string $line): bool => str_contains($line, 'Archive contains unsafe path entry:')));

mkdir($root . '/king-link/bin', 0700, true);
symlink('/bin/sh', $root . '/king-link/bin/smoke.sh');
$buildOutput = [];
$cmd = sprintf(
    'cd %s && tar -czf %s %s',
    escapeshellarg($root),
    escapeshellarg($symlinkArchive),
    escapeshellarg('king-link')
);
exec($cmd, $buildOutput, $buildStatus);
var_dump($buildStatus === 0);
file_put_contents(
    $symlinkArchive . '.sha256',
    hash_file('sha256', $symlinkArchive) . '  ' . basename($symlinkArchive) . PHP_EOL
);

$cmd = sprintf(
    'bash %s --archive %s 2>&1',
    escapeshellarg($script),
    escapeshellarg($symlinkArchive)
);
$output = [];
exec($cmd, $output, $status);
var_dump($status !== 0);
var_dump((bool) array_filter($output, static fn(string $line): bool => str_contains($line, 'Archive contains disallowed link entry.')));

king_verify_release_cleanup($root);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
