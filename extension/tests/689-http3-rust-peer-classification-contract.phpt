--TEST--
King HTTP/3 Rust peers and Cargo locks are removed from test fixtures
--FILE--
<?php
$root = dirname(__DIR__, 2);
$testsDir = $root . '/extension/tests';
$classificationPath = $testsDir . '/http3_rust_peer_classification.inc';
$classification = require $classificationPath;

function king_http3_classification_fail(string $message): void
{
    echo "FAIL: {$message}\n";
    exit(1);
}

function king_http3_relative_path(string $root, string $path): string
{
    $relative = substr($path, strlen($root) + 1);
    if (!is_string($relative) || $relative === '') {
        king_http3_classification_fail("path is outside repository root: {$path}");
    }

    return str_replace('\\', '/', $relative);
}

$inventory = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($testsDir, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }

    $filename = $file->getFilename();
    if (str_ends_with($filename, '.rs') || $filename === 'Cargo.toml' || $filename === 'Cargo.lock') {
        $inventory[] = king_http3_relative_path($root, $file->getPathname());
    }
}

sort($inventory);
if ($inventory !== []) {
    king_http3_classification_fail(
        "HTTP/3 Rust/Cargo fixtures still exist\n" .
        'inventory=' . json_encode($inventory, JSON_UNESCAPED_SLASHES)
    );
}

if (!is_array($classification) || $classification !== []) {
    king_http3_classification_fail('HTTP/3 Rust/Cargo fixture classification must stay empty after removal');
}

echo "No HTTP/3 Rust/Cargo fixtures remain.\n";
?>
--EXPECT--
No HTTP/3 Rust/Cargo fixtures remain.
