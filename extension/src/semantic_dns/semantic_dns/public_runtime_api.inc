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

    if (king_semantic_dns_runtime.config.semantic_mode_enable) {
        (void) king_semantic_dns_state_load();
        (void) king_semantic_dns_discover_mother_nodes();
        (void) king_semantic_dns_sync_with_mother_nodes();
    }

    king_semantic_dns_health_check_services();

    if (king_semantic_dns_listener_start() != SUCCESS) {
        king_semantic_dns_runtime.server_active = false;
        king_semantic_dns_runtime.server_started_at = 0;
        king_semantic_dns_listener_stop();
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "king_semantic_dns_start_server() could not bind the UDP DNS listener to %s:%u.",
            king_semantic_dns_runtime.config.bind_address,
            king_semantic_dns_runtime.config.dns_port
        );
        RETURN_THROWS();
    }

    king_semantic_dns_runtime.start_count++;
    RETURN_TRUE;
}

PHP_FUNCTION(king_semantic_dns_query)
{
    zend_string *query;
    zend_long max_response_bytes = 256;
    char *response;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STR(query)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(max_response_bytes)
    ZEND_PARSE_PARAMETERS_END();

    if (!king_semantic_dns_runtime_require_initialized("king_semantic_dns_query")) {
        RETURN_THROWS();
    }

    if (ZSTR_LEN(query) == 0) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "king_semantic_dns_query() requires a non-empty query."
        );
        RETURN_THROWS();
    }

    if (max_response_bytes < 1 || max_response_bytes > 4096) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "king_semantic_dns_query() 'max_response_bytes' must be between 1 and 4096."
        );
        RETURN_THROWS();
    }

    response = emalloc((size_t) max_response_bytes);
    if (king_semantic_dns_process_query(ZSTR_VAL(query), response, (size_t) max_response_bytes) != SUCCESS) {
        efree(response);
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "king_semantic_dns_query() could not produce a full response within %ld bytes.",
            max_response_bytes
        );
        RETURN_THROWS();
    }

    RETVAL_STRING(response);
    efree(response);
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

    king_semantic_dns_listener_stop();
    king_semantic_dns_runtime_reset();
    king_semantic_dns_runtime = new_state;

    return SUCCESS;
}

void king_semantic_dns_shutdown_system(void)
{
    king_semantic_dns_listener_stop();
    king_semantic_dns_runtime_reset();
}

void king_semantic_dns_request_shutdown(void)
{
    int lock_fd = -1;

    if (!king_semantic_dns_runtime.initialized) {
        king_semantic_dns_listener_stop();
        return;
    }

    if (!king_semantic_dns_runtime.config.semantic_mode_enable) {
        king_semantic_dns_listener_stop();
        return;
    }

    if (king_semantic_dns_state_has_regular_snapshot()) {
        king_semantic_dns_listener_stop();
        return;
    }

    if (king_semantic_dns_state_transaction_begin(&lock_fd) != SUCCESS) {
        king_semantic_dns_listener_stop();
        return;
    }

    (void) king_semantic_dns_state_persist_locked();
    king_semantic_dns_state_transaction_end(lock_fd);
    king_semantic_dns_listener_stop();
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

    king_semantic_dns_refresh_live_service_signals();
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
