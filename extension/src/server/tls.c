/*
 * =========================================================================
 * FILENAME:   src/server/tls.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Activates a first local server-side TLS reload slice for the runtime
 * build. This is intentionally an in-memory lifecycle contract over the
 * unified King\Session runtime: it validates replacement certificate/key
 * files, reflects the applied snapshot into session stats, and keeps a
 * minimal reload counter. A real server TLS backend and live accept-loop
 * reconfiguration remain outside this leaf.
 * =========================================================================
 */

#include "php.h"
#include "php_king.h"
#include "include/client/session.h"
#include "include/server/tls.h"

#include "main/php_streams.h"
#include <time.h>

#include "control.inc"

static bool king_server_tls_path_is_readable(
    const char *path,
    size_t path_len
)
{
    if (path == NULL || path_len == 0) {
        return false;
    }

    if (king_server_control_string_has_crlf(path, path_len)) {
        return false;
    }

    return VCWD_ACCESS(path, R_OK) == 0;
}

static zend_result king_server_tls_validate_ticket_key(
    king_client_session_t *session,
    const char *function_name,
    bool *ticket_key_loaded
)
{
    const char *ticket_key_path;
    size_t ticket_key_len;

    *ticket_key_loaded = false;

    if (session->tls_ticket_key_file == NULL) {
        return SUCCESS;
    }

    ticket_key_path = ZSTR_VAL(session->tls_ticket_key_file);
    ticket_key_len = ZSTR_LEN(session->tls_ticket_key_file);

    if (ticket_key_len == 0) {
        return SUCCESS;
    }

    if (!king_server_tls_path_is_readable(ticket_key_path, ticket_key_len)) {
        king_server_control_set_errorf(
            "%s() configured tls_ticket_key_file must be readable when set.",
            function_name
        );
        return FAILURE;
    }

    *ticket_key_loaded = true;
    return SUCCESS;
}

static void king_server_tls_apply_session_snapshot(
    king_client_session_t *session,
    const char *cert_file_path,
    size_t cert_file_path_len,
    const char *key_file_path,
    size_t key_file_path_len,
    bool ticket_key_loaded
)
{
    bool was_active = session->server_tls_active;

    session->server_tls_active = true;
    session->server_tls_apply_count++;
    if (was_active) {
        session->server_tls_reload_count++;
    }

    session->server_last_tls_ticket_key_loaded = ticket_key_loaded;
    session->last_activity_at = time(NULL);

    king_server_control_set_string_bytes(
        &session->tls_default_cert_file,
        cert_file_path,
        cert_file_path_len
    );
    king_server_control_set_string_bytes(
        &session->tls_default_key_file,
        key_file_path,
        key_file_path_len
    );
    king_server_control_set_string_bytes(
        &session->server_last_tls_cert_file,
        cert_file_path,
        cert_file_path_len
    );
    king_server_control_set_string_bytes(
        &session->server_last_tls_key_file,
        key_file_path,
        key_file_path_len
    );

    if (ticket_key_loaded && session->tls_ticket_key_file != NULL) {
        king_server_control_set_string_bytes(
            &session->server_last_tls_ticket_key_file,
            ZSTR_VAL(session->tls_ticket_key_file),
            ZSTR_LEN(session->tls_ticket_key_file)
        );
    } else {
        king_server_control_set_string_bytes(
            &session->server_last_tls_ticket_key_file,
            "",
            0
        );
    }
}

PHP_FUNCTION(king_server_reload_tls_config)
{
    zval *zsession;
    char *cert_file_path = NULL;
    char *key_file_path = NULL;
    size_t cert_file_path_len = 0;
    size_t key_file_path_len = 0;
    king_client_session_t *session;
    bool ticket_key_loaded;

    ZEND_PARSE_PARAMETERS_START(3, 3)
        Z_PARAM_ZVAL(zsession)
        Z_PARAM_STRING(cert_file_path, cert_file_path_len)
        Z_PARAM_STRING(key_file_path, key_file_path_len)
    ZEND_PARSE_PARAMETERS_END();

    session = king_server_control_fetch_open_session(
        zsession,
        1,
        "king_server_reload_tls_config"
    );
    if (session == NULL) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    if (!king_server_tls_path_is_readable(cert_file_path, cert_file_path_len)) {
        king_server_control_set_errorf(
            "king_server_reload_tls_config() cert_file_path must be a non-empty readable file path."
        );
        RETURN_FALSE;
    }

    if (!king_server_tls_path_is_readable(key_file_path, key_file_path_len)) {
        king_server_control_set_errorf(
            "king_server_reload_tls_config() key_file_path must be a non-empty readable file path."
        );
        RETURN_FALSE;
    }

    if (king_server_tls_validate_ticket_key(
            session,
            "king_server_reload_tls_config",
            &ticket_key_loaded
        ) != SUCCESS) {
        RETURN_FALSE;
    }

    king_server_tls_apply_session_snapshot(
        session,
        cert_file_path,
        cert_file_path_len,
        key_file_path,
        key_file_path_len,
        ticket_key_loaded
    );

    king_set_error("");
    RETURN_TRUE;
}
