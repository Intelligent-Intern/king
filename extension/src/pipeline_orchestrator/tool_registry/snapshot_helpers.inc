static zval *king_orchestrator_find_pipeline_step_tool(zval *pipeline, zend_long step_index)
{
    zval *step;

    if (
        pipeline == NULL
        || Z_TYPE_P(pipeline) != IS_ARRAY
        || step_index < 0
    ) {
        return NULL;
    }

    step = zend_hash_index_find(Z_ARRVAL_P(pipeline), (zend_ulong) step_index);
    if (step == NULL || Z_TYPE_P(step) != IS_ARRAY) {
        return NULL;
    }

    return zend_hash_str_find(Z_ARRVAL_P(step), "tool", sizeof("tool") - 1);
}

static zval *king_orchestrator_find_pipeline_step_string(
    zval *pipeline,
    zend_long step_index,
    const char *field_name,
    size_t field_name_len
)
{
    zval *step;
    zval *value;

    if (
        pipeline == NULL
        || Z_TYPE_P(pipeline) != IS_ARRAY
        || step_index < 0
    ) {
        return NULL;
    }

    step = zend_hash_index_find(Z_ARRVAL_P(pipeline), (zend_ulong) step_index);
    if (step == NULL || Z_TYPE_P(step) != IS_ARRAY) {
        return NULL;
    }

    value = zend_hash_str_find(Z_ARRVAL_P(step), field_name, field_name_len);
    if (value == NULL || Z_TYPE_P(value) != IS_STRING || Z_STRLEN_P(value) == 0) {
        return NULL;
    }

    return value;
}

static zend_long king_orchestrator_pipeline_step_metadata_count(
    zval *pipeline,
    const char *field_name,
    size_t field_name_len
)
{
    zval *step;
    zval *value;
    zend_long count = 0;

    if (pipeline == NULL || Z_TYPE_P(pipeline) != IS_ARRAY) {
        return 0;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(pipeline), step) {
        if (step == NULL || Z_TYPE_P(step) != IS_ARRAY) {
            continue;
        }

        value = zend_hash_str_find(Z_ARRVAL_P(step), field_name, field_name_len);
        if (value != NULL && Z_TYPE_P(value) == IS_STRING && Z_STRLEN_P(value) > 0) {
            count++;
        }
    } ZEND_HASH_FOREACH_END();

    return count;
}

static zend_string *king_orchestrator_build_attempt_identity_for_snapshot(
    const king_orchestrator_run_state_t *run_state
)
{
    zend_long attempt_number = 1;
    const char *run_id = "run-unknown";

    if (run_state != NULL && run_state->recovery_count >= 0) {
        attempt_number = run_state->recovery_count + 1;
    }

    if (run_state != NULL && run_state->run_id != NULL) {
        run_id = ZSTR_VAL(run_state->run_id);
    }

    return strpprintf(
        0,
        "%s:attempt-%ld",
        run_id,
        attempt_number > 0 ? attempt_number : 1
    );
}

static zend_string *king_orchestrator_build_failure_identity_for_snapshot(
    const king_orchestrator_run_state_t *run_state
)
{
    zend_string *attempt_identity;
    zend_string *failure_identity;

    if (
        run_state == NULL
        || run_state->run_id == NULL
        || run_state->status == NULL
        || (
            !zend_string_equals_literal(run_state->status, "failed")
            && !zend_string_equals_literal(run_state->status, "cancelled")
        )
    ) {
        return NULL;
    }

    attempt_identity = king_orchestrator_build_attempt_identity_for_snapshot(run_state);
    failure_identity = strpprintf(
        0,
        "%s:%s:%s:%ld",
        ZSTR_VAL(attempt_identity),
        ZSTR_VAL(run_state->status),
        run_state->error_category != NULL ? ZSTR_VAL(run_state->error_category) : "backend",
        run_state->error_step_index
    );
    zend_string_release(attempt_identity);

    return failure_identity;
}

