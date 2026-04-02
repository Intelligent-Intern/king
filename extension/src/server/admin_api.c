/*
 * =========================================================================
 * FILENAME:   src/server/admin_api.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Keeps the existing admin-listener snapshot contract and adds a bounded
 * one-shot on-wire admin leaf for the current runtime. Callers can still use
 * the function as a pure local state marker, while `accept_timeout_ms > 0`
 * enables one real TCP/TLS+mTLS admin request so auth, reload, and failure
 * reporting can be verified against real clients.
 * =========================================================================
 */

#include "php.h"
#include "php_king.h"
#include "include/client/session.h"
#include "include/config/config.h"
#include "include/config/dynamic_admin_api/base_layer.h"
#include "include/config/security_and_traffic/base_layer.h"
#include "include/server/admin_api.h"
#include "include/server/session.h"
#include "include/server/tls.h"

#include "main/php_network.h"
#include "main/php_streams.h"

#include <arpa/inet.h>
#include <ctype.h>
#include <errno.h>
#include <limits.h>
#include <netdb.h>
#include <poll.h>
#include <stdarg.h>
#include <stdio.h>
#include <string.h>
#include <strings.h>
#include <sys/socket.h>
#include <sys/types.h>
#include <time.h>
#include <unistd.h>

#include "control.inc"

#ifndef KING_SERVER_ADMIN_API_PATH_MAX
# ifdef PATH_MAX
#  define KING_SERVER_ADMIN_API_PATH_MAX PATH_MAX
# else
#  define KING_SERVER_ADMIN_API_PATH_MAX 1024
# endif
#endif

#define KING_SERVER_ADMIN_API_DEFAULT_ACCEPT_TIMEOUT_MS 0L
#define KING_SERVER_ADMIN_API_MAX_ACCEPT_TIMEOUT_MS 10000L
#define KING_SERVER_ADMIN_API_DEFAULT_IO_TIMEOUT_MS 1000L
#define KING_SERVER_ADMIN_API_MAX_REQUEST_HEAD_BYTES 16384
#define KING_SERVER_ADMIN_API_MAX_REQUEST_LINE_BYTES 2048

typedef struct _king_server_admin_api_config {
    const char *bind_host;
    size_t bind_host_len;
    zend_long port;
    const char *auth_mode;
    size_t auth_mode_len;
    const char *ca_file;
    size_t ca_file_len;
    const char *cert_file;
    size_t cert_file_len;
    const char *key_file;
    size_t key_file_len;
    zend_long accept_timeout_ms;
    bool enabled;
} king_server_admin_api_config_t;

typedef enum _king_server_admin_api_route {
    KING_SERVER_ADMIN_API_ROUTE_HEALTH = 1,
    KING_SERVER_ADMIN_API_ROUTE_RELOAD_TLS = 2,
    KING_SERVER_ADMIN_API_ROUTE_UNKNOWN = 3
} king_server_admin_api_route_t;

typedef struct _king_server_admin_api_request {
    king_server_admin_api_route_t route;
    char method[16];
    char path[128];
    char reload_cert_file[KING_SERVER_ADMIN_API_PATH_MAX];
    size_t reload_cert_file_len;
    char reload_key_file[KING_SERVER_ADMIN_API_PATH_MAX];
    size_t reload_key_file_len;
} king_server_admin_api_request_t;

typedef enum _king_server_admin_api_wait_result {
    KING_SERVER_ADMIN_API_WAIT_ERROR = -1,
    KING_SERVER_ADMIN_API_WAIT_TIMEOUT = 0,
    KING_SERVER_ADMIN_API_WAIT_READY = 1
} king_server_admin_api_wait_result_t;

static void king_server_admin_api_init_from_globals(
    king_server_admin_api_config_t *config
)
{
    config->bind_host = king_dynamic_admin_api_config.bind_host != NULL
        ? king_dynamic_admin_api_config.bind_host
        : "127.0.0.1";
    config->bind_host_len = strlen(config->bind_host);
    config->port = king_dynamic_admin_api_config.port;
    config->auth_mode = king_dynamic_admin_api_config.auth_mode != NULL
        ? king_dynamic_admin_api_config.auth_mode
        : "mtls";
    config->auth_mode_len = strlen(config->auth_mode);
    config->ca_file = king_dynamic_admin_api_config.ca_file != NULL
        ? king_dynamic_admin_api_config.ca_file
        : "";
    config->ca_file_len = strlen(config->ca_file);
    config->cert_file = king_dynamic_admin_api_config.cert_file != NULL
        ? king_dynamic_admin_api_config.cert_file
        : "";
    config->cert_file_len = strlen(config->cert_file);
    config->key_file = king_dynamic_admin_api_config.key_file != NULL
        ? king_dynamic_admin_api_config.key_file
        : "";
    config->key_file_len = strlen(config->key_file);
    config->accept_timeout_ms = KING_SERVER_ADMIN_API_DEFAULT_ACCEPT_TIMEOUT_MS;
    config->enabled = king_security_config.admin_api_enable;
}

