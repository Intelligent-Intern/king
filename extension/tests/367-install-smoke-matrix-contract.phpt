--TEST--
King release install and container smoke matrices stay wired into the canonical gates
--FILE--
<?php
$extensionDir = dirname(__DIR__);
$rootDir = dirname($extensionDir);
$installScript = $extensionDir . '/scripts/install-package-matrix.sh';
$containerScript = $extensionDir . '/scripts/container-smoke-matrix.sh';
$ciWorkflow = $rootDir . '/.github/workflows/ci.yml';
$dockerWorkflow = $rootDir . '/.github/workflows/docker.yml';
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
var_dump(str_contains($ci, 'install-package-matrix:'));
var_dump(str_contains($ci, 'Package Install Smoke PHP ${{ matrix.php-version }}'));
var_dump(str_contains($ci, './scripts/install-package-matrix.sh --archive'));
var_dump(str_contains($ci, '"8.1"'));
var_dump(str_contains($ci, '"8.2"'));
var_dump(str_contains($ci, '"8.3"'));
var_dump(str_contains($ci, '"8.4"'));
var_dump(str_contains($ci, '"8.5"'));

$docker = (string) file_get_contents($dockerWorkflow);
var_dump(str_contains($docker, "php-version: ['8.1', '8.2', '8.3', '8.4', '8.5']"));
var_dump(str_contains($docker, 'Build, Smoke & Push Docker Images'));

$runtime = (string) file_get_contents($runtimeDockerfile);
var_dump(str_contains($runtime, 'COPY extension/scripts/runtime-install-smoke.php /opt/king/runtime/smoke.php'));
var_dump(str_contains($runtime, 'php -d king.security_allow_config_override=1 /opt/king/runtime/smoke.php'));
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
