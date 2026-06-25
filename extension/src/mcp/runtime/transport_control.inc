static void king_mcp_mark_transport_closed(king_mcp_state *state)
{
    if (state == NULL || state->transport_stream == NULL) {
        return;
    }

    php_stream_close(state->transport_stream);
    state->transport_stream = NULL;
}

static zend_result king_mcp_begin_operation(
    king_mcp_state *state,
    const char *operation_name
)
{
    if (state == NULL || state->closed) {
        return FAILURE;
    }

    if (state->operation_active) {
        king_mcp_set_errorf(
            "%s cannot start while another MCP operation is already active on this connection.",
            operation_name
        );
        return FAILURE;
    }

    king_mcp_state_clear_error_kind(state);
    state->operation_active = true;
    return SUCCESS;
}

static void king_mcp_end_operation(king_mcp_state *state)
{
    if (state != NULL) {
        state->operation_active = false;
    }
}

static zend_string *king_mcp_build_transport_target(king_mcp_state *state)
{
    const char *host;
    size_t host_len;
    bool needs_brackets;

    if (state == NULL || state->host == NULL) {
        return NULL;
    }

    host = ZSTR_VAL(state->host);
    host_len = ZSTR_LEN(state->host);
    needs_brackets =
        memchr(host, ':', host_len) != NULL
        && !(host_len >= 2 && host[0] == '[' && host[host_len - 1] == ']');

    if (needs_brackets) {
        return strpprintf(0, "tcp://[%s]:" ZEND_LONG_FMT, host, state->port);
    }

    return strpprintf(0, "tcp://%s:" ZEND_LONG_FMT, host, state->port);
}

static zend_long king_mcp_default_transport_timeout_ms(void)
{
    if (king_mcp_orchestrator_config.mcp_default_request_timeout_ms > 0) {
        return king_mcp_orchestrator_config.mcp_default_request_timeout_ms;
    }

    return 30000;
}

static size_t king_mcp_remote_line_limit(void)
{
    size_t max_payload_bytes = 4194304;

    if (king_mcp_orchestrator_config.mcp_max_message_size_bytes > 0) {
        max_payload_bytes = (size_t) king_mcp_orchestrator_config.mcp_max_message_size_bytes;
    }

    if (max_payload_bytes > (((size_t) -1) / 2)) {
        max_payload_bytes = ((size_t) -1) / 2;
    }

    return (max_payload_bytes * 2) + KING_MCP_REMOTE_LINE_OVERHEAD;
}

static uint64_t king_mcp_monotonic_time_ms(void)
{
    return king_hrtime_ms();
}

static zend_long king_mcp_control_timeout_budget_ms(const king_mcp_runtime_control_t *control)
{
    uint64_t elapsed_ms;
    uint64_t remaining_ms;

    if (control == NULL || control->timeout_ms <= 0) {
        return 0;
    }

    elapsed_ms = king_mcp_monotonic_time_ms() - control->started_at_ms;
    if (elapsed_ms >= (uint64_t) control->timeout_ms) {
        return 0;
    }

    remaining_ms = (uint64_t) control->timeout_ms - elapsed_ms;
    if (remaining_ms > (uint64_t) ZEND_LONG_MAX) {
        remaining_ms = (uint64_t) ZEND_LONG_MAX;
    }

    return (zend_long) remaining_ms;
}

static zend_long king_mcp_control_deadline_budget_ms(const king_mcp_runtime_control_t *control)
{
    uint64_t now_ms;
    uint64_t remaining_ms;

    if (control == NULL || control->deadline_ms == 0) {
        return 0;
    }

    now_ms = king_mcp_monotonic_time_ms();
    if (now_ms >= control->deadline_ms) {
        return 0;
    }

    remaining_ms = control->deadline_ms - now_ms;
    if (remaining_ms > (uint64_t) ZEND_LONG_MAX) {
        remaining_ms = (uint64_t) ZEND_LONG_MAX;
    }

    return (zend_long) remaining_ms;
}

