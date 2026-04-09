static void king_autoscaling_build_node_name(char *buffer, size_t buffer_size)
{
    const char *prefix = king_autoscaling_runtime.config.server_name_prefix;

    snprintf(
        buffer,
        buffer_size,
        "%s-%ld-%zu",
        (prefix != NULL && prefix[0] != '\0') ? prefix : "king-node",
        (long) time(NULL),
        king_autoscaling_runtime.managed_node_count + 1
    );
}

static size_t king_autoscaling_runtime_count_live_nodes(void)
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

static size_t king_autoscaling_runtime_count_deletable_nodes(void)
{
    size_t index;
    size_t deletable_nodes = 0;

    for (index = 0; index < king_autoscaling_runtime.managed_node_count; index++) {
        king_autoscaling_managed_node_t *node = &king_autoscaling_runtime.managed_nodes[index];

        if (node->deleted_at > 0 || node->lifecycle_state == KING_AUTOSCALING_NODE_DELETED) {
            continue;
        }

        if (
            node->lifecycle_state == KING_AUTOSCALING_NODE_PROVISIONED
            || node->lifecycle_state == KING_AUTOSCALING_NODE_REGISTERED
            || node->lifecycle_state == KING_AUTOSCALING_NODE_DRAINING
        ) {
            deletable_nodes++;
        }
    }

    return deletable_nodes;
}

static zend_bool king_autoscaling_runtime_has_bootstrap_rollout_intent(void)
{
    if (
        king_autoscaling_runtime.config.bootstrap_user_data != NULL
        && king_autoscaling_runtime.config.bootstrap_user_data[0] != '\0'
    ) {
        return 1;
    }

    return king_autoscaling_runtime.config.prepared_release_url != NULL
        && king_autoscaling_runtime.config.prepared_release_url[0] != '\0'
        && king_autoscaling_runtime.config.join_endpoint != NULL
        && king_autoscaling_runtime.config.join_endpoint[0] != '\0';
}

static time_t king_autoscaling_pending_node_anchor(
    const king_autoscaling_managed_node_t *node)
{
    if (node == NULL) {
        return 0;
    }

    if (node->lifecycle_state == KING_AUTOSCALING_NODE_REGISTERED) {
        return node->registered_at > 0 ? node->registered_at : node->created_at;
    }

    return node->created_at;
}

static const char *king_autoscaling_pending_node_phase(
    const king_autoscaling_managed_node_t *node)
{
    if (node == NULL) {
        return "unknown";
    }

    if (node->lifecycle_state == KING_AUTOSCALING_NODE_REGISTERED) {
        return "readiness";
    }

    return king_autoscaling_runtime_has_bootstrap_rollout_intent()
        ? "bootstrap"
        : "registration";
}

static const char *king_autoscaling_pending_node_rollback_status(
    const char *phase)
{
    if (phase != NULL && strcmp(phase, "bootstrap") == 0) {
        return "rollback_bootstrap";
    }
    if (phase != NULL && strcmp(phase, "readiness") == 0) {
        return "rollback_readiness";
    }

    return "rollback_registration";
}

