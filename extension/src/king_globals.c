/*
 * =========================================================================
 * FILENAME:   src/king_globals.c
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Defines the single global state instance for the king extension.
 * This file must be compiled exactly once. Including king_globals.h
 * anywhere else only provides an extern declaration.
 * =========================================================================
 */

#include "include/king_globals.h"

/*
 * The single authoritative instance of the extension's global state.
 * Conservative defaults: userland config overrides are forbidden until
 * an administrator explicitly enables them via php.ini.
 */
king_globals_t king_globals = {
    .is_userland_override_allowed = false,
    .ticket_ring_fd = -1,
    .ticket_ring_size = 0,
    .ticket_ring = NULL,
    .ticket_ring_name = {0},
};
