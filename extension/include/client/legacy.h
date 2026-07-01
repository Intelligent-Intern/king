/*
 * include/client/legacy.h - Unprefixed client compatibility entry points
 * ======================================================================
 *
 * These declarations keep the original procedural client surface anchored in
 * the client subsystem while newer APIs use the explicit king_client_* names.
 */

#ifndef KING_CLIENT_LEGACY_H
#define KING_CLIENT_LEGACY_H

#include <php.h>

PHP_FUNCTION(king_connect);
PHP_FUNCTION(king_close);
PHP_FUNCTION(king_send_request);
PHP_FUNCTION(king_send_request_async);
PHP_FUNCTION(king_receive_response);
PHP_FUNCTION(king_poll);
PHP_FUNCTION(king_cancel_stream);
PHP_FUNCTION(king_export_session_ticket);
PHP_FUNCTION(king_import_session_ticket);
PHP_FUNCTION(king_set_ca_file);
PHP_FUNCTION(king_set_client_cert);
PHP_FUNCTION(king_get_stats);

#endif /* KING_CLIENT_LEGACY_H */
