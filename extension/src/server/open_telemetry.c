/*
 * =========================================================================
 * FILENAME:   src/server/open_telemetry.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Activates a first local server-side telemetry control leaf for the
 * current runtime. The current runtime validates telemetry configuration for
 * an open King\Session, records an initialized snapshot on that session, and
 * tracks the last locally instrumented request after the handler returns.
 * =========================================================================
 */

#include "php.h"
#include "php_king.h"
#include "include/client/session.h"
#include "include/config/config.h"
#include "include/server/open_telemetry.h"

#include <string.h>
#include <time.h>

#include "control.inc"

typedef struct _king_server_telemetry_config {
    const char *service_name;
    size_t service_name_len;
    const char *exporter_endpoint;
    size_t exporter_endpoint_len;
    const char *exporter_protocol;
    size_t exporter_protocol_len;
    bool enable;
    bool metrics_enable;
    bool logs_enable;
} king_server_telemetry_config_t;

static void king_server_telemetry_init_from_session(
    king_server_telemetry_config_t *config,
    king_client_session_t *session
)
{
    config->service_name = session->config_otel_service_name != NULL
        ? ZSTR_VAL(session->config_otel_service_name)
        : "";
    config->service_name_len = session->config_otel_service_name != NULL
        ? ZSTR_LEN(session->config_otel_service_name)
        : 0;
    config->exporter_endpoint = session->config_otel_exporter_endpoint != NULL
        ? ZSTR_VAL(session->config_otel_exporter_endpoint)
        : "";
    config->exporter_endpoint_len =
        session->config_otel_exporter_endpoint != NULL
            ? ZSTR_LEN(session->config_otel_exporter_endpoint)
            : 0;
    config->exporter_protocol = session->config_otel_exporter_protocol != NULL
        ? ZSTR_VAL(session->config_otel_exporter_protocol)
        : "";
    config->exporter_protocol_len =
        session->config_otel_exporter_protocol != NULL
            ? ZSTR_LEN(session->config_otel_exporter_protocol)
            : 0;
    config->enable = session->config_otel_enable;
    config->metrics_enable = session->config_otel_metrics_enable;
    config->logs_enable = session->config_otel_logs_enable;
}

static void king_server_telemetry_init_from_cfg(
    king_server_telemetry_config_t *config,
    const king_cfg_t *cfg
)
{
    config->service_name = cfg->observability.service_name != NULL
        ? cfg->observability.service_name
        : "";
    config->service_name_len = strlen(config->service_name);
    config->exporter_endpoint = cfg->observability.exporter_endpoint != NULL
        ? cfg->observability.exporter_endpoint
        : "";
    config->exporter_endpoint_len = strlen(config->exporter_endpoint);
    config->exporter_protocol = cfg->observability.exporter_protocol != NULL
        ? cfg->observability.exporter_protocol
        : "";
    config->exporter_protocol_len = strlen(config->exporter_protocol);
    config->enable = cfg->observability.enable;
    config->metrics_enable = cfg->observability.metrics_enable;
    config->logs_enable = cfg->observability.logs_enable;
}

static bool king_server_telemetry_protocol_is_valid(
    const char *protocol,
    size_t protocol_len
)
{
    return (protocol_len == sizeof("grpc") - 1
            && memcmp(protocol, "grpc", sizeof("grpc") - 1) == 0)
        || (protocol_len == sizeof("http/protobuf") - 1
            && memcmp(
                protocol,
                "http/protobuf",
                sizeof("http/protobuf") - 1
            ) == 0);
}

