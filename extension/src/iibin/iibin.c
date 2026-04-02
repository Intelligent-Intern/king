/*
 * IIBIN module bootstrap. Registers the final King\IIBIN facade class that
 * exposes the public static proto helpers to userland.
 */

#include "php.h"
#include "include/iibin/iibin_internal.h"

int king_iibin_minit(void)
{
    zend_class_entry ce;
    zend_class_entry *registered;

    INIT_NS_CLASS_ENTRY(ce, "King", "IIBIN", king_iibin_class_methods);
    registered = zend_register_internal_class(&ce);
    if (registered == NULL) {
        return FAILURE;
    }

    registered->ce_flags |= ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES;

    return SUCCESS;
}
