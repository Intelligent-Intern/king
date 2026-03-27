--TEST--
King release persistence migration gate stays wired into scripts and CI
--FILE--
<?php
$extensionDir = dirname(__DIR__);
$rootDir = dirname($extensionDir);
$script = $extensionDir . '/scripts/check-persistence-migration.sh';
$fixture = $extensionDir . '/scripts/runtime-persistence-migration.php';
$ciWorkflow = $rootDir . '/.github/workflows/ci.yml';

$output = [];
$status = 1;
exec('bash ' . escapeshellarg($script) . ' --help 2>&1', $output, $status);
$help = implode("\n", $output);

var_dump($status === 0);
var_dump(str_contains($help, 'Usage: ./scripts/check-persistence-migration.sh --from-ref REF'));
var_dump(str_contains($help, '--current-archive PATH'));
var_dump(str_contains($help, '--php-bin BIN'));
var_dump(str_contains($help, '--artifacts-dir DIR'));

$fixtureSource = (string) file_get_contents($fixture);
var_dump(str_contains($fixtureSource, '$mode === \'write\''));
var_dump(str_contains($fixtureSource, '$mode !== \'write\' && $mode !== \'read\''));
var_dump(str_contains($fixtureSource, 'king_pipeline_orchestrator_register_tool(\'summarizer\''));
var_dump(str_contains($fixtureSource, 'king_semantic_dns_register_service(['));

$ci = (string) file_get_contents($ciWorkflow);
var_dump(str_contains($ci, 'release-persistence-migration:'));
var_dump(str_contains($ci, 'name: Release Persistence Migration'));
var_dump(str_contains($ci, 'fetch-depth: 0'));
var_dump(str_contains($ci, 'github.event.before'));
var_dump(str_contains($ci, './scripts/check-persistence-migration.sh --from-ref'));
var_dump(str_contains($ci, 'compat-artifacts/release-persistence-migration/'));
var_dump(str_contains($ci, 'king-release-persistence-migration-failures'));
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
