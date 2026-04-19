#!/usr/bin/env php
<?php
/**
 * Omega (MSB-first) vs Varint (LSB-first) for batch length prefixes.
 *
 * Tests batch encode/decode roundtrip at different payload sizes to see
 * whether the length-prefix encoding matters relative to payload work.
 *
 * Run twice — once with omega build, once with varint build — and compare.
 */

if (!extension_loaded('king')) {
    $extSo = dirname(__DIR__) . '/extension/modules/king.so';
    $phpBin = getenv('PHP_BIN') ?: '/usr/local/bin/php';
    if (!file_exists($extSo)) {
        fwrite(STDERR, "king.so not found at {$extSo}\nRun: cd extension && make\n");
        exit(1);
    }
    $cmd = escapeshellarg($phpBin) . ' -d ' . escapeshellarg('extension=' . $extSo);
    foreach ($argv as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }
    passthru($cmd, $exitCode);
    exit($exitCode);
}

echo "=== Batch Length-Prefix Benchmark ===\n";
echo "PHP " . PHP_VERSION . " | King " . phpversion('king') . "\n";
echo "Encoding: " . ($argv[1] ?? 'unknown') . "\n\n";

// Schema with a variable-size payload field
\King\IIBIN::defineSchema('Msg', [
    'id'   => ['type' => 'uint32', 'tag' => 1],
    'body' => ['type' => 'string', 'tag' => 2],
]);

function make_records(int $count, int $bodyLen): array {
    $body = str_repeat('x', $bodyLen);
    $records = [];
    for ($i = 0; $i < $count; $i++) {
        $records[] = ['id' => $i, 'body' => $body];
    }
    return $records;
}

function bench_encode(string $schema, array $records, int $iters): float {
    $start = hrtime(true);
    for ($i = 0; $i < $iters; $i++) {
        \King\IIBIN::encodeBatch($schema, $records);
    }
    return (hrtime(true) - $start) / 1e3 / $iters; // µs per call
}

function bench_roundtrip(string $schema, array $records, int $iters): float {
    // Pre-encode once for decode path
    $packed = \King\IIBIN::encodeBatch($schema, $records);

    $start = hrtime(true);
    for ($i = 0; $i < $iters; $i++) {
        $enc = \King\IIBIN::encodeBatch($schema, $records);
        \King\IIBIN::decodeBatch($schema, $enc);
    }
    return (hrtime(true) - $start) / 1e3 / $iters; // µs per call
}

function bench_decode(string $schema, string $packed, int $iters): float {
    $start = hrtime(true);
    for ($i = 0; $i < $iters; $i++) {
        \King\IIBIN::decodeBatch($schema, $packed);
    }
    return (hrtime(true) - $start) / 1e3 / $iters;
}

// Workloads: [description, record_count, body_bytes]
$workloads = [
    ['1000 × tiny (10B)',       1000,    10],
    ['1000 × small (100B)',     1000,   100],
    ['100  × medium (1KB)',      100,  1024],
    ['100  × large (10KB)',      100, 10240],
    ['10   × big (100KB)',        10, 102400],
    ['10   × huge (1MB)',         10, 1048576],
    ['1    × giant (10MB)',        1, 10485760],
];

echo str_repeat("=", 78) . "\n";
echo sprintf("%-25s %8s %10s %10s %12s\n",
    "Workload", "Recs", "Encode µs", "Decode µs", "RT µs");
echo str_repeat("-", 78) . "\n";

foreach ($workloads as [$desc, $count, $bodyLen]) {
    $records = make_records($count, $bodyLen);
    $totalPayload = $count * ($bodyLen + 8); // approx

    // Scale iterations inversely with payload so each test takes ~0.5s
    $iters = max(1, (int)(500000 / $totalPayload));
    $iters = min($iters, 5000);

    $encUs   = bench_encode('Msg', $records, $iters);
    $packed  = \King\IIBIN::encodeBatch('Msg', $records);
    $decUs   = bench_decode('Msg', $packed, $iters);
    $rtUs    = bench_roundtrip('Msg', $records, $iters);

    echo sprintf("%-25s %8d %10.1f %10.1f %12.1f\n",
        $desc, $count, $encUs, $decUs, $rtUs);
}

echo str_repeat("=", 78) . "\n";

// Verify correctness on the largest workload
$big = make_records(10, 1048576);
$packed = \King\IIBIN::encodeBatch('Msg', $big);
$decoded = \King\IIBIN::decodeBatch('Msg', $packed);
$ok = count($decoded) === 10;
for ($i = 0; $i < 10 && $ok; $i++) {
    $ok = $decoded[$i]['id'] === $big[$i]['id']
       && $decoded[$i]['body'] === $big[$i]['body'];
}
echo "Correctness: " . ($ok ? "PASS" : "FAIL") . "\n";
