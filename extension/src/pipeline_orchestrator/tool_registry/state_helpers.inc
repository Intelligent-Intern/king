static void king_orchestrator_persistent_string_zval_dtor(zval *zv)
{
    if (Z_TYPE_P(zv) == IS_STRING) {
        zend_string_release_ex(Z_STR_P(zv), 1);
    }
}

static void king_orchestrator_run_state_free(king_orchestrator_run_state_t *run_state)
{
    if (run_state == NULL) {
        return;
    }

    if (run_state->run_id != NULL) {
        zend_string_release_ex(run_state->run_id, 1);
    }
    if (run_state->status != NULL) {
        zend_string_release_ex(run_state->status, 1);
    }
    if (run_state->execution_backend != NULL) {
        zend_string_release_ex(run_state->execution_backend, 1);
    }
    if (run_state->queue_phase != NULL) {
        zend_string_release_ex(run_state->queue_phase, 1);
    }
    if (run_state->initial_data_b64 != NULL) {
        zend_string_release_ex(run_state->initial_data_b64, 1);
    }
    if (run_state->pipeline_b64 != NULL) {
        zend_string_release_ex(run_state->pipeline_b64, 1);
    }
    if (run_state->options_b64 != NULL) {
        zend_string_release_ex(run_state->options_b64, 1);
    }
    if (run_state->telemetry_parent_context_b64 != NULL) {
        zend_string_release_ex(run_state->telemetry_parent_context_b64, 1);
    }
    if (run_state->handler_boundary_b64 != NULL) {
        zend_string_release_ex(run_state->handler_boundary_b64, 1);
    }
    if (run_state->result_b64 != NULL) {
        zend_string_release_ex(run_state->result_b64, 1);
    }
    if (run_state->error_b64 != NULL) {
        zend_string_release_ex(run_state->error_b64, 1);
    }
    if (run_state->error_category != NULL) {
        zend_string_release_ex(run_state->error_category, 1);
    }
    if (run_state->retry_disposition != NULL) {
        zend_string_release_ex(run_state->retry_disposition, 1);
    }
    if (run_state->error_backend != NULL) {
        zend_string_release_ex(run_state->error_backend, 1);
    }
    if (run_state->last_recovery_reason != NULL) {
        zend_string_release_ex(run_state->last_recovery_reason, 1);
    }

    pefree(run_state, 1);
}

static void king_orchestrator_run_state_zval_dtor(zval *zv)
{
    if (Z_TYPE_P(zv) == IS_PTR) {
        king_orchestrator_run_state_free((king_orchestrator_run_state_t *) Z_PTR_P(zv));
    }
}

static zend_string *king_orchestrator_serialize_zval(zval *value)
{
    smart_str buffer = {0};
    php_serialize_data_t var_hash;

    PHP_VAR_SERIALIZE_INIT(var_hash);
    php_var_serialize(&buffer, value, &var_hash);
    PHP_VAR_SERIALIZE_DESTROY(var_hash);
    smart_str_0(&buffer);

    return buffer.s;
}

static zend_string *king_orchestrator_encode_zval_base64(zval *value)
{
    zend_string *serialized;
    zend_string *encoded;

    if (value == NULL) {
        zval null_value;

        ZVAL_NULL(&null_value);
        serialized = king_orchestrator_serialize_zval(&null_value);
    } else {
        serialized = king_orchestrator_serialize_zval(value);
    }

    if (serialized == NULL) {
        return NULL;
    }

    encoded = php_base64_encode(
        (const unsigned char *) ZSTR_VAL(serialized),
        ZSTR_LEN(serialized)
    );
    zend_string_release(serialized);

    return encoded;
}

static zend_string *king_orchestrator_encode_error_message_base64(const char *error_message)
{
    zend_string *encoded;
    zend_object *saved_exception;
    zval error_value;

    saved_exception = EG(exception);
    EG(exception) = NULL;

    ZVAL_STRING(&error_value, error_message != NULL ? error_message : "");
    encoded = king_orchestrator_encode_zval_base64(&error_value);
    zval_ptr_dtor(&error_value);

    EG(exception) = saved_exception;
    return encoded;
}

static zend_string *king_orchestrator_dup_state_field(const char *value)
{
    return zend_string_init(value != NULL ? value : "", value != NULL ? strlen(value) : 0, 1);
}

static void king_orchestrator_replace_runtime_string(zend_string **target, zend_string *value)
{
    if (*target != NULL) {
        zend_string_release_ex(*target, 1);
    }

    *target = value;
}

