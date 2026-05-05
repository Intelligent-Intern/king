--TEST--
King ticket-ring random fill uses a platform selector instead of unconditional getrandom
--FILE--
<?php
$root = dirname(__DIR__);
$support = (string) file_get_contents($root . '/src/king_init/ticket_ring/support.inc');

var_dump(str_contains($support, 'KING_TICKET_RING_RANDOM_FILL_ARC4RANDOM'));
var_dump(str_contains($support, 'KING_TICKET_RING_RANDOM_FILL_GETRANDOM'));
var_dump(str_contains($support, 'KING_TICKET_RING_RANDOM_FILL_DEV_URANDOM'));
var_dump(str_contains($support, '#if defined(__APPLE__) || defined(__FreeBSD__) || defined(__OpenBSD__) || defined(__NetBSD__)'));
var_dump(str_contains($support, '#elif defined(__linux__)'));
var_dump(str_contains($support, 'arc4random_buf(buffer, len);'));
var_dump(str_contains($support, 'getrandom(cursor + offset, len - offset, 0);'));
var_dump(str_contains($support, 'open("/dev/urandom", O_RDONLY);'));
var_dump(str_contains($support, '#elif defined(KING_TICKET_RING_RANDOM_FILL_GETRANDOM)'));
var_dump(!str_contains($support, '#else' . PHP_EOL . '    unsigned char *cursor = (unsigned char *) buffer;' . PHP_EOL . '    size_t offset = 0;' . PHP_EOL . PHP_EOL . '    while (offset < len) {' . PHP_EOL . '        ssize_t written = getrandom('));
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