static void king_orchestrator_append_run_telemetry_adapter_snapshot(
    zval *target,
    zval *pipeline,
    const king_orchestrator_run_state_t *run_state
)
{
    zval adapter;
    zend_string *attempt_identity;
    zend_string *failure_identity;
    zval *failed_partition;
    zval *failed_batch;

    if (target == NULL) {
        return;
    }

    array_init(&adapter);
    add_assoc_string(&adapter, "contract", "run_partition_batch_retry_failure_identity");
    add_assoc_string(
        &adapter,
        "identity_surface",
        "run_snapshots+otlp_spans+otlp_logs+telemetry_metrics"
    );

    attempt_identity = king_orchestrator_build_attempt_identity_for_snapshot(run_state);
    add_assoc_stringl(&adapter, "attempt_identity", ZSTR_VAL(attempt_identity), ZSTR_LEN(attempt_identity));
    if (run_state != NULL && run_state->recovery_count > 0) {
        add_assoc_stringl(&adapter, "retry_identity", ZSTR_VAL(attempt_identity), ZSTR_LEN(attempt_identity));
    } else {
        add_assoc_null(&adapter, "retry_identity");
    }
    zend_string_release(attempt_identity);

    add_assoc_long(
        &adapter,
        "partition_count",
        king_orchestrator_pipeline_step_metadata_count(
            pipeline,
            "partition_id",
            sizeof("partition_id") - 1
        )
    );
    add_assoc_long(
        &adapter,
        "batch_count",
        king_orchestrator_pipeline_step_metadata_count(
            pipeline,
            "batch_id",
            sizeof("batch_id") - 1
        )
    );

    failure_identity = king_orchestrator_build_failure_identity_for_snapshot(run_state);
    if (failure_identity != NULL) {
        add_assoc_stringl(&adapter, "failure_identity", ZSTR_VAL(failure_identity), ZSTR_LEN(failure_identity));
        zend_string_release(failure_identity);
    } else {
        add_assoc_null(&adapter, "failure_identity");
    }

    if (run_state != NULL && run_state->retry_disposition != NULL) {
        add_assoc_stringl(
            &adapter,
            "retry_disposition",
            ZSTR_VAL(run_state->retry_disposition),
            ZSTR_LEN(run_state->retry_disposition)
        );
    } else {
        add_assoc_null(&adapter, "retry_disposition");
    }

    failed_partition = king_orchestrator_find_pipeline_step_string(
        pipeline,
        run_state != NULL ? run_state->error_step_index : -1,
        "partition_id",
        sizeof("partition_id") - 1
    );
    if (failed_partition != NULL) {
        add_assoc_stringl(&adapter, "failed_partition_id", Z_STRVAL_P(failed_partition), Z_STRLEN_P(failed_partition));
    } else {
        add_assoc_null(&adapter, "failed_partition_id");
    }

    failed_batch = king_orchestrator_find_pipeline_step_string(
        pipeline,
        run_state != NULL ? run_state->error_step_index : -1,
        "batch_id",
        sizeof("batch_id") - 1
    );
    if (failed_batch != NULL) {
        add_assoc_stringl(&adapter, "failed_batch_id", Z_STRVAL_P(failed_batch), Z_STRLEN_P(failed_batch));
    } else {
        add_assoc_null(&adapter, "failed_batch_id");
    }

    add_assoc_zval(target, "telemetry_adapter", &adapter);
}

static void king_orchestrator_append_error_classification(
    zval *target,
    const king_orchestrator_run_state_t *run_state,
    zval *pipeline
)
{
    zval classification;
    zval *step_tool;

    if (target == NULL) {
        return;
    }

    if (
        run_state == NULL
        || (
            run_state->error_category == NULL
            && run_state->retry_disposition == NULL
            && run_state->error_backend == NULL
            && run_state->error_step_index < 0
        )
    ) {
        add_assoc_null(target, "error_classification");
        return;
    }

    array_init(&classification);
    add_assoc_string(
        &classification,
        "category",
        run_state->error_category != NULL ? ZSTR_VAL(run_state->error_category) : ""
    );
    add_assoc_string(
        &classification,
        "retry_disposition",
        run_state->retry_disposition != NULL ? ZSTR_VAL(run_state->retry_disposition) : ""
    );
    add_assoc_string(
        &classification,
        "scope",
        run_state->error_step_index >= 0 ? "step" : "run"
    );

    if (run_state->error_backend != NULL) {
        add_assoc_stringl(
            &classification,
            "backend",
            ZSTR_VAL(run_state->error_backend),
            ZSTR_LEN(run_state->error_backend)
        );
    } else {
        add_assoc_null(&classification, "backend");
    }

    if (run_state->error_step_index >= 0) {
        add_assoc_long(&classification, "step_index", run_state->error_step_index);
    } else {
        add_assoc_null(&classification, "step_index");
    }

    step_tool = king_orchestrator_find_pipeline_step_tool(pipeline, run_state->error_step_index);
    if (step_tool != NULL && Z_TYPE_P(step_tool) == IS_STRING) {
        add_assoc_stringl(
            &classification,
            "step_tool",
            Z_STRVAL_P(step_tool),
            Z_STRLEN_P(step_tool)
        );
    } else {
        add_assoc_null(&classification, "step_tool");
    }

    add_assoc_zval(target, "error_classification", &classification);
}

