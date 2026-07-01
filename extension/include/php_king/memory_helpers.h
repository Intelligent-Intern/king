/*
 * include/php_king/memory_helpers.h - Shared memory utility helpers
 */

#ifndef KING_PHP_KING_MEMORY_HELPERS_H
#define KING_PHP_KING_MEMORY_HELPERS_H

#include <stddef.h>

static inline void king_secure_zero(void *v, size_t n)
{
    volatile unsigned char *p = (volatile unsigned char *) v;
    while (n--) *p++ = 0;
}

#endif /* KING_PHP_KING_MEMORY_HELPERS_H */