static king_autoscaling_managed_node_t *king_autoscaling_runtime_pick_stale_pending_node(
    time_t now,
    const char **phase_out,
    time_t *anchor_out)
{
    size_t index;
    time_t timeout;
    king_autoscaling_managed_node_t *selected = NULL;
    time_t selected_anchor = 0;

    if (phase_out != NULL) {
        *phase_out = NULL;
    }
    if (anchor_out != NULL) {
        *anchor_out = 0;
    }

    if (king_autoscaling_runtime.config.idle_node_timeout_sec <= 0) {
        return NULL;
    }

    timeout = (time_t) king_autoscaling_runtime.config.idle_node_timeout_sec;
    for (index = 0; index < king_autoscaling_runtime.managed_node_count; index++) {
        king_autoscaling_managed_node_t *node = &king_autoscaling_runtime.managed_nodes[index];
        time_t anchor;

        if (
            node->deleted_at > 0
            || node->lifecycle_state == KING_AUTOSCALING_NODE_DELETED
            || node->lifecycle_state == KING_AUTOSCALING_NODE_READY
            || node->lifecycle_state == KING_AUTOSCALING_NODE_DRAINING
        ) {
            continue;
        }

        if (
            node->lifecycle_state != KING_AUTOSCALING_NODE_PROVISIONED
            && node->lifecycle_state != KING_AUTOSCALING_NODE_REGISTERED
        ) {
            continue;
        }

        anchor = king_autoscaling_pending_node_anchor(node);
        if (anchor <= 0 || now < anchor || (now - anchor) < timeout) {
            continue;
        }

        if (selected == NULL || anchor < selected_anchor) {
            selected = node;
            selected_anchor = anchor;
        }
    }

    if (selected != NULL) {
        if (phase_out != NULL) {
            *phase_out = king_autoscaling_pending_node_phase(selected);
        }
        if (anchor_out != NULL) {
            *anchor_out = selected_anchor;
        }
    }

    return selected;
}

static king_autoscaling_managed_node_t *king_autoscaling_runtime_pick_delete_candidate(void)
{
    size_t index = king_autoscaling_runtime.managed_node_count;
    king_autoscaling_managed_node_t *node = king_autoscaling_runtime_pick_draining_node();

    if (node != NULL) {
        return node;
    }

    while (index > 0) {
        index--;
        node = &king_autoscaling_runtime.managed_nodes[index];
        if (node->deleted_at > 0 || node->lifecycle_state == KING_AUTOSCALING_NODE_DELETED) {
            continue;
        }
        if (
            node->lifecycle_state == KING_AUTOSCALING_NODE_PROVISIONED
            || node->lifecycle_state == KING_AUTOSCALING_NODE_REGISTERED
        ) {
            return node;
        }
    }

    return NULL;
}

static const long king_autoscaling_hetzner_delete_max_attempts = 3;
static const long king_autoscaling_hetzner_delete_retry_delay_us = 100000;

static zend_bool king_autoscaling_http_status_is_retryable_delete(long http_code)
{
    return http_code == 429 || (http_code >= 500 && http_code <= 599);
}

static zend_result king_autoscaling_hetzner_delete_node(
    king_autoscaling_managed_node_t *node,
    const char *error_context)
{
    char url[512];
    const char *effective_error_context = error_context != NULL
        ? error_context
        : "Hetzner delete server request";
    long attempt;
    long http_code = 0;
    smart_str response = {0};
    const char *token = king_autoscaling_runtime.config.hetzner_api_token;

    if (node == NULL) {
        return FAILURE;
    }

    if (token == NULL || token[0] == '\0') {
        snprintf(
            king_autoscaling_runtime.last_error,
            sizeof(king_autoscaling_runtime.last_error),
            "Hetzner autoscaling requires king.cluster_autoscale_hetzner_api_token on the controller node."
        );
        return FAILURE;
    }

    snprintf(
        url,
        sizeof(url),
        "%s/servers/%ld",
        king_autoscaling_runtime.config.api_endpoint,
        (long) node->server_id
    );

    for (attempt = 1; attempt <= king_autoscaling_hetzner_delete_max_attempts; attempt++) {
        if (king_autoscaling_http_request(
            "DELETE",
            url,
            token,
            NULL,
            &http_code,
            &response
        ) != SUCCESS) {
            smart_str_free(&response);
            if (attempt < king_autoscaling_hetzner_delete_max_attempts) {
                usleep(king_autoscaling_hetzner_delete_retry_delay_us);
                continue;
            }
            return FAILURE;
        }

        if (
            (http_code >= 200 && http_code < 300)
            || http_code == 404
            || http_code == 410
        ) {
            smart_str_free(&response);
            return SUCCESS;
        }

        if (king_autoscaling_http_status_is_retryable_delete(http_code)
            && attempt < king_autoscaling_hetzner_delete_max_attempts
        ) {
            smart_str_free(&response);
            usleep(king_autoscaling_hetzner_delete_retry_delay_us);
            continue;
        }

        snprintf(
            king_autoscaling_runtime.last_error,
            sizeof(king_autoscaling_runtime.last_error),
            "%s for %ld returned HTTP %ld after %ld attempt(s).",
            effective_error_context,
            (long) node->server_id,
            http_code,
            attempt
        );
        smart_str_free(&response);
        return FAILURE;
    }

    smart_str_free(&response);
    snprintf(
        king_autoscaling_runtime.last_error,
        sizeof(king_autoscaling_runtime.last_error),
        "%s for %ld failed after %ld attempt(s).",
        effective_error_context,
        (long) node->server_id,
        king_autoscaling_hetzner_delete_max_attempts
    );
    return FAILURE;
}

