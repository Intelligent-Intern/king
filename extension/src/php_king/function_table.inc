/*
 * Root function table for the King extension. Maps the public procedural
 * surface onto the shared arginfo blocks declared in this module.
 */

static const zend_function_entry king_functions[] = {
    PHP_FE(king_version, arginfo_king_no_args)
    PHP_FE(king_health, arginfo_king_no_args)
    PHP_FE(king_new_config, arginfo_king_new_config)
#include "../awaitable/function_entries.inc"
#include "../client/function_entries.inc"
#include "../db_ingest/function_entry.inc"

    PHP_FE(king_http1_server_listen, arginfo_king_server_listen)
    PHP_FE(king_http1_server_listen_once, arginfo_king_server_listen)
    PHP_FE(king_http2_server_listen, arginfo_king_server_listen)
    PHP_FE(king_http2_server_listen_once, arginfo_king_server_listen)
    PHP_FE(king_http3_server_listen, arginfo_king_server_listen)
    PHP_FE(king_http3_server_listen_once, arginfo_king_server_listen)
    PHP_FE(king_server_listen, arginfo_king_server_listen)
    PHP_FE(king_server_on_cancel, arginfo_king_server_on_cancel)
    PHP_FE(king_server_send_early_hints, arginfo_king_server_send_early_hints)
    PHP_FE(king_server_upgrade_to_websocket, arginfo_king_server_upgrade_to_websocket)
#include "../media/function_entries.inc"
    PHP_FE(king_server_reload_tls_config, arginfo_king_server_reload_tls_config)
    PHP_FE(king_server_init_telemetry, arginfo_king_server_init_telemetry)
    PHP_FE(king_session_get_peer_cert_subject, arginfo_king_session_capability)
    PHP_FE(king_session_close_server_initiated, arginfo_king_session_close_server_initiated)
    PHP_FE(king_admin_api_listen, arginfo_king_admin_api_listen)

#include "../iibin/function_entries.inc"

#include "../mcp/php_binding/function_entries.inc"

#include "../pipeline_orchestrator/function_entries.inc"

#include "../semantic_dns/function_entries.inc"

#include "../object_store/function_entries.inc"

#include "../xslt/function_entries.inc"
#include "../inference/function_entries.inc"

#include "../telemetry/function_entries.inc"

#include "../autoscaling/function_entries.inc"

#include "../integration/function_entries.inc"

    PHP_FE_END
};
