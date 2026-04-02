/*
 * =========================================================================
 * FILENAME:   src/config/app_http3_websockets_webtransport/ini.c
 * PROJECT:    king
 *
 * PURPOSE:
 * php.ini registration and update callbacks for the app-protocol config
 * family. This file exposes the system-level directives for HTTP/3, Early
 * Hints, WebSocket, and WebTransport tuning and keeps the shared
 * `king_app_protocols_config` snapshot aligned with validated ini updates.
 * =========================================================================
 */

#include "include/config/app_http3_websockets_webtransport/ini.h"
#include "include/config/app_http3_websockets_webtransport/base_layer.h"

#include "php.h"
#include <ext/spl/spl_exceptions.h>
#include <zend_ini.h>
#include <zend_exceptions.h>

static ZEND_INI_MH(OnUpdateAppProtocolPositiveLong)
{
    zend_long val = ZEND_STRTOL(ZSTR_VAL(new_value), NULL, 10);

    if (val <= 0) {
        zend_throw_exception_ex(spl_ce_InvalidArgumentException, 0,
            "Invalid value provided for an application protocol directive. A positive integer is required.");
        return FAILURE;
    }

    /* This shared callback updates several directives by matching entry->name. */
    if (zend_string_equals_literal(entry->name, "king.h3_max_header_list_size")) {
        king_app_protocols_config.h3_max_header_list_size = val;
    } else if (zend_string_equals_literal(entry->name, "king.h3_qpack_max_table_capacity")) {
        king_app_protocols_config.h3_qpack_max_table_capacity = val;
    } else if (zend_string_equals_literal(entry->name, "king.h3_qpack_blocked_streams")) {
        king_app_protocols_config.h3_qpack_blocked_streams = val;
    } else if (zend_string_equals_literal(entry->name, "king.websocket_default_max_payload_size")) {
        king_app_protocols_config.websocket_default_max_payload_size = val;
    } else if (zend_string_equals_literal(entry->name, "king.websocket_default_max_queued_messages")) {
        king_app_protocols_config.websocket_default_max_queued_messages = val;
    } else if (zend_string_equals_literal(entry->name, "king.websocket_default_max_queued_bytes")) {
        king_app_protocols_config.websocket_default_max_queued_bytes = val;
    } else if (zend_string_equals_literal(entry->name, "king.websocket_default_ping_interval_ms")) {
        king_app_protocols_config.websocket_default_ping_interval_ms = val;
    } else if (zend_string_equals_literal(entry->name, "king.websocket_handshake_timeout_ms")) {
        king_app_protocols_config.websocket_handshake_timeout_ms = val;
    } else if (zend_string_equals_literal(entry->name, "king.webtransport_max_concurrent_sessions")) {
        king_app_protocols_config.webtransport_max_concurrent_sessions = val;
    } else if (zend_string_equals_literal(entry->name, "king.webtransport_max_streams_per_session")) {
        king_app_protocols_config.webtransport_max_streams_per_session = val;
    }

    return SUCCESS;
}

PHP_INI_BEGIN()
    STD_PHP_INI_ENTRY("king.http_advertise_h3_alt_svc", "1", PHP_INI_SYSTEM, OnUpdateBool,
        http_advertise_h3_alt_svc, kg_app_protocols_config_t, king_app_protocols_config)
    STD_PHP_INI_ENTRY("king.http_auto_compress", "brotli,gzip", PHP_INI_SYSTEM, OnUpdateString,
        http_auto_compress, kg_app_protocols_config_t, king_app_protocols_config)
    STD_PHP_INI_ENTRY("king.h3_max_header_list_size", "65536", PHP_INI_SYSTEM, OnUpdateAppProtocolPositiveLong,
        h3_max_header_list_size, kg_app_protocols_config_t, king_app_protocols_config)
    STD_PHP_INI_ENTRY("king.h3_qpack_max_table_capacity", "4096", PHP_INI_SYSTEM, OnUpdateAppProtocolPositiveLong,
        h3_qpack_max_table_capacity, kg_app_protocols_config_t, king_app_protocols_config)
    STD_PHP_INI_ENTRY("king.h3_qpack_blocked_streams", "100", PHP_INI_SYSTEM, OnUpdateAppProtocolPositiveLong,
        h3_qpack_blocked_streams, kg_app_protocols_config_t, king_app_protocols_config)
    STD_PHP_INI_ENTRY("king.h3_server_push_enable", "0", PHP_INI_SYSTEM, OnUpdateBool,
        h3_server_push_enable, kg_app_protocols_config_t, king_app_protocols_config)
    STD_PHP_INI_ENTRY("king.http_enable_early_hints", "1", PHP_INI_SYSTEM, OnUpdateBool,
        http_enable_early_hints, kg_app_protocols_config_t, king_app_protocols_config)
    STD_PHP_INI_ENTRY("king.websocket_default_max_payload_size", "16777216", PHP_INI_SYSTEM, OnUpdateAppProtocolPositiveLong,
        websocket_default_max_payload_size, kg_app_protocols_config_t, king_app_protocols_config)
    STD_PHP_INI_ENTRY("king.websocket_default_max_queued_messages", "64", PHP_INI_SYSTEM, OnUpdateAppProtocolPositiveLong,
        websocket_default_max_queued_messages, kg_app_protocols_config_t, king_app_protocols_config)
    STD_PHP_INI_ENTRY("king.websocket_default_max_queued_bytes", "67108864", PHP_INI_SYSTEM, OnUpdateAppProtocolPositiveLong,
        websocket_default_max_queued_bytes, kg_app_protocols_config_t, king_app_protocols_config)
    STD_PHP_INI_ENTRY("king.websocket_default_ping_interval_ms", "25000", PHP_INI_SYSTEM, OnUpdateAppProtocolPositiveLong,
        websocket_default_ping_interval_ms, kg_app_protocols_config_t, king_app_protocols_config)
    STD_PHP_INI_ENTRY("king.websocket_handshake_timeout_ms", "5000", PHP_INI_SYSTEM, OnUpdateAppProtocolPositiveLong,
        websocket_handshake_timeout_ms, kg_app_protocols_config_t, king_app_protocols_config)
    STD_PHP_INI_ENTRY("king.webtransport_enable", "1", PHP_INI_SYSTEM, OnUpdateBool,
        webtransport_enable, kg_app_protocols_config_t, king_app_protocols_config)
    STD_PHP_INI_ENTRY("king.webtransport_max_concurrent_sessions", "10000", PHP_INI_SYSTEM, OnUpdateAppProtocolPositiveLong,
        webtransport_max_concurrent_sessions, kg_app_protocols_config_t, king_app_protocols_config)
    STD_PHP_INI_ENTRY("king.webtransport_max_streams_per_session", "256", PHP_INI_SYSTEM, OnUpdateAppProtocolPositiveLong,
        webtransport_max_streams_per_session, kg_app_protocols_config_t, king_app_protocols_config)
PHP_INI_END()

extern int king_ini_module_number;

void kg_config_app_http3_websockets_webtransport_ini_register(void)
{
    zend_register_ini_entries(ini_entries, king_ini_module_number);
}

void kg_config_app_http3_websockets_webtransport_ini_unregister(void)
{
    zend_unregister_ini_entries(king_ini_module_number);
}