static zend_long king_orchestrator_pipeline_step_count(zval *pipeline)
{
    if (pipeline == NULL || Z_TYPE_P(pipeline) != IS_ARRAY) {
        return 0;
    }

    return (zend_long) zend_hash_num_elements(Z_ARRVAL_P(pipeline));
}

static const char *king_orchestrator_step_status_for_snapshot(
    const king_orchestrator_run_state_t *run_state,
    zend_long step_index
)
{
    if (run_state == NULL || run_state->status == NULL) {
        return "pending";
    }

    if (zend_string_equals_literal(run_state->status, "completed")) {
        return "completed";
    }

    if (
        zend_string_equals_literal(run_state->status, "queued")
        || zend_string_equals_literal(run_state->status, "running")
    ) {
        return step_index < run_state->completed_step_count ? "completed" : "pending";
    }

    if (
        (zend_string_equals_literal(run_state->status, "failed")
        || zend_string_equals_literal(run_state->status, "cancelled"))
        && run_state->error_step_index >= 0
    ) {
        if (
            step_index < run_state->completed_step_count
            || step_index < run_state->error_step_index
        ) {
            return "completed";
        }
        if (step_index == run_state->error_step_index) {
            return zend_string_equals_literal(run_state->status, "cancelled")
                ? "cancelled"
                : "failed";
        }
        return "pending";
    }

    if (
        zend_string_equals_literal(run_state->status, "failed")
        || zend_string_equals_literal(run_state->status, "cancelled")
    ) {
        if (step_index < run_state->completed_step_count) {
            return "completed";
        }
    }

    if (
        run_state->error_category != NULL
        && zend_string_equals_literal(run_state->error_category, "remote_transport")
    ) {
        return "indeterminate";
    }

    if (
        run_state->error_backend != NULL
        && zend_string_equals_literal(run_state->error_backend, "remote_peer")
    ) {
        return "indeterminate";
    }

    return "pending";
}

static zend_bool king_orchestrator_run_requires_compensation(
    const king_orchestrator_run_state_t *run_state
)
{
    if (
        run_state == NULL
        || run_state->status == NULL
        || run_state->completed_step_count <= 0
    ) {
        return 0;
    }

    return zend_string_equals_literal(run_state->status, "failed")
        || zend_string_equals_literal(run_state->status, "cancelled");
}

static const char *king_orchestrator_step_compensation_status_for_snapshot(
    const king_orchestrator_run_state_t *run_state,
    zend_long step_index
)
{
    if (
        run_state == NULL
        || run_state->status == NULL
        || step_index < 0
        || step_index >= run_state->completed_step_count
    ) {
        return "not_applicable";
    }

    if (zend_string_equals_literal(run_state->status, "completed")) {
        return "not_required";
    }

    if (king_orchestrator_run_requires_compensation(run_state)) {
        return "pending";
    }

    return "available";
}

static zend_long king_orchestrator_indeterminate_step_count(
    const king_orchestrator_run_state_t *run_state,
    zval *pipeline
)
{
    zend_long step_count;

    if (run_state == NULL) {
        return 0;
    }

    step_count = king_orchestrator_pipeline_step_count(pipeline);
    if (
        step_count > 0
        && (
            (run_state->error_category != NULL && zend_string_equals_literal(run_state->error_category, "remote_transport"))
            || (run_state->error_backend != NULL && zend_string_equals_literal(run_state->error_backend, "remote_peer"))
        )
        && run_state->error_step_index < 0
    ) {
        return step_count > run_state->completed_step_count
            ? step_count - run_state->completed_step_count
            : 0;
    }

    return 0;
}

