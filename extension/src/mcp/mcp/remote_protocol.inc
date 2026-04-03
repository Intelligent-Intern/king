static zend_result king_mcp_remote_write_all(
    king_mcp_state *state,
    const char *buffer,
    size_t buffer_len,
    const char *operation_name,
    king_mcp_runtime_control_t *control
)
{
    size_t written = 0;

    while (written < buffer_len) {
        int chunk;

        if (king_mcp_remote_wait_socket(
                state,
                POLLOUT,
                control,
                operation_name,
                "write"
            ) != SUCCESS) {
            return FAILURE;
        }

        chunk = php_stream_xport_sendto(
            state->transport_stream,
            buffer + written,
            buffer_len - written,
            0,
            NULL,
            0
        );

        if (chunk <= 0) {
            king_mcp_mark_transport_closed(state);
            if (king_get_error()[0] == '\0') {
                king_mcp_state_set_errorf(
                    state,
                    KING_MCP_ERROR_TRANSPORT,
                    "%s failed while writing the remote MCP command to the active peer socket.",
                    operation_name
                );
            }
            return FAILURE;
        }

        written += (size_t) chunk;
    }

    return SUCCESS;
}

static zend_string *king_mcp_remote_read_line(
    king_mcp_state *state,
    const char *operation_name,
    king_mcp_runtime_control_t *control
)
{
    char *buffer;
    size_t buffer_len;
    size_t used = 0;
    zend_string *result;

    buffer_len = king_mcp_remote_line_limit();
    buffer = emalloc(buffer_len);
    buffer[0] = '\0';

    while (used + 1 < buffer_len) {
        char *newline;
        int chunk;
        size_t line_len;

        if (king_mcp_remote_wait_socket(
                state,
                POLLIN,
                control,
                operation_name,
                "read"
            ) != SUCCESS) {
            efree(buffer);
            return NULL;
        }

        chunk = php_stream_xport_recvfrom(
            state->transport_stream,
            buffer + used,
            (buffer_len - used) - 1,
            0,
            NULL,
            NULL,
            NULL
        );
        if (chunk <= 0) {
            efree(buffer);
            king_mcp_mark_transport_closed(state);
            if (king_get_error()[0] == '\0') {
                king_mcp_state_set_errorf(
                    state,
                    KING_MCP_ERROR_TRANSPORT,
                    "%s did not receive a complete response line from the remote MCP peer.",
                    operation_name
                );
            }
            return NULL;
        }

        used += (size_t) chunk;
        buffer[used] = '\0';
        newline = memchr(buffer, '\n', used);
        if (newline == NULL) {
            continue;
        }

        line_len = (size_t) (newline - buffer);
        if (line_len > 0 && buffer[line_len - 1] == '\r') {
            line_len--;
        }

        result = zend_string_init(buffer, line_len, 0);
        efree(buffer);
        return result;
    }

    efree(buffer);
    king_mcp_mark_transport_closed(state);
    king_mcp_state_set_errorf(
        state,
        KING_MCP_ERROR_TRANSPORT,
        "%s received an oversized response line from the remote MCP peer.",
        operation_name
    );
    return NULL;
}

static zend_string *king_mcp_base64_decode_field(
    king_mcp_state *state,
    const char *encoded,
    size_t encoded_len,
    const char *operation_name
)
{
    zend_string *decoded;

    decoded = php_base64_decode(
        (const unsigned char *) encoded,
        encoded_len
    );
    if (decoded == NULL) {
        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_PROTOCOL,
            "%s received invalid base64 payload data from the remote MCP peer.",
            operation_name
        );
        return NULL;
    }

    return decoded;
}

static zend_result king_mcp_remote_send_command(
    king_mcp_state *state,
    const char *operation_name,
    const char *opcode,
    const char *service,
    size_t service_len,
    const char *method,
    size_t method_len,
    const char *identifier,
    size_t identifier_len,
    zend_string *payload,
    king_mcp_runtime_control_t *control
)
{
    zend_string *encoded_service = NULL;
    zend_string *encoded_method = NULL;
    zend_string *encoded_identifier = NULL;
    zend_string *encoded_payload = NULL;
    smart_str command = {0};
    zend_long timeout_budget_ms;
    zend_long deadline_budget_ms;
    zend_result status = FAILURE;

    encoded_service = php_base64_encode(
        (const unsigned char *) service,
        service_len
    );
    encoded_method = php_base64_encode(
        (const unsigned char *) method,
        method_len
    );
    if (identifier != NULL) {
        encoded_identifier = php_base64_encode(
            (const unsigned char *) identifier,
            identifier_len
        );
    }
    if (payload != NULL) {
        encoded_payload = php_base64_encode(
            (const unsigned char *) ZSTR_VAL(payload),
            ZSTR_LEN(payload)
        );
    }

    if (encoded_service == NULL || encoded_method == NULL
        || (identifier != NULL && encoded_identifier == NULL)
        || (payload != NULL && encoded_payload == NULL)) {
        king_mcp_set_errorf("%s failed while encoding the remote MCP command.", operation_name);
        goto cleanup;
    }

    smart_str_appends(&command, opcode);
    smart_str_appendc(&command, '\t');
    smart_str_append(&command, encoded_service);
    smart_str_appendc(&command, '\t');
    smart_str_append(&command, encoded_method);

    if (encoded_identifier != NULL) {
        smart_str_appendc(&command, '\t');
        smart_str_append(&command, encoded_identifier);
    }

    if (encoded_payload != NULL) {
        smart_str_appendc(&command, '\t');
        smart_str_append(&command, encoded_payload);
    }

    timeout_budget_ms = king_mcp_control_timeout_budget_ms(control);
    deadline_budget_ms = king_mcp_control_deadline_budget_ms(control);
    smart_str_append_printf(&command, "\t%ld\t%ld", timeout_budget_ms, deadline_budget_ms);
    smart_str_appendc(&command, '\n');
    smart_str_0(&command);

    if (command.s == NULL) {
        king_mcp_set_errorf("%s failed while materializing the remote MCP command.", operation_name);
        goto cleanup;
    }

    status = king_mcp_remote_write_all(
        state,
        ZSTR_VAL(command.s),
        ZSTR_LEN(command.s),
        operation_name,
        control
    );

cleanup:
    if (encoded_service != NULL) {
        zend_string_release(encoded_service);
    }
    if (encoded_method != NULL) {
        zend_string_release(encoded_method);
    }
    if (encoded_identifier != NULL) {
        zend_string_release(encoded_identifier);
    }
    if (encoded_payload != NULL) {
        zend_string_release(encoded_payload);
    }
    smart_str_free(&command);

    return status;
}

