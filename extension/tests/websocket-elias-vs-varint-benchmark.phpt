--TEST--
Benchmark: C-level Elias omega vs LEB128 varint batch encode/decode under high load
--FILE--
<?php
/*
 * Head-to-head: C-level Elias omega (MSB-first varint) vs LEB128 varint
 * for batch length-prefix encode/decode.
 *
 * Uses the actual C implementations:
 *   encodeBatch  / decodeBatch        -> king_proto_encode_omega / decode_omega
 *   encodeBatchVarint / decodeBatchVarint -> king_proto_encode_varint / decode_varint
 *
 * Payload serialization is identical — only the length prefix framing differs.
 *
 * Workloads simulate WebSocket broadcast patterns:
 *   - high-fanout tiny messages  (ticks, pings, presence)
 *   - moderate medium messages   (chat, state patches, API responses)
 *   - fewer large messages       (file push, snapshot sync)
 */

\King\IIBIN::defineSchema('BenchMsg', [
    'id'   => ['type' => 'uint32', 'tag' => 1],
    'body' => ['type' => 'string', 'tag' => 2],
]);

function make_records(int $count, int $bodyLen): array {
    $body = str_repeat('x', $bodyLen);
    $recs = [];
    for ($i = 0; $i < $count; $i++) {
        $recs[] = ['id' => $i, 'body' => $body];
    }
    return $recs;
}

function bench_encode_omega(array $recs, int $iters): float {
    $s = hrtime(true);
    for ($i = 0; $i < $iters; $i++) \King\IIBIN::encodeBatch('BenchMsg', $recs);
    return (hrtime(true) - $s) / 1e3 / $iters;
}
function bench_encode_varint(array $recs, int $iters): float {
    $s = hrtime(true);
    for ($i = 0; $i < $iters; $i++) \King\IIBIN::encodeBatchVarint('BenchMsg', $recs);
    return (hrtime(true) - $s) / 1e3 / $iters;
}
function bench_decode_omega(string $p, int $iters): float {
    $s = hrtime(true);
    for ($i = 0; $i < $iters; $i++) \King\IIBIN::decodeBatch('BenchMsg', $p);
    return (hrtime(true) - $s) / 1e3 / $iters;
}
function bench_decode_varint(string $p, int $iters): float {
    $s = hrtime(true);
    for ($i = 0; $i < $iters; $i++) \King\IIBIN::decodeBatchVarint('BenchMsg', $p);
    return (hrtime(true) - $s) / 1e3 / $iters;
}
function bench_rt_omega(array $recs, int $iters): float {
    $s = hrtime(true);
    for ($i = 0; $i < $iters; $i++) {
        $e = \King\IIBIN::encodeBatch('BenchMsg', $recs);
        \King\IIBIN::decodeBatch('BenchMsg', $e);
    }
    return (hrtime(true) - $s) / 1e3 / $iters;
}
function bench_rt_varint(array $recs, int $iters): float {
    $s = hrtime(true);
    for ($i = 0; $i < $iters; $i++) {
        $e = \King\IIBIN::encodeBatchVarint('BenchMsg', $recs);
        \King\IIBIN::decodeBatchVarint('BenchMsg', $e);
    }
    return (hrtime(true) - $s) / 1e3 / $iters;
}

function winner(float $a, float $b): string {
    if (abs($a - $b) / max($a, $b) < 0.03) return '  tie';
    return $a < $b ? 'OMEGA' : 'varint';
}

$workloads = [
    ['5000x16B  tick fanout',   5000,    16],
    ['5000x64B  chat bcast',    5000,    64],
    ['1000x256B state patch',   1000,   256],
    ['1000x1KB  API resp',      1000,  1024],
    ['100x10KB  medium',         100, 10240],
    ['10x100KB  large push',      10, 102400],
    ['10x1MB    snapshot',        10, 1048576],
];

echo "=== C-level Elias Omega vs LEB128 Varint benchmark ===\n\n";

$hdr = sprintf("%-24s| %8s %8s %6s | %8s %8s %6s | %8s %8s %6s",
    "Workload",
    "Enc:O", "Enc:V", "",
    "Dec:O", "Dec:V", "",
    "RT:O",  "RT:V",  "");
echo $hdr . "\n";
echo str_repeat("-", strlen($hdr)) . "\n";

$ow = 0; $vw = 0; $tw = 0;

foreach ($workloads as [$desc, $cnt, $blen]) {
    $recs = make_records($cnt, $blen);
    $sz   = $cnt * ($blen + 8);
    $it   = max(1, min(5000, (int)(300000 / $sz)));

    /* warmup both paths */
    bench_encode_omega($recs, 2);
    bench_encode_varint($recs, 2);

    $eO = bench_encode_omega($recs, $it);
    $eV = bench_encode_varint($recs, $it);
    $eW = winner($eO, $eV);

    $pO = \King\IIBIN::encodeBatch('BenchMsg', $recs);
    $pV = \King\IIBIN::encodeBatchVarint('BenchMsg', $recs);
    $dO = bench_decode_omega($pO, $it);
    $dV = bench_decode_varint($pV, $it);
    $dW = winner($dO, $dV);

    $rO = bench_rt_omega($recs, $it);
    $rV = bench_rt_varint($recs, $it);
    $rW = winner($rO, $rV);

    foreach ([$eW, $dW, $rW] as $w) {
        if (trim($w) === 'OMEGA')  $ow++;
        elseif (trim($w) === 'varint') $vw++;
        else $tw++;
    }

    echo sprintf("%-24s| %7.1f %8.1f %6s | %7.1f %8.1f %6s | %7.1f %8.1f %6s\n",
        $desc, $eO, $eV, $eW, $dO, $dV, $dW, $rO, $rV, $rW);
}

echo "\nOmega wins: $ow  Varint wins: $vw  Ties: $tw\n";

/* correctness gate */
$recs = make_records(100, 512);
$po = \King\IIBIN::encodeBatch('BenchMsg', $recs);
$pv = \King\IIBIN::encodeBatchVarint('BenchMsg', $recs);
$do = \King\IIBIN::decodeBatch('BenchMsg', $po);
$dv = \King\IIBIN::decodeBatchVarint('BenchMsg', $pv);
$ok = count($do) === 100 && count($dv) === 100;
for ($i = 0; $i < 100 && $ok; $i++) {
    $ok = $do[$i]['id'] === $recs[$i]['id'] && $do[$i]['body'] === $recs[$i]['body']
       && $dv[$i]['id'] === $recs[$i]['id'] && $dv[$i]['body'] === $recs[$i]['body'];
}
echo "Correctness: " . ($ok ? "PASS" : "FAIL") . "\n";
--EXPECTF--
=== C-level Elias Omega vs LEB128 Varint benchmark ===

%s
%s
%s
%s
%s
%s
%s
%s
%s

Omega wins: %d  Varint wins: %d  Ties: %d
Correctness: PASS