static void king_autoscaling_runtime_mark_node_deleted(
    king_autoscaling_managed_node_t *node,
    time_t deleted_at,
    const char *provider_status)
{
    if (node == NULL) {
        return;
    }

    node->active = 0;
    node->lifecycle_state = KING_AUTOSCALING_NODE_DELETED;
    node->deleted_at = deleted_at > 0 ? deleted_at : time(NULL);
    snprintf(
        node->provider_status,
        sizeof(node->provider_status),
        "%s",
        provider_status != NULL ? provider_status : "deleted"
    );
}

static uint32_t king_autoscaling_cap_scale_up(uint32_t requested)
{
    uint32_t capped;
    zend_long max_nodes = king_autoscaling_runtime.config.max_nodes;
    uint32_t current_total = (uint32_t) (1 + king_autoscaling_runtime_count_live_nodes());

    if (requested == 0) {
        return 0;
    }

    capped = requested;
    if ((zend_long) current_total >= max_nodes) {
        return 0;
    }

    if ((zend_long) (current_total + capped) > max_nodes) {
        capped = (uint32_t) (max_nodes - current_total);
    }

    return capped;
}

static uint32_t king_autoscaling_cap_scale_down(uint32_t requested)
{
    uint32_t capped;
    size_t active_nodes = king_autoscaling_runtime_count_active_nodes();
    size_t minimum_managed = king_autoscaling_runtime.config.min_nodes > 1
        ? (size_t) (king_autoscaling_runtime.config.min_nodes - 1)
        : 0;

    if (requested == 0) {
        return 0;
    }

    capped = requested;
    if (active_nodes <= minimum_managed) {
        return 0;
    }

    if ((size_t) capped > (active_nodes - minimum_managed)) {
        capped = (uint32_t) (active_nodes - minimum_managed);
    }

    return capped;
}

static zend_result king_autoscaling_simulate_scale_up(uint32_t count)
{
    uint32_t index;

    for (index = 0; index < count; index++) {
        char node_name[128];
        zend_long server_id;

        king_autoscaling_build_node_name(node_name, sizeof(node_name));
        server_id = (zend_long) (time(NULL) * 1000 + king_autoscaling_runtime.managed_node_count + 1);

        if (king_autoscaling_runtime_append_node(server_id, node_name, "simulated_running", time(NULL), 1) != SUCCESS) {
            return FAILURE;
        }
    }

    return SUCCESS;
}

static zend_result king_autoscaling_simulate_scale_down(uint32_t count)
{
    uint32_t index;

    for (index = 0; index < count; index++) {
        king_autoscaling_managed_node_t *node = king_autoscaling_runtime_pick_active_node();
        if (node == NULL) {
            break;
        }

        node->active = 0;
        node->lifecycle_state = KING_AUTOSCALING_NODE_DELETED;
        node->deleted_at = time(NULL);
        snprintf(node->provider_status, sizeof(node->provider_status), "%s", "deleted");
    }

    return SUCCESS;
}