static void king_server_admin_api_init_from_cfg(
    king_server_admin_api_config_t *config,
    const king_cfg_t *cfg
)
{
    config->bind_host = cfg->admin_api.bind_host != NULL
        ? cfg->admin_api.bind_host
        : "127.0.0.1";
    config->bind_host_len = strlen(config->bind_host);
    config->port = cfg->admin_api.port;
    config->auth_mode = cfg->admin_api.auth_mode != NULL
        ? cfg->admin_api.auth_mode
        : "mtls";
    config->auth_mode_len = strlen(config->auth_mode);
    config->ca_file = cfg->admin_api.ca_file != NULL
        ? cfg->admin_api.ca_file
        : "";
    config->ca_file_len = strlen(config->ca_file);
    config->cert_file = cfg->admin_api.cert_file != NULL
        ? cfg->admin_api.cert_file
        : "";
    config->cert_file_len = strlen(config->cert_file);
    config->key_file = cfg->admin_api.key_file != NULL
        ? cfg->admin_api.key_file
        : "";
    config->key_file_len = strlen(config->key_file);
    config->accept_timeout_ms = KING_SERVER_ADMIN_API_DEFAULT_ACCEPT_TIMEOUT_MS;
    config->enabled = cfg->security.admin_api_enable;
}

static bool king_server_admin_api_host_is_valid(
    const char *host,
    size_t host_len
)
{
    size_t i;

    if (host == NULL || host_len == 0) {
        return false;
    }

    for (i = 0; i < host_len; ++i) {
        unsigned char c = (unsigned char) host[i];

        if (!isalnum(c) && c != '.' && c != '-' && c != ':') {
            return false;
        }
    }

    return true;
}

static bool king_server_admin_api_path_is_readable(
    const char *path,
    size_t path_len
)
{
    if (path == NULL || path_len == 0) {
        return false;
    }

    return VCWD_ACCESS(path, R_OK) == 0;
}

