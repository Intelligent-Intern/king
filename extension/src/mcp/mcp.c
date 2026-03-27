/*
 * =========================================================================
 * FILENAME:   src/mcp/mcp.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Implementation of the native MCP runtime. Manages connection lifecycle and
 * a small remote line-framed peer protocol for request/upload/download flows.
 * =========================================================================
 */
#include "include/mcp/mcp.h"
#include "include/php_king.h"
#include "include/config/mcp_and_orchestrator/base_layer.h"

#include "Zend/zend_smart_str.h"
#include "ext/standard/base64.h"
#include "main/php_network.h"

#include <errno.h>
#include <stdarg.h>
#include <string.h>

#define KING_MCP_REMOTE_LINE_OVERHEAD 4096
#define KING_MCP_REMOTE_OP_REQUEST "REQ"
#define KING_MCP_REMOTE_OP_UPLOAD "PUT"
#define KING_MCP_REMOTE_OP_DOWNLOAD "GET"

static void king_mcp_set_errorf(const char *format, ...)
{
    char message[KING_ERR_LEN];
    va_list args;

    va_start(args, format);
    vsnprintf(message, sizeof(message), format, args);
    va_end(args);

    king_set_error(message);
}

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
        return strpprintf(0, "tcp://[%s]:%ld", host, state->port);
    }

    return strpprintf(0, "tcp://%s:%ld", host, state->port);
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
    return (uint64_t) (zend_hrtime() / 1000000ULL);
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
        king_mcp_set_errorf(
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
                king_mcp_set_errorf(
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
            king_mcp_set_errorf(
                "%s failed while waiting on the remote MCP peer socket during the %s phase (errno %d).",
                operation_name,
                phase,
                errno
            );
            return FAILURE;
        }

        if ((poll_result & (POLLERR | POLLHUP | POLLNVAL)) != 0) {
            king_mcp_mark_transport_closed(state);
            king_mcp_set_errorf(
                "%s detected a remote MCP peer socket error during the %s phase.",
                operation_name,
                phase
            );
            return FAILURE;
        }

        if ((poll_result & events) == 0) {
            king_mcp_mark_transport_closed(state);
            king_mcp_set_errorf(
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
        king_set_error("MCP runtime could not build the remote transport target.");
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
            king_mcp_set_errorf(
                "%s failed to connect to the remote MCP peer: %s",
                operation_name,
                ZSTR_VAL(transport_error)
            );
            zend_string_release(transport_error);
        } else {
            king_mcp_set_errorf(
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
        king_mcp_set_errorf(
            "%s could not configure the active MCP transport socket.",
            operation_name
        );
        return FAILURE;
    }
    king_set_error("");
    return SUCCESS;
}

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
                king_mcp_set_errorf(
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
                king_mcp_set_errorf(
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
    king_mcp_set_errorf(
        "%s received an oversized response line from the remote MCP peer.",
        operation_name
    );
    return NULL;
}

static zend_string *king_mcp_base64_decode_field(
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
        king_mcp_set_errorf(
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
                tab + 1,
                strlen(tab + 1),
                operation_name
            );
            zend_string_release(line);
            if (decoded_error == NULL) {
                return FAILURE;
            }

            king_set_error(ZSTR_VAL(decoded_error));
            zend_string_release(decoded_error);
            return FAILURE;
        }
    }

    king_mcp_set_errorf(
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
        }
        zend_string_release(line);
        king_set_error("");
        return SUCCESS;
    }

    tab = strchr(ZSTR_VAL(line), '\t');
    if (tab != NULL) {
        *tab = '\0';
        if (strcmp(ZSTR_VAL(line), "OK") == 0) {
            decoded = king_mcp_base64_decode_field(
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
                tab + 1,
                strlen(tab + 1),
                operation_name
            );
            zend_string_release(line);
            if (decoded == NULL) {
                return FAILURE;
            }

            king_set_error(ZSTR_VAL(decoded));
            zend_string_release(decoded);
            return FAILURE;
        }
    }

    king_mcp_set_errorf(
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
    king_mcp_state *state = ecalloc(1, sizeof(*state));

    state->host = zend_string_init(host, host_len, 0);
    state->port = port;
    ZVAL_UNDEF(&state->config);
    state->transport_stream = NULL;
    if (config != NULL && Z_TYPE_P(config) != IS_NULL) {
        ZVAL_COPY(&state->config, config);
    }
    state->closed = false;
    state->operation_active = false;

    return state;
}

void king_mcp_state_close(king_mcp_state *state)
{
    if (state == NULL) {
        return;
    }

    state->closed = true;
    state->operation_active = false;
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
        return NULL;
    }

    return payload;
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

cleanup:
    king_mcp_end_operation(state);
    return status;
}
