#!/usr/bin/env bash

set -euo pipefail

echo "=== King lsquic Verification Tests ==="
echo ""

echo "Test 1: Check lsquic library..."
ls -la /Users/sasha/king/extension/lsquic/liblsquic.a
echo "  PASS"

echo "Test 2: Check boringssl libs..."
ls -la /Users/sasha/king/extension/lsquic/libcrypto.a
ls -la /Users/sasha/king/extension/lsquic/libssl.a
echo "  PASS"

echo "Test 3: Verify no lsquic refs in extension..."
count=$(grep -ri "lsquic" /Users/sasha/king/extension/src --include="*.c" --include="*.h" --include="*.inc" 2>/dev/null | wc -l)
[[ "$count" -eq 0 ]]
echo "  PASS (0 refs)"

echo "Test 4: Verify no lsquic refs in infra scripts..."
count=$(grep -ri "lsquic" /Users/sasha/king/infra/scripts --include="*.sh" 2>/dev/null | wc -l)
[[ "$count" -eq 0 ]]
echo "  PASS (0 refs)"

echo "Test 5: Check lsquic loader files..."
ls /Users/sasha/king/extension/src/client/http3/lsquic_loader.inc
ls /Users/sasha/king/extension/src/server/http3/lsquic_loader.inc
echo "  PASS"

echo "Test 6: Check build scripts updated..."
grep -q "lsquic" /Users/sasha/king/infra/scripts/build-profile.sh
echo "  PASS"

echo "Test 7: Verify no lsquic/rust dirs..."
! ls /Users/sasha/king | grep -qi "lsquic"
! ls /Users/sasha/king | grep -qi "rust"
! ls /Users/sasha/king | grep -qi "cargo"
echo "  PASS"

echo ""
echo "=== ALL TESTS PASSED ==="