static zend_result king_autoscaling_hetzner_scale_up(uint32_t count)
{
    uint32_t index;
    king_autoscaling_hetzner_budget_decision_t budget_decision;
    const char *token = king_autoscaling_runtime.config.hetzner_api_token;
    king_autoscaling_runtime.last_warning[0] = '\0';

    if (token == NULL || token[0] == '\0') {
        snprintf(
            king_autoscaling_runtime.last_error,
            sizeof(king_autoscaling_runtime.last_error),
            "Hetzner autoscaling requires king.cluster_autoscale_hetzner_api_token on the controller node."
        );
        return FAILURE;
    }

    budget_decision = king_autoscaling_check_hetzner_budget_limits();
    if (budget_decision == KING_AUTOSCALING_HETZNER_BUDGET_DECISION_HARD_LIMIT) {
        snprintf(
            king_autoscaling_runtime.last_error,
            sizeof(king_autoscaling_runtime.last_error),
            "Scale-up blocked by Hetzner spend/quota hard limits."
        );
        return FAILURE;
    }
    if (budget_decision == KING_AUTOSCALING_HETZNER_BUDGET_DECISION_WARNING) {
        snprintf(
            king_autoscaling_runtime.last_warning,
            sizeof(king_autoscaling_runtime.last_warning),
            "Hetzner budget probe is in warning state but scale-up continues."
        );
    } else if (budget_decision == KING_AUTOSCALING_HETZNER_BUDGET_DECISION_API_ERROR) {
        snprintf(
            king_autoscaling_runtime.last_warning,
            sizeof(king_autoscaling_runtime.last_warning),
            "Hetzner budget probe was unavailable; scale-up continues with warning."
        );
    }

    for (index = 0; index < count; index++) {
        char node_name[128];
        char url[512];
        char server_status[32] = "running";
        zend_long server_id = 0;
        long http_code = 0;
        smart_str payload = {0};
        smart_str response = {0};

        king_autoscaling_build_node_name(node_name, sizeof(node_name));

        if (king_autoscaling_build_hetzner_create_payload(node_name, &payload) != SUCCESS) {
            smart_str_free(&payload);
            return FAILURE;
        }

        snprintf(
            url,
            sizeof(url),
            "%s/servers",
            king_autoscaling_runtime.config.api_endpoint
        );

        if (king_autoscaling_http_request(
            "POST",
            url,
            token,
            payload.s != NULL ? ZSTR_VAL(payload.s) : NULL,
            &http_code,
            &response
        ) != SUCCESS) {
            smart_str_free(&payload);
            smart_str_free(&response);
            return FAILURE;
        }

        smart_str_free(&payload);

        if (http_code < 200 || http_code >= 300) {
            snprintf(
                king_autoscaling_runtime.last_error,
                sizeof(king_autoscaling_runtime.last_error),
                "Hetzner create server request returned HTTP %ld.",
                http_code
            );
            smart_str_free(&response);
            return FAILURE;
        }

        if (
            response.s == NULL
            || king_autoscaling_json_extract_long(ZSTR_VAL(response.s), "id", &server_id) != SUCCESS
        ) {
            snprintf(
                king_autoscaling_runtime.last_error,
                sizeof(king_autoscaling_runtime.last_error),
                "Hetzner create server response did not include server.id."
            );
            smart_str_free(&response);
            return FAILURE;
        }

        if (response.s != NULL) {
            king_autoscaling_json_extract_string(
                ZSTR_VAL(response.s),
                "status",
                server_status,
                sizeof(server_status)
            );
        }

        if (king_autoscaling_runtime_append_node(server_id, node_name, server_status, time(NULL), 0) != SUCCESS) {
            smart_str_free(&response);
            return FAILURE;
        }

        smart_str_free(&response);
    }

    return SUCCESS;
}

