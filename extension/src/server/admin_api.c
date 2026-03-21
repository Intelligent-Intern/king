/*
 * =========================================================================
 * FILENAME:   src/server/admin_api.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Activates a first local Admin API listener slice for the skeleton build.
 * This is intentionally an in-memory control-plane contract: it validates
 * the local bind/auth/TLS snapshot for an admin endpoint and records that
 * state on the unified King\Session runtime. A real network listener,
 * request parser, and live config-apply backend remain outside this leaf.
 * =========================================================================
 */

#include "php.h"
#include "php_king.h"
#include "include/client/session.h"
#include "include/config/config.h"
#include "include/config/dynamic_admin_api/base_layer.h"
#include "include/config/security_and_traffic/base_layer.h"
#include "include/server/admin_api.h"

#include <ctype.h>
#include <stdarg.h>
#include <string.h>
#include <time.h>

#include "control.inc"

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
    bool enabled;
} king_server_admin_api_config_t;

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
    king_set_error("");
    RETURN_TRUE;
}