static zend_long king_mcp_control_effective_budget_ms(const king_mcp_runtime_control_t *control)
{
    zend_long timeout_budget_ms;
    zend_long deadline_budget_ms;

    if (control == NULL) {
        return king_mcp_default_transport_timeout_ms();
    }

    if (king_transport_cancel_token_is_cancelled(control->cancel_token)) {
        return 0;
    }

    timeout_budget_ms = king_mcp_control_timeout_budget_ms(control);
    deadline_budget_ms = king_mcp_control_deadline_budget_ms(control);

    if (timeout_budget_ms > 0 && deadline_budget_ms > 0) {
        return timeout_budget_ms < deadline_budget_ms
            ? timeout_budget_ms
            : deadline_budget_ms;
    }

    if (timeout_budget_ms > 0) {
        return timeout_budget_ms;
    }

    if (deadline_budget_ms > 0) {
        return deadline_budget_ms;
    }

    return king_mcp_default_transport_timeout_ms();
}

static zend_long king_mcp_control_transport_budget_ms(const king_mcp_runtime_control_t *control)
{
    zend_long budget_ms = king_mcp_control_effective_budget_ms(control);

    if (control == NULL) {
        return budget_ms;
    }

    if (king_transport_cancel_token_is_cancelled(control->cancel_token)) {
        return 0;
    }

    if (budget_ms <= 0) {
        if (control->timeout_ms > 0 || control->deadline_ms > 0) {
            return KING_TRANSPORT_INTERRUPT_SLICE_MS;
        }
        return 0;
    }

    if (control->timeout_ms > 0 || control->deadline_ms > 0) {
        if (budget_ms <= ZEND_LONG_MAX - KING_TRANSPORT_INTERRUPT_SLICE_MS) {
            budget_ms += KING_TRANSPORT_INTERRUPT_SLICE_MS;
        }
    }

    return budget_ms;
}

static zend_result king_mcp_prepare_stream_transport(
    king_mcp_state *state,
    zend_long timeout_ms
)
{
    struct timeval timeout;
    php_socket_t socketd = -1;

    if (state == NULL || state->transport_stream == NULL) {
        return FAILURE;
    }

    if (timeout_ms <= 0) {
        timeout_ms = king_mcp_default_transport_timeout_ms();
    }

    timeout.tv_sec = timeout_ms / 1000;
    timeout.tv_usec = (timeout_ms % 1000) * 1000;

    php_stream_set_option(state->transport_stream, PHP_STREAM_OPTION_BLOCKING, 0, NULL);
    php_stream_set_option(
        state->transport_stream,
        PHP_STREAM_OPTION_READ_TIMEOUT,
        0,
        &timeout
    );

    if (php_stream_cast(
            state->transport_stream,
            PHP_STREAM_AS_SOCKETD,
            (void *) &socketd,
            1
        ) == SUCCESS) {
        php_set_sock_blocking(socketd, false);
    }

    return SUCCESS;
}

