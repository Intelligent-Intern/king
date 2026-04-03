/* extension/include/server/early_hints.h */

#ifndef KING_SERVER_EARLY_HINTS_H
#define KING_SERVER_EARLY_HINTS_H

#include <php.h>

/**
 * @file extension/include/server/early_hints.h
 * @brief Server-side Early Hints normalization, session snapshot support, and
 *        HTTP/1 one-shot on-wire emission.
 */

/**
 * @brief Normalizes one Early Hints batch onto the active server session.
 *
 * The current runtime validates and records the last Early Hints batch on the
 * session snapshot for the addressed stream, and the HTTP/1 one-shot listener
 * can emit that normalized batch as a real on-wire `103 Early Hints`
 * response before the final status line for the same active stream.
 *
 * @param session_resource The current session.
 * @param stream_id The stream to use.
 * @param hints_array The header list to send.
 * @return TRUE on success, FALSE on failure.
 */
PHP_FUNCTION(king_server_send_early_hints);

#endif // KING_SERVER_EARLY_HINTS_H
