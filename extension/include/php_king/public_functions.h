/*
 * include/php_king/public_functions.h - Core public PHP_FUNCTION prototypes
 */

#ifndef KING_PHP_KING_PUBLIC_FUNCTIONS_H
#define KING_PHP_KING_PUBLIC_FUNCTIONS_H

#include <php.h>

PHP_FUNCTION(king_get_last_error);
PHP_FUNCTION(king_version);
PHP_FUNCTION(king_health);

#endif /* KING_PHP_KING_PUBLIC_FUNCTIONS_H */
