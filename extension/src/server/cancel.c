/*
 * =========================================================================
 * FILENAME:   src/server/cancel.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Activates a first local server-side cancel-handler runtime over the shared
 * King\Session runtime. Handlers are registered per stream and are invoked
 * once when the same local session records a stream cancellation.
 * =========================================================================
 */

#include "php.h"
#include "php_king.h"
#include "include/server/cancel.h"

#include <stdarg.h>
#include <time.h>

#include "control.inc"

zend_result king_server_cancel_invoke_if_registered(
    king_client_session_t *session,
    zend_long stream_id
)
{
    zval *stored_handler;
    zval handler;
    zval retval;
    zval params[1];
    zend_fcall_info fci;
    zend_fcall_info_cache fcc;

    if (session == NULL || !session->server_cancel_handlers_initialized) {
        return SUCCESS;
    }

    stored_handler = zend_hash_index_find(
        &session->server_cancel_handlers,
        (zend_ulong) stream_id
    );
    if (stored_handler == NULL) {
        return SUCCESS;
    }

    ZVAL_COPY(&handler, stored_handler);
    zend_hash_index_del(&session->server_cancel_handlers, (zend_ulong) stream_id);

    if (zend_fcall_info_init(&handler, 0, &fci, &fcc, NULL, NULL) != SUCCESS) {
        zval_ptr_dtor(&handler);
        king_server_control_set_errorf(
            "Failed to invoke the registered server cancel handler for stream " ZEND_LONG_FMT ".",
            stream_id
        );
        return FAILURE;
    }

    ZVAL_LONG(&params[0], stream_id);
    ZVAL_UNDEF(&retval);

    fci.retval = &retval;
    fci.param_count = 1;
    fci.params = params;

    session->server_cancel_handler_invocations++;
    session->server_last_cancel_invoked_stream_id = stream_id;
    session->last_activity_at = time(NULL);

    if (zend_call_function(&fci, &fcc) != SUCCESS) {
        zval_ptr_dtor(&handler);

        if (!Z_ISUNDEF(retval)) {
            zval_ptr_dtor(&retval);
        }

        if (EG(exception) == NULL) {
            king_server_control_set_errorf(
                "Failed to invoke the registered server cancel handler for stream " ZEND_LONG_FMT ".",
                stream_id
            );
        }

        return FAILURE;
    }

    zval_ptr_dtor(&handler);
    if (!Z_ISUNDEF(retval)) {
        zval_ptr_dtor(&retval);
    }

    return SUCCESS;
}

PHP_FUNCTION(king_server_on_cancel)
{
    zval *zsession;
    zend_long stream_id;
    zval *handler;
    king_client_session_t *session;
    zval validated_handler;
    zend_fcall_info fci;
    zend_fcall_info_cache fcc;

    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_ZVAL(zsession)
        Z_PARAM_LONG(stream_id)
        Z_PARAM_ZVAL(handler)
    ZEND_PARSE_PARAMETERS_END();

    session = king_server_control_fetch_open_session(
        zsession,
        1,
        "king_server_on_cancel"
    );
    if (session == NULL) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    if (
        king_server_control_validate_stream_id(
            session,
            stream_id,
            "king_server_on_cancel"
        ) != SUCCESS
    ) {
        RETURN_FALSE;
    }

    if (zend_fcall_info_init(handler, 0, &fci, &fcc, NULL, NULL) != SUCCESS) {
        zend_argument_type_error(3, "must be a valid callback");
        RETURN_THROWS();
    }

    ZVAL_COPY(&validated_handler, handler);
    if (zend_hash_index_update(
            &session->server_cancel_handlers,
            (zend_ulong) stream_id,
            &validated_handler
        ) == NULL) {
        zval_ptr_dtor(&validated_handler);
        king_server_control_set_errorf(
            "king_server_on_cancel() failed to register the cancel handler for stream " ZEND_LONG_FMT ".",
            stream_id
        );
        RETURN_FALSE;
    }

    session->server_cancel_handler_count++;
    session->server_last_cancel_handler_stream_id = stream_id;
    session->last_activity_at = time(NULL);

    king_set_error("");
    RETURN_TRUE;
}
