--TEST--
King libcurl runtime loaders use OS-specific dynamic library selectors
--FILE--
<?php
$header = file_get_contents(__DIR__ . '/../include/runtime/libcurl_candidates.h');
$http2 = file_get_contents(__DIR__ . '/../src/client/http2/libcurl.inc');
$telemetry = file_get_contents(__DIR__ . '/../src/telemetry/telemetry/runtime_and_libcurl.inc');
$autoscaling = file_get_contents(__DIR__ . '/../src/autoscaling/provisioning/curl_loader.inc');
$objectStore = file_get_contents(__DIR__ . '/../src/object_store/internal/cloud_s3_runtime/libcurl_loader.inc');

var_dump(str_contains($header, '#if defined(__APPLE__)'));
var_dump(str_contains($header, '#elif defined(__linux__)'));
var_dump(str_contains($header, '/opt/homebrew/opt/curl/lib/libcurl.4.dylib'));
var_dump(str_contains($header, '/usr/local/opt/curl/lib/libcurl.4.dylib'));
var_dump(str_contains($header, '"libcurl.so.4"'));
var_dump(str_contains($header, '"libcurl.so"'));
var_dump(str_contains($header, 'KING_LIBCURL_RUNTIME_CANDIDATE_NAMES'));

foreach ([$http2, $telemetry, $autoscaling, $objectStore] as $source) {
    var_dump(str_contains($source, 'const char *const candidates[] = {KING_LIBCURL_RUNTIME_CANDIDATES};'));
    var_dump(str_contains($source, 'KING_LIBCURL_RUNTIME_CANDIDATE_NAMES'));
}
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