static zend_result king_mcp_remote_expect_ok(
    king_mcp_state *state,
    const char *operation_name,
    king_mcp_runtime_control_t *control
)
{
    zend_string *line;
    char *tab;
    zend_string *decoded_error;

    line = king_mcp_remote_read_line(state, operation_name, control);
    if (line == NULL) {
        return FAILURE;
    }

    if (zend_string_equals_literal(line, "OK")) {
        zend_string_release(line);
        return SUCCESS;
    }

    tab = strchr(ZSTR_VAL(line), '\t');
    if (tab != NULL) {
        *tab = '\0';
        if (strcmp(ZSTR_VAL(line), "ERR") == 0) {
            decoded_error = king_mcp_base64_decode_field(
                state,
                tab + 1,
                strlen(tab + 1),
                operation_name
            );
            zend_string_release(line);
            if (decoded_error == NULL) {
                return FAILURE;
            }

            king_mcp_state_set_error_message(
                state,
                KING_MCP_ERROR_PROTOCOL,
                ZSTR_VAL(decoded_error)
            );
            zend_string_release(decoded_error);
            return FAILURE;
        }
    }

    king_mcp_state_set_errorf(
        state,
        KING_MCP_ERROR_PROTOCOL,
        "%s received an invalid acknowledgement from the remote MCP peer.",
        operation_name
    );
    zend_string_release(line);
    return FAILURE;
}

static zend_result king_mcp_remote_expect_payload(
    king_mcp_state *state,
    const char *operation_name,
    zend_string **payload_out,
    bool *missing_out,
    king_mcp_runtime_control_t *control
)
{
    zend_string *line;
    char *tab;
    zend_string *decoded;

    if (payload_out == NULL) {
        return FAILURE;
    }

    *payload_out = NULL;
    if (missing_out != NULL) {
        *missing_out = false;
    }

    line = king_mcp_remote_read_line(state, operation_name, control);
    if (line == NULL) {
        return FAILURE;
    }

    if (zend_string_equals_literal(line, "MISS")) {
        if (missing_out != NULL) {
            *missing_out = true;
            zend_string_release(line);
            king_set_error("");
            return SUCCESS;
        }

        king_mcp_state_set_errorf(
            state,
            KING_MCP_ERROR_PROTOCOL,
            "%s received an unexpected missing-payload response from the remote MCP peer.",
            operation_name
        );
        zend_string_release(line);
        return FAILURE;
    }

    tab = strchr(ZSTR_VAL(line), '\t');
    if (tab != NULL) {
        *tab = '\0';
        if (strcmp(ZSTR_VAL(line), "OK") == 0) {
            decoded = king_mcp_base64_decode_field(
                state,
                tab + 1,
                strlen(tab + 1),
                operation_name
            );
            zend_string_release(line);
            if (decoded == NULL) {
                return FAILURE;
            }

            *payload_out = decoded;
            king_set_error("");
            return SUCCESS;
        }

        if (strcmp(ZSTR_VAL(line), "ERR") == 0) {
            decoded = king_mcp_base64_decode_field(
                state,
                tab + 1,
                strlen(tab + 1),
                operation_name
            );
            zend_string_release(line);
            if (decoded == NULL) {
                return FAILURE;
            }

            king_mcp_state_set_error_message(
                state,
                KING_MCP_ERROR_PROTOCOL,
                ZSTR_VAL(decoded)
            );
            zend_string_release(decoded);
            return FAILURE;
        }
    }

    king_mcp_state_set_errorf(
        state,
        KING_MCP_ERROR_PROTOCOL,
        "%s received an invalid payload response from the remote MCP peer.",
        operation_name
    );
    zend_string_release(line);
    return FAILURE;
}

king_mcp_state *king_mcp_state_create(
    const char *host,
    size_t host_len,
    zend_long port,
    zval *config)
{
    zend_string *normalized_host = NULL;
    king_mcp_state *state = ecalloc(1, sizeof(*state));

    if (king_mcp_validate_peer_target(host, host_len, port, &normalized_host) != SUCCESS) {
        efree(state);
        return NULL;
    }

    state->host = normalized_host;
    state->port = port;
    ZVAL_UNDEF(&state->config);
    state->transport_stream = NULL;
    if (config != NULL && Z_TYPE_P(config) != IS_NULL) {
        ZVAL_COPY(&state->config, config);
    }
    state->closed = false;
    state->operation_active = false;
    state->last_error_kind = KING_MCP_ERROR_NONE;

    return state;
}