static zend_result king_mcp_remote_wait_socket(
    king_mcp_state *state,
    int events,
    king_mcp_runtime_control_t *control,
    const char *operation_name,
    const char *phase
)
{
    php_socket_t socketd = -1;
    zend_long timeout_ms;
    uint64_t deadline_ms;

    if (state == NULL || state->transport_stream == NULL) {
        return FAILURE;
    }

    if (php_stream_cast(
            state->transport_stream,
            PHP_STREAM_AS_SOCKETD,
            (void *) &socketd,
            1
        ) != SUCCESS) {
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_TRANSPORT,
            "%s could not access the active remote MCP transport socket.",
            operation_name
        );
        return FAILURE;
    }

    timeout_ms = king_mcp_control_transport_budget_ms(control);
    if (timeout_ms <= 0) {
        king_mcp_mark_transport_closed(state);
        return FAILURE;
    }

    deadline_ms = king_mcp_monotonic_time_ms() + (uint64_t) timeout_ms;

    for (;;) {
        uint64_t now_ms;
        zend_long remaining_ms;
        zend_long wait_timeout_ms;
        int poll_result;

        king_process_pending_interrupts();

        if (control != NULL && king_transport_cancel_token_is_cancelled(control->cancel_token)) {
            king_mcp_mark_transport_closed(state);
            return FAILURE;
        }

        now_ms = king_mcp_monotonic_time_ms();
        if (now_ms >= deadline_ms) {
            king_mcp_mark_transport_closed(state);
            if (king_get_error()[0] == '\0') {
                king_mcp_state_set_errorf(
                    state,
                    KING_MCP_ERROR_TRANSPORT,
                    "%s timed out while waiting on the remote MCP peer socket during the %s phase.",
                    operation_name,
                    phase
                );
            }
            return FAILURE;
        }

        remaining_ms = (zend_long) (deadline_ms - now_ms);
        wait_timeout_ms = remaining_ms > KING_TRANSPORT_INTERRUPT_SLICE_MS
            ? KING_TRANSPORT_INTERRUPT_SLICE_MS
            : remaining_ms;

        poll_result = php_pollfd_for_ms(socketd, events, (int) wait_timeout_ms);
        if (poll_result == 0) {
            continue;
        }

        if (poll_result < 0) {
            if (errno == EINTR) {
                continue;
            }

            king_mcp_mark_transport_closed(state);
            king_mcp_state_set_errorf(
                state,
                KING_MCP_ERROR_TRANSPORT,
                "%s failed while waiting on the remote MCP peer socket during the %s phase (errno %d).",
                operation_name,
                phase,
                errno
            );
            return FAILURE;
        }

        if ((poll_result & (POLLERR | POLLHUP | POLLNVAL)) != 0) {
            king_mcp_mark_transport_closed(state);
            king_mcp_state_set_errorf(
                state,
                KING_MCP_ERROR_TRANSPORT,
                "%s detected a remote MCP peer socket error during the %s phase.",
                operation_name,
                phase
            );
            return FAILURE;
        }

        if ((poll_result & events) == 0) {
            king_mcp_mark_transport_closed(state);
            king_mcp_state_set_errorf(
                state,
                KING_MCP_ERROR_TRANSPORT,
                "%s did not observe the expected remote MCP socket readiness during the %s phase.",
                operation_name,
                phase
            );
            return FAILURE;
        }

        return SUCCESS;
    }
}

static zend_result king_mcp_remote_connect(
    king_mcp_state *state,
    const char *operation_name,
    king_mcp_runtime_control_t *control
)
{
    zend_string *target = NULL;
    zend_string *transport_error = NULL;
    php_stream *stream = NULL;
    struct timeval timeout;
    int transport_error_code = 0;
    zend_long timeout_ms;

    if (state == NULL || state->closed) {
        return FAILURE;
    }

    if (state->transport_stream != NULL) {
        if (!php_stream_eof(state->transport_stream)) {
            return SUCCESS;
        }

        king_mcp_mark_transport_closed(state);
    }

    target = king_mcp_build_transport_target(state);
    if (target == NULL) {
        king_mcp_state_set_error_message(
            state,
            KING_MCP_ERROR_TRANSPORT,
            "MCP runtime could not build the remote transport target."
        );
        return FAILURE;
    }

    timeout_ms = king_mcp_control_effective_budget_ms(control);
    if (timeout_ms <= 0) {
        king_set_error("");
        zend_string_release(target);
        return FAILURE;
    }
    timeout.tv_sec = timeout_ms / 1000;
    timeout.tv_usec = (timeout_ms % 1000) * 1000;

    stream = php_stream_xport_create(
        ZSTR_VAL(target),
        ZSTR_LEN(target),
        0,
        STREAM_XPORT_CLIENT | STREAM_XPORT_CONNECT,
        NULL,
        &timeout,
        NULL,
        &transport_error,
        &transport_error_code
    );
    zend_string_release(target);

    if (stream == NULL) {
        if (transport_error != NULL) {
            king_mcp_state_set_errorf(
                state,
                KING_MCP_ERROR_TRANSPORT,
                "%s failed to connect to the remote MCP peer: %s",
                operation_name,
                ZSTR_VAL(transport_error)
            );
            zend_string_release(transport_error);
        } else {
            king_mcp_state_set_errorf(
                state,
                KING_MCP_ERROR_TRANSPORT,
                "%s failed to connect to the remote MCP peer (code %d).",
                operation_name,
                transport_error_code
            );
        }
        return FAILURE;
    }

    state->transport_stream = stream;
    if (king_mcp_prepare_stream_transport(state, timeout_ms) != SUCCESS) {
        king_mcp_mark_transport_closed(state);
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_TRANSPORT,
            "%s could not configure the active MCP transport socket.",
            operation_name
        );
        return FAILURE;
    }
    king_set_error("");
    return SUCCESS;
}
