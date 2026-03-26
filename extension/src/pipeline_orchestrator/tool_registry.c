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
#include "main/fopen_wrappers.h"
#include "zend_smart_str.h"

#include <dirent.h>
#include <errno.h>
#include <fcntl.h>
#include <sys/file.h>
#include <stdlib.h>
#include <stdio.h>
#include <string.h>
#include <sys/stat.h>
#include <unistd.h>
#include <zend_hash.h>

#define KING_ORCHESTRATOR_STATE_VERSION 2

typedef struct _king_orchestrator_run_state {
    zend_string *run_id;
    zend_string *status;
    time_t started_at;
    time_t finished_at;
    zend_bool cancel_requested;
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
static king_orchestrator_run_state_t *king_orchestrator_find_run(zend_string *run_id);
static int king_orchestrator_persist_state_locked(void);
static int king_orchestrator_load_state(void);

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

static int king_orchestrator_state_path_is_configured(void)
{
    return king_mcp_orchestrator_config.orchestrator_state_path != NULL
        && king_mcp_orchestrator_config.orchestrator_state_path[0] != '\0';
}

static int king_orchestrator_queue_path_is_configured(void)
{
    return king_mcp_orchestrator_config.orchestrator_worker_queue_path != NULL
        && king_mcp_orchestrator_config.orchestrator_worker_queue_path[0] != '\0';
}

static void king_orchestrator_runtime_clear_state(void)
{
    zend_hash_clean(&king_orchestrator_tool_registry);
    zend_hash_clean(&king_orchestrator_pipeline_runs);

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

    king_orchestrator_recovered_from_state = false;
    king_orchestrator_next_run_id = 1;
}

static int king_orchestrator_build_state_lock_path(
    const char *state_path,
    char *lock_path,
    size_t lock_path_len
)
{
    if (
        state_path == NULL
        || state_path[0] == '\0'
        || lock_path == NULL
        || lock_path_len == 0
    ) {
        return FAILURE;
    }

    if (snprintf(lock_path, lock_path_len, "%s.lock", state_path) >= (int) lock_path_len) {
        return FAILURE;
    }

    if (php_check_open_basedir(lock_path) != 0) {
        return FAILURE;
    }

    return SUCCESS;
}

static int king_orchestrator_state_lock_acquire(int *lock_fd_out)
{
    char lock_path[1024];
    int flags = O_RDWR | O_CREAT;
    int fd;

    if (lock_fd_out == NULL) {
        return FAILURE;
    }

    *lock_fd_out = -1;

    if (!king_orchestrator_state_path_is_configured()) {
        return SUCCESS;
    }

    if (king_orchestrator_build_state_lock_path(
            king_mcp_orchestrator_config.orchestrator_state_path,
            lock_path,
            sizeof(lock_path)
        ) != SUCCESS) {
        return FAILURE;
    }

#ifdef O_CLOEXEC
    flags |= O_CLOEXEC;
#endif
#ifdef O_NOFOLLOW
    flags |= O_NOFOLLOW;
#endif

    fd = open(lock_path, flags, 0600);
    if (fd < 0) {
        return FAILURE;
    }

    if (fchmod(fd, 0600) != 0) {
        close(fd);
        return FAILURE;
    }

    if (flock(fd, LOCK_EX) != 0) {
        close(fd);
        return FAILURE;
    }

    *lock_fd_out = fd;
    return SUCCESS;
}

static void king_orchestrator_state_lock_release(int lock_fd)
{
    if (lock_fd < 0) {
        return;
    }

    (void) flock(lock_fd, LOCK_UN);
    close(lock_fd);
}

static int king_orchestrator_state_refresh_locked(void)
{
    if (!king_orchestrator_state_path_is_configured()) {
        return SUCCESS;
    }

    return king_orchestrator_load_state();
}

static int king_orchestrator_state_transaction_begin(int *lock_fd_out)
{
    if (lock_fd_out == NULL) {
        return FAILURE;
    }

    *lock_fd_out = -1;

    if (!king_orchestrator_registry_initialized) {
        if (king_orchestrator_registry_init() != SUCCESS) {
            return FAILURE;
        }
    }

    if (king_orchestrator_state_lock_acquire(lock_fd_out) != SUCCESS) {
        return FAILURE;
    }

    if (king_orchestrator_state_refresh_locked() != SUCCESS) {
        king_orchestrator_state_lock_release(*lock_fd_out);
        *lock_fd_out = -1;
        return FAILURE;
    }

    return SUCCESS;
}

static int king_orchestrator_build_queue_entry_path(
    const char *entry_name,
    char *path,
    size_t path_len
)
{
    if (
        entry_name == NULL
        || path == NULL
        || path_len == 0
        || !king_orchestrator_queue_path_is_configured()
    ) {
        return FAILURE;
    }

    if (snprintf(
            path,
            path_len,
            "%s/%s",
            king_mcp_orchestrator_config.orchestrator_worker_queue_path,
            entry_name
        ) >= (int) path_len) {
        return FAILURE;
    }

    return SUCCESS;
}

static int king_orchestrator_queue_entry_is_regular_file(const char *path)
{
    struct stat st;

    if (path == NULL) {
        return 0;
    }

    return lstat(path, &st) == 0 && S_ISREG(st.st_mode);
}

static int king_orchestrator_queue_is_safe_directory(void)
{
    struct stat st;
    const char *queue_path = king_mcp_orchestrator_config.orchestrator_worker_queue_path;

    if (!king_orchestrator_queue_path_is_configured()) {
        return FAILURE;
    }

    if (lstat(queue_path, &st) != 0) {
        return FAILURE;
    }

    if (!S_ISDIR(st.st_mode)) {
        return FAILURE;
    }

    if ((st.st_mode & (S_IWGRP | S_IWOTH)) != 0) {
        return FAILURE;
    }

    return SUCCESS;
}

static FILE *king_orchestrator_open_nofollow_stream(
    const char *path,
    int flags,
    mode_t mode,
    const char *stream_mode
)
{
    int fd;
    FILE *stream;

    if (path == NULL || stream_mode == NULL) {
        return NULL;
    }

#ifdef O_CLOEXEC
    flags |= O_CLOEXEC;
#endif
#ifdef O_NOFOLLOW
    flags |= O_NOFOLLOW;
#endif

    fd = open(path, flags, mode);
    if (fd < 0) {
        return NULL;
    }

    stream = fdopen(fd, stream_mode);
    if (stream == NULL) {
        close(fd);
        return NULL;
    }

    return stream;
}

static int king_orchestrator_state_path_validate_existing(const char *state_path)
{
    struct stat st;

    if (state_path == NULL || state_path[0] == '\0') {
        return FAILURE;
    }

    if (php_check_open_basedir(state_path) != 0) {
        return FAILURE;
    }

    if (lstat(state_path, &st) != 0) {
        return errno == ENOENT ? SUCCESS : FAILURE;
    }

    return S_ISREG(st.st_mode) ? SUCCESS : FAILURE;
}

static int king_orchestrator_build_state_tmp_template(
    const char *state_path,
    char *tmp_path,
    size_t tmp_path_len
)
{
    if (
        state_path == NULL
        || state_path[0] == '\0'
        || tmp_path == NULL
        || tmp_path_len == 0
    ) {
        return FAILURE;
    }

    if (snprintf(tmp_path, tmp_path_len, "%s.tmp.XXXXXX", state_path) >= (int) tmp_path_len) {
        return FAILURE;
    }

    if (php_check_open_basedir(tmp_path) != 0) {
        return FAILURE;
    }

    return SUCCESS;
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

static int king_orchestrator_queue_name_is_claimed_job(const char *name)
{
    size_t name_len;

    if (name == NULL || strncmp(name, "claimed-", sizeof("claimed-") - 1) != 0) {
        return 0;
    }

    name_len = strlen(name);
    return name_len > sizeof("claimed-.job") - 1
        && strcmp(name + name_len - (sizeof(".job") - 1), ".job") == 0;
}

static zend_long king_orchestrator_queue_entry_sequence(const char *name)
{
    const char *run_start;
    char *endptr;
    zend_long sequence;

    if (name == NULL) {
        return 0;
    }

    run_start = strstr(name, "run-");
    if (run_start == NULL) {
        return 0;
    }

    run_start += sizeof("run-") - 1;
    if (*run_start == '\0') {
        return 0;
    }

    errno = 0;
    sequence = ZEND_STRTOL(run_start, &endptr, 10);
    if (errno != 0 || endptr == run_start || sequence <= 0) {
        return 0;
    }

    if (strcmp(endptr, ".job") != 0) {
        return 0;
    }

    return sequence;
}

static int king_orchestrator_queue_entry_is_better_candidate(
    const char *candidate_name,
    zend_long candidate_sequence,
    const char *selected_name,
    zend_long selected_sequence
)
{
    if (candidate_name == NULL || candidate_name[0] == '\0') {
        return 0;
    }

    if (selected_name == NULL || selected_name[0] == '\0') {
        return 1;
    }

    if (candidate_sequence > 0) {
        if (selected_sequence <= 0) {
            return 1;
        }
        if (candidate_sequence < selected_sequence) {
            return 1;
        }
        if (candidate_sequence > selected_sequence) {
            return 0;
        }
    } else if (selected_sequence > 0) {
        return 0;
    }

    return strcmp(candidate_name, selected_name) < 0;
}

static int king_orchestrator_build_cancel_marker_path(
    const zend_string *run_id,
    char *path,
    size_t path_len
)
{
    if (
        run_id == NULL
        || path == NULL
        || path_len == 0
        || !king_orchestrator_queue_path_is_configured()
    ) {
        return FAILURE;
    }

    if (snprintf(
            path,
            path_len,
            "%s/cancel-%s.sig",
            king_mcp_orchestrator_config.orchestrator_worker_queue_path,
            ZSTR_VAL(run_id)
        ) >= (int) path_len) {
        return FAILURE;
    }

    return SUCCESS;
}

static int king_orchestrator_build_run_queue_path(
    const zend_string *run_id,
    char *path,
    size_t path_len
)
{
    if (
        run_id == NULL
        || path == NULL
        || path_len == 0
        || !king_orchestrator_queue_path_is_configured()
    ) {
        return FAILURE;
    }

    if (snprintf(
            path,
            path_len,
            "%s/queued-%s.job",
            king_mcp_orchestrator_config.orchestrator_worker_queue_path,
            ZSTR_VAL(run_id)
        ) >= (int) path_len) {
        return FAILURE;
    }

    return SUCCESS;
}

static int king_orchestrator_run_state_is_terminal(const king_orchestrator_run_state_t *run_state)
{
    if (run_state == NULL || run_state->status == NULL) {
        return 0;
    }

    return zend_string_equals_literal(run_state->status, "completed")
        || zend_string_equals_literal(run_state->status, "failed")
        || zend_string_equals_literal(run_state->status, "cancelled");
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

    if (mkdir(queue_path, 0700) != 0 && errno != EEXIST) {
        return FAILURE;
    }

    if (king_orchestrator_queue_is_safe_directory() != SUCCESS) {
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
        char entry_path[1024];

        if (king_orchestrator_queue_name_is_job(entry->d_name)) {
            if (
                king_orchestrator_build_queue_entry_path(
                    entry->d_name,
                    entry_path,
                    sizeof(entry_path)
                ) == SUCCESS
                && king_orchestrator_queue_entry_is_regular_file(entry_path)
            ) {
                queued_runs++;
            }
        }
    }

    closedir(dir);
    return queued_runs;
}

static int king_orchestrator_persist_state_locked(void)
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
    if (king_orchestrator_state_path_validate_existing(state_path) != SUCCESS) {
        return FAILURE;
    }

    if (king_orchestrator_build_state_tmp_template(state_path, tmp_path, sizeof(tmp_path)) != SUCCESS) {
        return FAILURE;
    }

    {
        int fd = mkstemp(tmp_path);
        if (fd < 0) {
            return FAILURE;
        }
        if (fchmod(fd, 0600) != 0) {
            close(fd);
            unlink(tmp_path);
            return FAILURE;
        }
        stream = fdopen(fd, "wb");
        if (stream == NULL) {
            close(fd);
            unlink(tmp_path);
            return FAILURE;
        }
    }

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
            "run\t%s\t%s\t%ld\t%ld\t%s\t%s\t%s\t%s\t%s\t%d\n",
            ZSTR_VAL(run_state->run_id),
            ZSTR_VAL(run_state->status),
            (long) run_state->started_at,
            (long) run_state->finished_at,
            run_state->initial_data_b64 != NULL ? ZSTR_VAL(run_state->initial_data_b64) : "",
            run_state->pipeline_b64 != NULL ? ZSTR_VAL(run_state->pipeline_b64) : "",
            run_state->options_b64 != NULL ? ZSTR_VAL(run_state->options_b64) : "",
            run_state->result_b64 != NULL ? ZSTR_VAL(run_state->result_b64) : "",
            run_state->error_b64 != NULL ? ZSTR_VAL(run_state->error_b64) : "",
            run_state->cancel_requested ? 1 : 0
        );
    } ZEND_HASH_FOREACH_END();

    if (fclose(stream) != 0) {
        unlink(tmp_path);
        return FAILURE;
    }

    if (king_orchestrator_state_path_validate_existing(state_path) != SUCCESS) {
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
    struct stat st;

    king_orchestrator_runtime_clear_state();

    if (state_path == NULL || state_path[0] == '\0') {
        king_orchestrator_recovered_from_state = false;
        return SUCCESS;
    }

    if (php_check_open_basedir(state_path) != 0) {
        return FAILURE;
    }

    if (lstat(state_path, &st) != 0) {
        if (errno == ENOENT) {
            king_orchestrator_recovered_from_state = false;
            return SUCCESS;
        }

        return FAILURE;
    }

    if (!S_ISREG(st.st_mode)) {
        return FAILURE;
    }

    stream = king_orchestrator_open_nofollow_stream(
        state_path,
        O_RDONLY,
        0,
        "rb"
    );
    if (stream == NULL) {
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
            char *cancel_requested = strtok_r(NULL, "\t\r\n", &saveptr);
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
            run_state->cancel_requested = (zend_bool) (
                cancel_requested != NULL && cancel_requested[0] != '\0'
                && ZEND_STRTOL(cancel_requested, NULL, 10) != 0
            );
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
    int lock_fd = -1;

    if (initial_data == NULL || pipeline == NULL || options == NULL) {
        return FAILURE;
    }

    ZVAL_NULL(initial_data);
    ZVAL_NULL(pipeline);
    ZVAL_NULL(options);

    if (king_orchestrator_state_transaction_begin(&lock_fd) != SUCCESS) {
        return FAILURE;
    }

    run_state = king_orchestrator_find_run(run_id);
    if (run_state == NULL) {
        king_orchestrator_state_lock_release(lock_fd);
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
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    king_orchestrator_state_lock_release(lock_fd);
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
    int lock_fd = -1;

    ZVAL_NULL(&initial_data);
    ZVAL_NULL(&pipeline);
    ZVAL_NULL(&options);
    ZVAL_NULL(&result);
    ZVAL_NULL(&error);

    if (king_orchestrator_state_transaction_begin(&lock_fd) != SUCCESS) {
        return FAILURE;
    }

    run_state = king_orchestrator_find_run(run_id);
    if (run_state == NULL) {
        king_orchestrator_state_lock_release(lock_fd);
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
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    array_init(return_value);
    add_assoc_stringl(return_value, "run_id", ZSTR_VAL(run_state->run_id), ZSTR_LEN(run_state->run_id));
    add_assoc_stringl(return_value, "status", ZSTR_VAL(run_state->status), ZSTR_LEN(run_state->status));
    add_assoc_long(return_value, "started_at", (zend_long) run_state->started_at);
    add_assoc_long(return_value, "finished_at", (zend_long) run_state->finished_at);
    add_assoc_bool(return_value, "cancel_requested", run_state->cancel_requested ? 1 : 0);
    add_assoc_zval(return_value, "initial_data", &initial_data);
    add_assoc_zval(return_value, "pipeline", &pipeline);
    add_assoc_zval(return_value, "options", &options);
    add_assoc_zval(return_value, "result", &result);
    add_assoc_zval(return_value, "error", &error);

    king_orchestrator_state_lock_release(lock_fd);
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

    stream = king_orchestrator_open_nofollow_stream(
        queue_file_path,
        O_WRONLY | O_CREAT | O_EXCL,
        0600,
        "wb"
    );
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

int king_orchestrator_run_cancel_requested(zend_string *run_id)
{
    char marker_path[1024];

    if (king_orchestrator_build_cancel_marker_path(run_id, marker_path, sizeof(marker_path)) != SUCCESS) {
        return 0;
    }

    return king_orchestrator_queue_entry_is_regular_file(marker_path);
}

void king_orchestrator_clear_run_cancel_request(zend_string *run_id)
{
    char marker_path[1024];

    if (king_orchestrator_build_cancel_marker_path(run_id, marker_path, sizeof(marker_path)) != SUCCESS) {
        return;
    }

    unlink(marker_path);
}

int king_orchestrator_request_run_cancel(zend_string *run_id)
{
    king_orchestrator_run_state_t *run_state;
    char marker_path[1024];
    char queued_path[1024];
    FILE *stream;
    zend_bool previous_cancel_requested;
    int lock_fd = -1;
    int rc;

    if (
        run_id == NULL
        || !king_orchestrator_backend_is_file_worker()
        || !king_orchestrator_queue_path_is_configured()
    ) {
        return FAILURE;
    }

    if (king_orchestrator_state_transaction_begin(&lock_fd) != SUCCESS) {
        return FAILURE;
    }

    run_state = king_orchestrator_find_run(run_id);
    if (run_state == NULL || king_orchestrator_run_state_is_terminal(run_state)) {
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    if (run_state->cancel_requested) {
        king_orchestrator_state_lock_release(lock_fd);
        return SUCCESS;
    }

    if (
        run_state->status != NULL
        && zend_string_equals_literal(run_state->status, "queued")
        && king_orchestrator_build_run_queue_path(run_id, queued_path, sizeof(queued_path)) == SUCCESS
        && king_orchestrator_queue_entry_is_regular_file(queued_path)
    ) {
        zend_string *error_b64;

        if (unlink(queued_path) != 0) {
            king_orchestrator_state_lock_release(lock_fd);
            return FAILURE;
        }

        error_b64 = king_orchestrator_encode_error_message_base64(
            "king_pipeline_orchestrator_cancel_run() cancelled the queued run before worker claim."
        );
        king_orchestrator_replace_runtime_string(
            &run_state->status,
            zend_string_init("cancelled", sizeof("cancelled") - 1, 1)
        );
        run_state->finished_at = time(NULL);
        run_state->cancel_requested = 1;
        if (error_b64 != NULL) {
            king_orchestrator_replace_runtime_string(&run_state->error_b64, zend_string_dup(error_b64, 1));
            zend_string_release(error_b64);
        }
        king_orchestrator_set_last_run(run_state->run_id, "cancelled");

        rc = king_orchestrator_persist_state_locked();
        king_orchestrator_state_lock_release(lock_fd);
        return rc;
    }

    if (king_orchestrator_queue_ensure_directory() != SUCCESS) {
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }
    if (king_orchestrator_build_cancel_marker_path(run_id, marker_path, sizeof(marker_path)) != SUCCESS) {
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    previous_cancel_requested = run_state->cancel_requested;
    run_state->cancel_requested = 1;
    if (king_orchestrator_persist_state_locked() != SUCCESS) {
        run_state->cancel_requested = previous_cancel_requested;
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    stream = king_orchestrator_open_nofollow_stream(
        marker_path,
        O_WRONLY | O_CREAT | O_TRUNC,
        0600,
        "wb"
    );
    if (stream == NULL) {
        run_state->cancel_requested = previous_cancel_requested;
        (void) king_orchestrator_persist_state_locked();
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    if (fprintf(stream, "%s\n", ZSTR_VAL(run_id)) < 0) {
        fclose(stream);
        unlink(marker_path);
        run_state->cancel_requested = previous_cancel_requested;
        (void) king_orchestrator_persist_state_locked();
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    if (fclose(stream) != 0) {
        unlink(marker_path);
        run_state->cancel_requested = previous_cancel_requested;
        (void) king_orchestrator_persist_state_locked();
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    king_orchestrator_state_lock_release(lock_fd);
    return SUCCESS;
}

int king_orchestrator_claim_next_run(
    zend_string **run_id_out,
    char *claimed_path,
    size_t claimed_path_len,
    int *claimed_fd_out
)
{
    int claimed_fd = -1;
    char run_id_buffer[256];
    char *newline;
    unsigned int attempt = 0;
    char busy_names[64][256];
    size_t busy_count = 0;

    if (run_id_out == NULL || claimed_path == NULL || claimed_path_len == 0) {
        return FAILURE;
    }

    *run_id_out = NULL;
    claimed_path[0] = '\0';

    if (claimed_fd_out != NULL) {
        *claimed_fd_out = -1;
    }

    if (!king_orchestrator_backend_is_file_worker() || !king_orchestrator_queue_path_is_configured()) {
        return FAILURE;
    }

    for (;;) {
        DIR *dir;
        struct dirent *entry;
        char queued_path[1024];
        char selected_name[256];
        zend_long selected_sequence = 0;
        int selected_is_claimed = 0;

        dir = opendir(king_mcp_orchestrator_config.orchestrator_worker_queue_path);
        if (dir == NULL) {
            if (errno == ENOENT) {
                return SUCCESS;
            }
            return FAILURE;
        }

        selected_name[0] = '\0';
        while ((entry = readdir(dir)) != NULL) {
            if (king_orchestrator_queue_name_is_claimed_job(entry->d_name)) {
                char entry_path[1024];
                zend_long entry_sequence;
                size_t busy_idx;
                int skip_entry = 0;

                for (busy_idx = 0; busy_idx < busy_count; busy_idx++) {
                    if (strcmp(entry->d_name, busy_names[busy_idx]) == 0) {
                        skip_entry = 1;
                        break;
                    }
                }
                if (skip_entry) {
                    continue;
                }

                if (
                    king_orchestrator_build_queue_entry_path(
                        entry->d_name,
                        entry_path,
                        sizeof(entry_path)
                    ) != SUCCESS
                    || !king_orchestrator_queue_entry_is_regular_file(entry_path)
                ) {
                    continue;
                }

                entry_sequence = king_orchestrator_queue_entry_sequence(entry->d_name);
                if (!king_orchestrator_queue_entry_is_better_candidate(
                        entry->d_name,
                        entry_sequence,
                        selected_name,
                        selected_sequence
                    )) {
                    continue;
                }

                strncpy(selected_name, entry->d_name, sizeof(selected_name) - 1);
                selected_name[sizeof(selected_name) - 1] = '\0';
                selected_sequence = entry_sequence;
                selected_is_claimed = 1;
            }
        }

        if (selected_name[0] == '\0') {
            rewinddir(dir);

            while ((entry = readdir(dir)) != NULL) {
                if (king_orchestrator_queue_name_is_job(entry->d_name)) {
                    char entry_path[1024];
                    zend_long entry_sequence;

                    if (
                        king_orchestrator_build_queue_entry_path(
                            entry->d_name,
                            entry_path,
                            sizeof(entry_path)
                        ) != SUCCESS
                        || !king_orchestrator_queue_entry_is_regular_file(entry_path)
                    ) {
                        continue;
                    }

                    entry_sequence = king_orchestrator_queue_entry_sequence(entry->d_name);
                    if (!king_orchestrator_queue_entry_is_better_candidate(
                            entry->d_name,
                            entry_sequence,
                            selected_name,
                            selected_sequence
                        )) {
                        continue;
                    }

                    strncpy(selected_name, entry->d_name, sizeof(selected_name) - 1);
                    selected_name[sizeof(selected_name) - 1] = '\0';
                    selected_sequence = entry_sequence;
                }
            }
        }

        closedir(dir);

        if (selected_name[0] == '\0') {
            return SUCCESS;
        }

        if (selected_is_claimed) {
            snprintf(
                claimed_path,
                claimed_path_len,
                "%s/%s",
                king_mcp_orchestrator_config.orchestrator_worker_queue_path,
                selected_name
            );
        } else {
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
                if (errno != ENOENT && errno != EEXIST) {
                    return FAILURE;
                }

                attempt++;
                if (attempt >= 64) {
                    return SUCCESS;
                }
                continue;
            }
        }

        claimed_fd = open(claimed_path, O_RDONLY);
        if (claimed_fd < 0) {
            if (!selected_is_claimed) {
                unlink(claimed_path);
            }
            claimed_path[0] = '\0';
            if (errno != ENOENT) {
                return FAILURE;
            }
            attempt++;
            if (attempt >= 64) {
                return SUCCESS;
            }
            continue;
        }

        if (flock(claimed_fd, LOCK_EX | LOCK_NB) != 0) {
            int lock_errno = errno;

            close(claimed_fd);
            claimed_fd = -1;
            claimed_path[0] = '\0';

            if (lock_errno != EWOULDBLOCK && lock_errno != EAGAIN) {
                return FAILURE;
            }

            if (busy_count < (sizeof(busy_names) / sizeof(busy_names[0]))) {
                strncpy(busy_names[busy_count], selected_name, sizeof(busy_names[busy_count]) - 1);
                busy_names[busy_count][sizeof(busy_names[busy_count]) - 1] = '\0';
                busy_count++;
            }

            attempt++;
            if (attempt >= 64) {
                return SUCCESS;
            }
            continue;
        }

        if (lseek(claimed_fd, 0, SEEK_SET) < 0) {
            close(claimed_fd);
            claimed_fd = -1;
            if (!selected_is_claimed) {
                unlink(claimed_path);
            }
            claimed_path[0] = '\0';
            return FAILURE;
        }

        {
            ssize_t bytes_read = read(claimed_fd, run_id_buffer, sizeof(run_id_buffer) - 1);

            if (bytes_read <= 0) {
                close(claimed_fd);
                claimed_fd = -1;
                unlink(claimed_path);
                claimed_path[0] = '\0';
                return FAILURE;
            }

            run_id_buffer[bytes_read] = '\0';
        }

        newline = strpbrk(run_id_buffer, "\r\n");
        if (newline != NULL) {
            *newline = '\0';
        }

        if (run_id_buffer[0] == '\0') {
            close(claimed_fd);
            claimed_fd = -1;
            unlink(claimed_path);
            claimed_path[0] = '\0';
            return FAILURE;
        }

        *run_id_out = zend_string_init(run_id_buffer, strlen(run_id_buffer), 0);
        if (claimed_fd_out != NULL) {
            *claimed_fd_out = claimed_fd;
        } else {
            close(claimed_fd);
        }
        return SUCCESS;
    }
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
    int lock_fd = -1;
    int rc;

    if (king_orchestrator_state_transaction_begin(&lock_fd) != SUCCESS) {
        return FAILURE;
    }

    config_b64 = king_orchestrator_encode_zval_base64(config);
    if (config_b64 == NULL) {
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    ZVAL_STR(&stored_config, zend_string_dup(config_b64, 1));
    persistent_name = zend_string_init(name, name_len, 1);
    zend_hash_update(&king_orchestrator_tool_registry, persistent_name, &stored_config);
    zend_string_release_ex(persistent_name, 1);
    zend_string_release(config_b64);

    rc = king_orchestrator_persist_state_locked();
    king_orchestrator_state_lock_release(lock_fd);

    return rc;
}

zval *king_orchestrator_lookup_tool(const char *name, size_t name_len)
{
    int lock_fd = -1;
    zval *entry;

    if (!king_orchestrator_registry_initialized) {
        if (king_orchestrator_registry_init() != SUCCESS) {
            return NULL;
        }
    }

    if (king_orchestrator_state_transaction_begin(&lock_fd) != SUCCESS) {
        return NULL;
    }

    entry = zend_hash_str_find(&king_orchestrator_tool_registry, name, name_len);
    king_orchestrator_state_lock_release(lock_fd);

    return entry;
}

int king_orchestrator_configure_logging(zval *config)
{
    zend_string *config_b64;
    int lock_fd = -1;
    int rc;

    if (king_orchestrator_state_transaction_begin(&lock_fd) != SUCCESS) {
        return FAILURE;
    }

    config_b64 = king_orchestrator_encode_zval_base64(config);
    if (config_b64 == NULL) {
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    king_orchestrator_replace_runtime_string(
        &king_orchestrator_logging_config_b64,
        zend_string_dup(config_b64, 1)
    );
    zend_string_release(config_b64);

    rc = king_orchestrator_persist_state_locked();
    king_orchestrator_state_lock_release(lock_fd);

    return rc;
}

zend_string *king_orchestrator_pipeline_run_begin(
    zval *initial_data,
    zval *pipeline,
    zval *options,
    const char *initial_status)
{
    king_orchestrator_run_state_t *run_state;
    zval stored_run;
    zend_string *initial_data_b64;
    zend_string *pipeline_b64;
    zend_string *options_b64;
    zend_string *result_b64;
    zend_string *error_b64;
    zend_string *run_id;
    int lock_fd = -1;

    if (king_orchestrator_state_transaction_begin(&lock_fd) != SUCCESS) {
        return NULL;
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
        king_orchestrator_state_lock_release(lock_fd);
        return NULL;
    }

    if (initial_status == NULL || initial_status[0] == '\0') {
        initial_status = "running";
    }

    run_id = strpprintf(0, "run-%ld", king_orchestrator_next_run_id++);
    run_state = pemalloc(sizeof(*run_state), 1);
    memset(run_state, 0, sizeof(*run_state));

    run_state->run_id = zend_string_dup(run_id, 1);
    run_state->status = zend_string_init(initial_status, strlen(initial_status), 1);
    run_state->started_at = time(NULL);
    run_state->finished_at = 0;
    run_state->cancel_requested = 0;
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
    king_orchestrator_set_last_run(run_state->run_id, initial_status);

    if (king_orchestrator_persist_state_locked() != SUCCESS) {
        zend_hash_del(&king_orchestrator_pipeline_runs, run_state->run_id);
        king_orchestrator_state_lock_release(lock_fd);
        zend_string_release(run_id);
        return NULL;
    }

    king_orchestrator_state_lock_release(lock_fd);
    return run_id;
}

int king_orchestrator_pipeline_run_mark_running(zend_string *run_id)
{
    king_orchestrator_run_state_t *run_state;
    int lock_fd = -1;
    int rc;

    if (king_orchestrator_state_transaction_begin(&lock_fd) != SUCCESS) {
        return FAILURE;
    }

    run_state = king_orchestrator_find_run(run_id);
    if (run_state == NULL || run_state->status == NULL) {
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    if (
        king_orchestrator_run_state_is_terminal(run_state)
        || zend_string_equals_literal(run_state->status, "running")
    ) {
        king_orchestrator_state_lock_release(lock_fd);
        return SUCCESS;
    }

    king_orchestrator_replace_runtime_string(
        &run_state->status,
        zend_string_init("running", sizeof("running") - 1, 1)
    );
    run_state->finished_at = 0;
    king_orchestrator_set_last_run(run_state->run_id, "running");

    rc = king_orchestrator_persist_state_locked();
    king_orchestrator_state_lock_release(lock_fd);

    return rc;
}

int king_orchestrator_pipeline_run_is_terminal(zend_string *run_id)
{
    int lock_fd = -1;
    int is_terminal;

    if (king_orchestrator_state_transaction_begin(&lock_fd) != SUCCESS) {
        return 0;
    }

    is_terminal = king_orchestrator_run_state_is_terminal(king_orchestrator_find_run(run_id));
    king_orchestrator_state_lock_release(lock_fd);

    return is_terminal;
}

int king_orchestrator_pipeline_run_complete(zend_string *run_id, zval *result)
{
    king_orchestrator_run_state_t *run_state;
    zend_string *error_b64;
    zend_string *result_b64;
    zval null_value;
    int lock_fd = -1;
    int rc;

    if (king_orchestrator_state_transaction_begin(&lock_fd) != SUCCESS) {
        return FAILURE;
    }

    run_state = king_orchestrator_find_run(run_id);
    if (run_state == NULL) {
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    result_b64 = king_orchestrator_encode_zval_base64(result);
    if (result_b64 == NULL) {
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    ZVAL_NULL(&null_value);
    error_b64 = king_orchestrator_encode_zval_base64(&null_value);
    if (error_b64 == NULL) {
        zend_string_release(result_b64);
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    king_orchestrator_replace_runtime_string(
        &run_state->status,
        zend_string_init("completed", sizeof("completed") - 1, 1)
    );
    run_state->finished_at = time(NULL);
    run_state->cancel_requested = 0;
    king_orchestrator_replace_runtime_string(&run_state->result_b64, zend_string_dup(result_b64, 1));
    king_orchestrator_replace_runtime_string(&run_state->error_b64, zend_string_dup(error_b64, 1));
    zend_string_release(result_b64);
    zend_string_release(error_b64);
    king_orchestrator_set_last_run(run_state->run_id, "completed");

    rc = king_orchestrator_persist_state_locked();
    king_orchestrator_state_lock_release(lock_fd);
    if (rc != SUCCESS) {
        return FAILURE;
    }

    king_orchestrator_clear_run_cancel_request(run_state->run_id);
    return SUCCESS;
}

int king_orchestrator_pipeline_run_fail(zend_string *run_id, const char *error_message)
{
    king_orchestrator_run_state_t *run_state;
    zend_string *error_b64;
    int lock_fd = -1;
    int rc;

    if (king_orchestrator_state_transaction_begin(&lock_fd) != SUCCESS) {
        return FAILURE;
    }

    run_state = king_orchestrator_find_run(run_id);
    if (run_state == NULL) {
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    error_b64 = king_orchestrator_encode_error_message_base64(error_message);

    king_orchestrator_replace_runtime_string(
        &run_state->status,
        zend_string_init("failed", sizeof("failed") - 1, 1)
    );
    run_state->finished_at = time(NULL);
    run_state->cancel_requested = 0;
    if (error_b64 != NULL) {
        king_orchestrator_replace_runtime_string(&run_state->error_b64, zend_string_dup(error_b64, 1));
        zend_string_release(error_b64);
    }
    king_orchestrator_set_last_run(run_state->run_id, "failed");

    rc = king_orchestrator_persist_state_locked();
    king_orchestrator_state_lock_release(lock_fd);
    if (rc != SUCCESS) {
        return FAILURE;
    }

    king_orchestrator_clear_run_cancel_request(run_state->run_id);
    return SUCCESS;
}

int king_orchestrator_pipeline_run_cancelled(zend_string *run_id, const char *error_message)
{
    king_orchestrator_run_state_t *run_state;
    zend_string *error_b64;
    int lock_fd = -1;
    int rc;

    if (king_orchestrator_state_transaction_begin(&lock_fd) != SUCCESS) {
        return FAILURE;
    }

    run_state = king_orchestrator_find_run(run_id);
    if (run_state == NULL) {
        king_orchestrator_state_lock_release(lock_fd);
        return FAILURE;
    }

    error_b64 = king_orchestrator_encode_error_message_base64(error_message);

    king_orchestrator_replace_runtime_string(
        &run_state->status,
        zend_string_init("cancelled", sizeof("cancelled") - 1, 1)
    );
    run_state->finished_at = time(NULL);
    run_state->cancel_requested = 1;
    if (error_b64 != NULL) {
        king_orchestrator_replace_runtime_string(&run_state->error_b64, zend_string_dup(error_b64, 1));
        zend_string_release(error_b64);
    }
    king_orchestrator_set_last_run(run_state->run_id, "cancelled");

    rc = king_orchestrator_persist_state_locked();
    king_orchestrator_state_lock_release(lock_fd);
    if (rc != SUCCESS) {
        return FAILURE;
    }

    king_orchestrator_clear_run_cancel_request(run_state->run_id);
    return SUCCESS;
}

void king_orchestrator_append_component_info(zval *configuration)
{
    zval registered_tools;
    zend_string *tool_name;
    const char *execution_backend;
    const char *topology_scope = "local_in_process";
    const char *scheduler_policy = "in_process_linear";

    if (configuration == NULL || Z_TYPE_P(configuration) != IS_ARRAY) {
        return;
    }

    execution_backend = king_mcp_orchestrator_config.orchestrator_execution_backend != NULL
        ? king_mcp_orchestrator_config.orchestrator_execution_backend
        : "";
    if (strcmp(execution_backend, "file_worker") == 0) {
        topology_scope = "same_host_file_worker";
        scheduler_policy = "claimed_recovery_then_fifo_run_id";
    } else if (king_orchestrator_backend_is_remote_peer()) {
        topology_scope = "tcp_host_port_execution_peer";
        scheduler_policy = "controller_direct_remote_run";
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
        execution_backend
    );
    add_assoc_string(configuration, "topology_scope", topology_scope);
    add_assoc_string(configuration, "scheduler_policy", scheduler_policy);
    add_assoc_string(configuration, "retry_policy", "single_attempt");
    add_assoc_string(configuration, "idempotency_policy", "caller_managed");
    add_assoc_string(
        configuration,
        "worker_queue_path",
        king_mcp_orchestrator_config.orchestrator_worker_queue_path != NULL
            ? king_mcp_orchestrator_config.orchestrator_worker_queue_path
            : ""
    );
    add_assoc_string(
        configuration,
        "remote_host",
        king_mcp_orchestrator_config.orchestrator_remote_host != NULL
            ? king_mcp_orchestrator_config.orchestrator_remote_host
            : ""
    );
    add_assoc_long(
        configuration,
        "remote_port",
        king_mcp_orchestrator_config.orchestrator_remote_port
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

PHP_FUNCTION(king_pipeline_orchestrator_cancel_run)
{
    zend_string *run_id;

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(run_id)
    ZEND_PARSE_PARAMETERS_END();

    if (ZSTR_LEN(run_id) == 0) {
        king_set_error("king_pipeline_orchestrator_cancel_run() requires a non-empty run id.");
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "king_pipeline_orchestrator_cancel_run() requires a non-empty run id."
        );
        RETURN_THROWS();
    }

    if (!king_orchestrator_backend_is_file_worker()) {
        king_set_error("king_pipeline_orchestrator_cancel_run() requires orchestrator_execution_backend=file_worker.");
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "king_pipeline_orchestrator_cancel_run() requires orchestrator_execution_backend=file_worker."
        );
        RETURN_THROWS();
    }

    RETURN_BOOL(king_orchestrator_request_run_cancel(run_id) == SUCCESS);
}
