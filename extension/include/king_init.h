/*
 * =========================================================================
 * FILENAME:   include/king_init.h
 * PROJECT:    king
 *
 * PURPOSE:
 * Declares the small lifecycle API shared between php_king.c and the
 * central module dispatcher in src/king_init.c.
 * =========================================================================
 */

#ifndef KING_INIT_H
#define KING_INIT_H

#include <php.h>

extern int king_ini_module_number;

/**
 * Registers all config modules and captures the module number used for their
 * INI entry ownership.
 *
 * @param type The type of initialization (e.g., MODULE_PERSISTENT).
 * @param module_number The unique number assigned to this extension by Zend.
 * @return `SUCCESS` on successful registration, `FAILURE` otherwise.
 */
int king_init_modules(int type, int module_number);

/**
 * Unregisters all config modules in reverse order.
 *
 * @param type The type of shutdown (e.g., MODULE_PERSISTENT).
 * @param module_number The module number.
 * @return `SUCCESS` on successful shutdown, `FAILURE` otherwise.
 */
int king_shutdown_modules(int type, int module_number);

/**
 * Request-init hook used by PHP_RINIT_FUNCTION.
 */
int king_request_init(int type, int module_number);

/**
 * Request-shutdown hook used by PHP_RSHUTDOWN_FUNCTION.
 */
int king_request_shutdown(int type, int module_number);

#endif /* KING_INIT_H */
