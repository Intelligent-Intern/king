#ifndef KING_SERVER_ADMIN_API_H
#define KING_SERVER_ADMIN_API_H

#include <php.h>

/**
 * @file extension/include/server/admin_api.h
 * @brief Admin API entry point for live server reconfiguration.
 */

/**
 * @brief Starts the local skeleton admin listener slice for a running server session.
 *
 * The active skeleton build accepts an open `King\Session` resource or object
 * plus either `null`, an inline array, or a `King\Config` snapshot. The
 * function validates the local admin bind/auth/TLS snapshot and records the
 * resulting listener state on that session.
 *
 * @param target_server_resource The local server session to update.
 * @param config_resource Admin listener configuration (`null`, array, or `King\Config`).
 * @return TRUE on success, FALSE on failure.
 */
PHP_FUNCTION(king_admin_api_listen);

#endif // KING_SERVER_ADMIN_API_H
