/*
 * include/config/resource_helpers.h - Config resource access helpers
 */

#ifndef KING_CONFIG_RESOURCE_HELPERS_H
#define KING_CONFIG_RESOURCE_HELPERS_H

#include <php.h>

typedef struct king_cfg_s king_cfg_t;

king_cfg_t *king_fetch_config(zval *zcfg);

#endif /* KING_CONFIG_RESOURCE_HELPERS_H */