static zend_result king_server_admin_api_apply_inline_config(
    zval *config_array,
    king_server_admin_api_config_t *config,
    const char *function_name
)
{
    zval *value;
    zend_string *key;

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(config_array), key, value) {
        if (key == NULL) {
            continue;
        }

        if (zend_string_equals_literal(key, "enable")
            || zend_string_equals_literal(key, "admin_api_enable")) {
            if (Z_TYPE_P(value) != IS_TRUE && Z_TYPE_P(value) != IS_FALSE) {
                king_server_control_set_errorf(
                    "%s() config key '%s' must be boolean.",
                    function_name,
                    ZSTR_VAL(key)
                );
                return FAILURE;
            }

            config->enabled = zend_is_true(value);
        } else if (zend_string_equals_literal(key, "bind_host")
            || zend_string_equals_literal(key, "host")) {
            if (Z_TYPE_P(value) != IS_STRING
                || !king_server_admin_api_host_is_valid(Z_STRVAL_P(value), Z_STRLEN_P(value))) {
                king_server_control_set_errorf(
                    "%s() config key '%s' must be a non-empty host string.",
                    function_name,
                    ZSTR_VAL(key)
                );
                return FAILURE;
            }

            config->bind_host = Z_STRVAL_P(value);
            config->bind_host_len = Z_STRLEN_P(value);
        } else if (zend_string_equals_literal(key, "port")) {
            if (Z_TYPE_P(value) != IS_LONG) {
                king_server_control_set_errorf(
                    "%s() config key 'port' must be an integer.",
                    function_name
                );
                return FAILURE;
            }

            config->port = Z_LVAL_P(value);
        } else if (zend_string_equals_literal(key, "auth_mode")) {
            if (Z_TYPE_P(value) != IS_STRING || Z_STRLEN_P(value) == 0) {
                king_server_control_set_errorf(
                    "%s() config key 'auth_mode' must be a non-empty string.",
                    function_name
                );
                return FAILURE;
            }

            config->auth_mode = Z_STRVAL_P(value);
            config->auth_mode_len = Z_STRLEN_P(value);
        } else if (zend_string_equals_literal(key, "ca_file")) {
            if (Z_TYPE_P(value) != IS_STRING) {
                king_server_control_set_errorf(
                    "%s() config key 'ca_file' must be a string path.",
                    function_name
                );
                return FAILURE;
            }

            config->ca_file = Z_STRVAL_P(value);
            config->ca_file_len = Z_STRLEN_P(value);
        } else if (zend_string_equals_literal(key, "cert_file")) {
            if (Z_TYPE_P(value) != IS_STRING) {
                king_server_control_set_errorf(
                    "%s() config key 'cert_file' must be a string path.",
                    function_name
                );
                return FAILURE;
            }

            config->cert_file = Z_STRVAL_P(value);
            config->cert_file_len = Z_STRLEN_P(value);
        } else if (zend_string_equals_literal(key, "key_file")) {
            if (Z_TYPE_P(value) != IS_STRING) {
                king_server_control_set_errorf(
                    "%s() config key 'key_file' must be a string path.",
                    function_name
                );
                return FAILURE;
            }

            config->key_file = Z_STRVAL_P(value);
            config->key_file_len = Z_STRLEN_P(value);
        } else if (zend_string_equals_literal(key, "accept_timeout_ms")) {
            if (Z_TYPE_P(value) != IS_LONG) {
                king_server_control_set_errorf(
                    "%s() config key 'accept_timeout_ms' must be an integer.",
                    function_name
                );
                return FAILURE;
            }

            config->accept_timeout_ms = Z_LVAL_P(value);
        } else {
            king_server_control_set_errorf(
                "%s() config contains unsupported key '%s'.",
                function_name,
                ZSTR_VAL(key)
            );
            return FAILURE;
        }
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

static zend_result king_server_admin_api_resolve_config(
    zval *zconfig,
    uint32_t arg_num,
    king_server_admin_api_config_t *config,
    const char *function_name
)
{
    king_cfg_t *cfg;

    king_server_admin_api_init_from_globals(config);

    if (zconfig == NULL || Z_TYPE_P(zconfig) == IS_NULL) {
        return SUCCESS;
    }

    if (Z_TYPE_P(zconfig) == IS_ARRAY) {
        return king_server_admin_api_apply_inline_config(
            zconfig,
            config,
            function_name
        );
    }

    cfg = (king_cfg_t *) king_fetch_config(zconfig);
    if (cfg == NULL) {
        zend_argument_type_error(
            arg_num,
            "must be null, array, a King\\Config resource, or a King\\Config object"
        );
        return FAILURE;
    }

    king_server_admin_api_init_from_cfg(config, cfg);
    return SUCCESS;
}

static zend_result king_server_admin_api_validate_config(
    const king_server_admin_api_config_t *config,
    const char *function_name
)
{
    if (!config->enabled) {
        king_server_control_set_errorf(
            "%s() requires admin API enablement via config or php.ini.",
            function_name
        );
        return FAILURE;
    }

    if (!king_server_admin_api_host_is_valid(config->bind_host, config->bind_host_len)) {
        king_server_control_set_errorf(
            "%s() bind_host must be a non-empty host string.",
            function_name
        );
        return FAILURE;
    }

    if (config->port < 1024 || config->port > 65535) {
        king_server_control_set_errorf(
            "%s() port must be between 1024 and 65535.",
            function_name
        );
        return FAILURE;
    }

    if (config->auth_mode_len != sizeof("mtls") - 1
        || memcmp(config->auth_mode, "mtls", sizeof("mtls") - 1) != 0) {
        king_server_control_set_errorf(
            "%s() currently supports only auth_mode 'mtls'.",
            function_name
        );
        return FAILURE;
    }

    if (!king_server_admin_api_path_is_readable(config->ca_file, config->ca_file_len)
        || !king_server_admin_api_path_is_readable(config->cert_file, config->cert_file_len)
        || !king_server_admin_api_path_is_readable(config->key_file, config->key_file_len)) {
        king_server_control_set_errorf(
            "%s() auth_mode 'mtls' requires readable ca_file, cert_file, and key_file.",
            function_name
        );
        return FAILURE;
    }

    if (config->accept_timeout_ms < 0
        || config->accept_timeout_ms > KING_SERVER_ADMIN_API_MAX_ACCEPT_TIMEOUT_MS) {
        king_server_control_set_errorf(
            "%s() accept_timeout_ms must be between 0 and %ld.",
            function_name,
            (zend_long) KING_SERVER_ADMIN_API_MAX_ACCEPT_TIMEOUT_MS
        );
        return FAILURE;
    }

    return SUCCESS;
}

static void king_server_admin_api_apply_session_snapshot(
    king_client_session_t *session,
    const king_server_admin_api_config_t *config
)
{
    bool was_active = session->server_admin_api_active;

    session->server_admin_api_active = true;
    session->server_admin_api_listen_count++;
    if (was_active) {
        session->server_admin_api_reload_count++;
    }

    session->server_last_admin_api_port = config->port;
    session->server_last_admin_api_mtls_ready = true;
    session->last_activity_at = time(NULL);

    king_server_control_set_string_bytes(
        &session->server_last_admin_api_bind_host,
        config->bind_host,
        config->bind_host_len
    );
    king_server_control_set_string_bytes(
        &session->server_last_admin_api_auth_mode,
        config->auth_mode,
        config->auth_mode_len
    );
}

static zend_long king_server_admin_api_now_ms(void)
{
    struct timespec ts;

    clock_gettime(CLOCK_MONOTONIC, &ts);
    return (zend_long) (ts.tv_sec * 1000LL + ts.tv_nsec / 1000000LL);
}

static king_server_admin_api_wait_result_t king_server_admin_api_wait_fd(
    int fd,
    short events,
    zend_long deadline_ms,
    const char *function_name,
    const char *scope
)
{
    for (;;) {
        struct pollfd pfd;
        zend_long remaining_ms;
        int poll_result;

        king_process_pending_interrupts();
        if (EG(exception) != NULL) {
            return KING_SERVER_ADMIN_API_WAIT_ERROR;
        }

        remaining_ms = deadline_ms - king_server_admin_api_now_ms();
        if (remaining_ms <= 0) {
            return KING_SERVER_ADMIN_API_WAIT_TIMEOUT;
        }

        memset(&pfd, 0, sizeof(pfd));
        pfd.fd = fd;
        pfd.events = events;

        poll_result = poll(&pfd, 1, (int) remaining_ms);
        if (poll_result == 0) {
            return KING_SERVER_ADMIN_API_WAIT_TIMEOUT;
        }

        if (poll_result < 0) {
            if (errno == EINTR) {
                continue;
            }

            king_server_control_set_errorf(
                "%s() failed while polling the admin listener %s (errno %d).",
                function_name,
                scope,
                errno
            );
            return KING_SERVER_ADMIN_API_WAIT_ERROR;
        }

        if ((pfd.revents & events) != 0) {
            return KING_SERVER_ADMIN_API_WAIT_READY;
        }

        if ((pfd.revents & (POLLERR | POLLHUP | POLLNVAL)) != 0) {
            king_server_control_set_errorf(
                "%s() saw the admin listener %s close before it became ready.",
                function_name,
                scope
            );
            return KING_SERVER_ADMIN_API_WAIT_ERROR;
        }
    }
}

static zend_result king_server_admin_api_open_listener_socket(
    const king_server_admin_api_config_t *config,
    int *listener_fd_out,
    const char *function_name
)
{
    struct addrinfo hints;
    struct addrinfo *results = NULL;
    struct addrinfo *cursor;
    char port_buffer[16];
    int listener_fd = -1;
    int gai_status;

    memset(&hints, 0, sizeof(hints));
    hints.ai_family = AF_UNSPEC;
    hints.ai_socktype = SOCK_STREAM;
    hints.ai_flags = AI_ADDRCONFIG | AI_NUMERICSERV;

    snprintf(port_buffer, sizeof(port_buffer), "%ld", config->port);
    gai_status = getaddrinfo(config->bind_host, port_buffer, &hints, &results);
    if (gai_status != 0) {
        king_server_control_set_errorf(
            "%s() failed to resolve the admin listen target '%s:%ld' (getaddrinfo code %d).",
            function_name,
            config->bind_host,
            config->port,
            gai_status
        );
        return FAILURE;
    }

    for (cursor = results; cursor != NULL; cursor = cursor->ai_next) {
        int reuse_addr = 1;

        listener_fd = socket(cursor->ai_family, cursor->ai_socktype, cursor->ai_protocol);
        if (listener_fd < 0) {
            continue;
        }

        (void) setsockopt(
            listener_fd,
            SOL_SOCKET,
            SO_REUSEADDR,
            &reuse_addr,
            sizeof(reuse_addr)
        );

        if (bind(listener_fd, cursor->ai_addr, cursor->ai_addrlen) == 0) {
            if (listen(listener_fd, 1) == 0) {
                *listener_fd_out = listener_fd;
                freeaddrinfo(results);
                return SUCCESS;
            }
        }

        close(listener_fd);
        listener_fd = -1;
    }

    freeaddrinfo(results);
    king_server_control_set_errorf(
        "%s() failed to bind the admin listener on '%s:%ld' (errno %d).",
        function_name,
        config->bind_host,
        config->port,
        errno
    );
    return FAILURE;
}

static king_server_admin_api_wait_result_t king_server_admin_api_accept_once(
    int listener_fd,
    zend_long deadline_ms,
    int *accepted_fd_out,
    const char *function_name
)
{
    for (;;) {
        king_server_admin_api_wait_result_t wait_result =
            king_server_admin_api_wait_fd(
                listener_fd,
                POLLIN,
                deadline_ms,
                function_name,
                "accept phase"
            );

        if (wait_result != KING_SERVER_ADMIN_API_WAIT_READY) {
            return wait_result;
        }

        *accepted_fd_out = accept(listener_fd, NULL, NULL);
        if (*accepted_fd_out >= 0) {
            return KING_SERVER_ADMIN_API_WAIT_READY;
        }

        if (errno == EINTR) {
            continue;
        }

#ifdef EAGAIN
        if (errno == EAGAIN || errno == EWOULDBLOCK) {
            continue;
        }
#endif

        king_server_control_set_errorf(
            "%s() failed to accept the admin connection (errno %d).",
            function_name,
            errno
        );
        return KING_SERVER_ADMIN_API_WAIT_ERROR;
    }
}

static zend_result king_server_admin_api_context_set_string(
    php_stream_context *context,
    const char *wrapper,
    const char *option,
    const char *value
)
{
    zval zv;

    ZVAL_STRING(&zv, value != NULL ? value : "");
    php_stream_context_set_option(context, wrapper, option, &zv);
    zval_ptr_dtor(&zv);
    return SUCCESS;
}

static zend_result king_server_admin_api_context_set_bool(
    php_stream_context *context,
    const char *wrapper,
    const char *option,
    bool value
)
{
    zval zv;

    ZVAL_BOOL(&zv, value);
    php_stream_context_set_option(context, wrapper, option, &zv);
    zval_ptr_dtor(&zv);
    return SUCCESS;
}

static zend_result king_server_admin_api_enable_tls(
    zval *zstream,
    const char *function_name
)
{
    zval function_name_zv;
    zval retval;
    zval params[3];
    zend_result status = FAILURE;

    ZVAL_STRING(&function_name_zv, "stream_socket_enable_crypto");
    ZVAL_COPY(&params[0], zstream);
    ZVAL_TRUE(&params[1]);
    ZVAL_LONG(&params[2], STREAM_CRYPTO_METHOD_TLS_SERVER);
    ZVAL_UNDEF(&retval);

    if (call_user_function(
            EG(function_table),
            NULL,
            &function_name_zv,
            &retval,
            3,
            params
        ) == SUCCESS
        && zend_is_true(&retval)) {
        status = SUCCESS;
    } else {
        king_server_control_set_errorf(
            "%s() failed the admin TLS/mTLS handshake.",
            function_name
        );
    }

    zval_ptr_dtor(&params[0]);
    zval_ptr_dtor(&params[1]);
    zval_ptr_dtor(&params[2]);
    if (!Z_ISUNDEF(retval)) {
        zval_ptr_dtor(&retval);
    }
    zval_ptr_dtor(&function_name_zv);

    return status;
}

static zend_result king_server_admin_api_capture_peer_subject(
    king_client_session_t *session,
    php_stream *client_stream
)
{
    php_stream_context *context;
    zval *peer_certificate;
    zval function_name_zv;
    zval retval;
    zval params[1];
    zval *subject;
    zval *common_name;

    context = PHP_STREAM_CONTEXT(client_stream);
    if (context == NULL) {
        return SUCCESS;
    }

    peer_certificate = php_stream_context_get_option(
        context,
        "ssl",
        "peer_certificate"
    );
    if (peer_certificate == NULL) {
        return SUCCESS;
    }

    ZVAL_STRING(&function_name_zv, "openssl_x509_parse");
    ZVAL_COPY(&params[0], peer_certificate);
    ZVAL_UNDEF(&retval);

    if (call_user_function(
            EG(function_table),
            NULL,
            &function_name_zv,
            &retval,
            1,
            params
        ) != SUCCESS
        || Z_TYPE(retval) != IS_ARRAY) {
        goto cleanup;
    }

    subject = zend_hash_str_find(
        Z_ARRVAL(retval),
        "subject",
        sizeof("subject") - 1
    );
    if (subject == NULL || Z_TYPE_P(subject) != IS_ARRAY) {
        goto cleanup;
    }

    common_name = zend_hash_str_find(
        Z_ARRVAL_P(subject),
        "CN",
        sizeof("CN") - 1
    );
    if (common_name != NULL && Z_TYPE_P(common_name) == IS_STRING) {
        zend_string *rendered = strpprintf(0, "CN=%s", Z_STRVAL_P(common_name));

        if (rendered != NULL) {
            king_server_session_set_peer_cert_subject(
                session,
                ZSTR_VAL(rendered),
                ZSTR_LEN(rendered)
            );
            zend_string_release(rendered);
        }
    }

cleanup:
    zval_ptr_dtor(&params[0]);
    if (!Z_ISUNDEF(retval)) {
        zval_ptr_dtor(&retval);
    }
    zval_ptr_dtor(&function_name_zv);
    return SUCCESS;
}

static zend_result king_server_admin_api_open_listener_stream(
    const king_server_admin_api_config_t *config,
    php_stream **listener_stream_out,
    const char *function_name
)
{
    php_stream_context *context = NULL;
    zend_string *endpoint = NULL;
    zend_string *error_text = NULL;
    int error_code = 0;

    context = php_stream_context_alloc();
    if (context == NULL) {
        king_server_control_set_errorf(
            "%s() failed to allocate the admin listener TLS context.",
            function_name
        );
        return FAILURE;
    }

    king_server_admin_api_context_set_string(context, "ssl", "local_cert", config->cert_file);
    king_server_admin_api_context_set_string(context, "ssl", "local_pk", config->key_file);
    king_server_admin_api_context_set_string(context, "ssl", "cafile", config->ca_file);
    king_server_admin_api_context_set_bool(context, "ssl", "verify_peer", true);
    king_server_admin_api_context_set_bool(context, "ssl", "verify_peer_name", false);
    king_server_admin_api_context_set_bool(context, "ssl", "allow_self_signed", true);
    king_server_admin_api_context_set_bool(context, "ssl", "capture_peer_cert", true);

    endpoint = strpprintf(
        0,
        "tls://%.*s:%ld",
        (int) config->bind_host_len,
        config->bind_host,
        config->port
    );
    if (endpoint == NULL) {
        php_stream_context_free(context);
        king_server_control_set_errorf(
            "%s() failed to build the admin listener endpoint string.",
            function_name
        );
        return FAILURE;
    }

    *listener_stream_out = php_stream_xport_create(
        ZSTR_VAL(endpoint),
        ZSTR_LEN(endpoint),
        REPORT_ERRORS,
        STREAM_XPORT_SERVER | STREAM_XPORT_BIND | STREAM_XPORT_LISTEN,
        NULL,
        NULL,
        context,
        &error_text,
        &error_code
    );
    zend_string_release(endpoint);

    if (*listener_stream_out == NULL) {
        if (error_text != NULL && ZSTR_LEN(error_text) > 0) {
            king_server_control_set_errorf(
                "%s() failed to start the admin listener: %s",
                function_name,
                ZSTR_VAL(error_text)
            );
            zend_string_release(error_text);
        } else {
            king_server_control_set_errorf(
                "%s() failed to start the admin listener (error code %d).",
                function_name,
                error_code
            );
        }
        php_stream_context_free(context);
        return FAILURE;
    }

    return SUCCESS;
}

static king_server_admin_api_wait_result_t king_server_admin_api_accept_client_stream(
    php_stream *listener_stream,
    const king_server_admin_api_config_t *config,
    php_stream **client_stream_out,
    const char *function_name
)
{
    struct timeval timeout;
    zend_string *error_text = NULL;
    int accept_result;

    timeout.tv_sec = (time_t) (config->accept_timeout_ms / 1000);
    timeout.tv_usec = (suseconds_t) ((config->accept_timeout_ms % 1000) * 1000);

    accept_result = php_stream_xport_accept(
        listener_stream,
        client_stream_out,
        NULL,
        NULL,
        NULL,
        &timeout,
        &error_text
    );
    if (accept_result == 0 && *client_stream_out != NULL) {
        return KING_SERVER_ADMIN_API_WAIT_READY;
    }

    if (error_text != NULL && ZSTR_LEN(error_text) > 0) {
        king_server_control_set_errorf(
            "%s() failed the admin TLS/mTLS handshake: %s",
            function_name,
            ZSTR_VAL(error_text)
        );
        zend_string_release(error_text);
        return KING_SERVER_ADMIN_API_WAIT_ERROR;
    }

    return KING_SERVER_ADMIN_API_WAIT_TIMEOUT;
}

static void king_server_admin_api_trim_line(char *line, size_t *line_len)
{
    while (*line_len > 0) {
        char c = line[*line_len - 1];

        if (c != '\r' && c != '\n') {
            break;
        }

        line[--(*line_len)] = '\0';
    }
}

static const char *king_server_admin_api_skip_space(const char *value)
{
    while (*value == ' ' || *value == '\t') {
        value++;
    }

    return value;
}

static size_t king_server_admin_api_trim_value_len(
    const char *value,
    size_t value_len
)
{
    while (value_len > 0) {
        char c = value[value_len - 1];

        if (c != ' ' && c != '\t') {
            break;
        }

        value_len--;
    }

    return value_len;
}

static zend_result king_server_admin_api_copy_header_value(
    char *dest,
    size_t dest_size,
    size_t *dest_len,
    const char *value,
    size_t value_len,
    char *error_message,
    size_t error_message_size,
    const char *header_name
)
{
    if (value_len == 0) {
        snprintf(
            error_message,
            error_message_size,
            "admin header '%s' must not be empty.",
            header_name
        );
        return FAILURE;
    }

    if (value_len >= dest_size) {
        snprintf(
            error_message,
            error_message_size,
            "admin header '%s' is too large.",
            header_name
        );
        return FAILURE;
    }

    memcpy(dest, value, value_len);
    dest[value_len] = '\0';
    *dest_len = value_len;
    return SUCCESS;
}

static void king_server_admin_api_request_init(
    king_server_admin_api_request_t *request
)
{
    memset(request, 0, sizeof(*request));
    request->route = KING_SERVER_ADMIN_API_ROUTE_UNKNOWN;
}

static zend_result king_server_admin_api_parse_request(
    php_stream *client_stream,
    king_server_admin_api_request_t *request,
    char *error_message,
    size_t error_message_size
)
{
    char line[KING_SERVER_ADMIN_API_MAX_REQUEST_LINE_BYTES];
    size_t line_len = 0;
    size_t total_bytes = 0;
    char *method_end;
    char *path_end;

    king_server_admin_api_request_init(request);

    if (php_stream_get_line(client_stream, line, sizeof(line), &line_len) == NULL) {
        snprintf(
            error_message,
            error_message_size,
            "failed to read the admin request line."
        );
        return FAILURE;
    }

    total_bytes += line_len;
    king_server_admin_api_trim_line(line, &line_len);
    if (line_len == 0) {
        snprintf(
            error_message,
            error_message_size,
            "received an empty admin request line."
        );
        return FAILURE;
    }

    method_end = strchr(line, ' ');
    if (method_end == NULL || method_end == line) {
        snprintf(
            error_message,
            error_message_size,
            "received an invalid admin request method."
        );
        return FAILURE;
    }

    path_end = strchr(method_end + 1, ' ');
    if (path_end == NULL || path_end == method_end + 1) {
        snprintf(
            error_message,
            error_message_size,
            "received an invalid admin request target."
        );
        return FAILURE;
    }

    if ((size_t) (method_end - line) >= sizeof(request->method)
        || (size_t) (path_end - (method_end + 1)) >= sizeof(request->path)) {
        snprintf(
            error_message,
            error_message_size,
            "received an oversized admin request line."
        );
        return FAILURE;
    }

    memcpy(request->method, line, (size_t) (method_end - line));
    request->method[method_end - line] = '\0';
    memcpy(
        request->path,
        method_end + 1,
        (size_t) (path_end - (method_end + 1))
    );
    request->path[path_end - (method_end + 1)] = '\0';

    if (strcmp(request->method, "GET") == 0
        && (strcmp(request->path, "/") == 0
            || strcmp(request->path, "/health") == 0)) {
        request->route = KING_SERVER_ADMIN_API_ROUTE_HEALTH;
    } else if (strcmp(request->method, "POST") == 0
        && strcmp(request->path, "/reload-tls") == 0) {
        request->route = KING_SERVER_ADMIN_API_ROUTE_RELOAD_TLS;
    }

    for (;;) {
        char *colon;
        const char *value;
        size_t value_len;

        if (php_stream_get_line(client_stream, line, sizeof(line), &line_len) == NULL) {
            snprintf(
                error_message,
                error_message_size,
                "failed while reading the admin request headers."
            );
            return FAILURE;
        }

        total_bytes += line_len;
        if (total_bytes > KING_SERVER_ADMIN_API_MAX_REQUEST_HEAD_BYTES) {
            snprintf(
                error_message,
                error_message_size,
                "received an admin request head larger than %d bytes.",
                KING_SERVER_ADMIN_API_MAX_REQUEST_HEAD_BYTES
            );
            return FAILURE;
        }

        king_server_admin_api_trim_line(line, &line_len);
        if (line_len == 0) {
            break;
        }

        colon = strchr(line, ':');
        if (colon == NULL || colon == line) {
            snprintf(
                error_message,
                error_message_size,
                "received an invalid admin header line."
            );
            return FAILURE;
        }

        value = king_server_admin_api_skip_space(colon + 1);
        value_len = king_server_admin_api_trim_value_len(
            value,
            strlen(value)
        );

        if (strncasecmp(line, "X-King-TLS-Cert-File", sizeof("X-King-TLS-Cert-File") - 1) == 0
            && (size_t) (colon - line) == sizeof("X-King-TLS-Cert-File") - 1) {
            if (king_server_admin_api_copy_header_value(
                    request->reload_cert_file,
                    sizeof(request->reload_cert_file),
                    &request->reload_cert_file_len,
                    value,
                    value_len,
                    error_message,
                    error_message_size,
                    "X-King-TLS-Cert-File"
                ) != SUCCESS) {
                return FAILURE;
            }
        } else if (strncasecmp(line, "X-King-TLS-Key-File", sizeof("X-King-TLS-Key-File") - 1) == 0
            && (size_t) (colon - line) == sizeof("X-King-TLS-Key-File") - 1) {
            if (king_server_admin_api_copy_header_value(
                    request->reload_key_file,
                    sizeof(request->reload_key_file),
                    &request->reload_key_file_len,
                    value,
                    value_len,
                    error_message,
                    error_message_size,
                    "X-King-TLS-Key-File"
                ) != SUCCESS) {
                return FAILURE;
            }
        }
    }

    return SUCCESS;
}

static const char *king_server_admin_api_status_text(zend_long status)
{
    switch (status) {
        case 200:
            return "OK";
        case 400:
            return "Bad Request";
        case 404:
            return "Not Found";
        case 500:
            return "Internal Server Error";
        default:
            return "OK";
    }
}

static zend_result king_server_admin_api_write_response(
    php_stream *client_stream,
    zend_long status,
    const char *body,
    const char *function_name
)
{
    zend_string *response;
    size_t total = 0;
    size_t response_len;

    response = strpprintf(
        0,
        "HTTP/1.1 %ld %s\r\n"
        "Content-Type: text/plain\r\n"
        "Content-Length: %zu\r\n"
        "Connection: close\r\n\r\n"
        "%s",
        status,
        king_server_admin_api_status_text(status),
        body != NULL ? strlen(body) : 0,
        body != NULL ? body : ""
    );
    if (response == NULL) {
        king_server_control_set_errorf(
            "%s() failed to build the admin response payload.",
            function_name
        );
        return FAILURE;
    }

    response_len = ZSTR_LEN(response);
    while (total < response_len) {
        ssize_t written = php_stream_write(
            client_stream,
            ZSTR_VAL(response) + total,
            response_len - total
        );

        if (written <= 0) {
            zend_string_release(response);
            king_server_control_set_errorf(
                "%s() failed while writing the admin response.",
                function_name
            );
            return FAILURE;
        }

        total += (size_t) written;
    }

    (void) php_stream_flush(client_stream);
    zend_string_release(response);
    return SUCCESS;
}

static zend_result king_server_admin_api_handle_request(
    php_stream *client_stream,
    king_client_session_t *session,
    const char *function_name
)
{
    king_server_admin_api_request_t request;
    char request_error[KING_ERR_LEN];

    request_error[0] = '\0';
    if (king_server_admin_api_parse_request(
            client_stream,
            &request,
            request_error,
            sizeof(request_error)
        ) != SUCCESS) {
        return king_server_admin_api_write_response(
            client_stream,
            400,
            request_error,
            function_name
        );
    }

    if (request.route == KING_SERVER_ADMIN_API_ROUTE_HEALTH) {
        session->last_activity_at = time(NULL);
        king_set_error("");
        return king_server_admin_api_write_response(
            client_stream,
            200,
            "admin listener ready\n",
            function_name
        );
    }

    if (request.route == KING_SERVER_ADMIN_API_ROUTE_RELOAD_TLS) {
        const char *reload_error;

        if (request.reload_cert_file_len == 0 || request.reload_key_file_len == 0) {
            return king_server_admin_api_write_response(
                client_stream,
                400,
                "reload-tls requires X-King-TLS-Cert-File and X-King-TLS-Key-File.\n",
                function_name
            );
        }

        if (king_server_tls_reload_paths(
                session,
                request.reload_cert_file,
                request.reload_cert_file_len,
                request.reload_key_file,
                request.reload_key_file_len,
                "king_admin_api_listen"
            ) != SUCCESS) {
            reload_error = king_get_error();
            if (reload_error == NULL || reload_error[0] == '\0') {
                reload_error = "admin-triggered TLS reload failed.\n";
            }
            if (king_server_admin_api_write_response(
                    client_stream,
                    400,
                    reload_error,
                    function_name
                ) != SUCCESS) {
                return FAILURE;
            }

            king_set_error("");
            return SUCCESS;
        }

        session->last_activity_at = time(NULL);
        king_set_error("");
        return king_server_admin_api_write_response(
            client_stream,
            200,
            "tls reloaded\n",
            function_name
        );
    }

    return king_server_admin_api_write_response(
        client_stream,
        404,
        "unknown admin route\n",
        function_name
    );
}

static zend_result king_server_admin_api_maybe_serve_request(
    king_client_session_t *session,
    const king_server_admin_api_config_t *config,
    const char *function_name
)
{
    struct timeval read_timeout;
    php_stream *listener_stream = NULL;
    php_stream *client_stream = NULL;
    zend_result status = FAILURE;
    king_server_admin_api_wait_result_t accept_result;
    zend_long io_timeout_ms;

    if (config->accept_timeout_ms <= 0) {
        return SUCCESS;
    }

    if (king_server_admin_api_open_listener_stream(
            config,
            &listener_stream,
            function_name
        ) != SUCCESS) {
        return FAILURE;
    }

    accept_result = king_server_admin_api_accept_client_stream(
        listener_stream,
        config,
        &client_stream,
        function_name
    );
    if (accept_result == KING_SERVER_ADMIN_API_WAIT_TIMEOUT) {
        status = SUCCESS;
        goto cleanup;
    }
    if (accept_result != KING_SERVER_ADMIN_API_WAIT_READY) {
        goto cleanup;
    }

    io_timeout_ms = config->accept_timeout_ms > KING_SERVER_ADMIN_API_DEFAULT_IO_TIMEOUT_MS
        ? config->accept_timeout_ms
        : KING_SERVER_ADMIN_API_DEFAULT_IO_TIMEOUT_MS;
    read_timeout.tv_sec = (time_t) (io_timeout_ms / 1000);
    read_timeout.tv_usec = (suseconds_t) ((io_timeout_ms % 1000) * 1000);
    (void) php_stream_set_option(
        client_stream,
        PHP_STREAM_OPTION_BLOCKING,
        1,
        NULL
    );
    (void) php_stream_set_option(
        client_stream,
        PHP_STREAM_OPTION_READ_TIMEOUT,
        0,
        &read_timeout
    );

    (void) king_server_admin_api_capture_peer_subject(session, client_stream);
    if (king_server_admin_api_handle_request(
            client_stream,
            session,
            function_name
        ) != SUCCESS) {
        goto cleanup;
    }

    status = SUCCESS;

cleanup:
    if (client_stream != NULL) {
        php_stream_close(client_stream);
    }
    if (listener_stream != NULL) {
        php_stream_close(listener_stream);
    }
    return status;
}

PHP_FUNCTION(king_admin_api_listen)
{
    zval *ztarget_server;
    zval *zconfig;
    king_client_session_t *session;
    king_server_admin_api_config_t config;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_ZVAL(ztarget_server)
        Z_PARAM_ZVAL(zconfig)
    ZEND_PARSE_PARAMETERS_END();

    session = king_server_control_fetch_open_session(
        ztarget_server,
        1,
        "king_admin_api_listen"
    );
    if (session == NULL) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    if (king_server_admin_api_resolve_config(
            zconfig,
            2,
            &config,
            "king_admin_api_listen"
        ) != SUCCESS) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    if (king_server_admin_api_validate_config(&config, "king_admin_api_listen") != SUCCESS) {
        RETURN_FALSE;
    }

    king_server_admin_api_apply_session_snapshot(session, &config);
    if (king_server_admin_api_maybe_serve_request(
            session,
            &config,
            "king_admin_api_listen"
        ) != SUCCESS) {
        RETURN_FALSE;
    }

    king_set_error("");
    RETURN_TRUE;
}
