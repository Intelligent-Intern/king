/* extension/include/server/tls.h */

#ifndef KING_SERVER_TLS_H
#define KING_SERVER_TLS_H

#include <php.h>

/**
 * @file extension/include/server/tls.h
 * @brief Server-side TLS snapshot helpers.
 */

/**
 * @brief Reloads the local server TLS certificate and private-key snapshot.
 *
 * The current runtime validates readable certificate/key files, revalidates
 * optional ticket-key material, and records the resulting TLS snapshot on the
 * session. It does not hot-swap a live network listener backend.
 *
 * @param session_resource The open `King\Session` resource or object to update.
 * @param cert_file_path The PEM certificate chain path.
 * @param key_file_path The PEM private key path.
 * @return TRUE on success, FALSE on validation or closed-session failure.
 */
PHP_FUNCTION(king_server_reload_tls_config);

#endif // KING_SERVER_TLS_H
