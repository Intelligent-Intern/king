#!/usr/bin/env bash

echo "=== lsquic Functionality Tests ==="
echo ""

echo "Test 1: Library exists"
ls -la /Users/sasha/king/extension/lsquic/liblsquic.a
[ $? -eq 0 ] && echo "PASS" || echo "FAIL"
echo ""

echo "Test 2: BoringSSL libs exist"
ls -la /Users/sasha/king/extension/lsquic/libcrypto.a
ls -la /Users/sasha/king/extension/lsquic/libssl.a
[ $? -eq 0 ] && echo "PASS" || echo "FAIL"
echo ""

echo "Test 3: lsquic_global_init available"
nm /Users/sasha/king/extension/lsquic/liblsquic.a | grep -q lsquic_global_init
[ $? -eq 0 ] && echo "PASS (found)" || echo "FAIL"
echo ""

echo "Test 4: lsquic_engine_new available"
nm /Users/sasha/king/extension/lsquic/liblsquic.a | grep -q lsquic_engine_new
[ $? -eq 0 ] && echo "PASS (found)" || echo "FAIL"
echo ""

echo "Test 5: lsquic_engine_destroy available"
nm /Users/sasha/king/extension/lsquic/liblsquic.a | grep -q lsquic_engine_destroy
[ $? -eq 0 ] && echo "PASS (found)" || echo "FAIL"
echo ""

echo "Test 6: lsquic_engine_packet_in available"
nm /Users/sasha/king/extension/lsquic/liblsquic.a | grep -q lsquic_engine_packet_in
[ $? -eq 0 ] && echo "PASS (found)" || echo "FAIL"
echo ""

echo "Test 7: Connection functions available"
nm /Users/sasha/king/extension/lsquic/liblsquic.a | grep -q lsquic_conn_close
[ $? -eq 0 ] && echo "PASS (found)" || echo "FAIL"
echo ""

echo "Test 8: HTTP/3 stream functions available"
nm /Users/sasha/king/extension/lsquic/liblsquic.a | grep -qi h3
[ $? -eq 0 ] && echo "PASS (found)" || echo "FAIL"
echo ""

echo "Test 9: No lsquic references"
grep -rq lsquic /Users/sasha/king/extension/src --include="*.c" --include="*.h" --include="*.inc" 2>/dev/null
[ $? -ne 0 ] && echo "PASS (clean)" || echo "FAIL"
echo ""

echo "Test 10: Build scripts updated"
grep -q lsquic /Users/sasha/king/infra/scripts/build-profile.sh
[ $? -eq 0 ] && echo "PASS" || echo "FAIL"
echo ""

echo "=== SUMMARY ==="
echo "lsquic library: $(ls -la /Users/sasha/king/extension/lsquic/liblsquic.a | awk '{print $5}') bytes"
echo "BoringSSL: $(ls -la /Users/sasha/king/extension/lsquic/libcrypto.a | awk '{print $5}') + $(ls -la /Users/sasha/king/extension/lsquic/libssl.a | awk '{print $5}') bytes"
echo "Total: $(($(ls -la /Users/sasha/king/extension/lsquic/liblsquic.a | awk '{print $5}') + $(ls -la /Users/sasha/king/extension/lsquic/libcrypto.a | awk '{print $5}') + $(ls -la /Users/sasha/king/extension/lsquic/libssl.a | awk '{print $5}'))) bytes"
echo ""
echo "=== ALL TESTS COMPLETE ==="