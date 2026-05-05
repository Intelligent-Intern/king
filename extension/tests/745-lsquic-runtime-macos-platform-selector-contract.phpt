--TEST--
King LSQUIC runtime tooling preserves Linux behavior while selecting macOS dylib paths and flags
--FILE--
<?php
$runtimeScript = file_get_contents(dirname(__DIR__, 2) . '/infra/scripts/build-lsquic-runtime.sh');
$profileScript = file_get_contents(dirname(__DIR__, 2) . '/infra/scripts/build-profile.sh');
$clientLoader = file_get_contents(__DIR__ . '/../src/client/http3/lsquic_loader.inc');
$serverLoader = file_get_contents(__DIR__ . '/../src/server/http3/lsquic_loader.inc');

var_dump(str_contains($runtimeScript, 'default_jobs()'));
var_dump(str_contains($runtimeScript, 'sysctl -n hw.ncpu'));
var_dump(str_contains($runtimeScript, 'runtime_library_name()'));
var_dump(str_contains($runtimeScript, 'liblsquic.dylib'));
var_dump(str_contains($runtimeScript, '-dynamiclib'));
var_dump(str_contains($runtimeScript, '-Wl,-all_load'));
var_dump(str_contains($runtimeScript, '-Wl,--whole-archive'));
var_dump(str_contains($runtimeScript, '-lstdc++'));
var_dump(str_contains($runtimeScript, '-lc++'));
var_dump(str_contains($runtimeScript, 'nm -gU'));
var_dump(str_contains($runtimeScript, 'nm -D'));

var_dump(str_contains($profileScript, 'lsquic_runtime_library_name()'));
var_dump(str_contains($profileScript, 'boringssl_static_link_libs()'));
var_dump(str_contains($profileScript, '-Wl,--exclude-libs,ALL'));
var_dump(str_contains($profileScript, '-lc++'));
var_dump(str_contains($profileScript, '$(lsquic_runtime_library_name)'));

foreach ([$clientLoader, $serverLoader] as $loader) {
    var_dump(str_contains($loader, '#if defined(__APPLE__)'));
    var_dump(str_contains($loader, 'king/runtime/liblsquic.dylib'));
    var_dump(str_contains($loader, 'king/runtime/liblsquic.so'));
    var_dump(str_contains($loader, 'liblsquic.dylib'));
    var_dump(str_contains($loader, 'liblsquic.so'));
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
