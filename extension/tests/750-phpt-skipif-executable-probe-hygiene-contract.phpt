--TEST--
King PHPT SKIPIF executable probes resolve binaries before proc_open
--FILE--
<?php
$root = dirname(__DIR__, 2);
$testsDir = $root . '/extension/tests';
$violations = [];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testsDir)) as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'phpt') {
        continue;
    }

    $path = $file->getPathname();
    $contents = (string) file_get_contents($path);
    if (!preg_match('/--SKIPIF--\s*(.*?)\s*--FILE--/s', $contents, $match)) {
        continue;
    }

    $skipif = $match[1];
    if (preg_match('/proc_open\s*\(\s*\[\s*[\'"]python3[\'"]/s', $skipif)) {
        $violations[] = substr($path, strlen($root) + 1) . ': unresolved array-form python3 proc_open in SKIPIF';
    }
    if (preg_match('/proc_open\s*\(\s*[\'"]python3\s/s', $skipif)) {
        $violations[] = substr($path, strlen($root) + 1) . ': unresolved shell-form python3 proc_open in SKIPIF';
    }
}

if ($violations !== []) {
    echo implode("\n", $violations), "\n";
}
var_dump($violations === []);
?>
--EXPECT--
bool(true)