static zend_result king_autoscaling_hetzner_scale_down(uint32_t count)
{
    uint32_t index;

    for (index = 0; index < count; index++) {
        king_autoscaling_managed_node_t *node = king_autoscaling_runtime_pick_delete_candidate();

        if (node == NULL) {
            break;
        }

        if (king_autoscaling_hetzner_delete_node(node, "Hetzner delete server request") != SUCCESS) {
            return FAILURE;
        }

        king_autoscaling_runtime_mark_node_deleted(node, time(NULL), "deleted");
    }

    return SUCCESS;
}

int king_autoscaling_provider_rollback_stale_pending_node(time_t now)
{
    const char *phase = NULL;
    const char *rollback_status;
    char message[256];
    time_t anchor = 0;
    king_autoscaling_managed_node_t *node;

    if (
        king_autoscaling_runtime.provider_kind != KING_AUTOSCALING_PROVIDER_HETZNER
        || king_autoscaling_runtime.config.idle_node_timeout_sec <= 0
    ) {
        return 0;
    }

    node = king_autoscaling_runtime_pick_stale_pending_node(now, &phase, &anchor);
    if (node == NULL) {
        return 0;
    }

    snprintf(
        message,
        sizeof(message),
        "Stale Hetzner node %ld exceeded the %ld second pending-node timeout while waiting for %s.",
        (long) node->server_id,
        (long) king_autoscaling_runtime.config.idle_node_timeout_sec,
        phase != NULL ? phase : "pending lifecycle completion"
    );
    snprintf(
        king_autoscaling_runtime.last_decision_reason,
        sizeof(king_autoscaling_runtime.last_decision_reason),
        "%s",
        message
    );
    snprintf(
        king_autoscaling_runtime.last_warning,
        sizeof(king_autoscaling_runtime.last_warning),
        "%s Rolling the node back before another automatic decision.",
        message
    );

    if (
        king_autoscaling_hetzner_delete_node(
            node,
            "Hetzner rollback delete server request"
        ) != SUCCESS
    ) {
        return -1;
    }

    rollback_status = king_autoscaling_pending_node_rollback_status(phase);
    king_autoscaling_runtime_mark_node_deleted(node, now, rollback_status);
    king_autoscaling_runtime.action_count++;
    king_autoscaling_runtime.last_scale_down_at = now;
    king_autoscaling_runtime_sync_instance_count();
    if (king_autoscaling_runtime_persist_state() != SUCCESS) {
        if (king_autoscaling_runtime.last_error[0] == '\0') {
            snprintf(
                king_autoscaling_runtime.last_error,
                sizeof(king_autoscaling_runtime.last_error),
                "Failed to persist autoscaling state after rolling back stale Hetzner node %ld.",
                (long) node->server_id
            );
        }
        return -1;
    }

    snprintf(
        king_autoscaling_runtime.last_action_kind,
        sizeof(king_autoscaling_runtime.last_action_kind),
        "%s",
        "rollback_pending_node"
    );

    (void) anchor;
    return 1;
}

int king_autoscaling_provider_scale_up(uint32_t count)
{
    uint32_t capped_count = king_autoscaling_cap_scale_up(count);

    king_autoscaling_runtime.last_error[0] = '\0';
    king_autoscaling_runtime.last_warning[0] = '\0';

    if (capped_count == 0) {
        snprintf(
            king_autoscaling_runtime.last_warning,
            sizeof(king_autoscaling_runtime.last_warning),
            "Scale-up request did not add nodes because the cluster is already at its configured limit."
        );
        return SUCCESS;
    }

    switch (king_autoscaling_runtime.provider_kind) {
        case KING_AUTOSCALING_PROVIDER_HETZNER:
            if (king_autoscaling_hetzner_scale_up(capped_count) != SUCCESS) {
                return FAILURE;
            }
            break;
        case KING_AUTOSCALING_PROVIDER_SIMULATED:
        case KING_AUTOSCALING_PROVIDER_NONE:
        default:
            if (king_autoscaling_simulate_scale_up(capped_count) != SUCCESS) {
                return FAILURE;
            }
            break;
    }

    king_autoscaling_runtime.action_count += capped_count;
    king_autoscaling_runtime.last_scale_up_at = time(NULL);
    snprintf(
        king_autoscaling_runtime.last_action_kind,
        sizeof(king_autoscaling_runtime.last_action_kind),
        "scale_up"
    );

    king_autoscaling_runtime_sync_instance_count();
    king_autoscaling_runtime_persist_state();
    return SUCCESS;
}

