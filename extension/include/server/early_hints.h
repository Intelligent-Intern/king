/* extension/include/server/early_hints.h */

#ifndef KING_SERVER_EARLY_HINTS_H
#define KING_SERVER_EARLY_HINTS_H

#include <php.h>

/**
 * @file extension/include/server/early_hints.h
 * @brief Server-side HTTP 103 Early Hints support.
 */

/**
 * @brief Queues a 103 Early Hints response.
 *
 * @param session_resource The current session.
 * @param stream_id The stream to use.
 * @param hints_array The header list to send.
 * @return TRUE on success, FALSE on failure.
 */
PHP_FUNCTION(king_server_send_early_hints);

#endif // KING_SERVER_EARLY_HINTS_H
