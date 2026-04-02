/*
 * Core pipeline-orchestrator runner. Owns the execution loop, run-control and
 * cancel semantics, remote-peer/file-worker handoff paths, step-level error
 * classification and the persisted step snapshots used by resume/recovery.
 */
#include "php_king.h"
#include "include/config/mcp_and_orchestrator/base_layer.h"
#include "include/pipeline_orchestrator/orchestrator.h"
#include "ext/standard/base64.h"
#include "ext/standard/php_var.h"
#include "zend_smart_str.h"
#include <time.h>
#include <unistd.h>

typedef struct _king_orchestrator_exec_control {
    zend_long timeout_ms;
    zend_long max_concurrency;
    uint64_t deadline_ms;
    uint64_t started_at_ms;
    zval cancel_token;
    zend_string *run_id;
} king_orchestrator_exec_control_t;

typedef struct _king_orchestrator_error_meta {
    char category[32];
    char retry_disposition[32];
    char backend[32];
    zend_long step_index;
    zend_bool has_classification;
} king_orchestrator_error_meta_t;

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

static void king_orchestrator_error_meta_init(king_orchestrator_error_meta_t *meta)
{
    if (meta == NULL) {
        return;
    }

    memset(meta, 0, sizeof(*meta));
    meta->step_index = -1;
    meta->has_classification = 0;
}

static void king_orchestrator_error_meta_copy_bounded(
    char *destination,
    size_t destination_size,
    const char *source
)
{
    if (destination == NULL || destination_size == 0) {
        return;
    }

    destination[0] = '\0';
    if (source == NULL) {
        return;
    }

    strncpy(destination, source, destination_size - 1);
    destination[destination_size - 1] = '\0';
}

static void king_orchestrator_error_meta_set(
    king_orchestrator_error_meta_t *meta,
    const char *category,
    const char *retry_disposition,
    zend_long step_index,
    const char *backend
)
{
    if (meta == NULL) {
        return;
    }

    king_orchestrator_error_meta_init(meta);
    king_orchestrator_error_meta_copy_bounded(meta->category, sizeof(meta->category), category);
    king_orchestrator_error_meta_copy_bounded(
        meta->retry_disposition,
        sizeof(meta->retry_disposition),
        retry_disposition
    );
    king_orchestrator_error_meta_copy_bounded(meta->backend, sizeof(meta->backend), backend);
    meta->step_index = step_index;
    meta->has_classification = 1;
}

static zend_string *king_orchestrator_remote_serialize_zval(zval *value)
{
    smart_str buffer = {0};
    php_serialize_data_t var_hash;

    PHP_VAR_SERIALIZE_INIT(var_hash);
    php_var_serialize(&buffer, value, &var_hash);
    PHP_VAR_SERIALIZE_DESTROY(var_hash);
    smart_str_0(&buffer);

    return buffer.s;
}

