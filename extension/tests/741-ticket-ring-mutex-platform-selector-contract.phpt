--TEST--
King ticket-ring mutex attributes use a platform selector for Darwin/BSD startup
--FILE--
<?php
$root = dirname(__DIR__);
$lifecycle = (string) file_get_contents($root . '/src/king_init/ticket_ring/lifecycle.inc');

var_dump(str_contains($lifecycle, 'KING_TICKET_RING_MUTEX_PSHARED_FALLBACK_PRIVATE'));
var_dump(str_contains($lifecycle, 'king_ticket_ring_configure_mutexattr'));
var_dump(str_contains($lifecycle, 'pthread_mutexattr_setpshared(attr, PTHREAD_PROCESS_SHARED) == 0'));
var_dump(str_contains($lifecycle, 'pthread_mutexattr_setpshared(attr, PTHREAD_PROCESS_PRIVATE) == 0'));
var_dump(str_contains($lifecycle, '#if defined(KING_TICKET_RING_MUTEX_PSHARED_FALLBACK_PRIVATE)'));
var_dump(str_contains($lifecycle, 'if (king_ticket_ring_configure_mutexattr(&attr) != SUCCESS)'));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
