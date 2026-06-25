static char *king_autoscaling_strdup_persistent(const char *value)
{
    return pestrdup(value != NULL ? value : "", 1);
}

static void king_autoscaling_reset_message(char *buffer, size_t length)
{
    if (buffer == NULL || length == 0) {
        return;
    }

    buffer[0] = '\0';
}

static void king_autoscaling_set_runtime_string(char *buffer, size_t length, const char *value)
{
    if (buffer == NULL || length == 0) {
        return;
    }

    snprintf(buffer, length, "%s", value != NULL ? value : "");
}

static zend_bool king_autoscaling_finalize_node_update(const char *action_kind);

static void king_autoscaling_free_runtime_config(kg_cloud_autoscale_config_t *config)
{
    if (config == NULL) {
        return;
    }

    pefree(config->provider, 1);
    pefree(config->region, 1);
    pefree(config->credentials_path, 1);
    pefree(config->api_endpoint, 1);
    pefree(config->state_path, 1);
    pefree(config->server_name_prefix, 1);
    pefree(config->bootstrap_user_data, 1);
    pefree(config->firewall_ids, 1);
    pefree(config->placement_group_id, 1);
    pefree(config->prepared_release_url, 1);
    pefree(config->join_endpoint, 1);
    pefree(config->hetzner_api_token, 1);
    pefree(config->hetzner_budget_path, 1);
    pefree(config->scale_up_policy, 1);
    pefree(config->instance_type, 1);
    pefree(config->instance_image_id, 1);
    pefree(config->network_config, 1);
    pefree(config->instance_tags, 1);

    memset(config, 0, sizeof(*config));
}

static void king_autoscaling_reset_budget_runtime_state(void)
{
    king_autoscaling_runtime.spend_status = KING_AUTOSCALING_BUDGET_STATUS_DISABLED;
    king_autoscaling_runtime.quota_status = KING_AUTOSCALING_BUDGET_STATUS_DISABLED;
    king_autoscaling_runtime.spend_usage_percent = -1;
    king_autoscaling_runtime.quota_usage_percent = -1;
    king_autoscaling_runtime.budget_probe_error[0] = '\0';
}

static void king_autoscaling_copy_runtime_config(
    kg_cloud_autoscale_config_t *target,
    const kg_cloud_autoscale_config_t *source)
{
    memset(target, 0, sizeof(*target));

    target->provider = king_autoscaling_strdup_persistent(source->provider);
    target->region = king_autoscaling_strdup_persistent(source->region);
    target->credentials_path = king_autoscaling_strdup_persistent(source->credentials_path);
    target->api_endpoint = king_autoscaling_strdup_persistent(source->api_endpoint);
    target->state_path = king_autoscaling_strdup_persistent(source->state_path);
    target->server_name_prefix = king_autoscaling_strdup_persistent(source->server_name_prefix);
    target->bootstrap_user_data = king_autoscaling_strdup_persistent(source->bootstrap_user_data);
    target->firewall_ids = king_autoscaling_strdup_persistent(source->firewall_ids);
    target->placement_group_id = king_autoscaling_strdup_persistent(source->placement_group_id);
    target->prepared_release_url = king_autoscaling_strdup_persistent(source->prepared_release_url);
    target->join_endpoint = king_autoscaling_strdup_persistent(source->join_endpoint);
    target->hetzner_api_token = king_autoscaling_strdup_persistent(source->hetzner_api_token);
    target->hetzner_budget_path = king_autoscaling_strdup_persistent(source->hetzner_budget_path);
    target->scale_up_policy = king_autoscaling_strdup_persistent(source->scale_up_policy);
    target->instance_type = king_autoscaling_strdup_persistent(source->instance_type);
    target->instance_image_id = king_autoscaling_strdup_persistent(source->instance_image_id);
    target->network_config = king_autoscaling_strdup_persistent(source->network_config);
    target->instance_tags = king_autoscaling_strdup_persistent(source->instance_tags);

    target->min_nodes = source->min_nodes;
    target->max_nodes = source->max_nodes;
    target->max_scale_step = source->max_scale_step;
    target->scale_up_cpu_threshold_percent = source->scale_up_cpu_threshold_percent;
    target->scale_down_cpu_threshold_percent = source->scale_down_cpu_threshold_percent;
    target->spend_warning_threshold_percent = source->spend_warning_threshold_percent;
    target->spend_hard_limit_percent = source->spend_hard_limit_percent;
    target->quota_warning_threshold_percent = source->quota_warning_threshold_percent;
    target->quota_hard_limit_percent = source->quota_hard_limit_percent;
    target->cooldown_period_sec = source->cooldown_period_sec;
    target->idle_node_timeout_sec = source->idle_node_timeout_sec;
}

static void king_autoscaling_clear_managed_nodes(void)
{
    if (king_autoscaling_runtime.managed_nodes != NULL) {
        pefree(king_autoscaling_runtime.managed_nodes, 1);
        king_autoscaling_runtime.managed_nodes = NULL;
    }

    king_autoscaling_runtime.managed_node_count = 0;
    king_autoscaling_runtime.managed_node_capacity = 0;
}

static size_t king_autoscaling_runtime_count_persisted_live_nodes(void)
{
    size_t index;
    size_t live_nodes = 0;

    for (index = 0; index < king_autoscaling_runtime.managed_node_count; index++) {
        king_autoscaling_managed_node_t *node = &king_autoscaling_runtime.managed_nodes[index];

        if (
            node->lifecycle_state != KING_AUTOSCALING_NODE_DELETED
            && node->deleted_at == 0
        ) {
            live_nodes++;
        }
    }

    return live_nodes;
}