static void king_orchestrator_append_compensation_snapshot(
    zval *target,
    zval *pipeline,
    const king_orchestrator_run_state_t *run_state
)
{
    zval compensation;
    zval pending_steps;
    zval entry;
    zval *tool;
    zend_long step_index;
    zend_bool required;

    if (target == NULL) {
        return;
    }

    required = king_orchestrator_run_requires_compensation(run_state);

    array_init(&compensation);
    add_assoc_string(&compensation, "policy", "caller_managed");
    add_assoc_string(&compensation, "strategy", "reverse_completed_steps");
    add_assoc_bool(&compensation, "required", required ? 1 : 0);
    add_assoc_string(
        &compensation,
        "trigger",
        required && run_state != NULL && run_state->status != NULL
            ? ZSTR_VAL(run_state->status)
            : "none"
    );
    add_assoc_long(
        &compensation,
        "pending_step_count",
        required && run_state != NULL ? run_state->completed_step_count : 0
    );

    array_init(&pending_steps);
    if (required && pipeline != NULL && Z_TYPE_P(pipeline) == IS_ARRAY && run_state != NULL) {
        for (step_index = run_state->completed_step_count - 1; step_index >= 0; step_index--) {
            array_init(&entry);
            add_assoc_long(&entry, "index", step_index);

            tool = king_orchestrator_find_pipeline_step_tool(pipeline, step_index);
            if (tool != NULL && Z_TYPE_P(tool) == IS_STRING) {
                add_assoc_stringl(&entry, "tool", Z_STRVAL_P(tool), Z_STRLEN_P(tool));
            } else {
                add_assoc_null(&entry, "tool");
            }

            add_assoc_string(&entry, "status", "pending");
            add_next_index_zval(&pending_steps, &entry);

            if (step_index == 0) {
                break;
            }
        }
    }

    add_assoc_zval(&compensation, "pending_steps", &pending_steps);
    add_assoc_zval(target, "compensation", &compensation);
}

static void king_orchestrator_append_run_observability(
    zval *target,
    const king_orchestrator_run_state_t *run_state,
    zval *pipeline
)
{
    zval observability;

    if (target == NULL) {
        return;
    }

    array_init(&observability);
    add_assoc_string(
        &observability,
        "queue_phase",
        run_state != NULL && run_state->queue_phase != NULL
            ? ZSTR_VAL(run_state->queue_phase)
            : "not_queued"
    );
    add_assoc_long(&observability, "enqueued_at", run_state != NULL ? (zend_long) run_state->enqueued_at : 0);
    add_assoc_long(&observability, "claim_count", run_state != NULL ? run_state->claim_count : 0);
    add_assoc_long(&observability, "last_claimed_at", run_state != NULL ? (zend_long) run_state->last_claimed_at : 0);
    add_assoc_long(&observability, "last_claimed_by_pid", run_state != NULL ? run_state->last_claimed_by_pid : 0);
    add_assoc_long(&observability, "recovery_count", run_state != NULL ? run_state->recovery_count : 0);
    add_assoc_long(&observability, "last_recovered_at", run_state != NULL ? (zend_long) run_state->last_recovered_at : 0);
    if (run_state != NULL && run_state->last_recovery_reason != NULL) {
        add_assoc_stringl(
            &observability,
            "last_recovery_reason",
            ZSTR_VAL(run_state->last_recovery_reason),
            ZSTR_LEN(run_state->last_recovery_reason)
        );
    } else {
        add_assoc_null(&observability, "last_recovery_reason");
    }
    add_assoc_long(&observability, "remote_attempt_count", run_state != NULL ? run_state->remote_attempt_count : 0);
    add_assoc_long(
        &observability,
        "last_remote_attempt_at",
        run_state != NULL ? (zend_long) run_state->last_remote_attempt_at : 0
    );
    add_assoc_long(&observability, "step_count", king_orchestrator_pipeline_step_count(pipeline));
    add_assoc_long(&observability, "completed_step_count", run_state != NULL ? run_state->completed_step_count : 0);
    add_assoc_long(
        &observability,
        "indeterminate_step_count",
        king_orchestrator_indeterminate_step_count(run_state, pipeline)
    );

    add_assoc_zval(target, "distributed_observability", &observability);
}

static zend_bool king_orchestrator_tool_name_in_array(
    zval *tools,
    const char *tool_name,
    size_t tool_name_len
)
{
    zval *entry;

    if (
        tools == NULL
        || Z_TYPE_P(tools) != IS_ARRAY
        || tool_name == NULL
        || tool_name_len == 0
    ) {
        return 0;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(tools), entry) {
        if (
            entry != NULL
            && Z_TYPE_P(entry) == IS_STRING
            && Z_STRLEN_P(entry) == tool_name_len
            && memcmp(Z_STRVAL_P(entry), tool_name, tool_name_len) == 0
        ) {
            return 1;
        }
    } ZEND_HASH_FOREACH_END();

    return 0;
}

