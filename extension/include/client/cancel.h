#ifndef KING_CLIENT_CANCEL_H
#define KING_CLIENT_CANCEL_H

#include <php.h>

/**
 * @file extension/include/client/cancel.h
 * @brief Client-side request stream cancellation.
 */

/**
 * @brief Cancels an active request stream.
 *
 * `how_str` accepts `read`, `write`, or `both`.
 *
 * @param stream_id The stream to cancel.
 * @param how_str Cancellation mode.
 * @param how_len The length of `how_str`.
 * @return TRUE on success, FALSE on failure.
 */
PHP_FUNCTION(king_client_stream_cancel);

#endif // KING_CLIENT_CANCEL_H
