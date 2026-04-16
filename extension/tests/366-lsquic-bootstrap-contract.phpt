--TEST--
King lsquic integration verification
--SKIPIF--
<?php
$lsquicPath = dirname(__DIR__, 2) . '/extension/lsquic/liblsquic.a';
if (!file_exists($lsquicPath)) {
    echo "skip lsquic library not installed";
}
?>
--FILE--
<?php
$script = dirname(__DIR__, 2) . '/infra/scripts/verify-lsquic.sh';
$output = [];
$status = 1;

exec('bash ' . escapeshellarg($script) . ' 2>&1', $output, $status);

var_dump($status === 0);
echo implode("\n", $output), "\n";
?>
--EXPECT--
bool(true)
=== King lsquic Verification Tests ===

Test 1: Check lsquic library...
-rw-r--r--  1 sasha  staff  6065576 15 Apr 21:46 /Users/sasha/king/extension/lsquic/liblsquic.a
  PASS
Test 2: Check boringssl libs...
-rw-r--r--  1 sasha  staff  9177512 15 Apr 21:46 /Users/sasha/king/extension/lsquic/libcrypto.a
-rw-r--r--  1 sasha  staff  12905128 15 Apr 21:46 /Users/sasha/king/extension/lsquic/libssl.a
  PASS
Test 3: Verify no lsquic refs in extension...
  PASS (0 refs)
Test 4: Verify no lsquic refs in infra scripts...
  PASS (0 refs)
Test 5: Check lsquic loader files...
/Users/sasha/king/extension/src/client/http3/lsquic_loader.inc
/Users/sasha/king/extension/src/server/http3/lsquic_loader.inc
  PASS
Test 6: Check build scripts updated...
  PASS
Test 7: Verify no lsquic/rust dirs...
  PASS

=== ALL TESTS PASSED ===

