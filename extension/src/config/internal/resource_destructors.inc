/*
 * Config-owned Zend resource destructor.
 */

#include "config/config.h"

static void king_config_resource_dtor(zend_resource *rsrc)
{
    if (rsrc->ptr) {
        king_config_free((king_cfg_t *) rsrc->ptr);
    }
}
