/*
 * include/php_king/error_state.h - Shared error buffer and runtime summary API
 */

#ifndef KING_PHP_KING_ERROR_STATE_H
#define KING_PHP_KING_ERROR_STATE_H

#include <php.h>

#ifndef KING_ERR_LEN
#  define KING_ERR_LEN 256
#endif

#if defined(ZTS) && (PHP_VERSION_ID < 80200)
#  include <TSRM.h>
   extern ZEND_TLS char king_last_error[KING_ERR_LEN];
#else
   extern char king_last_error[KING_ERR_LEN];
#endif

void king_set_error(const char *msg);
const char *king_get_error(void);
int king_system_require_admission(const char *function_name, const char *admission_name);
void king_add_runtime_surface(zval *target);
const char *king_get_active_runtime_summary(void);
const char *king_get_stubbed_api_summary(void);

#endif /* KING_PHP_KING_ERROR_STATE_H */