static void king_orchestrator_run_state_replace_optional_string(
    zend_string **target,
    const char *value
)
{
    if (value == NULL || value[0] == '\0') {
        king_orchestrator_replace_runtime_string(target, NULL);
        return;
    }

    king_orchestrator_replace_runtime_string(
        target,
        zend_string_init(value, strlen(value), 1)
    );
}

static void king_orchestrator_run_state_clear_error_classification(
    king_orchestrator_run_state_t *run_state
)
{
    if (run_state == NULL) {
        return;
    }

    king_orchestrator_replace_runtime_string(&run_state->error_category, NULL);
    king_orchestrator_replace_runtime_string(&run_state->retry_disposition, NULL);
    king_orchestrator_replace_runtime_string(&run_state->error_backend, NULL);
    run_state->error_step_index = -1;
}

static void king_orchestrator_set_last_run(zend_string *run_id, const char *status)
{
    zend_string *persistent_run_id = NULL;
    zend_string *persistent_status = NULL;

    if (run_id != NULL) {
        persistent_run_id = zend_string_dup(run_id, 1);
    }
    if (status != NULL) {
        persistent_status = zend_string_init(status, strlen(status), 1);
    }

    king_orchestrator_replace_runtime_string(&king_orchestrator_last_run_id, persistent_run_id);
    king_orchestrator_replace_runtime_string(&king_orchestrator_last_run_status, persistent_status);
}

int king_orchestrator_run_id_is_valid_literal(const char *run_id, size_t run_id_len)
{
    size_t index;

    if (run_id == NULL || run_id_len <= sizeof("run-") - 1) {
        return 0;
    }

    if (memcmp(run_id, "run-", sizeof("run-") - 1) != 0) {
        return 0;
    }

    for (index = sizeof("run-") - 1; index < run_id_len; index++) {
        if (run_id[index] < '0' || run_id[index] > '9') {
            return 0;
        }
    }

    return 1;
}

int king_orchestrator_run_id_is_valid(zend_string *run_id)
{
    if (run_id == NULL) {
        return 0;
    }

    return king_orchestrator_run_id_is_valid_literal(ZSTR_VAL(run_id), ZSTR_LEN(run_id));
}

static zend_bool king_orchestrator_run_id_has_prefix(
    const zend_string *run_id,
    const char *prefix
)
{
    size_t prefix_len;

    if (run_id == NULL || prefix == NULL) {
        return 0;
    }

    prefix_len = strlen(prefix);
    return ZSTR_LEN(run_id) >= prefix_len
        && memcmp(ZSTR_VAL(run_id), prefix, prefix_len) == 0;
}

static zend_long king_orchestrator_extract_run_sequence(const zend_string *run_id)
{
    const char *raw;

    if (!king_orchestrator_run_id_has_prefix(run_id, "run-")) {
        return 0;
    }

    raw = ZSTR_VAL(run_id) + (sizeof("run-") - 1);
    if (*raw == '\0') {
        return 0;
    }

    return ZEND_STRTOL(raw, NULL, 10);
}

size_t king_orchestrator_count_active_runs(void)
{
    zval *entry;
    size_t active_runs = 0;

    ZEND_HASH_FOREACH_VAL(&king_orchestrator_pipeline_runs, entry) {
        king_orchestrator_run_state_t *run_state;

        if (Z_TYPE_P(entry) != IS_PTR) {
            continue;
        }

        run_state = (king_orchestrator_run_state_t *) Z_PTR_P(entry);
        if (
            run_state != NULL
            && run_state->status != NULL
            && zend_string_equals_literal(run_state->status, "running")
        ) {
            active_runs++;
        }
    } ZEND_HASH_FOREACH_END();

    return active_runs;
}

static int king_orchestrator_backend_is_file_worker(void)
{
    return king_mcp_orchestrator_config.orchestrator_execution_backend != NULL
        && strcmp(king_mcp_orchestrator_config.orchestrator_execution_backend, "file_worker") == 0;
}

static int king_orchestrator_backend_is_remote_peer(void)
{
    return king_mcp_orchestrator_config.orchestrator_execution_backend != NULL
        && strcmp(king_mcp_orchestrator_config.orchestrator_execution_backend, "remote_peer") == 0;
}

static const char *king_orchestrator_current_execution_backend(void)
{
    if (
        king_mcp_orchestrator_config.orchestrator_execution_backend == NULL
        || king_mcp_orchestrator_config.orchestrator_execution_backend[0] == '\0'
    ) {
        return "local";
    }

    return king_mcp_orchestrator_config.orchestrator_execution_backend;
}

