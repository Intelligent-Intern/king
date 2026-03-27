--TEST--
King go-live readiness resolves benchmark budget files from the repo root contract
--FILE--
<?php
$extensionDir = dirname(__DIR__);
$rootDir = dirname($extensionDir);
$script = (string) file_get_contents($extensionDir . '/scripts/go-live-readiness.sh');
$workflow = (string) file_get_contents($rootDir . '/.github/workflows/ci.yml');

var_dump($script !== '');
var_dump(str_contains($script, 'resolve_existing_path()'));
var_dump(str_contains($script, '${ROOT_DIR}/${candidate}'));
var_dump(str_contains($workflow, '--benchmark-budget-file benchmarks/budgets/canonical-ci.json'));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
