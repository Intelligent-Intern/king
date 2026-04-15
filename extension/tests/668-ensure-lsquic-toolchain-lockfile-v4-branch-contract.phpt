--TEST--
King ensure-quiche-toolchain preserves lockfile-v4 exit status and enters the dedicated remediation branch
--SKIPIF--
<?php
if (!function_exists('proc_open')) {
    echo "skip proc_open is required";
}
?>
--FILE--
<?php
function king_toolchain_668_cleanup(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            king_toolchain_668_cleanup($path . '/' . $entry);
        }

        @rmdir($path);
        return;
    }

    @unlink($path);
}

$tempRoot = sys_get_temp_dir() . '/king-toolchain-668-' . getmypid();
king_toolchain_668_cleanup($tempRoot);
mkdir($tempRoot, 0700, true);
mkdir($tempRoot . '/bin', 0700, true);

$ensureSource = __DIR__ . '/../../infra/scripts/ensure-quiche-toolchain.sh';
$ensureCopy = $tempRoot . '/ensure-quiche-toolchain.sh';
copy($ensureSource, $ensureCopy);
chmod($ensureCopy, 0700);

$compatStub = $tempRoot . '/cargo-build-compat.sh';
file_put_contents($compatStub, <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
echo "error: lock file version 4 requires newer Cargo support" >&2
exit 1
SH
);
chmod($compatStub, 0700);

$cargoStub = $tempRoot . '/bin/cargo';
file_put_contents($cargoStub, <<<'SH'
#!/usr/bin/env bash
exit 0
SH
);
chmod($cargoStub, 0700);

$rustcStub = $tempRoot . '/bin/rustc';
file_put_contents($rustcStub, <<<'SH'
#!/usr/bin/env bash
exit 0
SH
);
chmod($rustcStub, 0700);

$manifest = $tempRoot . '/Cargo.toml';
file_put_contents($manifest, "[package]\nname='stub'\nversion='0.1.0'\n");

$environment = $_ENV;
$environment['PATH'] = $tempRoot . '/bin:' . (getenv('PATH') ?: '');
$environment['KING_QUICHE_TOOLCHAIN_CONFIRM'] = 'no';

$command = ['bash', $ensureCopy, $manifest];
$process = proc_open(
    $command,
    [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ],
    $pipes,
    $tempRoot,
    $environment
);

if (!is_resource($process)) {
    throw new RuntimeException('failed to launch ensure-quiche-toolchain probe');
}

$stdout = (string) stream_get_contents($pipes[1]);
$stderr = (string) stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

var_dump($exitCode === 1);
var_dump(trim($stdout) === '');
var_dump(str_contains($stderr, 'Cargo toolchain does not support lockfile-v4 cleanly.'));
var_dump(str_contains($stderr, 'Aborted per confirmation (KING_QUICHE_TOOLCHAIN_CONFIRM=no).'));
var_dump(!str_contains($stderr, 'Toolchain check for quiche manifest failed before build.'));

king_toolchain_668_cleanup($tempRoot);
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
