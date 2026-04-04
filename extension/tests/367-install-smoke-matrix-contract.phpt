--TEST--
King release install and docker publish matrices stay split between canonical and mainline gates
--FILE--
<?php
$extensionDir = dirname(__DIR__);
$rootDir = dirname($extensionDir);
$installScript = $rootDir . '/infra/scripts/install-package-matrix.sh';
$containerScript = $rootDir . '/infra/scripts/container-smoke-matrix.sh';
$ciWorkflow = $rootDir . '/.github/workflows/ci.yml';
$dockerWorkflow = $rootDir . '/.github/workflows/docker-publish.yml';
$runtimeDockerfile = $rootDir . '/infra/php-runtime.Dockerfile';

$output = [];
$status = 1;
exec('bash ' . escapeshellarg($installScript) . ' --help 2>&1', $output, $status);
var_dump($status === 0);
var_dump(str_contains(implode("\n", $output), '--archive PATH'));

$output = [];
$status = 1;
exec('bash ' . escapeshellarg($containerScript) . ' --help 2>&1', $output, $status);
var_dump($status === 0);
var_dump(str_contains(implode("\n", $output), '--php-versions 8.1,8.2,8.3,...'));

$ci = (string) file_get_contents($ciWorkflow);
$docker = (string) file_get_contents($dockerWorkflow);
var_dump(str_contains($ci, 'install-package-matrix:'));
var_dump(str_contains($ci, 'Release Package Build & Install Smoke PHP ${{ matrix.php-version }} ${{ matrix.arch-label }}'));
var_dump(str_contains($ci, '../infra/scripts/install-package-matrix.sh --archive'));
var_dump(str_contains($ci, 'king-release-package-php8.5-linux-amd64'));
var_dump(str_contains($ci, 'king-release-package-php${{ matrix.php-version }}-${{ matrix.arch-label }}'));
var_dump(str_contains($ci, 'ubuntu-24.04-arm'));
var_dump(!str_contains($ci, 'docker-publish-gate:'));
var_dump(!str_contains($ci, 'docker-build-and-push:'));
var_dump(str_contains($docker, 'workflow_run:'));
var_dump(str_contains($docker, '- King Canonical Baseline'));
var_dump(str_contains($docker, '- main'));
var_dump(str_contains($docker, 'docker-build-and-push:'));
var_dump(str_contains($docker, 'docker-build-demo:'));
var_dump(str_contains($docker, 'run-id: ${{ needs.gate.outputs.source-run-id }}'));
var_dump(str_contains($docker, 'Build and push King PHP ${{ matrix.php-version }}'));
var_dump(str_contains($docker, 'king-release-package-php${{ matrix.php-version }}-linux-amd64'));
var_dump(str_contains($docker, 'king-release-package-php${{ matrix.php-version }}-linux-arm64'));

$runtime = (string) file_get_contents($runtimeDockerfile);
var_dump(str_contains($runtime, 'source=dist/docker-packages'));
var_dump(str_contains($runtime, 'extension=/opt/king/package/modules/king.so'));
var_dump(str_contains($runtime, 'PHP_BIN=php /opt/king/package/bin/smoke.sh'));
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
bool(true)
bool(true)
bool(true)
bool(true)
