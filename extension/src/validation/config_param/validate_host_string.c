/*
 * Validation helper for host strings. Enforces string input and the bounded
 * host-literal checks used by the current transport and control-plane config.
 */

#include "include/validation/config_param/validate_host_string.h"
#include <zend_exceptions.h>
#include <ext/spl/spl_exceptions.h>
#include <ctype.h>

int kg_validate_host_string(zval *value, char **target)
{
    if (Z_TYPE_P(value) != IS_STRING) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid type provided for host. A string is required.");
        return FAILURE;
    }

    const char *host = Z_STRVAL_P(value);
    size_t len = Z_STRLEN_P(value);

    if (len == 0) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid value provided for host. A non-empty string is required.");
        return FAILURE;
    }

    for (size_t i = 0; i < len; i++) {
        /* Basic sanity check: allow alphanumeric, dots, dashes, and colons (for IPv6). */
        if (!isalnum(host[i]) && host[i] != '.' && host[i] != '-' && host[i] != ':') {
             zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid character detected in host string.");
             return FAILURE;
        }
    }

    /* Free the old string if it exists. */
    if (*target) {
        pefree(*target, 1);
    }
    *target = pestrdup(host, 1);

    return SUCCESS;
}
