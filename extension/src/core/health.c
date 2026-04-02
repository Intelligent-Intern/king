/*
 * =========================================================================
 * FILENAME:   src/core/health.c
 * PROJECT:    king
 * AUTHOR:     Jochen Schultz <jschultz@php.net>
 *
 * PURPOSE:
 * Implements king_health(). The function still serves as a basic runtime
 * integrity probe, but it now also exports the current active-runtime
 * inventory and the current config-override policy gate for the loaded
 * extension build.
 * =========================================================================
 */

#include "php.h"
#include "php_king.h"
#include "include/king_globals.h"
#include <unistd.h>

static const char *const king_active_runtime_names[] = {
    "config",
    "client_session_runtime",
    "client_tls_runtime",
    "tls_ticket_ring",
    "client_http1_runtime",
    "client_http2_runtime",
    "client_http3_runtime",
    "client_websocket_runtime",
    "server_session_runtime",
    "server_index_runtime",
    "server_http1_runtime",
    "server_http2_runtime",
    "server_http3_runtime",
    "server_cancel_runtime",
    "server_early_hints_runtime",
    "server_websocket_upgrade_runtime",
    "server_admin_api_runtime",
    "server_tls_runtime",
    "server_cors_runtime",
    "server_open_telemetry_runtime",
    "iibin_proto",
    "semantic_dns_registry",
    "semantic_dns_server_runtime",
    "object_store_registry",
    "cdn_cache_registry",
    "mcp_runtime",
    "pipeline_orchestrator_runtime",
    "telemetry_runtime",
    "autoscaling_runtime",
    "system_integration_runtime"
};

#define KING_ACTIVE_RUNTIME_COUNT \
    ((size_t) (sizeof(king_active_runtime_names) / sizeof(king_active_runtime_names[0])))

static void king_add_string_list(
    zval *target,
    const char *const *names,
    size_t count
)
{
    size_t i;

    array_init(target);

    for (i = 0; i < count; ++i) {
        add_next_index_string(target, (char *) names[i]);
    }
}

void king_add_runtime_surface(zval *target)
{
    zval active_runtimes;
    zval stubbed_api_groups;

    add_assoc_long(target, "active_runtime_count", (zend_long) KING_ACTIVE_RUNTIME_COUNT);
    king_add_string_list(&active_runtimes, king_active_runtime_names, KING_ACTIVE_RUNTIME_COUNT);
    add_assoc_zval(target, "active_runtimes", &active_runtimes);

    add_assoc_long(target, "stubbed_api_group_count", 0);
    array_init(&stubbed_api_groups);
    add_assoc_zval(target, "stubbed_api_groups", &stubbed_api_groups);
}

const char *king_get_active_runtime_summary(void)
{
    return "config, client_session_runtime, client_tls_runtime, "
        "tls_ticket_ring, client_http1_runtime, client_http2_runtime, "
        "client_http3_runtime, client_websocket_runtime, "
        "server_session_runtime, server_index_runtime, "
        "server_http1_runtime, server_http2_runtime, "
        "server_http3_runtime, server_cancel_runtime, "
        "server_early_hints_runtime, server_websocket_upgrade_runtime, "
        "server_admin_api_runtime, server_tls_runtime, "
        "server_cors_runtime, server_open_telemetry_runtime, "
        "iibin_proto, semantic_dns_registry, semantic_dns_server_runtime, "
        "object_store_registry, cdn_cache_registry, mcp_runtime, "
        "pipeline_orchestrator_runtime, telemetry_runtime, "
        "autoscaling_runtime, system_integration_runtime";
}

const char *king_get_stubbed_api_summary(void)
{
    return "none";
}

/*
 * PHP_FUNCTION(king_health)
 *
 * PHP signature: king_health(): array
 *
 * Returns:
 *   [
 *     'status'           => 'ok',
 *     'build'            => 'v1',
 *     'version'          => PHP_KING_VERSION,
 *     'php_version'      => '8.4.x',
 *     'pid'              => 12345,
 *     'config_override_allowed' => false, // king_globals.is_userland_override_allowed
 *     'active_runtime_count' => 30,
 *     'active_runtimes'  => [...],
 *     'stubbed_api_group_count' => 0,
 *     'stubbed_api_groups' => [...],
 *   ]
 */
PHP_FUNCTION(king_health)
{
    ZEND_PARSE_PARAMETERS_NONE();

    array_init(return_value);

    add_assoc_string(return_value, "status",  "ok");
    add_assoc_string(return_value, "build",   "v1");
    add_assoc_string(return_value, "version", PHP_KING_VERSION);
    add_assoc_string(return_value, "php_version", PHP_VERSION);
    add_assoc_long(return_value,   "pid",     (zend_long)getpid());
    add_assoc_bool(return_value,   "config_override_allowed",
                   king_globals.is_userland_override_allowed ? 1 : 0);
    king_add_runtime_surface(return_value);
}
