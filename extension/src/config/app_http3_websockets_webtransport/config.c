/*
 * =========================================================================
 * FILENAME:   src/config/app_http3_websockets_webtransport/config.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Userland override application for the app-protocol config family. This
 * file validates the subset of HTTP/3, Early Hints, WebSocket, and
 * WebTransport keys that are allowed through the active `King\\Config`
 * snapshot path and applies them onto `king_app_protocols_config`.
 * =========================================================================
 */

#include "include/config/app_http3_websockets_webtransport/config.h"
#include "include/config/app_http3_websockets_webtransport/base_layer.h"
#include "include/king_globals.h"

#include "include/validation/config_param/validate_bool.h"
#include "include/validation/config_param/validate_positive_long.h"
#include "include/validation/config_param/validate_comma_separated_string_from_allowlist.h"

#include "php.h"
#include "zend_exceptions.h"
#include <ext/spl/spl_exceptions.h>

static int app_proto_apply_bool(zval *value, const char *param_name, bool *target)
{
    if (kg_validate_bool(value, param_name) != SUCCESS) {
        return FAILURE;
    }

    *target = zend_is_true(value);
    return SUCCESS;
}

static int app_proto_apply_positive_long(zval *value, zend_long *target)
{
    return kg_validate_positive_long(value, target);
}

int kg_config_app_http3_websockets_webtransport_apply_userland_config(zval *config_arr)
{
    zval *value;
    zend_string *key;

    if (!king_globals.is_userland_override_allowed) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Configuration override from userland is disabled by system administrator.");
        return FAILURE;
    }

    if (Z_TYPE_P(config_arr) != IS_ARRAY) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Configuration must be provided as an array.");
        return FAILURE;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(config_arr), key, value) {
        if (!key) {
            continue;
        }

        if (zend_string_equals_literal(key, "http_advertise_h3_alt_svc")) {
            if (app_proto_apply_bool(value, "http_advertise_h3_alt_svc", &king_app_protocols_config.http_advertise_h3_alt_svc) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "http_auto_compress")) {
            const char *allowed[] = {"brotli", "gzip", "none", NULL};
            if (kg_validate_comma_separated_string_from_allowlist(value, allowed,
                    &king_app_protocols_config.http_auto_compress) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "h3_max_header_list_size")) {
            if (app_proto_apply_positive_long(value, &king_app_protocols_config.h3_max_header_list_size) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "h3_qpack_max_table_capacity")) {
            if (app_proto_apply_positive_long(value, &king_app_protocols_config.h3_qpack_max_table_capacity) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "h3_qpack_blocked_streams")) {
            if (app_proto_apply_positive_long(value, &king_app_protocols_config.h3_qpack_blocked_streams) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "h3_server_push_enable")) {
            if (app_proto_apply_bool(value, "h3_server_push_enable", &king_app_protocols_config.h3_server_push_enable) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "http_enable_early_hints")) {
            if (app_proto_apply_bool(value, "http_enable_early_hints", &king_app_protocols_config.http_enable_early_hints) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "websocket_default_max_payload_size")) {
            if (app_proto_apply_positive_long(value, &king_app_protocols_config.websocket_default_max_payload_size) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "websocket_default_max_queued_messages")) {
            if (app_proto_apply_positive_long(value, &king_app_protocols_config.websocket_default_max_queued_messages) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "websocket_default_max_queued_bytes")) {
            if (app_proto_apply_positive_long(value, &king_app_protocols_config.websocket_default_max_queued_bytes) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "websocket_default_ping_interval_ms")) {
            if (app_proto_apply_positive_long(value, &king_app_protocols_config.websocket_default_ping_interval_ms) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "websocket_handshake_timeout_ms")) {
            if (app_proto_apply_positive_long(value, &king_app_protocols_config.websocket_handshake_timeout_ms) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "webtransport_enable")) {
            if (app_proto_apply_bool(value, "webtransport_enable", &king_app_protocols_config.webtransport_enable) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "webtransport_max_concurrent_sessions")) {
            if (app_proto_apply_positive_long(value, &king_app_protocols_config.webtransport_max_concurrent_sessions) != SUCCESS) {
                return FAILURE;
            }
        } else if (zend_string_equals_literal(key, "webtransport_max_streams_per_session")) {
            if (app_proto_apply_positive_long(value, &king_app_protocols_config.webtransport_max_streams_per_session) != SUCCESS) {
                return FAILURE;
            }
        }
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}