int king_autoscaling_provider_scale_down(uint32_t count)
{
    uint32_t capped_count;

    king_autoscaling_runtime.last_error[0] = '\0';
    king_autoscaling_runtime.last_warning[0] = '\0';

    switch (king_autoscaling_runtime.provider_kind) {
        case KING_AUTOSCALING_PROVIDER_HETZNER:
            capped_count = (uint32_t) king_autoscaling_runtime_count_deletable_nodes();
            if (count < capped_count) {
                capped_count = count;
            }

            if (capped_count == 0) {
                size_t active_nodes = king_autoscaling_runtime_count_active_nodes();
                size_t minimum_managed = king_autoscaling_runtime.config.min_nodes > 1
                    ? (size_t) (king_autoscaling_runtime.config.min_nodes - 1)
                    : 0;

                if (active_nodes > minimum_managed) {
                    snprintf(
                        king_autoscaling_runtime.last_warning,
                        sizeof(king_autoscaling_runtime.last_warning),
                        "Scale-down requires a drained or non-admitted Hetzner node; call king_autoscaling_drain_node() first."
                    );
                } else {
                    snprintf(
                        king_autoscaling_runtime.last_warning,
                        sizeof(king_autoscaling_runtime.last_warning),
                        "Scale-down request did not remove nodes because the cluster is already at its configured floor."
                    );
                }
                return SUCCESS;
            }

            if (king_autoscaling_hetzner_scale_down(capped_count) != SUCCESS) {
                return FAILURE;
            }
            break;
        case KING_AUTOSCALING_PROVIDER_SIMULATED:
        case KING_AUTOSCALING_PROVIDER_NONE:
        default:
            capped_count = king_autoscaling_cap_scale_down(count);
            if (capped_count == 0) {
                snprintf(
                    king_autoscaling_runtime.last_warning,
                    sizeof(king_autoscaling_runtime.last_warning),
                    "Scale-down request did not remove nodes because the cluster is already at its configured floor."
                );
                return SUCCESS;
            }
            if (king_autoscaling_simulate_scale_down(capped_count) != SUCCESS) {
                return FAILURE;
            }
            break;
    }

    king_autoscaling_runtime.action_count += capped_count;
    king_autoscaling_runtime.last_scale_down_at = time(NULL);
    snprintf(
        king_autoscaling_runtime.last_action_kind,
        sizeof(king_autoscaling_runtime.last_action_kind),
        "scale_down"
    );

    king_autoscaling_runtime_sync_instance_count();
    king_autoscaling_runtime_persist_state();
    return SUCCESS;
}

PHP_FUNCTION(king_autoscaling_scale_up)
{
    zend_long instances = 1;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(instances)
    ZEND_PARSE_PARAMETERS_END();

    if (instances <= 0) {
        RETURN_FALSE;
    }

    if (king_autoscaling_provider_scale_up((uint32_t) instances) == SUCCESS) {
        RETURN_TRUE;
    }

    RETURN_FALSE;
}

PHP_FUNCTION(king_autoscaling_scale_down)
{
    zend_long instances = 1;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(instances)
    ZEND_PARSE_PARAMETERS_END();

    if (instances <= 0) {
        RETURN_FALSE;
    }

    if (king_autoscaling_provider_scale_down((uint32_t) instances) == SUCCESS) {
        RETURN_TRUE;
    }

    RETURN_FALSE;
}
