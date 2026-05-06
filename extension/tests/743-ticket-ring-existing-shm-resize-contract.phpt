--TEST--
King ticket-ring startup validates existing shm size before mmap
--FILE--
<?php
$root = dirname(__DIR__);
$lifecycle = (string) file_get_contents($root . '/src/king_init/ticket_ring/lifecycle.inc');

var_dump(str_contains($lifecycle, 'struct stat statbuf;'));
var_dump(str_contains($lifecycle, 'fstat(fd, &statbuf)'));
var_dump(str_contains($lifecycle, 'statbuf.st_size < (off_t) sizeof(king_ticket_ring_shared_t)'));
var_dump(str_contains($lifecycle, 'needs_resize = true;'));
var_dump(str_contains($lifecycle, 'if (needs_resize && ftruncate(fd, sizeof(king_ticket_ring_shared_t)) != 0)'));
var_dump(str_contains($lifecycle, 'king_ticket_ring_init fstat failed'));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