static int king_autoscaling_runtime_ensure_capacity(size_t needed)
{
    size_t next_capacity;
    king_autoscaling_managed_node_t *resized;

    if (needed <= king_autoscaling_runtime.managed_node_capacity) {
        return SUCCESS;
    }

    next_capacity = king_autoscaling_runtime.managed_node_capacity == 0
        ? 4
        : king_autoscaling_runtime.managed_node_capacity;

    while (next_capacity < needed) {
        next_capacity *= 2;
    }

    if (king_autoscaling_runtime.managed_nodes == NULL) {
        resized = pemalloc(next_capacity * sizeof(*resized), 1);
        memset(resized, 0, next_capacity * sizeof(*resized));
    } else {
        resized = perealloc(
            king_autoscaling_runtime.managed_nodes,
            next_capacity * sizeof(*resized),
            1
        );
        memset(
            resized + king_autoscaling_runtime.managed_node_capacity,
            0,
            (next_capacity - king_autoscaling_runtime.managed_node_capacity) * sizeof(*resized)
        );
    }

    king_autoscaling_runtime.managed_nodes = resized;
    king_autoscaling_runtime.managed_node_capacity = next_capacity;
    return SUCCESS;
}

static void king_autoscaling_normalize_runtime_limits(void)
{
    if (king_autoscaling_runtime.config.min_nodes <= 0) {
        king_autoscaling_runtime.config.min_nodes = 1;
    }

    if (king_autoscaling_runtime.config.max_nodes < king_autoscaling_runtime.config.min_nodes) {
        king_autoscaling_runtime.config.max_nodes = king_autoscaling_runtime.config.min_nodes;
    }

    if (king_autoscaling_runtime.config.max_scale_step <= 0) {
        king_autoscaling_runtime.config.max_scale_step = 1;
    }

    if (king_autoscaling_runtime.config.spend_warning_threshold_percent < 0) {
        king_autoscaling_runtime.config.spend_warning_threshold_percent = 0;
    }
    if (king_autoscaling_runtime.config.spend_warning_threshold_percent > 100) {
        king_autoscaling_runtime.config.spend_warning_threshold_percent = 100;
    }
    if (king_autoscaling_runtime.config.spend_hard_limit_percent < 0) {
        king_autoscaling_runtime.config.spend_hard_limit_percent = 100;
    }
    if (king_autoscaling_runtime.config.spend_hard_limit_percent > 100) {
        king_autoscaling_runtime.config.spend_hard_limit_percent = 100;
    }
    if (king_autoscaling_runtime.config.quota_warning_threshold_percent < 0) {
        king_autoscaling_runtime.config.quota_warning_threshold_percent = 0;
    }
    if (king_autoscaling_runtime.config.quota_warning_threshold_percent > 100) {
        king_autoscaling_runtime.config.quota_warning_threshold_percent = 100;
    }
    if (king_autoscaling_runtime.config.quota_hard_limit_percent < 0) {
        king_autoscaling_runtime.config.quota_hard_limit_percent = 100;
    }
    if (king_autoscaling_runtime.config.quota_hard_limit_percent > 100) {
        king_autoscaling_runtime.config.quota_hard_limit_percent = 100;
    }

    if (
        king_autoscaling_runtime.config.spend_warning_threshold_percent > 0
        && king_autoscaling_runtime.config.spend_hard_limit_percent < king_autoscaling_runtime.config.spend_warning_threshold_percent
    ) {
        king_autoscaling_runtime.config.spend_hard_limit_percent = king_autoscaling_runtime.config.spend_warning_threshold_percent;
    }

    if (
        king_autoscaling_runtime.config.quota_warning_threshold_percent > 0
        && king_autoscaling_runtime.config.quota_hard_limit_percent < king_autoscaling_runtime.config.quota_warning_threshold_percent
    ) {
        king_autoscaling_runtime.config.quota_hard_limit_percent = king_autoscaling_runtime.config.quota_warning_threshold_percent;
    }
}

static const char *king_autoscaling_budget_status_to_string(
    king_autoscaling_budget_status_t status)
{
    switch (status) {
        case KING_AUTOSCALING_BUDGET_STATUS_WARNING:
            return "warning";
        case KING_AUTOSCALING_BUDGET_STATUS_HARD_LIMIT:
            return "hard_limit";
        case KING_AUTOSCALING_BUDGET_STATUS_API_ERROR:
            return "api_error";
        case KING_AUTOSCALING_BUDGET_STATUS_OK:
            return "ok";
        case KING_AUTOSCALING_BUDGET_STATUS_DISABLED:
        default:
            return "disabled";
    }
}

static king_autoscaling_provider_kind_t king_autoscaling_detect_provider_kind(const char *provider)
{
    if (provider == NULL || provider[0] == '\0') {
        return KING_AUTOSCALING_PROVIDER_NONE;
    }

    if (strcasecmp(provider, "hetzner") == 0) {
        return KING_AUTOSCALING_PROVIDER_HETZNER;
    }

    return KING_AUTOSCALING_PROVIDER_SIMULATED;
}

static const char *king_autoscaling_get_provider_mode(void)
{
    switch (king_autoscaling_runtime.provider_kind) {
        case KING_AUTOSCALING_PROVIDER_HETZNER:
            return king_autoscaling_runtime.controller_token_configured
                ? "hetzner_active"
                : "hetzner_readonly";
        case KING_AUTOSCALING_PROVIDER_SIMULATED:
            return "simulated_provider";
        case KING_AUTOSCALING_PROVIDER_NONE:
        default:
            return "simulated_local";
    }
}

