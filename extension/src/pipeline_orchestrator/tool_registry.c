/*
 * src/pipeline_orchestrator/tool_registry.c - Native Tool Handler Registry
 * =========================================================================
 *
 * This module manages the global registry of available orchestrator tools,
 * logging config, and persisted pipeline run snapshots.
 */
#include "php_king.h"
#include "include/config/mcp_and_orchestrator/base_layer.h"
#include "include/pipeline_orchestrator/orchestrator.h"

#include "ext/standard/base64.h"
#include "ext/standard/php_var.h"
#include "zend_smart_str.h"

#include <dirent.h>
#include <errno.h>
#include <stdio.h>
#include <string.h>
#include <sys/stat.h>
#include <unistd.h>
#include <zend_hash.h>

#define KING_ORCHESTRATOR_STATE_VERSION 1

typedef struct _king_orchestrator_run_state {
    zend_string *run_id;
    zend_string *status;
    time_t started_at;
    time_t finished_at;
    zend_string *initial_data_b64;
    zend_string *pipeline_b64;
    zend_string *options_b64;
    zend_string *result_b64;
    zend_string *error_b64;
} king_orchestrator_run_state_t;

static HashTable king_orchestrator_tool_registry;
static HashTable king_orchestrator_pipeline_runs;
static zend_string *king_orchestrator_logging_config_b64 = NULL;
static zend_string *king_orchestrator_last_run_id = NULL;
static zend_string *king_orchestrator_last_run_status = NULL;
static bool king_orchestrator_registry_initialized = false;
static bool king_orchestrator_recovered_from_state = false;
static zend_long king_orchestrator_next_run_id = 1;

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
    if (run_state->initial_data_b64 != NULL) {
        zend_string_release_ex(run_state->initial_data_b64, 1);
    }
    if (run_state->pipeline_b64 != NULL) {
        zend_string_release_ex(run_state->pipeline_b64, 1);
    }
    if (run_state->options_b64 != NULL) {
        zend_string_release_ex(run_state->options_b64, 1);
    }
    if (run_state->result_b64 != NULL) {
        zend_string_release_ex(run_state->result_b64, 1);
    }
    if (run_state->error_b64 != NULL) {
        zend_string_release_ex(run_state->error_b64, 1);
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

static zend_long king_orchestrator_extract_run_sequence(const zend_string *run_id)
{
    const char *raw;

    if (run_id == NULL || !zend_string_starts_with_literal(run_id, "run-")) {
        return 0;
    }

    raw = ZSTR_VAL(run_id) + (sizeof("run-") - 1);
    if (*raw == '\0') {
        return 0;
    }

    return ZEND_STRTOL(raw, NULL, 10);
}

static size_t king_orchestrator_count_active_runs(void)
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

static int king_orchestrator_queue_path_is_configured(void)
{
    return king_mcp_orchestrator_config.orchestrator_worker_queue_path != NULL
        && king_mcp_orchestrator_config.orchestrator_worker_queue_path[0] != '\0';
}

static int king_orchestrator_queue_name_is_job(const char *name)
{
    size_t name_len;

    if (name == NULL || strncmp(name, "queued-", sizeof("queued-") - 1) != 0) {
        return 0;
    }

    name_len = strlen(name);
    return name_len > sizeof("queued-.job") - 1
        && strcmp(name + name_len - (sizeof(".job") - 1), ".job") == 0;
}

static int king_orchestrator_decode_base64_zval(zend_string *encoded_value, zval *return_value)
{
    zend_string *decoded_payload;
    const unsigned char *cursor;
    php_unserialize_data_t var_hash;

    if (encoded_value == NULL) {
        ZVAL_NULL(return_value);
        return SUCCESS;
    }

    decoded_payload = php_base64_decode(
        (const unsigned char *) ZSTR_VAL(encoded_value),
        ZSTR_LEN(encoded_value)
    );
    if (decoded_payload == NULL) {
        return FAILURE;
    }

    cursor = (const unsigned char *) ZSTR_VAL(decoded_payload);
    ZVAL_NULL(return_value);

    PHP_VAR_UNSERIALIZE_INIT(var_hash);
    if (!php_var_unserialize(
            return_value,
            &cursor,
            cursor + ZSTR_LEN(decoded_payload),
            &var_hash)) {
        PHP_VAR_UNSERIALIZE_DESTROY(var_hash);
        zend_string_release(decoded_payload);
        zval_ptr_dtor(return_value);
        ZVAL_NULL(return_value);
        return FAILURE;
    }
    PHP_VAR_UNSERIALIZE_DESTROY(var_hash);
    zend_string_release(decoded_payload);

    return SUCCESS;
}

static int king_orchestrator_queue_ensure_directory(void)
{
    const char *queue_path = king_mcp_orchestrator_config.orchestrator_worker_queue_path;

    if (!king_orchestrator_queue_path_is_configured()) {
        return FAILURE;
    }

    if (mkdir(queue_path, 0755) != 0 && errno != EEXIST) {
        return FAILURE;
    }

    return SUCCESS;
}

static size_t king_orchestrator_count_queued_runs(void)
{
    DIR *dir;
    struct dirent *entry;
    size_t queued_runs = 0;

    if (!king_orchestrator_queue_path_is_configured()) {
        return 0;
    }

    dir = opendir(king_mcp_orchestrator_config.orchestrator_worker_queue_path);
    if (dir == NULL) {
        return 0;
    }

    while ((entry = readdir(dir)) != NULL) {
        if (king_orchestrator_queue_name_is_job(entry->d_name)) {
            queued_runs++;
        }
    }

    closedir(dir);
    return queued_runs;
}

static int king_orchestrator_persist_state(void)
{
    char tmp_path[1024];
    FILE *stream;
    const char *state_path = king_mcp_orchestrator_config.orchestrator_state_path;
    zend_string *tool_name;
    zval *tool_config_b64;
    zval *run_entry;

    if (state_path == NULL || state_path[0] == '\0') {
        return SUCCESS;
    }

    snprintf(tmp_path, sizeof(tmp_path), "%s.tmp.%ld", state_path, (long) getpid());
    stream = fopen(tmp_path, "wb");
    if (stream == NULL) {
        return FAILURE;
    }

    fprintf(stream, "version\t%d\n", KING_ORCHESTRATOR_STATE_VERSION);
    if (king_orchestrator_logging_config_b64 != NULL) {
        fprintf(stream, "logging\t%s\n", ZSTR_VAL(king_orchestrator_logging_config_b64));
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(&king_orchestrator_tool_registry, tool_name, tool_config_b64) {
        zend_string *encoded_name;

        if (tool_name == NULL || Z_TYPE_P(tool_config_b64) != IS_STRING) {
            continue;
        }

        encoded_name = php_base64_encode(
            (const unsigned char *) ZSTR_VAL(tool_name),
            ZSTR_LEN(tool_name)
        );
        if (encoded_name == NULL) {
            fclose(stream);
            unlink(tmp_path);
            return FAILURE;
        }

        fprintf(
            stream,
            "tool\t%s\t%s\n",
            ZSTR_VAL(encoded_name),
            ZSTR_VAL(Z_STR_P(tool_config_b64))
        );
        zend_string_release(encoded_name);
    } ZEND_HASH_FOREACH_END();

    ZEND_HASH_FOREACH_VAL(&king_orchestrator_pipeline_runs, run_entry) {
        king_orchestrator_run_state_t *run_state;

        if (Z_TYPE_P(run_entry) != IS_PTR) {
            continue;
        }

        run_state = (king_orchestrator_run_state_t *) Z_PTR_P(run_entry);
        if (run_state == NULL || run_state->run_id == NULL || run_state->status == NULL) {
            continue;
        }

        fprintf(
            stream,
            "run\t%s\t%s\t%ld\t%ld\t%s\t%s\t%s\t%s\t%s\n",
            ZSTR_VAL(run_state->run_id),
            ZSTR_VAL(run_state->status),
            (long) run_state->started_at,
            (long) run_state->finished_at,
            run_state->initial_data_b64 != NULL ? ZSTR_VAL(run_state->initial_data_b64) : "",
            run_state->pipeline_b64 != NULL ? ZSTR_VAL(run_state->pipeline_b64) : "",
            run_state->options_b64 != NULL ? ZSTR_VAL(run_state->options_b64) : "",
            run_state->result_b64 != NULL ? ZSTR_VAL(run_state->result_b64) : "",
            run_state->error_b64 != NULL ? ZSTR_VAL(run_state->error_b64) : ""
        );
    } ZEND_HASH_FOREACH_END();

    if (fclose(stream) != 0) {
        unlink(tmp_path);
        return FAILURE;
    }

    if (rename(tmp_path, state_path) != 0) {
        unlink(tmp_path);
        return FAILURE;
    }

    return SUCCESS;
}

static int king_orchestrator_load_state(void)
{
    FILE *stream;
    char line[16384];
    const char *state_path = king_mcp_orchestrator_config.orchestrator_state_path;

    if (state_path == NULL || state_path[0] == '\0') {
        king_orchestrator_recovered_from_state = false;
        return SUCCESS;
    }

    stream = fopen(state_path, "rb");
    if (stream == NULL) {
        if (errno == ENOENT) {
            king_orchestrator_recovered_from_state = false;
            return SUCCESS;
        }

        return FAILURE;
    }

    while (fgets(line, sizeof(line), stream) != NULL) {
        char *saveptr = NULL;
        char *kind;

        if (line[0] == '\n' || line[0] == '\r' || line[0] == '#') {
            continue;
        }

        kind = strtok_r(line, "\t\r\n", &saveptr);
        if (kind == NULL) {
            continue;
        }

        if (strcmp(kind, "version") == 0) {
            continue;
        }

        if (strcmp(kind, "logging") == 0) {
            char *logging_b64 = strtok_r(NULL, "\t\r\n", &saveptr);
            if (logging_b64 != NULL && logging_b64[0] != '\0') {
                king_orchestrator_replace_runtime_string(
                    &king_orchestrator_logging_config_b64,
                    king_orchestrator_dup_state_field(logging_b64)
                );
                king_orchestrator_recovered_from_state = true;
            }
            continue;
        }

        if (strcmp(kind, "tool") == 0) {
            char *encoded_name = strtok_r(NULL, "\t\r\n", &saveptr);
            char *config_b64 = strtok_r(NULL, "\t\r\n", &saveptr);
            zend_string *decoded_name;
            zend_string *persistent_name;
            zval stored_config;

            if (
                encoded_name == NULL || encoded_name[0] == '\0'
                || config_b64 == NULL || config_b64[0] == '\0'
            ) {
                continue;
            }

            decoded_name = php_base64_decode(
                (const unsigned char *) encoded_name,
                strlen(encoded_name)
            );
            if (decoded_name == NULL) {
                continue;
            }

            persistent_name = zend_string_dup(decoded_name, 1);
            ZVAL_STR(&stored_config, king_orchestrator_dup_state_field(config_b64));
            zend_hash_update(&king_orchestrator_tool_registry, persistent_name, &stored_config);
            zend_string_release_ex(persistent_name, 1);
            zend_string_release(decoded_name);
            king_orchestrator_recovered_from_state = true;
            continue;
        }

        if (strcmp(kind, "run") == 0) {
            char *run_id = strtok_r(NULL, "\t\r\n", &saveptr);
            char *status = strtok_r(NULL, "\t\r\n", &saveptr);
            char *started_at = strtok_r(NULL, "\t\r\n", &saveptr);
            char *finished_at = strtok_r(NULL, "\t\r\n", &saveptr);
            char *initial_data_b64 = strtok_r(NULL, "\t\r\n", &saveptr);
            char *pipeline_b64 = strtok_r(NULL, "\t\r\n", &saveptr);
            char *options_b64 = strtok_r(NULL, "\t\r\n", &saveptr);
            char *result_b64 = strtok_r(NULL, "\t\r\n", &saveptr);
            char *error_b64 = strtok_r(NULL, "\t\r\n", &saveptr);
            king_orchestrator_run_state_t *run_state;
            zval stored_run;
            zend_string *persistent_key;
            zend_long sequence;

            if (
                run_id == NULL || run_id[0] == '\0'
                || status == NULL || status[0] == '\0'
                || started_at == NULL
                || finished_at == NULL
                || initial_data_b64 == NULL
                || pipeline_b64 == NULL
                || options_b64 == NULL
                || result_b64 == NULL
                || error_b64 == NULL
            ) {
                continue;
            }

            run_state = pemalloc(sizeof(*run_state), 1);
            memset(run_state, 0, sizeof(*run_state));
            run_state->run_id = king_orchestrator_dup_state_field(run_id);
            run_state->status = king_orchestrator_dup_state_field(status);
            run_state->started_at = (time_t) ZEND_STRTOL(started_at, NULL, 10);
            run_state->finished_at = (time_t) ZEND_STRTOL(finished_at, NULL, 10);
            run_state->initial_data_b64 = king_orchestrator_dup_state_field(initial_data_b64);
            run_state->pipeline_b64 = king_orchestrator_dup_state_field(pipeline_b64);
            run_state->options_b64 = king_orchestrator_dup_state_field(options_b64);
            run_state->result_b64 = king_orchestrator_dup_state_field(result_b64);
            run_state->error_b64 = king_orchestrator_dup_state_field(error_b64);

            ZVAL_PTR(&stored_run, run_state);
            persistent_key = zend_string_dup(run_state->run_id, 1);
            zend_hash_update(&king_orchestrator_pipeline_runs, persistent_key, &stored_run);
            zend_string_release_ex(persistent_key, 1);
            king_orchestrator_set_last_run(run_state->run_id, ZSTR_VAL(run_state->status));

            sequence = king_orchestrator_extract_run_sequence(run_state->run_id);
            if (sequence >= king_orchestrator_next_run_id) {
                king_orchestrator_next_run_id = sequence + 1;
            }

            king_orchestrator_recovered_from_state = true;
        }
    }

    fclose(stream);
    return SUCCESS;
}

static king_orchestrator_run_state_t *king_orchestrator_find_run(zend_string *run_id)
{
    zval *entry;

    if (run_id == NULL) {
        return NULL;
    }

    entry = zend_hash_find(&king_orchestrator_pipeline_runs, run_id);
    if (entry == NULL || Z_TYPE_P(entry) != IS_PTR) {
        return NULL;
    }

    return (king_orchestrator_run_state_t *) Z_PTR_P(entry);
}

int king_orchestrator_load_run_payload(
    zend_string *run_id,
    zval *initial_data,
    zval *pipeline,
    zval *options)
{
    king_orchestrator_run_state_t *run_state;

    if (initial_data == NULL || pipeline == NULL || options == NULL) {
        return FAILURE;
    }

    ZVAL_NULL(initial_data);
    ZVAL_NULL(pipeline);
    ZVAL_NULL(options);

    run_state = king_orchestrator_find_run(run_id);
    if (run_state == NULL) {
        return FAILURE;
    }

    if (
        king_orchestrator_decode_base64_zval(run_state->initial_data_b64, initial_data) != SUCCESS
        || king_orchestrator_decode_base64_zval(run_state->pipeline_b64, pipeline) != SUCCESS
        || king_orchestrator_decode_base64_zval(run_state->options_b64, options) != SUCCESS
    ) {
        zval_ptr_dtor(initial_data);
        zval_ptr_dtor(pipeline);
        zval_ptr_dtor(options);
        ZVAL_NULL(initial_data);
        ZVAL_NULL(pipeline);
        ZVAL_NULL(options);
        return FAILURE;
    }

    return SUCCESS;
}

int king_orchestrator_get_run_snapshot(zend_string *run_id, zval *return_value)
{
    king_orchestrator_run_state_t *run_state;
    zval initial_data;
    zval pipeline;
    zval options;
    zval result;
    zval error;

    ZVAL_NULL(&initial_data);
    ZVAL_NULL(&pipeline);
    ZVAL_NULL(&options);
    ZVAL_NULL(&result);
    ZVAL_NULL(&error);

    run_state = king_orchestrator_find_run(run_id);
    if (run_state == NULL) {
        return FAILURE;
    }

    if (
        king_orchestrator_decode_base64_zval(run_state->initial_data_b64, &initial_data) != SUCCESS
        || king_orchestrator_decode_base64_zval(run_state->pipeline_b64, &pipeline) != SUCCESS
        || king_orchestrator_decode_base64_zval(run_state->options_b64, &options) != SUCCESS
        || king_orchestrator_decode_base64_zval(run_state->result_b64, &result) != SUCCESS
        || king_orchestrator_decode_base64_zval(run_state->error_b64, &error) != SUCCESS
    ) {
        zval_ptr_dtor(&initial_data);
        zval_ptr_dtor(&pipeline);
        zval_ptr_dtor(&options);
        zval_ptr_dtor(&result);
        zval_ptr_dtor(&error);
        return FAILURE;
    }

    array_init(return_value);
    add_assoc_stringl(return_value, "run_id", ZSTR_VAL(run_state->run_id), ZSTR_LEN(run_state->run_id));
    add_assoc_stringl(return_value, "status", ZSTR_VAL(run_state->status), ZSTR_LEN(run_state->status));
    add_assoc_long(return_value, "started_at", (zend_long) run_state->started_at);
    add_assoc_long(return_value, "finished_at", (zend_long) run_state->finished_at);
    add_assoc_zval(return_value, "initial_data", &initial_data);
    add_assoc_zval(return_value, "pipeline", &pipeline);
    add_assoc_zval(return_value, "options", &options);
    add_assoc_zval(return_value, "result", &result);
    add_assoc_zval(return_value, "error", &error);

    return SUCCESS;
}

int king_orchestrator_enqueue_run(zend_string *run_id, zval *return_value)
{
    char queue_file_path[1024];
    FILE *stream;

    if (!king_orchestrator_backend_is_file_worker() || !king_orchestrator_queue_path_is_configured()) {
        return FAILURE;
    }

    if (king_orchestrator_queue_ensure_directory() != SUCCESS) {
        return FAILURE;
    }

    snprintf(
        queue_file_path,
        sizeof(queue_file_path),
        "%s/queued-%s.job",
        king_mcp_orchestrator_config.orchestrator_worker_queue_path,
        ZSTR_VAL(run_id)
    );

    stream = fopen(queue_file_path, "wb");
    if (stream == NULL) {
        return FAILURE;
    }

    if (fprintf(stream, "%s\n", ZSTR_VAL(run_id)) < 0) {
        fclose(stream);
        unlink(queue_file_path);
        return FAILURE;
    }

    if (fclose(stream) != 0) {
        unlink(queue_file_path);
        return FAILURE;
    }

    array_init(return_value);
    add_assoc_stringl(return_value, "run_id", ZSTR_VAL(run_id), ZSTR_LEN(run_id));
    add_assoc_string(return_value, "backend", "file_worker");
    add_assoc_string(return_value, "status", "queued");
    add_assoc_long(return_value, "enqueued_at", (zend_long) time(NULL));

    return SUCCESS;
}

int king_orchestrator_claim_next_run(zend_string **run_id_out, char *claimed_path, size_t claimed_path_len)
{
    DIR *dir;
    FILE *stream;
    struct dirent *entry;
    char queued_path[1024];
    char selected_name[256];
    char run_id_buffer[256];
    char *newline;

    if (run_id_out == NULL || claimed_path == NULL || claimed_path_len == 0) {
        return FAILURE;
    }

    *run_id_out = NULL;
    claimed_path[0] = '\0';

    if (!king_orchestrator_backend_is_file_worker() || !king_orchestrator_queue_path_is_configured()) {
        return FAILURE;
    }

    dir = opendir(king_mcp_orchestrator_config.orchestrator_worker_queue_path);
    if (dir == NULL) {
        if (errno == ENOENT) {
            return SUCCESS;
        }
        return FAILURE;
    }

    selected_name[0] = '\0';
    while ((entry = readdir(dir)) != NULL) {
        if (king_orchestrator_queue_name_is_job(entry->d_name)) {
            strncpy(selected_name, entry->d_name, sizeof(selected_name) - 1);
            selected_name[sizeof(selected_name) - 1] = '\0';
            break;
        }
    }

    closedir(dir);

    if (selected_name[0] == '\0') {
        return SUCCESS;
    }

    snprintf(
        queued_path,
        sizeof(queued_path),
        "%s/%s",
        king_mcp_orchestrator_config.orchestrator_worker_queue_path,
        selected_name
    );
    snprintf(
        claimed_path,
        claimed_path_len,
        "%s/claimed-%ld-%s",
        king_mcp_orchestrator_config.orchestrator_worker_queue_path,
        (long) getpid(),
        selected_name
    );

    if (rename(queued_path, claimed_path) != 0) {
        claimed_path[0] = '\0';
        return FAILURE;
    }

    stream = fopen(claimed_path, "rb");
    if (stream == NULL) {
        unlink(claimed_path);
        claimed_path[0] = '\0';
        return FAILURE;
    }

    if (fgets(run_id_buffer, sizeof(run_id_buffer), stream) == NULL) {
        fclose(stream);
        unlink(claimed_path);
        claimed_path[0] = '\0';
        return FAILURE;
    }

    fclose(stream);

    newline = strpbrk(run_id_buffer, "\r\n");
    if (newline != NULL) {
        *newline = '\0';
    }

    if (run_id_buffer[0] == '\0') {
        unlink(claimed_path);
        claimed_path[0] = '\0';
        return FAILURE;
    }

    *run_id_out = zend_string_init(run_id_buffer, strlen(run_id_buffer), 0);
    return SUCCESS;
}

int king_orchestrator_registry_init(void)
{
    if (king_orchestrator_registry_initialized) {
        return SUCCESS;
    }

    zend_hash_init(
        &king_orchestrator_tool_registry,
        16,
        NULL,
        king_orchestrator_persistent_string_zval_dtor,
        1
    );
    zend_hash_init(
        &king_orchestrator_pipeline_runs,
        16,
        NULL,
        king_orchestrator_run_state_zval_dtor,
        1
    );
    king_orchestrator_logging_config_b64 = NULL;
    king_orchestrator_last_run_id = NULL;
    king_orchestrator_last_run_status = NULL;
    king_orchestrator_next_run_id = 1;
    king_orchestrator_recovered_from_state = false;
    king_orchestrator_registry_initialized = true;

    return king_orchestrator_load_state();
}

void king_orchestrator_registry_shutdown(void)
{
    if (!king_orchestrator_registry_initialized) {
        return;
    }

    zend_hash_destroy(&king_orchestrator_tool_registry);
    zend_hash_destroy(&king_orchestrator_pipeline_runs);

    if (king_orchestrator_logging_config_b64 != NULL) {
        zend_string_release_ex(king_orchestrator_logging_config_b64, 1);
        king_orchestrator_logging_config_b64 = NULL;
    }
    if (king_orchestrator_last_run_id != NULL) {
        zend_string_release_ex(king_orchestrator_last_run_id, 1);
        king_orchestrator_last_run_id = NULL;
    }
    if (king_orchestrator_last_run_status != NULL) {
        zend_string_release_ex(king_orchestrator_last_run_status, 1);
        king_orchestrator_last_run_status = NULL;
    }

    king_orchestrator_registry_initialized = false;
    king_orchestrator_recovered_from_state = false;
    king_orchestrator_next_run_id = 1;
}

int king_orchestrator_register_tool(const char *name, size_t name_len, zval *config)
{
    zend_string *config_b64;
    zend_string *persistent_name;
    zval stored_config;

    if (!king_orchestrator_registry_initialized) {
        if (king_orchestrator_registry_init() != SUCCESS) {
            return FAILURE;
        }
    }

    config_b64 = king_orchestrator_encode_zval_base64(config);
    if (config_b64 == NULL) {
        return FAILURE;
    }

    ZVAL_STR(&stored_config, zend_string_dup(config_b64, 1));
    persistent_name = zend_string_init(name, name_len, 1);
    zend_hash_update(&king_orchestrator_tool_registry, persistent_name, &stored_config);
    zend_string_release_ex(persistent_name, 1);
    zend_string_release(config_b64);

    return king_orchestrator_persist_state();
}

zval *king_orchestrator_lookup_tool(const char *name, size_t name_len)
{
    if (!king_orchestrator_registry_initialized) {
        return NULL;
    }

    return zend_hash_str_find(&king_orchestrator_tool_registry, name, name_len);
}

int king_orchestrator_configure_logging(zval *config)
{
    zend_string *config_b64;

    if (!king_orchestrator_registry_initialized) {
        if (king_orchestrator_registry_init() != SUCCESS) {
            return FAILURE;
        }
    }

    config_b64 = king_orchestrator_encode_zval_base64(config);
    if (config_b64 == NULL) {
        return FAILURE;
    }

    king_orchestrator_replace_runtime_string(
        &king_orchestrator_logging_config_b64,
        zend_string_dup(config_b64, 1)
    );
    zend_string_release(config_b64);

    return king_orchestrator_persist_state();
}

zend_string *king_orchestrator_pipeline_run_begin(
    zval *initial_data,
    zval *pipeline,
    zval *options)
{
    king_orchestrator_run_state_t *run_state;
    zval stored_run;
    zend_string *initial_data_b64;
    zend_string *pipeline_b64;
    zend_string *options_b64;
    zend_string *result_b64;
    zend_string *error_b64;
    zend_string *run_id;

    if (!king_orchestrator_registry_initialized) {
        if (king_orchestrator_registry_init() != SUCCESS) {
            return NULL;
        }
    }

    initial_data_b64 = king_orchestrator_encode_zval_base64(initial_data);
    pipeline_b64 = king_orchestrator_encode_zval_base64(pipeline);
    options_b64 = king_orchestrator_encode_zval_base64(options);

    {
        zval null_value;

        ZVAL_NULL(&null_value);
        result_b64 = king_orchestrator_encode_zval_base64(&null_value);
        error_b64 = king_orchestrator_encode_zval_base64(&null_value);
    }

    if (
        initial_data_b64 == NULL
        || pipeline_b64 == NULL
        || options_b64 == NULL
        || result_b64 == NULL
        || error_b64 == NULL
    ) {
        if (initial_data_b64 != NULL) {
            zend_string_release(initial_data_b64);
        }
        if (pipeline_b64 != NULL) {
            zend_string_release(pipeline_b64);
        }
        if (options_b64 != NULL) {
            zend_string_release(options_b64);
        }
        if (result_b64 != NULL) {
            zend_string_release(result_b64);
        }
        if (error_b64 != NULL) {
            zend_string_release(error_b64);
        }
        return NULL;
    }

    run_id = strpprintf(0, "run-%ld", king_orchestrator_next_run_id++);
    run_state = pemalloc(sizeof(*run_state), 1);
    memset(run_state, 0, sizeof(*run_state));

    run_state->run_id = zend_string_dup(run_id, 1);
    run_state->status = zend_string_init("running", sizeof("running") - 1, 1);
    run_state->started_at = time(NULL);
    run_state->finished_at = 0;
    run_state->initial_data_b64 = zend_string_dup(initial_data_b64, 1);
    run_state->pipeline_b64 = zend_string_dup(pipeline_b64, 1);
    run_state->options_b64 = zend_string_dup(options_b64, 1);
    run_state->result_b64 = zend_string_dup(result_b64, 1);
    run_state->error_b64 = zend_string_dup(error_b64, 1);

    zend_string_release(initial_data_b64);
    zend_string_release(pipeline_b64);
    zend_string_release(options_b64);
    zend_string_release(result_b64);
    zend_string_release(error_b64);

    ZVAL_PTR(&stored_run, run_state);
    {
        zend_string *persistent_key = zend_string_dup(run_state->run_id, 1);

        zend_hash_update(&king_orchestrator_pipeline_runs, persistent_key, &stored_run);
        zend_string_release_ex(persistent_key, 1);
    }
    king_orchestrator_set_last_run(run_state->run_id, "running");

    if (king_orchestrator_persist_state() != SUCCESS) {
        zend_hash_del(&king_orchestrator_pipeline_runs, run_state->run_id);
        zend_string_release(run_id);
        return NULL;
    }

    return run_id;
}

int king_orchestrator_pipeline_run_complete(zend_string *run_id, zval *result)
{
    king_orchestrator_run_state_t *run_state;
    zend_string *error_b64;
    zend_string *result_b64;
    zval null_value;

    run_state = king_orchestrator_find_run(run_id);
    if (run_state == NULL) {
        return FAILURE;
    }

    result_b64 = king_orchestrator_encode_zval_base64(result);
    if (result_b64 == NULL) {
        return FAILURE;
    }

    ZVAL_NULL(&null_value);
    error_b64 = king_orchestrator_encode_zval_base64(&null_value);
    if (error_b64 == NULL) {
        zend_string_release(result_b64);
        return FAILURE;
    }

    king_orchestrator_replace_runtime_string(
        &run_state->status,
        zend_string_init("completed", sizeof("completed") - 1, 1)
    );
    run_state->finished_at = time(NULL);
    king_orchestrator_replace_runtime_string(&run_state->result_b64, zend_string_dup(result_b64, 1));
    king_orchestrator_replace_runtime_string(&run_state->error_b64, zend_string_dup(error_b64, 1));
    zend_string_release(result_b64);
    zend_string_release(error_b64);
    king_orchestrator_set_last_run(run_state->run_id, "completed");

    return king_orchestrator_persist_state();
}

int king_orchestrator_pipeline_run_fail(zend_string *run_id, const char *error_message)
{
    king_orchestrator_run_state_t *run_state;
    zend_string *error_b64;
    zval error_value;

    run_state = king_orchestrator_find_run(run_id);
    if (run_state == NULL) {
        return FAILURE;
    }

    ZVAL_STRING(&error_value, error_message != NULL ? error_message : "");
    error_b64 = king_orchestrator_encode_zval_base64(&error_value);
    zval_ptr_dtor(&error_value);
    if (error_b64 == NULL) {
        return FAILURE;
    }

    king_orchestrator_replace_runtime_string(
        &run_state->status,
        zend_string_init("failed", sizeof("failed") - 1, 1)
    );
    run_state->finished_at = time(NULL);
    king_orchestrator_replace_runtime_string(&run_state->error_b64, zend_string_dup(error_b64, 1));
    zend_string_release(error_b64);
    king_orchestrator_set_last_run(run_state->run_id, "failed");

    return king_orchestrator_persist_state();
}

void king_orchestrator_append_component_info(zval *configuration)
{
    zval registered_tools;
    zend_string *tool_name;

    if (configuration == NULL || Z_TYPE_P(configuration) != IS_ARRAY) {
        return;
    }

    add_assoc_string(
        configuration,
        "state_path",
        king_mcp_orchestrator_config.orchestrator_state_path != NULL
            ? king_mcp_orchestrator_config.orchestrator_state_path
            : ""
    );
    add_assoc_bool(
        configuration,
        "logging_configured",
        king_orchestrator_logging_config_b64 != NULL ? 1 : 0
    );
    add_assoc_string(
        configuration,
        "execution_backend",
        king_mcp_orchestrator_config.orchestrator_execution_backend != NULL
            ? king_mcp_orchestrator_config.orchestrator_execution_backend
            : ""
    );
    add_assoc_string(
        configuration,
        "worker_queue_path",
        king_mcp_orchestrator_config.orchestrator_worker_queue_path != NULL
            ? king_mcp_orchestrator_config.orchestrator_worker_queue_path
            : ""
    );
    add_assoc_bool(
        configuration,
        "recovered_from_state",
        king_orchestrator_recovered_from_state ? 1 : 0
    );
    add_assoc_long(
        configuration,
        "tool_count",
        (zend_long) zend_hash_num_elements(&king_orchestrator_tool_registry)
    );
    add_assoc_long(
        configuration,
        "run_history_count",
        (zend_long) zend_hash_num_elements(&king_orchestrator_pipeline_runs)
    );
    add_assoc_long(
        configuration,
        "active_run_count",
        (zend_long) king_orchestrator_count_active_runs()
    );
    add_assoc_long(
        configuration,
        "queued_run_count",
        (zend_long) king_orchestrator_count_queued_runs()
    );

    if (king_orchestrator_last_run_id != NULL) {
        add_assoc_stringl(
            configuration,
            "last_run_id",
            ZSTR_VAL(king_orchestrator_last_run_id),
            ZSTR_LEN(king_orchestrator_last_run_id)
        );
    } else {
        add_assoc_null(configuration, "last_run_id");
    }

    if (king_orchestrator_last_run_status != NULL) {
        add_assoc_stringl(
            configuration,
            "last_run_status",
            ZSTR_VAL(king_orchestrator_last_run_status),
            ZSTR_LEN(king_orchestrator_last_run_status)
        );
    } else {
        add_assoc_null(configuration, "last_run_status");
    }

    array_init(&registered_tools);
    ZEND_HASH_FOREACH_STR_KEY(&king_orchestrator_tool_registry, tool_name) {
        if (tool_name != NULL) {
            add_next_index_stringl(&registered_tools, ZSTR_VAL(tool_name), ZSTR_LEN(tool_name));
        }
    } ZEND_HASH_FOREACH_END();
    add_assoc_zval(configuration, "registered_tools", &registered_tools);
}

PHP_FUNCTION(king_pipeline_orchestrator_register_tool)
{
    char *tool_name = NULL;
    size_t tool_name_len = 0;
    zval *config;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STRING(tool_name, tool_name_len)
        Z_PARAM_ARRAY(config)
    ZEND_PARSE_PARAMETERS_END();

    if (tool_name_len == 0) {
        king_set_error("king_pipeline_orchestrator_register_tool() requires a non-empty tool name.");
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "king_pipeline_orchestrator_register_tool() requires a non-empty tool name."
        );
        RETURN_THROWS();
    }

    if (king_orchestrator_register_tool(tool_name, tool_name_len, config) == SUCCESS) {
        RETURN_TRUE;
    }

    king_set_error("king_pipeline_orchestrator_register_tool() failed to persist the tool registry snapshot.");
    zend_throw_exception_ex(
        king_ce_runtime_exception,
        0,
        "king_pipeline_orchestrator_register_tool() failed to persist the tool registry snapshot."
    );
    RETURN_THROWS();
}

PHP_FUNCTION(king_pipeline_orchestrator_get_run)
{
    zend_string *run_id;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(run_id)
    ZEND_PARSE_PARAMETERS_END();

    if (ZSTR_LEN(run_id) == 0) {
        king_set_error("king_pipeline_orchestrator_get_run() requires a non-empty run id.");
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "king_pipeline_orchestrator_get_run() requires a non-empty run id."
        );
        RETURN_THROWS();
    }

    if (king_orchestrator_get_run_snapshot(run_id, return_value) == SUCCESS) {
        return;
    }

    RETURN_FALSE;
}
