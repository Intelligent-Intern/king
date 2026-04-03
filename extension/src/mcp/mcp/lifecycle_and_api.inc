void king_mcp_state_close(king_mcp_state *state)
{
    if (state == NULL) {
        return;
    }

    state->closed = true;
    state->operation_active = false;
    state->last_error_kind = KING_MCP_ERROR_NONE;
    king_mcp_mark_transport_closed(state);
}

void king_mcp_state_free(king_mcp_state *state)
{
    if (state == NULL) {
        return;
    }

    if (state->host != NULL) {
        zend_string_release(state->host);
    }
    zval_ptr_dtor(&state->config);
    king_mcp_state_close(state);
    efree(state);
}

int king_mcp_transfer_store(
    king_mcp_state *state,
    const char *service,
    size_t service_len,
    const char *method,
    size_t method_len,
    const char *id,
    size_t id_len,
    zend_string *payload,
    king_mcp_runtime_control_t *control)
{
    int status = FAILURE;

    if (!state || state->closed || payload == NULL) {
        return FAILURE;
    }

    if (king_mcp_begin_operation(state, "MCP upload") != SUCCESS) {
        return FAILURE;
    }

    if (king_mcp_remote_connect(state, "MCP upload", control) != SUCCESS) {
        goto cleanup;
    }

    if (king_mcp_remote_send_command(
            state,
            "MCP upload",
            KING_MCP_REMOTE_OP_UPLOAD,
            service,
            service_len,
            method,
            method_len,
            id,
            id_len,
            payload,
            control
        ) != SUCCESS) {
        goto cleanup;
    }

    status = king_mcp_remote_expect_ok(state, "MCP upload", control);
    if (
        status == SUCCESS
        && king_mcp_transfer_state_store_payload(
            state,
            service,
            service_len,
            method,
            method_len,
            id,
            id_len,
            payload
        ) != SUCCESS
    ) {
        status = FAILURE;
    }

cleanup:
    king_mcp_end_operation(state);
    return status;
}

zend_string *king_mcp_transfer_find(
    king_mcp_state *state,
    const char *service,
    size_t service_len,
    const char *method,
    size_t method_len,
    const char *id,
    size_t id_len,
    king_mcp_runtime_control_t *control)
{
    zend_string *payload = NULL;
    bool missing = false;

    if (!state || state->closed) {
        return NULL;
    }

    if (king_mcp_begin_operation(state, "MCP download") != SUCCESS) {
        return NULL;
    }

    if (king_mcp_remote_connect(state, "MCP download", control) != SUCCESS) {
        goto cleanup;
    }

    if (king_mcp_remote_send_command(
            state,
            "MCP download",
            KING_MCP_REMOTE_OP_DOWNLOAD,
            service,
            service_len,
            method,
            method_len,
            id,
            id_len,
            NULL,
            control
        ) != SUCCESS) {
        goto cleanup;
    }

    if (king_mcp_remote_expect_payload(
            state,
            "MCP download",
            &payload,
            &missing,
            control
        ) != SUCCESS) {
        goto cleanup;
    }

cleanup:
    king_mcp_end_operation(state);
    if (missing) {
        if (payload != NULL) {
            zend_string_release(payload);
        }
        return king_mcp_transfer_state_find_payload(
            state,
            service,
            service_len,
            method,
            method_len,
            id,
            id_len
        );
    }

    return payload;
}

int king_mcp_transfer_acknowledge(
    king_mcp_state *state,
    const char *service,
    size_t service_len,
    const char *method,
    size_t method_len,
    const char *id,
    size_t id_len)
{
    if (state == NULL || state->closed) {
        return FAILURE;
    }

    return king_mcp_transfer_state_delete_payload(
        state,
        service,
        service_len,
        method,
        method_len,
        id,
        id_len
    );
}

int king_mcp_request(
    king_mcp_state *state,
    const char *service,
    size_t service_len,
    const char *method,
    size_t method_len,
    zend_string *payload,
    zend_string **response_out,
    king_mcp_runtime_control_t *control)
{
    int status = FAILURE;

    if (!state || state->closed || payload == NULL || response_out == NULL) {
        return FAILURE;
    }

    *response_out = NULL;

    if (king_mcp_begin_operation(state, "MCP request") != SUCCESS) {
        return FAILURE;
    }

    if (king_mcp_remote_connect(state, "MCP request", control) != SUCCESS) {
        goto cleanup;
    }

    if (king_mcp_remote_send_command(
            state,
            "MCP request",
            KING_MCP_REMOTE_OP_REQUEST,
            service,
            service_len,
            method,
            method_len,
            NULL,
            0,
            payload,
            control
        ) != SUCCESS) {
        goto cleanup;
    }

    status = king_mcp_remote_expect_payload(
        state,
        "MCP request",
        response_out,
        NULL,
        control
    );
    if (status == SUCCESS && *response_out == NULL) {
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_PROTOCOL,
            "MCP request completed without a response payload from the remote MCP peer."
        );
        status = FAILURE;
    }

cleanup:
    king_mcp_end_operation(state);
    return status;
}
