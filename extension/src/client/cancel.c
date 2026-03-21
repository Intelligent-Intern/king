/*
 * =========================================================================
 * FILENAME:   src/client/cancel.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Local stream-cancel runtime for the active skeleton client-session build.
 * This records cancel intents on a real King\Session resource even before the
 * transport backend is wired in.
 * =========================================================================
 */

#include "php.h"
#include "php_king.h"
#include "include/client/cancel.h"
#include "include/client/session.h"

#include <stdio.h>

static void king_cancel_stream_internal(
    INTERNAL_FUNCTION_PARAMETERS,
    const char *function_name)
{
    zend_long stream_id;
    char *how = "both";
    size_t how_len = sizeof("both") - 1;
    zval *zsession = NULL;
    king_client_session_t *session;
    char message[KING_ERR_LEN];

    ZEND_PARSE_PARAMETERS_START(1, 3)
        Z_PARAM_LONG(stream_id)
        Z_PARAM_OPTIONAL
        Z_PARAM_STRING(how, how_len)
        Z_PARAM_ZVAL(zsession)
    ZEND_PARSE_PARAMETERS_END();

    if (stream_id < 0) {
        snprintf(
            message,
            sizeof(message),
            "%s() stream_id must be >= 0.",
            function_name
        );
        king_set_error(message);
        RETURN_FALSE;
    }

    if (zsession == NULL || Z_TYPE_P(zsession) == IS_NULL) {
        snprintf(
            message,
            sizeof(message),
            "%s() requires a King\\Session resource in the skeleton build.",
            function_name
        );
        king_set_error(message);
        RETURN_FALSE;
    }

    session = king_client_session_fetch_resource(zsession, 3);
    if (session == NULL) {
        RETURN_THROWS();
    }

    if (king_client_session_mark_cancelled(
            session,
            stream_id,
            how,
            how_len,
            function_name
        ) != SUCCESS) {
        RETURN_FALSE;
    }

    king_set_error("");
    RETURN_TRUE;
}

PHP_FUNCTION(king_cancel_stream)
{
    king_cancel_stream_internal(
        INTERNAL_FUNCTION_PARAM_PASSTHRU,
        "king_cancel_stream"
    );
}

PHP_FUNCTION(king_client_stream_cancel)
{
    king_cancel_stream_internal(
        INTERNAL_FUNCTION_PARAM_PASSTHRU,
        "king_client_stream_cancel"
    );
}
