#ifndef KING_SERVER_CANCEL_H
#define KING_SERVER_CANCEL_H

#include <php.h>

/**
 * @file extension/include/server/cancel.h
 * @brief Server-side request cancellation hooks.
 */

/**
 * @brief Registers a callback that runs if a request stream is cancelled.
 *
 * @param session_resource The session that owns the stream.
 * @param stream_id The stream identifier.
 * @param cancel_handler_callable The PHP callback to invoke.
 * @return TRUE on success, FALSE on failure.
 */
PHP_FUNCTION(king_server_on_cancel);

#endif // KING_SERVER_CANCEL_H
