/*
 * Validation helper for readable filesystem paths. Enforces string input and
 * the current `VCWD_ACCESS(..., R_OK)` readability check used by config paths.
 */

#include "include/validation/config_param/validate_readable_file_path.h"
#include "main/php_streams.h"
#include <zend_exceptions.h>
#include <ext/spl/spl_exceptions.h>

int kg_validate_readable_file_path(zval *value, char **target)
{
    if (Z_TYPE_P(value) != IS_STRING) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Invalid type provided for file path. A string is required.");
        return FAILURE;
    }

    char *path = Z_STRVAL_P(value);
    if (strlen(path) == 0) {
        /* An empty path can be a valid "not set" value. */
        if (*target) { pefree(*target, 1); }
        *target = pestrdup(path, 1);
        return SUCCESS;
    }

    /* Use PHP's cross-platform file access check. R_OK checks for read permission. */
    if (VCWD_ACCESS(path, R_OK) != 0) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0, "Provided file path is not accessible or does not exist.");
        return FAILURE;
    }

    /* Free the old string if it exists. */
    if (*target) {
        pefree(*target, 1);
    }
    *target = pestrdup(path, 1);

    return SUCCESS;
}
