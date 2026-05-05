--TEST--
King native compiler-warning cleanup keeps macOS thread IDs and zend_long formatting portable
--FILE--
<?php
$root = dirname(__DIR__, 2);

$sessionHeader = (string) file_get_contents($root . '/extension/include/server/session.h');
$appleBranch = strpos($sessionHeader, '#if defined(__APPLE__)');
$syscallBranch = strpos($sessionHeader, '#elif defined(SYS_gettid)');
var_dump($appleBranch !== false);
var_dump($syscallBranch !== false);
var_dump($appleBranch < $syscallBranch);
var_dump(str_contains($sessionHeader, 'pthread_threadid_np(NULL, &tid64);'));
var_dump(str_contains($sessionHeader, 'return (uint64_t)syscall(SYS_gettid);'));

$formatViolations = [];
$sourceRoot = $root . '/extension/src';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot)) as $file) {
    if (!$file->isFile() || !in_array($file->getExtension(), ['c', 'h', 'inc'], true)) {
        continue;
    }

    $source = (string) file_get_contents($file->getPathname());
    if (str_contains($source, '%ld')) {
        $formatViolations[] = substr($file->getPathname(), strlen($root) + 1);
    }
}
if ($formatViolations !== []) {
    echo implode("\n", $formatViolations), "\n";
}
var_dump($formatViolations === []);

$rtpSource = (string) file_get_contents($root . '/extension/src/media/rtp.c');
var_dump(str_contains($rtpSource, 'OPENSSL_VERSION_MAJOR'));
var_dump(str_contains($rtpSource, 'EVP_RSA_gen(2048)'));
var_dump(str_contains($rtpSource, 'RSA_generate_key_ex'));
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
