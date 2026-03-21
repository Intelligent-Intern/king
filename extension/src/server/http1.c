/*
 * =========================================================================
 * FILENAME:   src/server/http1.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Activates a first local HTTP/1 listener leaf for the skeleton build. This
 * is intentionally a single-dispatch in-memory contract: it validates the
 * listener inputs, materializes a local King\Session snapshot, invokes the
 * handler once with a normalized HTTP/1-style request array, and validates
 * the returned response shape.
 * =========================================================================
 */

#include "php.h"
#include "php_king.h"
#include "include/client/session.h"
#include "include/config/config.h"
#include "include/server/http1.h"
#include "include/server/session.h"

#include <stdarg.h>
#include <time.h>

#include "local_listener.inc"

static void king_server_http1_build_request(
    zval *request,
    king_client_session_t *session,
    const char *host,
    size_t host_len,
    zend_long port
)
{
    zval headers;
    zend_string *authority;

    authority = strpprintf(0, "%.*s:%ld", (int) host_len, host, port);

    array_init(request);
    add_assoc_string(request, "method", "GET");
    add_assoc_string(request, "uri", "/");
    add_assoc_string(request, "version", "HTTP/1.1");
    add_assoc_str(request, "host", zend_string_copy(authority));

    array_init(&headers);
    add_assoc_str(&headers, "Host", authority);
    add_assoc_string(&headers, "Connection", "close");
    add_assoc_zval(request, "headers", &headers);
    add_assoc_string(request, "body", "");

    king_server_local_add_common_request_fields(
        request,
        session,
        "http/1.1",
        "http",
        0
    );
}

PHP_FUNCTION(king_http1_server_listen)
{
    char *host = NULL;
    size_t host_len = 0;
    zend_long port;
    zval *config;
    zval *handler;
    king_client_session_t *session;
    zval request;
    zval retval;

    ZEND_PARSE_PARAMETERS_START(4, 4)
        Z_PARAM_STRING(host, host_len)
        Z_PARAM_LONG(port)
        Z_PARAM_ZVAL(config)
        Z_PARAM_ZVAL(handler)
    ZEND_PARSE_PARAMETERS_END();

    session = king_server_local_open_session(
        "king_http1_server_listen",
        host,
        host_len,
        port,
        config,
        3,
        "http/1.1",
        "server_http1_local"
    );
    if (session == NULL) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    ZVAL_UNDEF(&request);
    ZVAL_UNDEF(&retval);

    king_server_http1_build_request(&request, session, host, host_len, port);

    if (king_server_local_invoke_handler(
            handler,
            &request,
            &retval,
            "king_http1_server_listen"
        ) != SUCCESS) {
        king_server_local_close_session(session);
        zval_ptr_dtor(&request);

        if (!Z_ISUNDEF(retval)) {
            zval_ptr_dtor(&retval);
        }

        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    if (king_server_local_validate_response(
            &retval,
            session,
            "http/1.1",
            "king_http1_server_listen"
        ) != SUCCESS) {
        king_server_local_close_session(session);
        zval_ptr_dtor(&request);
        zval_ptr_dtor(&retval);
        RETURN_FALSE;
    }

    king_server_local_close_session(session);
    zval_ptr_dtor(&request);
    zval_ptr_dtor(&retval);

    king_set_error("");
    RETURN_TRUE;
}
