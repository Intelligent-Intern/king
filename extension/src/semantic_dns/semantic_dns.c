/*
 * =========================================================================
 * FILENAME:   src/semantic_dns/semantic_dns.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Provides the first active Semantic-DNS core/server-state slice in the
 * current runtime. The richer registry/read-model runtime still lives under
 * src/core/introspection/semantic_dns/*.inc; this file owns the local
 * config-backed init/start lifecycle and the internal C helper surface that
 * later mother-node and routing leaves will build on.
 * =========================================================================
 */

#include "php_king.h"
#include "include/config/smart_dns/base_layer.h"
#include "include/semantic_dns/semantic_dns.h"

#include <arpa/inet.h>
#include <stdio.h>
#include <string.h>
#include <time.h>

#include "semantic_dns/semantic_dns_internal.h"

king_semantic_dns_runtime_state king_semantic_dns_runtime;

static void king_semantic_dns_config_clear(king_semantic_dns_config_t *config)
{
    if (config == NULL) {
        return;
    }

    if (Z_TYPE(config->routing_policies) != IS_UNDEF) {
        zval_ptr_dtor(&config->routing_policies);
        ZVAL_UNDEF(&config->routing_policies);
    }

    if (config->mother_nodes != NULL) {
        pefree(config->mother_nodes, 1);
        config->mother_nodes = NULL;
        config->mother_node_count = 0;
    }
}

static void king_semantic_dns_config_assign_defaults(king_semantic_dns_config_t *config)
{
    memset(config, 0, sizeof(*config));
    ZVAL_UNDEF(&config->routing_policies);

    config->enabled = king_smart_dns_config.server_enable ? 1 : 0;
    config->dns_port = (king_smart_dns_config.server_port > 0
        && king_smart_dns_config.server_port <= 65535)
        ? (uint16_t) king_smart_dns_config.server_port
        : 5353;
    config->server_enable_tcp = 0;
    config->health_check_interval_ms = 30000;
    config->service_ttl_seconds = king_smart_dns_config.default_record_ttl_sec > 0
        ? (uint32_t) king_smart_dns_config.default_record_ttl_sec
        : 300;
    config->max_services_per_type = king_smart_dns_config.service_discovery_max_ips_per_response > 0
        ? (uint32_t) king_smart_dns_config.service_discovery_max_ips_per_response
        : 8;
    config->semantic_mode_enable = king_smart_dns_config.semantic_mode_enable ? 1 : 0;
    config->mothernode_sync_interval_sec = 0;
    config->service_discovery_max_ips_per_response = config->max_services_per_type;

    if (king_smart_dns_config.server_bind_host != NULL
        && king_smart_dns_config.server_bind_host[0] != '\0') {
        strncpy(
            config->bind_address,
            king_smart_dns_config.server_bind_host,
            sizeof(config->bind_address) - 1
        );
        config->bind_address[sizeof(config->bind_address) - 1] = '\0';
    } else {
        strncpy(config->bind_address, "0.0.0.0", sizeof(config->bind_address) - 1);
    }

    if (king_smart_dns_config.mothernode_uri != NULL
        && king_smart_dns_config.mothernode_uri[0] != '\0') {
        strncpy(
            config->mothernode_uri,
            king_smart_dns_config.mothernode_uri,
            sizeof(config->mothernode_uri) - 1
        );
        config->mothernode_uri[sizeof(config->mothernode_uri) - 1] = '\0';
    }
}

static void king_semantic_dns_config_copy(
    king_semantic_dns_config_t *target,
    const king_semantic_dns_config_t *source
)
{
    memset(target, 0, sizeof(*target));
    ZVAL_UNDEF(&target->routing_policies);

    target->enabled = source->enabled;
    target->dns_port = source->dns_port;
    target->server_enable_tcp = source->server_enable_tcp;
    target->health_check_interval_ms = source->health_check_interval_ms;
    target->service_ttl_seconds = source->service_ttl_seconds;
    target->max_services_per_type = source->max_services_per_type;
    target->semantic_mode_enable = source->semantic_mode_enable;
    target->mothernode_sync_interval_sec = source->mothernode_sync_interval_sec;
    target->service_discovery_max_ips_per_response = source->service_discovery_max_ips_per_response;
    target->mother_nodes = NULL;
    target->mother_node_count = 0;

    strncpy(target->bind_address, source->bind_address, sizeof(target->bind_address) - 1);
    target->bind_address[sizeof(target->bind_address) - 1] = '\0';
    strncpy(target->mothernode_uri, source->mothernode_uri, sizeof(target->mothernode_uri) - 1);
    target->mothernode_uri[sizeof(target->mothernode_uri) - 1] = '\0';

}