static const char *king_orchestrator_topology_scope_for_backend(const char *execution_backend)
{
    if (execution_backend == NULL || execution_backend[0] == '\0' || strcmp(execution_backend, "local") == 0) {
        return "local_in_process";
    }

    if (strcmp(execution_backend, "file_worker") == 0) {
        return "same_host_file_worker";
    }

    if (strcmp(execution_backend, "remote_peer") == 0) {
        return "tcp_host_port_execution_peer";
    }

    return "local_in_process";
}

static const char *king_orchestrator_run_execution_backend(const king_orchestrator_run_state_t *run_state)
{
    if (
        run_state != NULL
        && run_state->execution_backend != NULL
        && ZSTR_LEN(run_state->execution_backend) > 0
    ) {
        return ZSTR_VAL(run_state->execution_backend);
    }

    return king_orchestrator_current_execution_backend();
}

static zend_bool king_orchestrator_handler_boundary_requires_persistence(
    const char *execution_backend
)
{
    return execution_backend != NULL
        && (
            strcmp(execution_backend, "file_worker") == 0
            || strcmp(execution_backend, "remote_peer") == 0
        );
}

static zend_result king_orchestrator_build_handler_boundary_snapshot(
    zval *pipeline,
    const char *execution_backend,
    zval *return_value
)
{
    zval required_tools;
    zval required_step_refs;
    zval *step;
    uint32_t index = 0;

    if (return_value == NULL) {
        return FAILURE;
    }

    if (!king_orchestrator_handler_boundary_requires_persistence(execution_backend)) {
        ZVAL_NULL(return_value);
        return SUCCESS;
    }

    array_init(return_value);
    add_assoc_string(return_value, "contract", "durable_tool_name_refs_only");
    add_assoc_string(return_value, "binding_scope", "process_local_re_registration");
    add_assoc_string(return_value, "execution_backend", (char *) execution_backend);
    add_assoc_string(
        return_value,
        "topology_scope",
        (char *) king_orchestrator_topology_scope_for_backend(execution_backend)
    );

    array_init(&required_tools);
    array_init(&required_step_refs);
    if (pipeline != NULL && Z_TYPE_P(pipeline) == IS_ARRAY) {
        ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(pipeline), step) {
            zval *tool;
            zval entry;
            zval *required_tool_entry;
            zend_bool seen_tool = 0;

            if (step == NULL || Z_TYPE_P(step) != IS_ARRAY) {
                index++;
                continue;
            }

            tool = zend_hash_str_find(Z_ARRVAL_P(step), "tool", sizeof("tool") - 1);
            if (tool == NULL || Z_TYPE_P(tool) != IS_STRING || Z_STRLEN_P(tool) == 0) {
                index++;
                continue;
            }

            if (
                king_orchestrator_lookup_tool_handler(
                    Z_STRVAL_P(tool),
                    Z_STRLEN_P(tool)
                ) == NULL
            ) {
                index++;
                continue;
            }

            ZEND_HASH_FOREACH_VAL(Z_ARRVAL(required_tools), required_tool_entry) {
                if (
                    required_tool_entry != NULL
                    && Z_TYPE_P(required_tool_entry) == IS_STRING
                    && zend_string_equals(Z_STR_P(required_tool_entry), Z_STR_P(tool))
                ) {
                    seen_tool = 1;
                    break;
                }
            } ZEND_HASH_FOREACH_END();
            if (!seen_tool) {
                add_next_index_stringl(&required_tools, Z_STRVAL_P(tool), Z_STRLEN_P(tool));
            }

            array_init(&entry);
            add_assoc_long(&entry, "index", (zend_long) index);
            add_assoc_stringl(&entry, "tool_name", Z_STRVAL_P(tool), Z_STRLEN_P(tool));
            add_next_index_zval(&required_step_refs, &entry);
            index++;
        } ZEND_HASH_FOREACH_END();
    }

    if (zend_hash_num_elements(Z_ARRVAL(required_step_refs)) == 0) {
        zval_ptr_dtor(&required_tools);
        zval_ptr_dtor(&required_step_refs);
        zval_ptr_dtor(return_value);
        ZVAL_NULL(return_value);
        return SUCCESS;
    }

    add_assoc_long(return_value, "required_tool_count", zend_hash_num_elements(Z_ARRVAL(required_tools)));
    add_assoc_long(
        return_value,
        "required_step_count",
        zend_hash_num_elements(Z_ARRVAL(required_step_refs))
    );
    add_assoc_bool(
        return_value,
        "requires_process_registration",
        zend_hash_num_elements(Z_ARRVAL(required_step_refs)) > 0
    );
    add_assoc_zval(return_value, "required_tools", &required_tools);
    add_assoc_zval(return_value, "required_step_refs", &required_step_refs);

    return SUCCESS;
}
