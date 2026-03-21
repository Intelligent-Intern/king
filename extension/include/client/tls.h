#ifndef KING_CLIENT_TLS_H
#define KING_CLIENT_TLS_H

#include <php.h>

/**
 * @file extension/include/client/tls.h
 * @brief Client-side TLS helpers.
 */

/**
 * @brief Sets the default CA file for client certificate verification.
 *
 * @param path_str The CA certificate bundle path.
 * @param path_len The length of `path_str`.
 * @return TRUE on success, FALSE on failure.
 */
PHP_FUNCTION(king_client_tls_set_ca_file);

/**
 * @brief Sets the default client certificate and key files for mTLS.
 *
 * @param cert_str The client certificate path.
 * @param cert_len The length of `cert_str`.
 * @param key_str The client private key path.
 * @param key_len The length of `key_str`.
 * @return TRUE on success, FALSE on failure.
 */
PHP_FUNCTION(king_client_tls_set_client_cert);

/**
 * @brief Exports the current TLS session ticket from a client session.
 *
 * @param session_resource The client session resource.
 * @return The raw ticket string on success, or an empty string if none exists.
 */
PHP_FUNCTION(king_client_tls_export_session_ticket);

/**
 * @brief Imports a previously exported TLS session ticket.
 *
 * @param session_resource The client session resource.
 * @param ticket_blob_str The raw ticket string.
 * @param ticket_blob_len The length of `ticket_blob_str`.
 * @return TRUE on success, FALSE on failure.
 */
PHP_FUNCTION(king_client_tls_import_session_ticket);

#endif // KING_CLIENT_TLS_H
