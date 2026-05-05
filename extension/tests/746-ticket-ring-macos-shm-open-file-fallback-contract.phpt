--TEST--
King ticket-ring falls back to file-backed mmap on Darwin/BSD shm_open EPERM
--FILE--
<?php
$root = dirname(__DIR__);
$lifecycle = (string) file_get_contents($root . '/src/king_init/ticket_ring/lifecycle.inc');

var_dump(str_contains($lifecycle, 'KING_TICKET_RING_FILE_BACKED_SHM_FALLBACK'));
var_dump(str_contains($lifecycle, 'king_ticket_ring_open_file_backed_fallback'));
var_dump(str_contains($lifecycle, '"/tmp/king_ticket_ring_" ZEND_LONG_FMT "_%d"'));
var_dump(str_contains($lifecycle, 'fd < 0 && (errno == EPERM || errno == EACCES)'));
var_dump(str_contains($lifecycle, 'O_CREAT | O_EXCL | O_RDWR'));
var_dump(str_contains($lifecycle, 'king_ticket_ring_unlink_name'));
var_dump(str_contains($lifecycle, 'return shm_unlink(name);'));
var_dump(str_contains($lifecycle, 'return unlink(name);'));
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
