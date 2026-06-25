/*
 * include/php_king/resource_helpers.h - Shared resource access helpers
 */

#ifndef KING_PHP_KING_RESOURCE_HELPERS_H
#define KING_PHP_KING_RESOURCE_HELPERS_H

#include <php.h>
#include <stdint.h>

#include "client/objects.h"

extern void *king_fetch_config(zval *zcfg);
extern void king_ticket_ring_put(const uint8_t *ticket, size_t len);
extern int king_ticket_ring_get(uint8_t *out, size_t *out_len);
extern void king_client_session_free(void *session_ptr);

static inline void *king_obj_fetch(zval *zobj)
{
    if (Z_TYPE_P(zobj) != IS_OBJECT) return NULL;
    king_session_object *intern = php_king_obj_from_zend(Z_OBJ_P(zobj));
    if (Z_ISUNDEF(intern->resource) || Z_TYPE(intern->resource) != IS_RESOURCE) {
        return NULL;
    }

    return Z_RES(intern->resource)->ptr;
}

#endif /* KING_PHP_KING_RESOURCE_HELPERS_H */