static zend_result king_server_telemetry_apply_inline_config(
    zval *config_array,
    king_server_telemetry_config_t *config,
    const char *function_name
)
{
    zval *value;
    zend_string *key;

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(config_array), key, value) {
        if (key == NULL) {
            continue;
        }

        if (zend_string_equals_literal(key, "enable")) {
            if (Z_TYPE_P(value) != IS_TRUE && Z_TYPE_P(value) != IS_FALSE) {
                king_server_control_set_errorf(
                    "%s() config key 'enable' must be boolean.",
                    function_name
                );
                return FAILURE;
            }

            config->enable = zend_is_true(value);
        } else if (zend_string_equals_literal(key, "service_name")) {
            if (Z_TYPE_P(value) != IS_STRING || Z_STRLEN_P(value) == 0) {
                king_server_control_set_errorf(
                    "%s() config key 'service_name' must be a non-empty string.",
                    function_name
                );
                return FAILURE;
            }

            config->service_name = Z_STRVAL_P(value);
            config->service_name_len = Z_STRLEN_P(value);
        } else if (zend_string_equals_literal(key, "exporter_endpoint")) {
            if (Z_TYPE_P(value) != IS_STRING || Z_STRLEN_P(value) == 0) {
                king_server_control_set_errorf(
                    "%s() config key 'exporter_endpoint' must be a non-empty string.",
                    function_name
                );
                return FAILURE;
            }

            config->exporter_endpoint = Z_STRVAL_P(value);
            config->exporter_endpoint_len = Z_STRLEN_P(value);
        } else if (zend_string_equals_literal(key, "exporter_protocol")) {
            if (Z_TYPE_P(value) != IS_STRING
                || !king_server_telemetry_protocol_is_valid(
                    Z_STRVAL_P(value),
                    Z_STRLEN_P(value)
                )) {
                king_server_control_set_errorf(
                    "%s() config key 'exporter_protocol' must be 'grpc' or 'http/protobuf'.",
                    function_name
                );
                return FAILURE;
            }

            config->exporter_protocol = Z_STRVAL_P(value);
            config->exporter_protocol_len = Z_STRLEN_P(value);
        } else if (zend_string_equals_literal(key, "metrics_enable")) {
            if (Z_TYPE_P(value) != IS_TRUE && Z_TYPE_P(value) != IS_FALSE) {
                king_server_control_set_errorf(
                    "%s() config key 'metrics_enable' must be boolean.",
                    function_name
                );
                return FAILURE;
            }

            config->metrics_enable = zend_is_true(value);
        } else if (zend_string_equals_literal(key, "logs_enable")) {
            if (Z_TYPE_P(value) != IS_TRUE && Z_TYPE_P(value) != IS_FALSE) {
                king_server_control_set_errorf(
                    "%s() config key 'logs_enable' must be boolean.",
                    function_name
                );
                return FAILURE;
            }

            config->logs_enable = zend_is_true(value);
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

static zend_result king_server_telemetry_resolve_config(
    zval *zconfig,
    king_client_session_t *session,
    king_server_telemetry_config_t *config,
    const char *function_name
)
{
    king_cfg_t *cfg;

    king_server_telemetry_init_from_session(config, session);

    if (zconfig == NULL || Z_TYPE_P(zconfig) == IS_NULL) {
        return SUCCESS;
    }

    if (Z_TYPE_P(zconfig) == IS_ARRAY) {
        return king_server_telemetry_apply_inline_config(
            zconfig,
            config,
            function_name
        );
    }

    cfg = (king_cfg_t *) king_fetch_config(zconfig);
    if (cfg == NULL) {
        zend_argument_type_error(
            2,
            "must be null, array, a King\\Config resource, or a King\\Config object"
        );
        return FAILURE;
    }

    king_server_telemetry_init_from_cfg(config, cfg);
    return SUCCESS;
}

static zend_result king_server_telemetry_validate_config(
    const king_server_telemetry_config_t *config,
    const char *function_name
)
{
    if (!config->enable) {
        king_server_control_set_errorf(
            "%s() requires telemetry to be enabled.",
            function_name
        );
        return FAILURE;
    }

    if (config->service_name_len == 0
        || king_server_control_string_has_crlf(
            config->service_name,
            config->service_name_len
        )) {
        king_server_control_set_errorf(
            "%s() service_name must be a non-empty single-line string.",
            function_name
        );
        return FAILURE;
    }

    if (config->exporter_endpoint_len == 0
        || king_server_control_string_has_crlf(
            config->exporter_endpoint,
            config->exporter_endpoint_len
        )) {
        king_server_control_set_errorf(
            "%s() exporter_endpoint must be a non-empty single-line string.",
            function_name
        );
        return FAILURE;
    }

    if (!king_server_telemetry_protocol_is_valid(
            config->exporter_protocol,
            config->exporter_protocol_len
        )) {
        king_server_control_set_errorf(
            "%s() exporter_protocol must be 'grpc' or 'http/protobuf'.",
            function_name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static void king_server_telemetry_apply_snapshot(
    king_client_session_t *session,
    const king_server_telemetry_config_t *config
)
{
    session->server_telemetry_active = true;
    session->server_telemetry_init_count++;
    session->server_telemetry_metrics_enable = config->metrics_enable;
    session->server_telemetry_logs_enable = config->logs_enable;
    session->last_activity_at = time(NULL);

    king_server_control_set_string_bytes(
        &session->server_telemetry_service_name,
        config->service_name,
        config->service_name_len
    );
    king_server_control_set_string_bytes(
        &session->server_telemetry_exporter_endpoint,
        config->exporter_endpoint,
        config->exporter_endpoint_len
    );
    king_server_control_set_string_bytes(
        &session->server_telemetry_exporter_protocol,
        config->exporter_protocol,
        config->exporter_protocol_len
    );
}

PHP_FUNCTION(king_server_init_telemetry)
{
    zval *zsession;
    zval *zconfig;
    king_client_session_t *session;
    king_server_telemetry_config_t config;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_ZVAL(zsession)
        Z_PARAM_ZVAL(zconfig)
    ZEND_PARSE_PARAMETERS_END();

    session = king_server_control_fetch_open_session(
        zsession,
        1,
        "king_server_init_telemetry"
    );
    if (session == NULL) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    if (king_server_telemetry_resolve_config(
            zconfig,
            session,
            &config,
            "king_server_init_telemetry"
        ) != SUCCESS) {
        if (EG(exception) != NULL) {
            RETURN_THROWS();
        }

        RETURN_FALSE;
    }

    if (king_server_telemetry_validate_config(
            &config,
            "king_server_init_telemetry"
        ) != SUCCESS) {
        RETURN_FALSE;
    }

    king_server_telemetry_apply_snapshot(session, &config);

    king_set_error("");
    RETURN_TRUE;
}

void king_server_open_telemetry_add_request_metadata(
    zval *request,
    king_client_session_t *session
)
{
    zval telemetry;
    zend_string *service_name = session->server_telemetry_active
        ? session->server_telemetry_service_name
        : session->config_otel_service_name;
    zend_string *exporter_endpoint = session->server_telemetry_active
        ? session->server_telemetry_exporter_endpoint
        : session->config_otel_exporter_endpoint;
    zend_string *exporter_protocol = session->server_telemetry_active
        ? session->server_telemetry_exporter_protocol
        : session->config_otel_exporter_protocol;

    array_init(&telemetry);
    add_assoc_bool(&telemetry, "enabled", session->config_otel_enable);
    add_assoc_bool(&telemetry, "initialized", session->server_telemetry_active);
    add_assoc_str(&telemetry, "service_name", zend_string_copy(service_name));
    add_assoc_str(
        &telemetry,
        "exporter_endpoint",
        zend_string_copy(exporter_endpoint)
    );
    add_assoc_str(
        &telemetry,
        "exporter_protocol",
        zend_string_copy(exporter_protocol)
    );
    add_assoc_bool(
        &telemetry,
        "metrics_enable",
        session->server_telemetry_active
            ? session->server_telemetry_metrics_enable
            : session->config_otel_metrics_enable
    );
    add_assoc_bool(
        &telemetry,
        "logs_enable",
        session->server_telemetry_active
            ? session->server_telemetry_logs_enable
            : session->config_otel_logs_enable
    );
    add_assoc_zval(request, "telemetry", &telemetry);
}

void king_server_open_telemetry_record_response(
    king_client_session_t *session,
    const char *protocol,
    zval *response
)
{
    zval *status;
    zend_long code = 200;

    if (!session->server_telemetry_active) {
        return;
    }

    status = zend_hash_str_find(
        Z_ARRVAL_P(response),
        "status",
        sizeof("status") - 1
    );
    if (status != NULL && Z_TYPE_P(status) != IS_NULL) {
        code = zval_get_long(status);
    }

    session->server_telemetry_request_count++;
    session->server_telemetry_last_status = code;
    king_server_control_set_string_bytes(
        &session->server_telemetry_last_protocol,
        protocol,
        strlen(protocol)
    );
    session->last_activity_at = time(NULL);
}
