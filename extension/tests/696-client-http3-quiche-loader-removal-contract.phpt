--TEST--
King client HTTP/3 removes the Quiche loader fallback
--FILE--
<?php
$root = dirname(__DIR__, 2);
$client = (string) file_get_contents($root . '/extension/src/client/http3.c');
$dispatch = (string) file_get_contents($root . '/extension/src/client/http3/dispatch_api.inc');
$errors = (string) file_get_contents($root . '/extension/src/client/http3/errors_and_validation.inc');
$issues = (string) file_get_contents($root . '/ISSUES.md');

function require_contains(string $label, string $source, string $needle): void
{
    if (!str_contains($source, $needle)) {
        throw new RuntimeException($label . ' must contain ' . $needle);
    }
}

function require_not_contains(string $label, string $source, string $needle): void
{
    if (str_contains($source, $needle)) {
        throw new RuntimeException($label . ' must not contain ' . $needle);
    }
}

$matches = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/extension/src', FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getFilename() === 'quiche_loader.inc') {
        $matches[] = $file->getPathname();
    }
}

if ($matches !== []) {
    throw new RuntimeException('Removed Quiche loader files still exist: ' . implode(', ', $matches));
}

require_not_contains('Client HTTP/3 source', $client, 'http3/quiche_loader.inc');
require_contains('Client HTTP/3 source', $client, '#include "http3/lsquic_loader.inc"');
require_contains('Client HTTP/3 source', $client, 'fail closed instead of loading a legacy fallback');
require_contains('HTTP/3 dispatch backend selector', $dispatch, 'king_http3_ensure_lsquic_ready()');
require_contains('HTTP/3 dispatch backend selector', $dispatch, 'king_http3_throw_lsquic_unavailable(function_name)');
require_contains('HTTP/3 dispatch backend selector', $dispatch, 'king_http3_throw_lsquic_build_required(function_name)');
require_not_contains('HTTP/3 dispatch backend selector', $dispatch, 'king_http3_ensure_quiche_ready');
require_not_contains('HTTP/3 dispatch backend selector', $dispatch, 'king_http3_quiche.load_error');
require_contains('HTTP/3 non-LSQUIC build failure', $errors, 'requires an LSQUIC-enabled HTTP/3 client build.');
require_contains(
    'Q-9 issue leaf',
    $issues,
    '- [x] Remove `extension/src/**/quiche_loader.inc` and fail closed without a Quiche loader fallback.'
);

echo "OK\n";
?>
--EXPECT--
OK