static void king_orchestrator_append_handler_readiness(
    zval *handler_boundary,
    zval *target
)
{
    zval required_tools;
    zval required_step_refs;
    zval missing_tools;
    zval *requires_process_registration_value;
    zval *required_tools_value;
    zval *required_step_refs_value;
    zval *entry;
    zval step_ref;
    const char *tool_name;
    zend_bool requires_process_registration = 0;
    zend_bool ready = 1;
    zend_long required_tool_count = 0;
    zend_long required_step_count = 0;
    zend_long missing_tool_count = 0;
    size_t tool_name_len;

    if (target == NULL) {
        return;
    }

    array_init(target);

    if (handler_boundary == NULL || Z_TYPE_P(handler_boundary) != IS_ARRAY) {
        add_assoc_bool(target, "requires_process_registration", 0);
        add_assoc_bool(target, "ready", 1);
        add_assoc_long(target, "required_tool_count", 0);
        add_assoc_long(target, "required_step_count", 0);
        add_assoc_long(target, "missing_tool_count", 0);
        array_init(&required_tools);
        array_init(&required_step_refs);
        array_init(&missing_tools);
        add_assoc_zval(target, "required_tools", &required_tools);
        add_assoc_zval(target, "required_step_refs", &required_step_refs);
        add_assoc_zval(target, "missing_tools", &missing_tools);
        return;
    }

    requires_process_registration_value = zend_hash_str_find(
        Z_ARRVAL_P(handler_boundary),
        "requires_process_registration",
        sizeof("requires_process_registration") - 1
    );
    requires_process_registration = requires_process_registration_value != NULL
        && zend_is_true(requires_process_registration_value);

    required_tools_value = zend_hash_str_find(
        Z_ARRVAL_P(handler_boundary),
        "required_tools",
        sizeof("required_tools") - 1
    );
    required_step_refs_value = zend_hash_str_find(
        Z_ARRVAL_P(handler_boundary),
        "required_step_refs",
        sizeof("required_step_refs") - 1
    );

    array_init(&required_tools);
    if (required_tools_value != NULL && Z_TYPE_P(required_tools_value) == IS_ARRAY) {
        ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(required_tools_value), entry) {
            if (entry == NULL || Z_TYPE_P(entry) != IS_STRING || Z_STRLEN_P(entry) == 0) {
                continue;
            }

            tool_name = Z_STRVAL_P(entry);
            tool_name_len = Z_STRLEN_P(entry);
            if (king_orchestrator_tool_name_in_array(&required_tools, tool_name, tool_name_len)) {
                continue;
            }

            add_next_index_stringl(&required_tools, tool_name, tool_name_len);
            required_tool_count++;
        } ZEND_HASH_FOREACH_END();
    }

    array_init(&required_step_refs);
    if (required_step_refs_value != NULL && Z_TYPE_P(required_step_refs_value) == IS_ARRAY) {
        ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(required_step_refs_value), entry) {
            if (entry == NULL || Z_TYPE_P(entry) != IS_ARRAY) {
                continue;
            }

            ZVAL_DUP(&step_ref, entry);
            add_next_index_zval(&required_step_refs, &step_ref);
            required_step_count++;
        } ZEND_HASH_FOREACH_END();
    }

    array_init(&missing_tools);
    if (requires_process_registration) {
        ZEND_HASH_FOREACH_VAL(Z_ARRVAL(required_tools), entry) {
            if (entry == NULL || Z_TYPE_P(entry) != IS_STRING || Z_STRLEN_P(entry) == 0) {
                continue;
            }

            tool_name = Z_STRVAL_P(entry);
            tool_name_len = Z_STRLEN_P(entry);

            if (
                king_orchestrator_lookup_tool_handler(
                    tool_name,
                    tool_name_len
                ) != NULL
            ) {
                continue;
            }

            if (
                king_orchestrator_tool_name_in_array(
                    &missing_tools,
                    tool_name,
                    tool_name_len
                )
            ) {
                continue;
            }

            add_next_index_stringl(&missing_tools, tool_name, tool_name_len);
            missing_tool_count++;
        } ZEND_HASH_FOREACH_END();
    }

    ready = !requires_process_registration || missing_tool_count == 0;

    add_assoc_bool(target, "requires_process_registration", requires_process_registration ? 1 : 0);
    add_assoc_bool(target, "ready", ready ? 1 : 0);
    add_assoc_long(target, "required_tool_count", required_tool_count);
    add_assoc_long(target, "required_step_count", required_step_count);
    add_assoc_long(target, "missing_tool_count", missing_tool_count);
    add_assoc_zval(target, "required_tools", &required_tools);
    add_assoc_zval(target, "required_step_refs", &required_step_refs);
    add_assoc_zval(target, "missing_tools", &missing_tools);
}