static void king_semantic_dns_runtime_reset(void)
{
    king_semantic_dns_config_clear(&king_semantic_dns_runtime.config);
    memset(&king_semantic_dns_runtime, 0, sizeof(king_semantic_dns_runtime));
    ZVAL_UNDEF(&king_semantic_dns_runtime.config.routing_policies);
}

static zval *king_semantic_dns_find_option(
    zval *config,
    const char *primary_name,
    size_t primary_name_len,
    const char *alias_name,
    size_t alias_name_len
)
{
    zval *value_zv;

    value_zv = zend_hash_str_find(Z_ARRVAL_P(config), primary_name, primary_name_len);
    if (value_zv != NULL || alias_name == NULL) {
        return value_zv;
    }

    return zend_hash_str_find(Z_ARRVAL_P(config), alias_name, alias_name_len);
}

static bool king_semantic_dns_require_bool_option(
    zval *config,
    const char *primary_name,
    size_t primary_name_len,
    const char *alias_name,
    size_t alias_name_len,
    zend_bool *target
)
{
    zval *value_zv = king_semantic_dns_find_option(
        config,
        primary_name,
        primary_name_len,
        alias_name,
        alias_name_len
    );

    if (value_zv == NULL) {
        return true;
    }

    if (Z_TYPE_P(value_zv) != IS_TRUE && Z_TYPE_P(value_zv) != IS_FALSE) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Semantic-DNS init option '%s' must be of type bool.",
            primary_name
        );
        return false;
    }

    *target = (Z_TYPE_P(value_zv) == IS_TRUE);
    return true;
}

static bool king_semantic_dns_require_positive_long_option(
    zval *config,
    const char *primary_name,
    size_t primary_name_len,
    const char *alias_name,
    size_t alias_name_len,
    uint32_t *target,
    uint32_t max_value
)
{
    zval *value_zv = king_semantic_dns_find_option(
        config,
        primary_name,
        primary_name_len,
        alias_name,
        alias_name_len
    );

    if (value_zv == NULL) {
        return true;
    }

    if (Z_TYPE_P(value_zv) != IS_LONG) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Semantic-DNS init option '%s' must be of type int.",
            primary_name
        );
        return false;
    }

    if (Z_LVAL_P(value_zv) <= 0 || (max_value > 0 && Z_LVAL_P(value_zv) > (zend_long) max_value)) {
        if (max_value > 0) {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Semantic-DNS init option '%s' must be between 1 and %u.",
                primary_name,
                max_value
            );
        } else {
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Semantic-DNS init option '%s' must be greater than 0.",
                primary_name
            );
        }
        return false;
    }

    *target = (uint32_t) Z_LVAL_P(value_zv);
    return true;
}

static bool king_semantic_dns_require_port_option(
    zval *config,
    const char *primary_name,
    size_t primary_name_len,
    const char *alias_name,
    size_t alias_name_len,
    uint16_t *target
)
{
    uint32_t parsed_value = 0;

    if (!king_semantic_dns_require_positive_long_option(
            config,
            primary_name,
            primary_name_len,
            alias_name,
            alias_name_len,
            &parsed_value,
            65535
        )) {
        return false;
    }

    if (parsed_value == 0) {
        return true;
    }

    *target = (uint16_t) parsed_value;
    return true;
}

static bool king_semantic_dns_require_non_empty_string_option(
    zval *config,
    const char *primary_name,
    size_t primary_name_len,
    const char *alias_name,
    size_t alias_name_len,
    char *target,
    size_t target_size
)
{
    zval *value_zv = king_semantic_dns_find_option(
        config,
        primary_name,
        primary_name_len,
        alias_name,
        alias_name_len
    );

    if (value_zv == NULL) {
        return true;
    }

    if (Z_TYPE_P(value_zv) != IS_STRING) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Semantic-DNS init option '%s' must be of type string.",
            primary_name
        );
        return false;
    }

    if (Z_STRLEN_P(value_zv) == 0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Semantic-DNS init option '%s' cannot be empty.",
            primary_name
        );
        return false;
    }

    strncpy(target, Z_STRVAL_P(value_zv), target_size - 1);
    target[target_size - 1] = '\0';
    return true;
}

static bool king_semantic_dns_reject_unsupported_option(
    zval *config,
    const char *option_name,
    size_t option_name_len
)
{
    if (zend_hash_str_exists(Z_ARRVAL_P(config), option_name, option_name_len)) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Semantic-DNS v1 does not support init option '%s'.",
            option_name
        );
        return false;
    }

    return true;
}

