--TEST--
King release determinism and supply-chain gates stay pinned in scripts and CI workflows
--FILE--
<?php
$extensionDir = dirname(__DIR__);
$rootDir = dirname($extensionDir);
$packageScript = (string) file_get_contents($rootDir . '/infra/scripts/package-release.sh');
$fuzzScript = (string) file_get_contents($rootDir . '/infra/scripts/fuzz-runtime.sh');
$supplyChainScript = (string) file_get_contents($rootDir . '/infra/scripts/verify-release-supply-chain.sh');
$ciWorkflow = (string) file_get_contents($rootDir . '/.github/workflows/ci.yml');
$releaseWorkflow = (string) file_get_contents($rootDir . '/.github/workflows/release-merge-publish.yml');

var_dump(file_exists($rootDir . '/infra/scripts/phpize-generated-files.list'));
// phpize supplies these tools for the selected PHP version. The build must
// leave them available for PHPT execution and subsequent make invocations.
var_dump(is_file($extensionDir . '/run-tests.php'));
var_dump(is_file($extensionDir . '/build/gen_stub.php'));
var_dump(str_contains($packageScript, 'normalize_arch_name'));
var_dump(str_contains($packageScript, "'provenance' => ["));
var_dump(str_contains($fuzzScript, '--suite NAME'));
var_dump(str_contains($fuzzScript, 'transport'));
var_dump(str_contains($fuzzScript, 'object-store'));
var_dump(str_contains($fuzzScript, 'mcp'));
var_dump(str_contains($supplyChainScript, '--expected-git-commit'));
var_dump(str_contains($supplyChainScript, 'supply-chain provenance ok'));
var_dump(str_contains($ciWorkflow, 'verify-release-supply-chain.sh --archive'));
var_dump(str_contains($releaseWorkflow, 'verify-release-supply-chain.sh --archive'));
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
