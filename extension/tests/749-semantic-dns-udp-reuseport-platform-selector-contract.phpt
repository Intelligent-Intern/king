--TEST--
King Smart-DNS UDP listener uses SO_REUSEPORT only on Darwin/BSD selector platforms
--FILE--
<?php
$root = dirname(__DIR__, 2);
$source = (string) file_get_contents($root . '/extension/src/semantic_dns/semantic_dns/wire_listener.inc');

foreach ([
    'SO_REUSEADDR',
    '#if defined(__APPLE__)',
    'defined(__FreeBSD__)',
    'defined(__OpenBSD__)',
    'defined(__NetBSD__)',
    'defined(__DragonFly__)',
    'SO_REUSEPORT',
] as $needle) {
    if (!str_contains($source, $needle)) {
        throw new RuntimeException('Missing Smart-DNS UDP listener selector needle: ' . $needle);
    }
}

if (!preg_match('/SO_REUSEADDR.*#if defined\(__APPLE__\).*SO_REUSEPORT.*#endif/s', $source)) {
    throw new RuntimeException('Smart-DNS UDP listener must keep SO_REUSEPORT behind the Darwin/BSD selector.');
}

echo "Smart-DNS UDP listener preserves Linux SO_REUSEADDR and adds Darwin/BSD SO_REUSEPORT.\n";
?>
--EXPECT--
Smart-DNS UDP listener preserves Linux SO_REUSEADDR and adds Darwin/BSD SO_REUSEPORT.
