--TEST--
King ticket-ring shm fd locking tolerates unsupported flock only on selected platforms
--FILE--
<?php
$root = dirname(__DIR__);
$lifecycle = (string) file_get_contents($root . '/src/king_init/ticket_ring/lifecycle.inc');

var_dump(str_contains($lifecycle, 'KING_TICKET_RING_FLOCK_ENOTSUP_CONTINUE'));
var_dump(str_contains($lifecycle, 'king_ticket_ring_lock_fd'));
var_dump(str_contains($lifecycle, 'king_ticket_ring_unlock_fd'));
var_dump(str_contains($lifecycle, 'flock(fd, LOCK_EX) == 0'));
var_dump(str_contains($lifecycle, 'errno == ENOTSUP || errno == EOPNOTSUPP'));
var_dump(str_contains($lifecycle, 'king_ticket_ring_lock_fd(fd, &fd_locked)'));
var_dump(str_contains($lifecycle, 'king_ticket_ring_unlock_fd(fd, fd_locked)'));
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