static bool king_semantic_dns_parse_init_config(
    zval *config,
    king_semantic_dns_config_t *parsed
)
{
    zval *value_zv;

    king_semantic_dns_config_assign_defaults(parsed);

    if (!king_semantic_dns_reject_unsupported_option(
            config,
            "server_enable_tcp",
            sizeof("server_enable_tcp") - 1
        )
        || !king_semantic_dns_reject_unsupported_option(
            config,
            "health_check_interval_ms",
            sizeof("health_check_interval_ms") - 1
        )
        || !king_semantic_dns_reject_unsupported_option(
            config,
            "mothernode_sync_interval_sec",
            sizeof("mothernode_sync_interval_sec") - 1
        )) {
        king_semantic_dns_config_clear(parsed);
        return false;
    }

    if (!king_semantic_dns_require_bool_option(
            config,
            "enabled",
            sizeof("enabled") - 1,
            "server_enable",
            sizeof("server_enable") - 1,
            &parsed->enabled
        )
        || !king_semantic_dns_require_port_option(
            config,
            "dns_port",
            sizeof("dns_port") - 1,
            "server_port",
            sizeof("server_port") - 1,
            &parsed->dns_port
        )
        || !king_semantic_dns_require_non_empty_string_option(
            config,
            "bind_address",
            sizeof("bind_address") - 1,
            "server_bind_host",
            sizeof("server_bind_host") - 1,
            parsed->bind_address,
            sizeof(parsed->bind_address)
        )
        || !king_semantic_dns_require_positive_long_option(
            config,
            "default_record_ttl_sec",
            sizeof("default_record_ttl_sec") - 1,
            "service_ttl_seconds",
            sizeof("service_ttl_seconds") - 1,
            &parsed->service_ttl_seconds,
            0
        )
        || !king_semantic_dns_require_positive_long_option(
            config,
            "service_discovery_max_ips_per_response",
            sizeof("service_discovery_max_ips_per_response") - 1,
            "max_services_per_type",
            sizeof("max_services_per_type") - 1,
            &parsed->service_discovery_max_ips_per_response,
            0
        )
        || !king_semantic_dns_require_bool_option(
            config,
            "semantic_mode_enable",
            sizeof("semantic_mode_enable") - 1,
            NULL,
            0,
            &parsed->semantic_mode_enable
        )
        || !king_semantic_dns_require_non_empty_string_option(
            config,
            "mothernode_uri",
            sizeof("mothernode_uri") - 1,
            NULL,
            0,
            parsed->mothernode_uri,
            sizeof(parsed->mothernode_uri)
        )) {
        king_semantic_dns_config_clear(parsed);
        return false;
    }

    parsed->max_services_per_type = parsed->service_discovery_max_ips_per_response;

    value_zv = zend_hash_str_find(
        Z_ARRVAL_P(config),
        "routing_policies",
        sizeof("routing_policies") - 1
    );
    if (value_zv != NULL) {
        if (Z_TYPE_P(value_zv) != IS_ARRAY) {
            king_semantic_dns_config_clear(parsed);
            zend_throw_exception_ex(
                king_ce_validation_exception,
                0,
                "Semantic-DNS init option 'routing_policies' must be of type array."
            );
            return false;
        }

        /* The current core/server-state slice validates routing hints but does
         * not persist request-owned zvals across module lifetime yet. */
    }

    return true;
}

static bool king_semantic_dns_runtime_require_initialized(const char *function_name)
{
    if (king_semantic_dns_runtime.initialized) {
        return true;
    }

    zend_throw_exception_ex(
        king_ce_runtime_exception,
        0,
        "%s() requires prior king_semantic_dns_init().",
        function_name
    );
    return false;
}

#include "include/king_globals.h"

PHP_FUNCTION(king_semantic_dns_init)
{
    zval *config;
    king_semantic_dns_config_t parsed;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY(config)
    ZEND_PARSE_PARAMETERS_END();

    if (!king_globals.is_userland_override_allowed && zend_hash_num_elements(Z_ARRVAL_P(config)) > 0) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Configuration override is disabled by system policy."
        );
        RETURN_THROWS();
    }

    if (!king_semantic_dns_parse_init_config(config, &parsed)) {
        RETURN_THROWS();
    }

    if (king_semantic_dns_init_system(&parsed) != SUCCESS) {
        king_semantic_dns_config_clear(&parsed);
        if (EG(exception) == NULL) {
            zend_throw_exception_ex(
                king_ce_system_exception,
                0,
                "Semantic-DNS core initialization failed."
            );
        }
        RETURN_THROWS();
    }

    king_semantic_dns_config_clear(&parsed);
    RETURN_TRUE;
}

