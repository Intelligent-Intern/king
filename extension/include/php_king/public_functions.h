/*
 * include/php_king/public_functions.h - Core public PHP_FUNCTION prototypes
 */

#ifndef KING_PHP_KING_PUBLIC_FUNCTIONS_H
#define KING_PHP_KING_PUBLIC_FUNCTIONS_H

#include <php.h>

PHP_FUNCTION(king_connect);
PHP_FUNCTION(king_close);
PHP_FUNCTION(king_send_request);
PHP_FUNCTION(king_receive_response);
PHP_FUNCTION(king_poll);
PHP_FUNCTION(king_cancel_stream);
PHP_FUNCTION(king_export_session_ticket);
PHP_FUNCTION(king_import_session_ticket);
PHP_FUNCTION(king_set_ca_file);
PHP_FUNCTION(king_set_client_cert);
PHP_FUNCTION(king_get_last_error);
PHP_FUNCTION(king_get_stats);
PHP_FUNCTION(king_version);
PHP_FUNCTION(king_health);

#endif /* KING_PHP_KING_PUBLIC_FUNCTIONS_H */
