#ifndef KING_SERVER_ADMIN_API_H
#define KING_SERVER_ADMIN_API_H

#include <php.h>

/**
 * @file extension/include/server/admin_api.h
 * @brief Admin-listener control leaf for server sessions.
 */

/**
 * @brief Materializes the admin-listener snapshot and optional one-shot runtime.
 *
 * The active runtime accepts an open `King\Session` resource or object
 * plus either `null`, an inline array, or a `King\Config` snapshot. The
 * function validates the local admin bind/auth/TLS snapshot and records the
 * resulting listener state on that session. When callers pass an inline
 * `accept_timeout_ms > 0`, the same leaf also serves one bounded real
 * TCP/TLS+mTLS admin request before returning, so auth and reload behavior
 * can be exercised against a live client without introducing a long-lived
 * background listener.
 *
 * @param target_server_resource The local server session to update.
 * @param config_resource Admin listener configuration (`null`, array, or `King\Config`).
 * @return TRUE on success, FALSE on failure.
 */
PHP_FUNCTION(king_admin_api_listen);

#endif // KING_SERVER_ADMIN_API_H