static zend_string *king_orchestrator_remote_encode_zval_base64(zval *value)
{
    zend_string *serialized;
    zend_string *encoded;

    if (value == NULL) {
        zval null_value;

        ZVAL_NULL(&null_value);
        serialized = king_orchestrator_remote_serialize_zval(&null_value);
    } else {
        serialized = king_orchestrator_remote_serialize_zval(value);
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

static zend_bool king_orchestrator_remote_value_tree_is_safe(
    zval *value,
    HashTable *seen_arrays
)
{
    zval *entry;
    zend_ulong array_key;

    if (value == NULL) {
        return 0;
    }

    switch (Z_TYPE_P(value)) {
        case IS_NULL:
        case IS_FALSE:
        case IS_TRUE:
        case IS_LONG:
        case IS_DOUBLE:
        case IS_STRING:
            return 1;
        case IS_ARRAY:
            array_key = (zend_ulong) (uintptr_t) Z_ARR_P(value);
            if (zend_hash_index_exists(seen_arrays, array_key)) {
                return 1;
            }
            zend_hash_index_add_empty_element(seen_arrays, array_key);
            ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(value), entry) {
                if (!king_orchestrator_remote_value_tree_is_safe(entry, seen_arrays)) {
                    return 0;
                }
            } ZEND_HASH_FOREACH_END();
            return 1;
        case IS_REFERENCE:
        case IS_OBJECT:
        case IS_RESOURCE:
        default:
            return 0;
    }
}

static zend_result king_orchestrator_remote_decode_base64_zval(
    zend_string *encoded_value,
    zval *return_value
)
{
    zend_string *decoded_payload;
    const unsigned char *cursor;
    const unsigned char *end;
    php_unserialize_data_t var_hash;
    HashTable allowed_classes;
    HashTable seen_arrays;
    zend_result result = FAILURE;

    if (encoded_value == NULL || return_value == NULL) {
        return FAILURE;
    }

    decoded_payload = php_base64_decode(
        (const unsigned char *) ZSTR_VAL(encoded_value),
        ZSTR_LEN(encoded_value)
    );
    if (decoded_payload == NULL) {
        return FAILURE;
    }

    cursor = (const unsigned char *) ZSTR_VAL(decoded_payload);
    end = cursor + ZSTR_LEN(decoded_payload);
    ZVAL_NULL(return_value);

    PHP_VAR_UNSERIALIZE_INIT(var_hash);
    zend_hash_init(&allowed_classes, 0, NULL, NULL, 0);
    php_var_unserialize_set_allowed_classes(var_hash, &allowed_classes);
    if (!php_var_unserialize(
            return_value,
            &cursor,
            end,
            &var_hash)) {
        goto cleanup;
    }
    if (cursor != end) {
        goto cleanup;
    }
    zend_hash_init(&seen_arrays, 0, NULL, NULL, 0);
    if (!king_orchestrator_remote_value_tree_is_safe(return_value, &seen_arrays)) {
        zend_hash_destroy(&seen_arrays);
        goto cleanup;
    }
    zend_hash_destroy(&seen_arrays);
    result = SUCCESS;

cleanup:
    PHP_VAR_UNSERIALIZE_DESTROY(var_hash);
    zend_hash_destroy(&allowed_classes);
    zend_string_release(decoded_payload);
    if (result != SUCCESS) {
        zval_ptr_dtor(return_value);
        ZVAL_NULL(return_value);
    }

    return result;
}

static zend_string *king_orchestrator_remote_build_target(void)
{
    const char *host;
    size_t host_len;
    bool needs_brackets;

    if (
        king_mcp_orchestrator_config.orchestrator_remote_host == NULL
        || king_mcp_orchestrator_config.orchestrator_remote_host[0] == '\0'
    ) {
        return NULL;
    }

    host = king_mcp_orchestrator_config.orchestrator_remote_host;
    host_len = strlen(host);
    needs_brackets =
        memchr(host, ':', host_len) != NULL
        && !(host_len >= 2 && host[0] == '[' && host[host_len - 1] == ']');

    if (needs_brackets) {
        return strpprintf(
            0,
            "tcp://[%s]:%ld",
            host,
            king_mcp_orchestrator_config.orchestrator_remote_port
        );
    }

    return strpprintf(
        0,
        "tcp://%s:%ld",
        host,
        king_mcp_orchestrator_config.orchestrator_remote_port
    );
}

static zend_long king_orchestrator_remote_line_limit(void)
{
    if (king_mcp_orchestrator_config.mcp_max_message_size_bytes > 0) {
        return king_mcp_orchestrator_config.mcp_max_message_size_bytes;
    }

    return 4194304;
}

static uint64_t king_orchestrator_monotonic_time_ms(void)
{
    struct timespec ts;

    clock_gettime(CLOCK_MONOTONIC, &ts);
    return ((uint64_t) ts.tv_sec * 1000ULL) + ((uint64_t) ts.tv_nsec / 1000000ULL);
}

static zend_long king_orchestrator_control_timeout_budget_ms(
    king_orchestrator_exec_control_t *control
)
{
    uint64_t elapsed_ms;

    if (control == NULL || control->timeout_ms <= 0) {
        return 0;
    }

    elapsed_ms = king_orchestrator_monotonic_time_ms() - control->started_at_ms;
    if (elapsed_ms >= (uint64_t) control->timeout_ms) {
        return 0;
    }

    return (zend_long) ((uint64_t) control->timeout_ms - elapsed_ms);
}

static zend_long king_orchestrator_control_deadline_budget_ms(
    king_orchestrator_exec_control_t *control
)
{
    uint64_t now_ms;

    if (control == NULL || control->deadline_ms == 0) {
        return 0;
    }

    now_ms = king_orchestrator_monotonic_time_ms();
    if (now_ms >= control->deadline_ms) {
        return 0;
    }

    return (zend_long) (control->deadline_ms - now_ms);
}

static zend_long king_orchestrator_control_effective_budget_ms(
    king_orchestrator_exec_control_t *control
)
{
    zend_long timeout_budget_ms;
    zend_long deadline_budget_ms;

    timeout_budget_ms = king_orchestrator_control_timeout_budget_ms(control);
    deadline_budget_ms = king_orchestrator_control_deadline_budget_ms(control);

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
    if (king_mcp_orchestrator_config.orchestrator_default_pipeline_timeout_ms > 0) {
        return king_mcp_orchestrator_config.orchestrator_default_pipeline_timeout_ms;
    }

    return 30000;
}

static void king_orchestrator_exec_control_cleanup(
    king_orchestrator_exec_control_t *control
)
{
    if (control == NULL) {
        return;
    }

    if (!Z_ISUNDEF(control->cancel_token)) {
        zval_ptr_dtor(&control->cancel_token);
        ZVAL_UNDEF(&control->cancel_token);
    }

    control->run_id = NULL;
}

static zend_result king_orchestrator_raise_error(
    const char *message,
    zend_class_entry *exception_ce,
    zend_bool throw_on_error)
{
    king_set_error(message);

    if (throw_on_error) {
        zend_throw_exception_ex(exception_ce, 0, "%s", message);
    }

    return FAILURE;
}

static zend_bool king_orchestrator_exception_message_contains(const char *needle)
{
    zval rv;
    zval *message;

    if (needle == NULL || needle[0] == '\0' || EG(exception) == NULL) {
        return 0;
    }

    message = zend_read_property(
        zend_ce_exception,
        EG(exception),
        "message",
        sizeof("message") - 1,
        1,
        &rv
    );
    if (message == NULL || Z_TYPE_P(message) != IS_STRING) {
        return 0;
    }

    return strstr(Z_STRVAL_P(message), needle) != NULL;
}

static zend_result king_orchestrator_validate_positive_long_option(
    zval *value,
    const char *option_name,
    zend_long *target,
    const char *function_name,
    zend_bool throw_on_error)
{
    char message[KING_ERR_LEN];

    if (Z_TYPE_P(value) != IS_LONG) {
        snprintf(
            message,
            sizeof(message),
            "%s() option '%s' must be provided as an integer.",
            function_name,
            option_name
        );
        return king_orchestrator_raise_error(
            message,
            king_ce_validation_exception,
            throw_on_error
        );
    }

    if (Z_LVAL_P(value) <= 0) {
        snprintf(
            message,
            sizeof(message),
            "%s() option '%s' must be > 0.",
            function_name,
            option_name
        );
        return king_orchestrator_raise_error(
            message,
            king_ce_validation_exception,
            throw_on_error
        );
    }

    *target = Z_LVAL_P(value);
    return SUCCESS;
}

static zend_result king_orchestrator_exec_control_parse(
    zval *options,
    const char *function_name,
    zend_bool throw_on_error,
    king_orchestrator_exec_control_t *control)
{
    zval *option_value;
    zend_long deadline_ms = 0;
    char message[KING_ERR_LEN];

    if (control == NULL) {
        return FAILURE;
    }

    control->timeout_ms = king_mcp_orchestrator_config.orchestrator_default_pipeline_timeout_ms > 0
        ? king_mcp_orchestrator_config.orchestrator_default_pipeline_timeout_ms
        : 0;
    control->max_concurrency = king_mcp_orchestrator_config.orchestrator_loop_concurrency_default > 0
        ? king_mcp_orchestrator_config.orchestrator_loop_concurrency_default
        : 1;
    control->deadline_ms = 0;
    control->started_at_ms = king_orchestrator_monotonic_time_ms();
    ZVAL_UNDEF(&control->cancel_token);
    control->run_id = NULL;

    if (options == NULL || Z_TYPE_P(options) != IS_ARRAY) {
        return SUCCESS;
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options),
        "overall_timeout_ms",
        sizeof("overall_timeout_ms") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (king_orchestrator_validate_positive_long_option(
                option_value,
                "overall_timeout_ms",
                &control->timeout_ms,
                function_name,
                throw_on_error
            ) != SUCCESS) {
            return FAILURE;
        }
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options),
        "timeout_ms",
        sizeof("timeout_ms") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (king_orchestrator_validate_positive_long_option(
                option_value,
                "timeout_ms",
                &control->timeout_ms,
                function_name,
                throw_on_error
            ) != SUCCESS) {
            return FAILURE;
        }
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options),
        "deadline_ms",
        sizeof("deadline_ms") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (king_orchestrator_validate_positive_long_option(
                option_value,
                "deadline_ms",
                &deadline_ms,
                function_name,
                throw_on_error
            ) != SUCCESS) {
            return FAILURE;
        }
        control->deadline_ms = (uint64_t) deadline_ms;
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options),
        "max_concurrency",
        sizeof("max_concurrency") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (king_orchestrator_validate_positive_long_option(
                option_value,
                "max_concurrency",
                &control->max_concurrency,
                function_name,
                throw_on_error
            ) != SUCCESS) {
            return FAILURE;
        }
    }

    if (
        king_mcp_orchestrator_config.orchestrator_loop_concurrency_default > 0
        && control->max_concurrency > king_mcp_orchestrator_config.orchestrator_loop_concurrency_default
    ) {
        snprintf(
            message,
            sizeof(message),
            "%s() option 'max_concurrency' must be <= the configured orchestrator_loop_concurrency_default (%ld).",
            function_name,
            king_mcp_orchestrator_config.orchestrator_loop_concurrency_default
        );
        return king_orchestrator_raise_error(
            message,
            king_ce_validation_exception,
            throw_on_error
        );
    }

    option_value = zend_hash_str_find(
        Z_ARRVAL_P(options),
        "cancel",
        sizeof("cancel") - 1
    );
    if (option_value != NULL && Z_TYPE_P(option_value) != IS_NULL) {
        if (
            Z_TYPE_P(option_value) != IS_OBJECT
            || !instanceof_function(Z_OBJCE_P(option_value), king_ce_cancel_token)
        ) {
            snprintf(
                message,
                sizeof(message),
                "%s() option 'cancel' must be null or King\\CancelToken.",
                function_name
            );
            return king_orchestrator_raise_error(
                message,
                king_ce_validation_exception,
                throw_on_error
            );
        }

        ZVAL_COPY(&control->cancel_token, option_value);
    }

    return SUCCESS;
}

