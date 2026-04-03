/* extension/include/server/tls.h */

#ifndef KING_SERVER_TLS_H
#define KING_SERVER_TLS_H

#include <php.h>
#include "include/client/session.h"

/**
 * @file extension/include/server/tls.h
 * @brief Server-side TLS snapshot helpers.
 */

/**
 * @brief Reloads the local server TLS certificate and private-key snapshot.
 *
 * The current runtime validates readable certificate/key files, revalidates
 * optional ticket-key material, and records the resulting TLS snapshot on the
 * session, including during active on-wire request handling. It does not
 * hot-swap a live network listener backend.
 *
 * @param session_resource The open `King\Session` resource or object to update.
 * @param cert_file_path The PEM certificate chain path.
 * @param key_file_path The PEM private key path.
 * @return TRUE on success, FALSE on validation or closed-session failure.
 */
PHP_FUNCTION(king_server_reload_tls_config);

/**
 * @brief Internal shared TLS-reload helper for server-side control leaves.
 *
 * The active runtime uses one validation-and-apply path for direct TLS
 * reloads and admin-triggered reload requests so both surfaces keep the same
 * ticket-key checks, file validation, and session snapshot semantics.
 *
 * @param session The open server session to update.
 * @param cert_file_path The PEM certificate chain path.
 * @param cert_file_path_len Certificate path length.
 * @param key_file_path The PEM private-key path.
 * @param key_file_path_len Private-key path length.
 * @param function_name Error-message prefix for the calling leaf.
 * @return SUCCESS on success, FAILURE on validation failure.
 */
zend_result king_server_tls_reload_paths(
    king_client_session_t *session,
    const char *cert_file_path,
    size_t cert_file_path_len,
    const char *key_file_path,
    size_t key_file_path_len,
    const char *function_name
);

#endif // KING_SERVER_TLS_H
