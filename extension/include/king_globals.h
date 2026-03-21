/*
 * =========================================================================
 * FILENAME:   include/king_globals.h
 * PROJECT:    king
 *
 * PURPOSE:
 * Declares the small amount of process-wide state shared across the active
 * extension modules.
 * =========================================================================
 */

#ifndef KING_GLOBALS_H
#define KING_GLOBALS_H

#include <php.h>
#include <stdbool.h>

#ifndef KING_TICKET_RING_NAME_LEN
#  define KING_TICKET_RING_NAME_LEN 64
#endif

/* Defines the globally accessible state for the extension. */
typedef struct _king_globals_t {
    /*
     * Set during module initialization by the security config module.
     * This gate controls whether non-empty userland config overrides are
     * accepted by king_new_config().
     */
    bool is_userland_override_allowed;
    int ticket_ring_fd;
    size_t ticket_ring_size;
    void *ticket_ring;
    char ticket_ring_name[KING_TICKET_RING_NAME_LEN];
} king_globals_t;

/* The single global state instance is defined in src/king_globals.c. */
extern ZEND_API king_globals_t king_globals;

#endif /* KING_GLOBALS_H */