static zend_result king_orchestrator_exec_control_check(
    king_orchestrator_exec_control_t *control,
    const char *function_name,
    zend_bool throw_on_error,
    zend_bool *cancelled_out)
{
    char message[KING_ERR_LEN];
    uint64_t now_ms;

    if (control == NULL) {
        return SUCCESS;
    }

    king_process_pending_interrupts();

    if (cancelled_out != NULL) {
        *cancelled_out = 0;
    }

    if (king_transport_cancel_token_is_cancelled(&control->cancel_token)) {
        snprintf(
            message,
            sizeof(message),
            "%s() cancelled the active orchestrator run via CancelToken.",
            function_name
        );
        if (cancelled_out != NULL) {
            *cancelled_out = 1;
        }
        return king_orchestrator_raise_error(
            message,
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    if (
        control->run_id != NULL
        && king_orchestrator_run_cancel_requested(control->run_id)
    ) {
        snprintf(
            message,
            sizeof(message),
            "%s() cancelled the active orchestrator run via the persisted file-worker cancel channel.",
            function_name
        );
        if (cancelled_out != NULL) {
            *cancelled_out = 1;
        }
        (void) king_orchestrator_pipeline_run_cancelled_classified(
            control->run_id,
            message,
            "cancelled",
            "not_applicable",
            -1,
            "file_worker"
        );
        return king_orchestrator_raise_error(
            message,
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    now_ms = king_orchestrator_monotonic_time_ms();
    if (
        control->timeout_ms > 0
        && now_ms - control->started_at_ms >= (uint64_t) control->timeout_ms
    ) {
        snprintf(
            message,
            sizeof(message),
            "%s() exceeded the active orchestrator timeout budget.",
            function_name
        );
        return king_orchestrator_raise_error(
            message,
            king_ce_timeout_exception,
            throw_on_error
        );
    }

    if (control->deadline_ms > 0 && now_ms >= control->deadline_ms) {
        snprintf(
            message,
            sizeof(message),
            "%s() exceeded the active orchestrator deadline budget.",
            function_name
        );
        return king_orchestrator_raise_error(
            message,
            king_ce_timeout_exception,
            throw_on_error
        );
    }

    return SUCCESS;
}

static void king_orchestrator_record_terminal_error(
    zend_string *run_id,
    zend_bool cancelled,
    const char *category,
    const char *retry_disposition,
    zend_long step_index,
    const char *backend
)
{
    if (run_id == NULL) {
        return;
    }

    if (cancelled) {
        (void) king_orchestrator_pipeline_run_cancelled_classified(
            run_id,
            king_get_error(),
            category,
            retry_disposition,
            step_index,
            backend
        );
    } else {
        (void) king_orchestrator_pipeline_run_fail_classified(
            run_id,
            king_get_error(),
            category,
            retry_disposition,
            step_index,
            backend
        );
    }
}

static void king_orchestrator_mark_interrupted_run(
    zend_string *run_id,
    zend_bool cancelled,
    zend_long step_index,
    const char *backend
)
{
    const char *category = "backend";
    const char *retry_disposition = "caller_managed_retry";

    if (cancelled) {
        category = "cancelled";
        retry_disposition = "not_applicable";
    } else if (
        EG(exception) != NULL
        && instanceof_function(EG(exception)->ce, king_ce_timeout_exception)
    ) {
        category = "timeout";
        retry_disposition = "caller_managed_retry";
    }

    king_orchestrator_record_terminal_error(
        run_id,
        cancelled,
        category,
        retry_disposition,
        step_index,
        backend
    );
}

static zend_result king_orchestrator_enforce_max_concurrency(
    king_orchestrator_exec_control_t *control,
    const char *function_name,
    zend_bool throw_on_error)
{
    char message[KING_ERR_LEN];
    size_t active_runs;

    if (control == NULL || control->max_concurrency <= 0) {
        return SUCCESS;
    }

    active_runs = king_orchestrator_count_active_runs();
    if ((zend_long) active_runs < control->max_concurrency) {
        return SUCCESS;
    }

    snprintf(
        message,
        sizeof(message),
        "%s() cannot exceed the active orchestrator max_concurrency of %ld while %zu run(s) are already in flight.",
        function_name,
        control->max_concurrency,
        active_runs
    );
    return king_orchestrator_raise_error(
        message,
        king_ce_runtime_exception,
        throw_on_error
    );
}

static zend_result king_orchestrator_remote_prepare_stream(
    php_stream *stream,
    zend_long timeout_ms
)
{
    struct timeval timeout;

    if (stream == NULL) {
        return FAILURE;
    }

    if (timeout_ms <= 0) {
        timeout_ms = 30000;
    }

    timeout.tv_sec = timeout_ms / 1000;
    timeout.tv_usec = (timeout_ms % 1000) * 1000;

    php_stream_set_option(stream, PHP_STREAM_OPTION_BLOCKING, 1, NULL);
    php_stream_set_option(
        stream,
        PHP_STREAM_OPTION_READ_TIMEOUT,
        0,
        &timeout
    );

    return SUCCESS;
}

static zend_result king_orchestrator_remote_write_all(
    php_stream *stream,
    const char *buffer,
    size_t buffer_len,
    const char *function_name
)
{
    size_t written = 0;

    if (stream == NULL || buffer == NULL) {
        return FAILURE;
    }

    (void) function_name;

    while (written < buffer_len) {
        ssize_t chunk = php_stream_write(
            stream,
            buffer + written,
            buffer_len - written
        );

        if (chunk <= 0) {
            king_set_error("king_pipeline_orchestrator_run() failed while writing the remote peer request.");
            return FAILURE;
        }

        written += (size_t) chunk;
    }

    return SUCCESS;
}

static zend_string *king_orchestrator_remote_read_line(
    php_stream *stream,
    const char *function_name
)
{
    char *buffer;
    size_t buffer_len;
    size_t used = 0;

    if (stream == NULL) {
        return NULL;
    }

    (void) function_name;

    buffer_len = (size_t) king_orchestrator_remote_line_limit();
    buffer = emalloc(buffer_len);
    buffer[0] = '\0';

    while (used + 1 < buffer_len) {
        char *newline;
        ssize_t chunk = php_stream_read(stream, buffer + used, (buffer_len - used) - 1);
        size_t line_len;

        if (chunk <= 0) {
            efree(buffer);
            king_set_error("king_pipeline_orchestrator_run() did not receive a complete remote peer response.");
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

        {
            zend_string *result = zend_string_init(buffer, line_len, 0);
            efree(buffer);
            return result;
        }
    }

    efree(buffer);
    king_set_error("king_pipeline_orchestrator_run() received an oversized remote peer response.");
    return NULL;
}

static zend_result king_orchestrator_remote_expect_result(
    php_stream *stream,
    const char *function_name,
    zval *return_value,
    king_orchestrator_error_meta_t *error_meta_out
)
{
    zend_string *line;
    char *tab;
    zend_string *decoded_error;
    zend_string *payload;
    zval decoded_meta;
    zval *message;
    zval *category;
    zval *retry_disposition;
    zval *backend;
    zval *step_index;

    king_orchestrator_error_meta_init(error_meta_out);

    line = king_orchestrator_remote_read_line(stream, function_name);
    if (line == NULL) {
        return FAILURE;
    }

    tab = strchr(ZSTR_VAL(line), '\t');
    if (tab == NULL) {
        zend_string_release(line);
        king_set_error("king_pipeline_orchestrator_run() received an invalid acknowledgement from the remote peer.");
        return FAILURE;
    }

    *tab = '\0';
    payload = zend_string_init(tab + 1, strlen(tab + 1), 0);

    if (strcmp(ZSTR_VAL(line), "OK") == 0) {
        zend_string_release(line);
        if (king_orchestrator_remote_decode_base64_zval(payload, return_value) != SUCCESS) {
            zend_string_release(payload);
            king_set_error("king_pipeline_orchestrator_run() received an invalid serialized result from the remote peer.");
            return FAILURE;
        }
        zend_string_release(payload);
        king_set_error("");
        return SUCCESS;
    }

    if (strcmp(ZSTR_VAL(line), "ERR") == 0) {
        decoded_error = php_base64_decode(
            (const unsigned char *) ZSTR_VAL(payload),
            ZSTR_LEN(payload)
        );
        zend_string_release(line);
        zend_string_release(payload);
        if (decoded_error == NULL) {
            king_set_error("king_pipeline_orchestrator_run() received an invalid error payload from the remote peer.");
            return FAILURE;
        }
        king_set_error(ZSTR_VAL(decoded_error));
        zend_string_release(decoded_error);
        return FAILURE;
    }

    if (strcmp(ZSTR_VAL(line), "ERRMETA") == 0) {
        ZVAL_NULL(&decoded_meta);
        if (king_orchestrator_remote_decode_base64_zval(payload, &decoded_meta) != SUCCESS) {
            zend_string_release(line);
            zend_string_release(payload);
            king_set_error("king_pipeline_orchestrator_run() received an invalid structured error payload from the remote peer.");
            return FAILURE;
        }
        zend_string_release(line);
        zend_string_release(payload);

        if (Z_TYPE(decoded_meta) != IS_ARRAY) {
            zval_ptr_dtor(&decoded_meta);
            king_set_error("king_pipeline_orchestrator_run() received a malformed structured error payload from the remote peer.");
            return FAILURE;
        }

        message = zend_hash_str_find(Z_ARRVAL(decoded_meta), "message", sizeof("message") - 1);
        category = zend_hash_str_find(Z_ARRVAL(decoded_meta), "category", sizeof("category") - 1);
        retry_disposition = zend_hash_str_find(Z_ARRVAL(decoded_meta), "retry_disposition", sizeof("retry_disposition") - 1);
        backend = zend_hash_str_find(Z_ARRVAL(decoded_meta), "backend", sizeof("backend") - 1);
        step_index = zend_hash_str_find(Z_ARRVAL(decoded_meta), "step_index", sizeof("step_index") - 1);

        if (message == NULL || Z_TYPE_P(message) != IS_STRING) {
            zval_ptr_dtor(&decoded_meta);
            king_set_error("king_pipeline_orchestrator_run() received a structured remote error without a message.");
            return FAILURE;
        }

        king_set_error(Z_STRVAL_P(message));
        if (
            error_meta_out != NULL
            && category != NULL && Z_TYPE_P(category) == IS_STRING
            && retry_disposition != NULL && Z_TYPE_P(retry_disposition) == IS_STRING
        ) {
            king_orchestrator_error_meta_set(
                error_meta_out,
                Z_STRVAL_P(category),
                Z_STRVAL_P(retry_disposition),
                step_index != NULL && Z_TYPE_P(step_index) == IS_LONG ? Z_LVAL_P(step_index) : -1,
                backend != NULL && Z_TYPE_P(backend) == IS_STRING ? Z_STRVAL_P(backend) : NULL
            );
        }

        zval_ptr_dtor(&decoded_meta);
        return FAILURE;
    }

    zend_string_release(line);
    zend_string_release(payload);
    king_set_error("king_pipeline_orchestrator_run() received an unknown response opcode from the remote peer.");
    return FAILURE;
}

static zval *king_orchestrator_prepare_persisted_options(zval *options, zval *sanitized_options)
{
    if (sanitized_options == NULL) {
        return options;
    }

    ZVAL_NULL(sanitized_options);
    if (options == NULL || Z_TYPE_P(options) != IS_ARRAY) {
        return options;
    }

    ZVAL_COPY(sanitized_options, options);
    SEPARATE_ARRAY(sanitized_options);
    zend_hash_str_del(
        Z_ARRVAL_P(sanitized_options),
        "cancel",
        sizeof("cancel") - 1
    );

    return sanitized_options;
}

static zend_result king_orchestrator_validate_non_negative_step_delay(
    zval *value,
    uint32_t index,
    zend_bool throw_on_error)
{
    char message[KING_ERR_LEN];

    if (value == NULL || Z_TYPE_P(value) == IS_NULL) {
        return SUCCESS;
    }

    if (Z_TYPE_P(value) != IS_LONG || Z_LVAL_P(value) < 0) {
        snprintf(
            message,
            sizeof(message),
            "king_pipeline_orchestrator_run() step %u option 'delay_ms' must be provided as an integer >= 0.",
            (unsigned) index
        );
        return king_orchestrator_raise_error(
            message,
            king_ce_validation_exception,
            throw_on_error
        );
    }

    return SUCCESS;
}

static zend_result king_orchestrator_execute_step_delay(
    zval *step,
    uint32_t index,
    king_orchestrator_exec_control_t *control,
    const char *function_name,
    zend_bool throw_on_error,
    zend_bool *cancelled_out)
{
    zval *delay_value;
    zend_long delay_ms;
    uint64_t started_at_ms;

    if (step == NULL || Z_TYPE_P(step) != IS_ARRAY) {
        return SUCCESS;
    }

    delay_value = zend_hash_str_find(Z_ARRVAL_P(step), "delay_ms", sizeof("delay_ms") - 1);
    if (king_orchestrator_validate_non_negative_step_delay(delay_value, index, throw_on_error) != SUCCESS) {
        return FAILURE;
    }
    if (delay_value == NULL || Z_TYPE_P(delay_value) == IS_NULL || Z_LVAL_P(delay_value) == 0) {
        return SUCCESS;
    }

    delay_ms = Z_LVAL_P(delay_value);
    started_at_ms = king_orchestrator_monotonic_time_ms();

    while (king_orchestrator_monotonic_time_ms() - started_at_ms < (uint64_t) delay_ms) {
        uint64_t elapsed_ms = king_orchestrator_monotonic_time_ms() - started_at_ms;
        uint64_t remaining_ms = (uint64_t) delay_ms > elapsed_ms
            ? (uint64_t) delay_ms - elapsed_ms
            : 0;
        useconds_t sleep_chunk = (useconds_t) ((remaining_ms > 10 ? 10 : remaining_ms) * 1000U);

        if (king_orchestrator_exec_control_check(control, function_name, throw_on_error, cancelled_out) != SUCCESS) {
            return FAILURE;
        }
        if (sleep_chunk == 0) {
            break;
        }
        usleep(sleep_chunk);
    }

    return king_orchestrator_exec_control_check(control, function_name, throw_on_error, cancelled_out);
}

static zend_result king_orchestrator_validate_pipeline_step(
    zval *step,
    uint32_t index,
    zend_bool throw_on_error,
    zend_long *failed_step_index_out)
{
    zval *tool;
    char message[256];

    if (Z_TYPE_P(step) != IS_ARRAY) {
        return SUCCESS;
    }

    if (failed_step_index_out != NULL) {
        *failed_step_index_out = (zend_long) index;
    }

    tool = zend_hash_str_find(Z_ARRVAL_P(step), "tool", sizeof("tool") - 1);
    if (tool == NULL || Z_TYPE_P(tool) != IS_STRING || Z_STRLEN_P(tool) == 0) {
        snprintf(
            message,
            sizeof(message),
            "king_pipeline_orchestrator_run() step %u requires a non-empty tool name.",
            (unsigned) index
        );
        return king_orchestrator_raise_error(
            message,
            king_ce_validation_exception,
            throw_on_error
        );
    }

    if (king_orchestrator_lookup_tool(Z_STRVAL_P(tool), Z_STRLEN_P(tool)) == NULL) {
        snprintf(
            message,
            sizeof(message),
            "king_pipeline_orchestrator_run() references unknown tool '%s'.",
            Z_STRVAL_P(tool)
        );
        return king_orchestrator_raise_error(
            message,
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    return king_orchestrator_validate_non_negative_step_delay(
        zend_hash_str_find(Z_ARRVAL_P(step), "delay_ms", sizeof("delay_ms") - 1),
        index,
        throw_on_error
    );
}

static zend_result king_orchestrator_validate_pipeline_definition(
    zval *pipeline_array,
    const char *function_name,
    zend_bool throw_on_error,
    zend_long *failed_step_index_out)
{
    HashTable *ht;
    zval *step;
    uint32_t index = 0;

    if (pipeline_array == NULL || Z_TYPE_P(pipeline_array) != IS_ARRAY) {
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() requires an array pipeline definition.",
            king_ce_validation_exception,
            throw_on_error
        );
    }

    ht = Z_ARRVAL_P(pipeline_array);
    ZEND_HASH_FOREACH_VAL(ht, step) {
        if (king_orchestrator_validate_pipeline_step(step, index, throw_on_error, failed_step_index_out) != SUCCESS) {
            return FAILURE;
        }
        index++;
    } ZEND_HASH_FOREACH_END();

    (void) function_name;
    return SUCCESS;
}

static int king_orchestrator_execute_remote_run(
    zend_string *run_id,
    zval *initial_data,
    zval *pipeline_array,
    zval *options,
    zval *return_value,
    king_orchestrator_exec_control_t *control,
    const char *function_name,
    zend_bool throw_on_error)
{
    php_stream *stream = NULL;
    zend_string *target = NULL;
    zend_string *encoded_run_id = NULL;
    zend_string *encoded_initial = NULL;
    zend_string *encoded_pipeline = NULL;
    zend_string *encoded_options = NULL;
    smart_str command = {0};
    zend_long timeout_budget_ms;
    zend_long deadline_budget_ms;
    struct timeval timeout;
    zend_string *transport_error = NULL;
    int transport_error_code = 0;
    zend_bool cancelled = 0;
    const char *error_message;
    zend_long failed_step_index = -1;
    king_orchestrator_error_meta_t remote_error_meta;

    king_orchestrator_error_meta_init(&remote_error_meta);

    if (control != NULL) {
        control->run_id = run_id;
    }

    if (king_orchestrator_exec_control_check(control, function_name, throw_on_error, &cancelled) != SUCCESS) {
        king_orchestrator_mark_interrupted_run(run_id, cancelled, -1, "remote_peer");
        ZVAL_FALSE(return_value);
        return FAILURE;
    }

    if (king_orchestrator_validate_pipeline_definition(
            pipeline_array,
            function_name,
            throw_on_error,
            &failed_step_index
        ) != SUCCESS) {
        ZVAL_FALSE(return_value);
        (void) king_orchestrator_pipeline_run_fail_classified(
            run_id,
            king_get_error(),
            "validation",
            "non_retryable",
            failed_step_index,
            NULL
        );
        return FAILURE;
    }

    if (king_orchestrator_pipeline_run_note_remote_attempt(run_id) != SUCCESS) {
        ZVAL_FALSE(return_value);
        (void) king_orchestrator_pipeline_run_fail_classified(
            run_id,
            "king_pipeline_orchestrator_run() failed to persist remote-attempt observability.",
            "backend",
            "caller_managed_retry",
            -1,
            "controller"
        );
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() failed to persist remote-attempt observability.",
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    target = king_orchestrator_remote_build_target();
    if (target == NULL) {
        error_message = "king_pipeline_orchestrator_run() requires a non-empty orchestrator_remote_host when orchestrator_execution_backend=remote_peer.";
        king_set_error(error_message);
        ZVAL_FALSE(return_value);
        (void) king_orchestrator_pipeline_run_fail_classified(
            run_id,
            error_message,
            "backend",
            "caller_managed_retry",
            -1,
            "remote_peer"
        );
        return king_orchestrator_raise_error(
            error_message,
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    timeout_budget_ms = king_orchestrator_control_effective_budget_ms(control);
    if (timeout_budget_ms <= 0) {
        zend_string_release(target);
        ZVAL_FALSE(return_value);
        king_set_error("king_pipeline_orchestrator_run() exceeded the active orchestrator timeout budget.");
        (void) king_orchestrator_pipeline_run_fail_classified(
            run_id,
            king_get_error(),
            "timeout",
            "caller_managed_retry",
            -1,
            "remote_peer"
        );
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() exceeded the active orchestrator timeout budget.",
            king_ce_timeout_exception,
            throw_on_error
        );
    }

    timeout.tv_sec = timeout_budget_ms / 1000;
    timeout.tv_usec = (timeout_budget_ms % 1000) * 1000;

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
    target = NULL;

    if (stream == NULL) {
        if (transport_error != NULL) {
            king_set_error(ZSTR_VAL(transport_error));
            zend_string_release(transport_error);
        } else {
            char message[KING_ERR_LEN];

            snprintf(
                message,
                sizeof(message),
                "king_pipeline_orchestrator_run() failed to connect to the remote execution peer (code %d).",
                transport_error_code
            );
            king_set_error(message);
        }
        error_message = king_get_error();
        ZVAL_FALSE(return_value);
        (void) king_orchestrator_pipeline_run_fail_classified(
            run_id,
            error_message,
            "remote_transport",
            "caller_managed_retry",
            -1,
            "remote_peer"
        );
        return king_orchestrator_raise_error(
            error_message,
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    if (king_orchestrator_remote_prepare_stream(stream, timeout_budget_ms) != SUCCESS) {
        php_stream_close(stream);
        ZVAL_FALSE(return_value);
        (void) king_orchestrator_pipeline_run_fail_classified(
            run_id,
            "king_pipeline_orchestrator_run() could not configure the remote execution peer stream.",
            "remote_transport",
            "caller_managed_retry",
            -1,
            "remote_peer"
        );
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() could not configure the remote execution peer stream.",
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    encoded_run_id = php_base64_encode(
        (const unsigned char *) ZSTR_VAL(run_id),
        ZSTR_LEN(run_id)
    );
    encoded_initial = king_orchestrator_remote_encode_zval_base64(initial_data);
    encoded_pipeline = king_orchestrator_remote_encode_zval_base64(pipeline_array);
    encoded_options = king_orchestrator_remote_encode_zval_base64(options);
    if (
        encoded_run_id == NULL
        || encoded_initial == NULL
        || encoded_pipeline == NULL
        || encoded_options == NULL
    ) {
        php_stream_close(stream);
        if (encoded_run_id != NULL) {
            zend_string_release(encoded_run_id);
        }
        if (encoded_initial != NULL) {
            zend_string_release(encoded_initial);
        }
        if (encoded_pipeline != NULL) {
            zend_string_release(encoded_pipeline);
        }
        if (encoded_options != NULL) {
            zend_string_release(encoded_options);
        }
        ZVAL_FALSE(return_value);
        (void) king_orchestrator_pipeline_run_fail_classified(
            run_id,
            "king_pipeline_orchestrator_run() failed while encoding the remote execution payload.",
            "backend",
            "caller_managed_retry",
            -1,
            "controller"
        );
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() failed while encoding the remote execution payload.",
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    deadline_budget_ms = king_orchestrator_control_deadline_budget_ms(control);
    smart_str_appends(&command, "RUN\t");
    smart_str_append(&command, encoded_run_id);
    smart_str_appendc(&command, '\t');
    smart_str_append(&command, encoded_initial);
    smart_str_appendc(&command, '\t');
    smart_str_append(&command, encoded_pipeline);
    smart_str_appendc(&command, '\t');
    smart_str_append(&command, encoded_options);
    smart_str_append_printf(&command, "\t%ld\t%ld\n", timeout_budget_ms, deadline_budget_ms);
    smart_str_0(&command);

    zend_string_release(encoded_run_id);
    zend_string_release(encoded_initial);
    zend_string_release(encoded_pipeline);
    zend_string_release(encoded_options);

    if (command.s == NULL) {
        php_stream_close(stream);
        ZVAL_FALSE(return_value);
        (void) king_orchestrator_pipeline_run_fail_classified(
            run_id,
            "king_pipeline_orchestrator_run() failed while materializing the remote execution payload.",
            "backend",
            "caller_managed_retry",
            -1,
            "controller"
        );
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() failed while materializing the remote execution payload.",
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    if (king_orchestrator_exec_control_check(control, function_name, throw_on_error, &cancelled) != SUCCESS) {
        smart_str_free(&command);
        php_stream_close(stream);
        ZVAL_FALSE(return_value);
        king_orchestrator_mark_interrupted_run(run_id, cancelled, -1, "remote_peer");
        return FAILURE;
    }

    if (king_orchestrator_remote_write_all(stream, ZSTR_VAL(command.s), ZSTR_LEN(command.s), function_name) != SUCCESS) {
        smart_str_free(&command);
        php_stream_close(stream);
        error_message = king_get_error();
        ZVAL_FALSE(return_value);
        (void) king_orchestrator_pipeline_run_fail_classified(
            run_id,
            error_message,
            "remote_transport",
            "caller_managed_retry",
            -1,
            "remote_peer"
        );
        return king_orchestrator_raise_error(
            error_message,
            king_ce_runtime_exception,
            throw_on_error
        );
    }
    smart_str_free(&command);

    if (king_orchestrator_exec_control_check(control, function_name, throw_on_error, &cancelled) != SUCCESS) {
        php_stream_close(stream);
        ZVAL_FALSE(return_value);
        king_orchestrator_mark_interrupted_run(run_id, cancelled, -1, "remote_peer");
        return FAILURE;
    }

    if (king_orchestrator_remote_expect_result(stream, function_name, return_value, &remote_error_meta) != SUCCESS) {
        php_stream_close(stream);
        error_message = king_get_error();
        if (error_message == NULL || error_message[0] == '\0') {
            error_message = "king_pipeline_orchestrator_run() failed while waiting for the remote execution result.";
            king_set_error(error_message);
        }
        ZVAL_FALSE(return_value);
        (void) king_orchestrator_pipeline_run_fail_classified(
            run_id,
            error_message,
            remote_error_meta.has_classification ? remote_error_meta.category : "remote_transport",
            remote_error_meta.has_classification ? remote_error_meta.retry_disposition : "caller_managed_retry",
            remote_error_meta.has_classification ? remote_error_meta.step_index : -1,
            remote_error_meta.has_classification && remote_error_meta.backend[0] != '\0'
                ? remote_error_meta.backend
                : "remote_peer"
        );
        return king_orchestrator_raise_error(
            error_message,
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    php_stream_close(stream);

    if (king_orchestrator_pipeline_run_complete(run_id, return_value) != SUCCESS) {
        zval_ptr_dtor(return_value);
        ZVAL_FALSE(return_value);
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() failed to persist the completed run snapshot.",
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    return SUCCESS;
}

static int king_orchestrator_execute_existing_run(
    zend_string *run_id,
    zval *initial_data,
    zval *pipeline_array,
    zval *return_value,
    king_orchestrator_exec_control_t *control,
    const char *function_name,
    zend_bool throw_on_error)
{
    HashTable *ht;
    zval *step;
    uint32_t index = 0;
    zend_bool cancelled = 0;
    zend_long failed_step_index = -1;

    if (initial_data == NULL || pipeline_array == NULL || Z_TYPE_P(pipeline_array) != IS_ARRAY) {
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() requires an array pipeline definition.",
            king_ce_validation_exception,
            throw_on_error
        );
    }

    if (control != NULL) {
        control->run_id = run_id;
    }

    if (king_orchestrator_exec_control_check(control, function_name, throw_on_error, &cancelled) != SUCCESS) {
        king_orchestrator_mark_interrupted_run(run_id, cancelled, -1, NULL);
        ZVAL_FALSE(return_value);
        return FAILURE;
    }

    /* Clone initial data to return_value as the current placeholder result. */
    ZVAL_COPY(return_value, initial_data);

    ht = Z_ARRVAL_P(pipeline_array);
    ZEND_HASH_FOREACH_VAL(ht, step) {
        cancelled = 0;
        if (king_orchestrator_exec_control_check(control, function_name, throw_on_error, &cancelled) != SUCCESS) {
            zval_ptr_dtor(return_value);
            ZVAL_FALSE(return_value);
            king_orchestrator_mark_interrupted_run(run_id, cancelled, (zend_long) index, NULL);
            return FAILURE;
        }

        failed_step_index = (zend_long) index;
        if (king_orchestrator_validate_pipeline_step(step, index, throw_on_error, &failed_step_index) != SUCCESS) {
            zval_ptr_dtor(return_value);
            ZVAL_FALSE(return_value);
            (void) king_orchestrator_pipeline_run_fail_classified(
                run_id,
                king_get_error(),
                "validation",
                "non_retryable",
                failed_step_index,
                NULL
            );
            return FAILURE;
        }
        cancelled = 0;
        if (king_orchestrator_execute_step_delay(
                step,
                index,
                control,
                function_name,
                throw_on_error,
                &cancelled
            ) != SUCCESS) {
            zval_ptr_dtor(return_value);
            ZVAL_FALSE(return_value);
            king_orchestrator_mark_interrupted_run(run_id, cancelled, (zend_long) index, NULL);
            return FAILURE;
        }
        if (king_orchestrator_pipeline_run_record_completed_steps(run_id, (zend_long) index + 1) != SUCCESS) {
            zval_ptr_dtor(return_value);
            ZVAL_FALSE(return_value);
            king_set_error("king_pipeline_orchestrator_run() failed to persist completed step progress.");
            (void) king_orchestrator_pipeline_run_fail_classified(
                run_id,
                king_get_error(),
                "backend",
                "caller_managed_retry",
                -1,
                "controller"
            );
            return king_orchestrator_raise_error(
                king_get_error(),
                king_ce_runtime_exception,
                throw_on_error
            );
        }
        index++;
    } ZEND_HASH_FOREACH_END();

    cancelled = 0;
    if (king_orchestrator_exec_control_check(control, function_name, throw_on_error, &cancelled) != SUCCESS) {
        zval_ptr_dtor(return_value);
        ZVAL_FALSE(return_value);
        king_orchestrator_mark_interrupted_run(run_id, cancelled, -1, NULL);
        return FAILURE;
    }

    if (king_orchestrator_pipeline_run_complete(run_id, return_value) != SUCCESS) {
        zval_ptr_dtor(return_value);
        ZVAL_FALSE(return_value);
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() failed to persist the completed run snapshot.",
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    return SUCCESS;
}

int king_orchestrator_resume_run(
    zend_string *run_id,
    zval *return_value,
    const char *function_name,
    zend_bool throw_on_error
)
{
    zval initial_data;
    zval pipeline;
    zval options;
    king_orchestrator_exec_control_t control;
    int rc;

    if (run_id == NULL) {
        return FAILURE;
    }

    ZVAL_NULL(&initial_data);
    ZVAL_NULL(&pipeline);
    ZVAL_NULL(&options);
    ZVAL_UNDEF(&control.cancel_token);
    control.run_id = NULL;

    if (king_orchestrator_load_run_payload(run_id, &initial_data, &pipeline, &options) != SUCCESS) {
        char message[KING_ERR_LEN];

        snprintf(
            message,
            sizeof(message),
            "%s() could not load the persisted run payload.",
            function_name != NULL ? function_name : "king_pipeline_orchestrator_resume_run"
        );
        return king_orchestrator_raise_error(
            message,
            king_ce_runtime_exception,
            throw_on_error
        );
    }

    if (king_orchestrator_exec_control_parse(
            &options,
            function_name,
            throw_on_error,
            &control
        ) != SUCCESS) {
        zval_ptr_dtor(&initial_data);
        zval_ptr_dtor(&pipeline);
        zval_ptr_dtor(&options);
        king_orchestrator_exec_control_cleanup(&control);
        return FAILURE;
    }

    if (king_orchestrator_backend_is_remote_peer()) {
        rc = king_orchestrator_execute_remote_run(
            run_id,
            &initial_data,
            &pipeline,
            &options,
            return_value,
            &control,
            function_name,
            throw_on_error
        );
    } else {
        rc = king_orchestrator_execute_existing_run(
            run_id,
            &initial_data,
            &pipeline,
            return_value,
            &control,
            function_name,
            throw_on_error
        );
    }

    zval_ptr_dtor(&initial_data);
    zval_ptr_dtor(&pipeline);
    zval_ptr_dtor(&options);
    king_orchestrator_exec_control_cleanup(&control);

    return rc;
}

int king_orchestrator_run(zval *initial_data, zval *pipeline_array, zval *options, zval *return_value)
{
    zend_string *run_id;
    zval sanitized_options;
    zval *persisted_options;
    king_orchestrator_exec_control_t control;
    int rc = FAILURE;

    ZVAL_UNDEF(&sanitized_options);
    ZVAL_UNDEF(&control.cancel_token);
    control.run_id = NULL;

    if (king_orchestrator_backend_is_file_worker()) {
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() is unavailable when orchestrator_execution_backend=file_worker; use king_pipeline_orchestrator_dispatch().",
            king_ce_runtime_exception,
            1
        );
    }

    if (king_orchestrator_exec_control_parse(
            options,
            "king_pipeline_orchestrator_run",
            1,
            &control
        ) != SUCCESS) {
        goto cleanup;
    }

    if (king_orchestrator_exec_control_check(&control, "king_pipeline_orchestrator_run", 1, NULL) != SUCCESS) {
        goto cleanup;
    }

    if (king_orchestrator_enforce_max_concurrency(&control, "king_pipeline_orchestrator_run", 1) != SUCCESS) {
        goto cleanup;
    }

    persisted_options = king_orchestrator_prepare_persisted_options(options, &sanitized_options);
    run_id = king_orchestrator_pipeline_run_begin(initial_data, pipeline_array, persisted_options, "running");
    if (run_id == NULL) {
        rc = king_orchestrator_raise_error(
            "king_pipeline_orchestrator_run() failed to persist the initial run snapshot.",
            king_ce_runtime_exception,
            1
        );
        goto cleanup;
    }

    if (king_orchestrator_backend_is_remote_peer()) {
        rc = king_orchestrator_execute_remote_run(
            run_id,
            initial_data,
            pipeline_array,
            persisted_options,
            return_value,
            &control,
            "king_pipeline_orchestrator_run",
            1
        );
    } else {
        rc = king_orchestrator_execute_existing_run(
            run_id,
            initial_data,
            pipeline_array,
            return_value,
            &control,
            "king_pipeline_orchestrator_run",
            1
        );
    }
    zend_string_release(run_id);
cleanup:
    if (!Z_ISUNDEF(sanitized_options)) {
        zval_ptr_dtor(&sanitized_options);
    }
    king_orchestrator_exec_control_cleanup(&control);
    return rc;
}

int king_orchestrator_dispatch(zval *initial_data, zval *pipeline_array, zval *options, zval *return_value)
{
    zend_string *run_id;
    zval sanitized_options;
    zval *persisted_options;
    king_orchestrator_exec_control_t control;
    int rc = FAILURE;

    ZVAL_UNDEF(&sanitized_options);
    ZVAL_UNDEF(&control.cancel_token);
    control.run_id = NULL;

    if (!king_orchestrator_backend_is_file_worker()) {
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_dispatch() requires orchestrator_execution_backend=file_worker.",
            king_ce_runtime_exception,
            1
        );
    }

    if (
        king_mcp_orchestrator_config.orchestrator_worker_queue_path == NULL
        || king_mcp_orchestrator_config.orchestrator_worker_queue_path[0] == '\0'
    ) {
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_dispatch() requires a non-empty orchestrator_worker_queue_path.",
            king_ce_runtime_exception,
            1
        );
    }

    if (king_orchestrator_exec_control_parse(
            options,
            "king_pipeline_orchestrator_dispatch",
            1,
            &control
        ) != SUCCESS) {
        goto cleanup;
    }

    if (!Z_ISUNDEF(control.cancel_token)) {
        rc = king_orchestrator_raise_error(
            "king_pipeline_orchestrator_dispatch() does not support live CancelToken propagation on the file_worker backend.",
            king_ce_runtime_exception,
            1
        );
        goto cleanup;
    }

    if (king_orchestrator_exec_control_check(&control, "king_pipeline_orchestrator_dispatch", 1, NULL) != SUCCESS) {
        goto cleanup;
    }

    if (king_orchestrator_enforce_max_concurrency(&control, "king_pipeline_orchestrator_dispatch", 1) != SUCCESS) {
        goto cleanup;
    }

    persisted_options = king_orchestrator_prepare_persisted_options(options, &sanitized_options);
    run_id = king_orchestrator_pipeline_run_begin(initial_data, pipeline_array, persisted_options, "queued");
    if (run_id == NULL) {
        rc = king_orchestrator_raise_error(
            "king_pipeline_orchestrator_dispatch() failed to persist the initial run snapshot.",
            king_ce_runtime_exception,
            1
        );
        goto cleanup;
    }

    rc = king_orchestrator_enqueue_run(run_id, return_value);
    if (rc != SUCCESS) {
        (void) king_orchestrator_pipeline_run_fail_classified(
            run_id,
            "king_pipeline_orchestrator_dispatch() failed to enqueue the run for the file-worker backend.",
            "backend",
            "caller_managed_retry",
            -1,
            "file_worker_queue"
        );
        zend_string_release(run_id);
        rc = king_orchestrator_raise_error(
            "king_pipeline_orchestrator_dispatch() failed to enqueue the run for the file-worker backend.",
            king_ce_runtime_exception,
            1
        );
        goto cleanup;
    }

    zend_string_release(run_id);
    rc = SUCCESS;
cleanup:
    if (!Z_ISUNDEF(sanitized_options)) {
        zval_ptr_dtor(&sanitized_options);
    }
    king_orchestrator_exec_control_cleanup(&control);
    return rc;
}

int king_orchestrator_worker_run_next(zval *return_value)
{
    zend_string *run_id = NULL;
    char claimed_path[1024];
    int claimed_fd = -1;
    zend_bool recovered_claim = 0;
    int rc;

    if (!king_orchestrator_backend_is_file_worker()) {
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_worker_run_next() requires orchestrator_execution_backend=file_worker.",
            king_ce_runtime_exception,
            1
        );
    }

    if (
        king_mcp_orchestrator_config.orchestrator_worker_queue_path == NULL
        || king_mcp_orchestrator_config.orchestrator_worker_queue_path[0] == '\0'
    ) {
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_worker_run_next() requires a non-empty orchestrator_worker_queue_path.",
            king_ce_runtime_exception,
            1
        );
    }

    if (
        king_orchestrator_claim_next_run(
            &run_id,
            claimed_path,
            sizeof(claimed_path),
            &claimed_fd,
            &recovered_claim
        ) != SUCCESS
    ) {
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_worker_run_next() could not claim a queued run.",
            king_ce_runtime_exception,
            1
        );
    }

    if (run_id == NULL) {
        if (claimed_fd >= 0) {
            close(claimed_fd);
        }
        ZVAL_FALSE(return_value);
        return SUCCESS;
    }

    if (king_orchestrator_pipeline_run_is_terminal(run_id)) {
        if (claimed_path[0] != '\0') {
            unlink(claimed_path);
        }
        if (claimed_fd >= 0) {
            close(claimed_fd);
        }
        if (king_orchestrator_get_run_snapshot(run_id, return_value) != SUCCESS) {
            zend_string_release(run_id);
            return king_orchestrator_raise_error(
                "king_pipeline_orchestrator_worker_run_next() could not read back the persisted terminal run snapshot.",
                king_ce_runtime_exception,
                1
            );
        }
        zend_string_release(run_id);
        return SUCCESS;
    }

    if (king_orchestrator_pipeline_run_mark_running(run_id, recovered_claim, (zend_long) getpid()) != SUCCESS) {
        if (claimed_path[0] != '\0') {
            unlink(claimed_path);
        }
        if (claimed_fd >= 0) {
            close(claimed_fd);
        }
        zend_string_release(run_id);
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_worker_run_next() could not persist the claimed running snapshot.",
            king_ce_runtime_exception,
            1
        );
    }

    ZVAL_NULL(return_value);
    rc = king_orchestrator_resume_run(
        run_id,
        return_value,
        "king_pipeline_orchestrator_worker_run_next",
        1
    );
    zval_ptr_dtor(return_value);
    ZVAL_NULL(return_value);

    if (claimed_path[0] != '\0') {
        unlink(claimed_path);
    }
    if (claimed_fd >= 0) {
        close(claimed_fd);
        claimed_fd = -1;
    }

    if (rc != SUCCESS) {
        if (EG(exception) != NULL) {
            zval cancelled_snapshot;
            zval *status;
            const char *error_message = king_get_error();

            if (
                king_orchestrator_run_cancel_requested(run_id)
                || (
                    error_message != NULL
                    && strstr(error_message, "persisted file-worker cancel channel") != NULL
                )
                || king_orchestrator_exception_message_contains(
                    "persisted file-worker cancel channel"
                )
            ) {
                (void) king_orchestrator_pipeline_run_cancelled_classified(
                    run_id,
                    error_message,
                    "cancelled",
                    "not_applicable",
                    -1,
                    "file_worker"
                );
            }

            ZVAL_NULL(&cancelled_snapshot);
            if (king_orchestrator_get_run_snapshot(run_id, &cancelled_snapshot) == SUCCESS) {
                status = zend_hash_str_find(
                    Z_ARRVAL(cancelled_snapshot),
                    "status",
                    sizeof("status") - 1
                );
                if (
                    status != NULL
                    && Z_TYPE_P(status) == IS_STRING
                    && zend_string_equals_literal(Z_STR_P(status), "cancelled")
                ) {
                    zend_clear_exception();
                    zend_string_release(run_id);
                    ZVAL_COPY_VALUE(return_value, &cancelled_snapshot);
                    return SUCCESS;
                }
                zval_ptr_dtor(&cancelled_snapshot);
            }
            if (!king_orchestrator_pipeline_run_is_terminal(run_id)) {
                (void) king_orchestrator_pipeline_run_fail_classified(
                    run_id,
                    error_message,
                    "backend",
                    "caller_managed_retry",
                    -1,
                    "file_worker"
                );
            }
            zend_string_release(run_id);
            return FAILURE;
        }
        zend_string_release(run_id);
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_worker_run_next() failed while executing the claimed run.",
            king_ce_runtime_exception,
            1
        );
    }

    if (king_orchestrator_get_run_snapshot(run_id, return_value) != SUCCESS) {
        zend_string_release(run_id);
        return king_orchestrator_raise_error(
            "king_pipeline_orchestrator_worker_run_next() could not read back the persisted run snapshot.",
            king_ce_runtime_exception,
            1
        );
    }

    zend_string_release(run_id);
    return SUCCESS;
}

PHP_FUNCTION(king_pipeline_orchestrator_run)
{
    zval *initial_data;
    zval *pipeline;
    zval *exec_options = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_ZVAL(initial_data)
        Z_PARAM_ARRAY(pipeline)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(exec_options)
    ZEND_PARSE_PARAMETERS_END();

    if (king_orchestrator_run(initial_data, pipeline, exec_options, return_value) == SUCCESS) {
        return;
    }

    RETURN_THROWS();
}

PHP_FUNCTION(king_pipeline_orchestrator_dispatch)
{
    zval *initial_data;
    zval *pipeline;
    zval *exec_options = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_ZVAL(initial_data)
        Z_PARAM_ARRAY(pipeline)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(exec_options)
    ZEND_PARSE_PARAMETERS_END();

    if (king_orchestrator_dispatch(initial_data, pipeline, exec_options, return_value) == SUCCESS) {
        return;
    }

    RETURN_THROWS();
}

PHP_FUNCTION(king_pipeline_orchestrator_worker_run_next)
{
    ZEND_PARSE_PARAMETERS_NONE();

    if (king_orchestrator_worker_run_next(return_value) == SUCCESS) {
        return;
    }

    RETURN_THROWS();
}

PHP_FUNCTION(king_pipeline_orchestrator_resume_run)
{
    zend_string *run_id;
    zval run_snapshot;
    zval *status;
    char message[KING_ERR_LEN];

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(run_id)
    ZEND_PARSE_PARAMETERS_END();

    if (ZSTR_LEN(run_id) == 0) {
        king_set_error("king_pipeline_orchestrator_resume_run() requires a non-empty run id.");
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "king_pipeline_orchestrator_resume_run() requires a non-empty run id."
        );
        RETURN_THROWS();
    }

    if (king_orchestrator_backend_is_file_worker()) {
        king_set_error(
            "king_pipeline_orchestrator_resume_run() is unavailable when orchestrator_execution_backend=file_worker; use king_pipeline_orchestrator_worker_run_next()."
        );
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "king_pipeline_orchestrator_resume_run() is unavailable when orchestrator_execution_backend=file_worker; use king_pipeline_orchestrator_worker_run_next()."
        );
        RETURN_THROWS();
    }

    ZVAL_NULL(&run_snapshot);
    if (king_orchestrator_get_run_snapshot(run_id, &run_snapshot) != SUCCESS) {
        king_set_error("king_pipeline_orchestrator_resume_run() could not read the persisted run snapshot.");
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "king_pipeline_orchestrator_resume_run() could not read the persisted run snapshot."
        );
        RETURN_THROWS();
    }

    status = zend_hash_str_find(
        Z_ARRVAL(run_snapshot),
        "status",
        sizeof("status") - 1
    );
    if (status == NULL || Z_TYPE_P(status) != IS_STRING) {
        zval_ptr_dtor(&run_snapshot);
        king_set_error("king_pipeline_orchestrator_resume_run() requires a persisted run snapshot with a valid status.");
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "king_pipeline_orchestrator_resume_run() requires a persisted run snapshot with a valid status."
        );
        RETURN_THROWS();
    }

    if (!zend_string_equals_literal(Z_STR_P(status), "running")) {
        snprintf(
            message,
            sizeof(message),
            "king_pipeline_orchestrator_resume_run() can only continue runs in 'running' state; '%s' is terminal or not resumable.",
            Z_STRVAL_P(status)
        );
        zval_ptr_dtor(&run_snapshot);
        king_set_error(message);
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "%s", message);
        RETURN_THROWS();
    }

    zval_ptr_dtor(&run_snapshot);

    if (king_orchestrator_pipeline_run_note_recovery(run_id, "resume_run") != SUCCESS) {
        king_set_error("king_pipeline_orchestrator_resume_run() failed to persist recovery observability.");
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "king_pipeline_orchestrator_resume_run() failed to persist recovery observability."
        );
        RETURN_THROWS();
    }

    if (
        king_orchestrator_resume_run(
            run_id,
            return_value,
            "king_pipeline_orchestrator_resume_run",
            1
        ) == SUCCESS
    ) {
        return;
    }

    RETURN_THROWS();
}

PHP_FUNCTION(king_pipeline_orchestrator_configure_logging)
{
    zval *config;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_ARRAY(config)
    ZEND_PARSE_PARAMETERS_END();

    if (king_orchestrator_configure_logging(config) == SUCCESS) {
        RETURN_TRUE;
    }

    king_set_error("king_pipeline_orchestrator_configure_logging() failed to persist the logging snapshot.");
    zend_throw_exception_ex(
        king_ce_runtime_exception,
        0,
        "king_pipeline_orchestrator_configure_logging() failed to persist the logging snapshot."
    );
    RETURN_THROWS();
}
