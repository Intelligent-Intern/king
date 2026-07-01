/*
 * Arginfo for the procedural server/listener/control surface.
 * The declarations are consumed through include/server/arginfo/index.h.
 */

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_server_listen, 0, 0, 4)
    ZEND_ARG_TYPE_INFO(0, host, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, port, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, config, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, handler, IS_CALLABLE, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_server_on_cancel, 0, 0, 3)
    ZEND_ARG_TYPE_INFO(0, session, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, stream_id, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, handler, IS_CALLABLE, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_server_send_early_hints, 0, 0, 3)
    ZEND_ARG_TYPE_INFO(0, session, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, stream_id, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, hints, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_server_upgrade_to_websocket, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, session, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, stream_id, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_server_reload_tls_config, 0, 0, 3)
    ZEND_ARG_TYPE_INFO(0, session, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, cert_file_path, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, key_file_path, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_server_init_telemetry, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, session, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, config, IS_MIXED, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_session_capability, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, session, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, capability, IS_LONG, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_session_close_server_initiated, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, session, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, capability, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, error_code, IS_LONG, 0, "0")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, reason, IS_STRING, 0, "\"\"")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_king_admin_api_listen, 0, 0, 2)
    ZEND_ARG_TYPE_INFO(0, target_server, IS_MIXED, 0)
    ZEND_ARG_TYPE_INFO(0, config, IS_MIXED, 0)
ZEND_END_ARG_INFO()