PHP_FUNCTION(king_semantic_dns_start_server)
{
    ZEND_PARSE_PARAMETERS_NONE();

    if (!king_semantic_dns_runtime_require_initialized("king_semantic_dns_start_server")) {
        RETURN_THROWS();
    }

    if (!king_semantic_dns_runtime.config.enabled) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "king_semantic_dns_start_server() semantic DNS is disabled in the active runtime config."
        );
        RETURN_THROWS();
    }

    if (king_semantic_dns_runtime.server_active) {
        RETURN_TRUE;
    }

    king_semantic_dns_runtime.server_active = true;
    king_semantic_dns_runtime.server_started_at = time(NULL);
    king_semantic_dns_runtime.start_count++;

    if (king_semantic_dns_runtime.config.semantic_mode_enable) {
        (void) king_semantic_dns_state_load();
        (void) king_semantic_dns_discover_mother_nodes();
        (void) king_semantic_dns_sync_with_mother_nodes();
    }

    king_semantic_dns_health_check_services();
    RETURN_TRUE;
}

int king_semantic_dns_init_system(king_semantic_dns_config_t *config)
{
    king_semantic_dns_runtime_state new_state;

    if (config == NULL || config->dns_port == 0 || config->bind_address[0] == '\0') {
        return FAILURE;
    }

    memset(&new_state, 0, sizeof(new_state));
    ZVAL_UNDEF(&new_state.config.routing_policies);
    king_semantic_dns_config_copy(&new_state.config, config);
    new_state.initialized = true;
    new_state.server_active = false;
    new_state.initialized_at = time(NULL);

    king_semantic_dns_runtime_reset();
    king_semantic_dns_runtime = new_state;

    return SUCCESS;
}

void king_semantic_dns_shutdown_system(void)
{
    king_semantic_dns_state_save();
    king_semantic_dns_runtime_reset();
}

int king_semantic_dns_process_query(
    const char *query,
    char *response,
    size_t response_size
)
{
    int written;

    if (!king_semantic_dns_runtime.initialized
        || query == NULL
        || response == NULL
        || response_size == 0) {
        return FAILURE;
    }

    if (strncmp(query, "discover:", sizeof("discover:") - 1) == 0) {
        written = snprintf(
            response,
            response_size,
            "discover:%s:max=%u",
            query + (sizeof("discover:") - 1),
            king_semantic_dns_runtime.config.service_discovery_max_ips_per_response
        );
    } else if (strcmp(query, "status") == 0) {
        written = snprintf(
            response,
            response_size,
            "enabled=%d;active=%d;bind=%s;port=%u",
            king_semantic_dns_runtime.config.enabled ? 1 : 0,
            king_semantic_dns_runtime.server_active ? 1 : 0,
            king_semantic_dns_runtime.config.bind_address,
            king_semantic_dns_runtime.config.dns_port
        );
    } else {
        written = snprintf(
            response,
            response_size,
            "active=%d;semantic=%d;ttl=%u",
            king_semantic_dns_runtime.server_active ? 1 : 0,
            king_semantic_dns_runtime.config.semantic_mode_enable ? 1 : 0,
            king_semantic_dns_runtime.config.service_ttl_seconds
        );
    }

    if (written < 0 || (size_t) written >= response_size) {
        if (response_size > 0) {
            response[response_size - 1] = '\0';
        }
        return FAILURE;
    }

    king_semantic_dns_runtime.processed_query_count++;
    return SUCCESS;
}


void king_semantic_dns_health_check_services(void)
{
    if (!king_semantic_dns_runtime.initialized) {
        return;
    }
}

const char *king_service_type_to_string(king_service_type_t type)
{
    switch (type) {
        case KING_SERVICE_TYPE_HTTP_SERVER:
            return "http_server";
        case KING_SERVICE_TYPE_MCP_AGENT:
            return "mcp_agent";
        case KING_SERVICE_TYPE_PIPELINE_ORCHESTRATOR:
            return "pipeline_orchestrator";
        case KING_SERVICE_TYPE_CACHE_NODE:
            return "cache_node";
        case KING_SERVICE_TYPE_DATABASE:
            return "database";
        case KING_SERVICE_TYPE_AI_MODEL:
            return "ai_model";
        case KING_SERVICE_TYPE_LOAD_BALANCER:
            return "load_balancer";
        case KING_SERVICE_TYPE_MOTHER_NODE:
            return "mother_node";
        default:
            return "unknown";
    }
}

const char *king_service_status_to_string(king_service_status_t status)
{
    switch (status) {
        case KING_SERVICE_STATUS_HEALTHY:
            return "healthy";
        case KING_SERVICE_STATUS_DEGRADED:
            return "degraded";
        case KING_SERVICE_STATUS_UNHEALTHY:
            return "unhealthy";
        case KING_SERVICE_STATUS_MAINTENANCE:
            return "maintenance";
        case KING_SERVICE_STATUS_UNKNOWN:
        default:
            return "unknown";
    }
}
