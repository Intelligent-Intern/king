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

static bool king_semantic_dns_listener_snapshot_path_init(void)
{
    if (king_semantic_dns_runtime.listener_state_path[0] != '\0') {
        return true;
    }

    if (snprintf(
            king_semantic_dns_runtime.listener_state_path,
            sizeof(king_semantic_dns_runtime.listener_state_path),
            "/tmp/king_semantic_dns_state/listener_state.%ld.bin",
            (long) getpid()
        ) >= (int) sizeof(king_semantic_dns_runtime.listener_state_path)) {
        king_semantic_dns_runtime.listener_state_path[0] = '\0';
        return false;
    }

    return true;
}

static int king_semantic_dns_listener_sync_snapshot(void)
{
    zval payload;
    int result;

    if (!king_semantic_dns_runtime.initialized || !king_semantic_dns_listener_snapshot_path_init()) {
        return FAILURE;
    }

    ZVAL_UNDEF(&payload);
    if (king_semantic_dns_export_state_payload(&payload) != SUCCESS) {
        return FAILURE;
    }

    result = king_semantic_dns_state_write_snapshot_file(
        king_semantic_dns_runtime.listener_state_path,
        &payload
    );
    zval_ptr_dtor(&payload);

    return result;
}

int king_semantic_dns_listener_write_runtime_snapshot(void)
{
    if (!king_semantic_dns_runtime.initialized || king_semantic_dns_runtime.listener_pid <= 0) {
        return SUCCESS;
    }

    return king_semantic_dns_listener_sync_snapshot();
}

static int king_semantic_dns_listener_load_snapshot(void)
{
    zval payload;
    int result;

    if (!king_semantic_dns_runtime.initialized || king_semantic_dns_runtime.listener_state_path[0] == '\0') {
        return FAILURE;
    }

    ZVAL_UNDEF(&payload);
    if (king_semantic_dns_state_read_snapshot_file(
            king_semantic_dns_runtime.listener_state_path,
            &payload
        ) != SUCCESS) {
        return FAILURE;
    }

    result = king_semantic_dns_import_state_payload(&payload);
    zval_ptr_dtor(&payload);

    return result;
}

static void king_semantic_dns_listener_unlink_snapshot(void)
{
    if (king_semantic_dns_runtime.listener_state_path[0] == '\0') {
        return;
    }

    (void) king_semantic_dns_state_remove_snapshot_file(king_semantic_dns_runtime.listener_state_path);
    king_semantic_dns_runtime.listener_state_path[0] = '\0';
}

static void king_semantic_dns_listener_stop(void)
{
    pid_t pid = king_semantic_dns_runtime.listener_pid;

    if (pid > 0) {
        (void) kill(pid, SIGTERM);
        (void) waitpid(pid, NULL, 0);
        king_semantic_dns_runtime.listener_pid = 0;
    }

    king_semantic_dns_listener_unlink_snapshot();
}

