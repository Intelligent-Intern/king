/*
 * =========================================================================
 * FILENAME:   src/semantic_dns/state.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Implements the native Semantic-DNS durable state persistence,
 * allowing the control plane to recover topology and runtime data
 * across reboots or process limits.
 * =========================================================================
 */

#include "semantic_dns/semantic_dns_internal.h"
#include <ext/standard/php_var.h>
#include <errno.h>
#include <fcntl.h>
#include <limits.h>
#include <stdio.h>
#include <sys/file.h>
#include <sys/stat.h>
#include <sys/types.h>
#include <unistd.h>
#include <string.h>
#include <zend_smart_str.h>

#define KING_SEMANTIC_DNS_STATE_DIR "/tmp/king_semantic_dns_state"
#define KING_SEMANTIC_DNS_STATE_FILE KING_SEMANTIC_DNS_STATE_DIR "/durable_state.bin"
#define KING_SEMANTIC_DNS_STATE_LOCK_FILE KING_SEMANTIC_DNS_STATE_DIR "/durable_state.bin.lock"
#define KING_SEMANTIC_DNS_STATE_MAGIC 0x53444e53 /* 'SDNS' */
#define KING_SEMANTIC_DNS_STATE_VERSION 1
#define KING_SEMANTIC_DNS_STATE_MAX_MOTHER_NODES 1024U
#define KING_SEMANTIC_DNS_STATE_MAX_PAYLOAD_BYTES (16U * 1024U * 1024U)

static zend_string *king_semantic_dns_state_serialize_zval(zval *value)
{
    smart_str buffer = {0};
    php_serialize_data_t var_hash;

    if (value == NULL) {
        return NULL;
    }

    PHP_VAR_SERIALIZE_INIT(var_hash);
    php_var_serialize(&buffer, value, &var_hash);
    PHP_VAR_SERIALIZE_DESTROY(var_hash);
    smart_str_0(&buffer);

    return buffer.s;
}

static int king_semantic_dns_state_unserialize_zval(
    const unsigned char *buffer,
    size_t buffer_len,
    zval *return_value
)
{
    const unsigned char *cursor = buffer;
    php_unserialize_data_t var_hash;

    if (buffer == NULL || return_value == NULL) {
        return FAILURE;
    }

    ZVAL_NULL(return_value);
    PHP_VAR_UNSERIALIZE_INIT(var_hash);
    if (!php_var_unserialize(
            return_value,
            &cursor,
            buffer + buffer_len,
            &var_hash
        )) {
        PHP_VAR_UNSERIALIZE_DESTROY(var_hash);
        zval_ptr_dtor(return_value);
        ZVAL_NULL(return_value);
        return FAILURE;
    }
    PHP_VAR_UNSERIALIZE_DESTROY(var_hash);

    if (cursor != buffer + buffer_len) {
        zval_ptr_dtor(return_value);
        ZVAL_NULL(return_value);
        return FAILURE;
    }

    return SUCCESS;
}

static int king_semantic_dns_state_dir_is_secure(const struct stat *st)
{
    mode_t perms;

    if (st == NULL || !S_ISDIR(st->st_mode)) {
        return FAILURE;
    }

    if (st->st_uid != geteuid()) {
        return FAILURE;
    }

    perms = st->st_mode & 0777;
    if ((perms & 0077) != 0 || perms != 0700) {
        return FAILURE;
    }

    return SUCCESS;
}

static int king_semantic_dns_state_ensure_directory(void)
{
    struct stat st;

    if (php_check_open_basedir(KING_SEMANTIC_DNS_STATE_DIR) != 0) {
        return FAILURE;
    }

    if (mkdir(KING_SEMANTIC_DNS_STATE_DIR, 0700) != 0 && errno != EEXIST) {
        return FAILURE;
    }

    if (lstat(KING_SEMANTIC_DNS_STATE_DIR, &st) != 0) {
        return FAILURE;
    }

    return king_semantic_dns_state_dir_is_secure(&st);
}

static int king_semantic_dns_state_path_is_regular_file(void)
{
    struct stat state_stat;

    if (lstat(KING_SEMANTIC_DNS_STATE_FILE, &state_stat) != 0) {
        return 0;
    }

    return S_ISREG(state_stat.st_mode) ? 1 : 0;
}

static int king_semantic_dns_state_lock_acquire(int *lock_fd_out)
{
    int flags = O_RDWR | O_CREAT;
    int fd;
    struct stat lock_stat;

    if (lock_fd_out == NULL) {
        return FAILURE;
    }

    *lock_fd_out = -1;

    if (king_semantic_dns_state_ensure_directory() != SUCCESS) {
        return FAILURE;
    }

    if (php_check_open_basedir(KING_SEMANTIC_DNS_STATE_LOCK_FILE) != 0) {
        return FAILURE;
    }

#ifdef O_CLOEXEC
    flags |= O_CLOEXEC;
#endif
#ifdef O_NOFOLLOW
    flags |= O_NOFOLLOW;
#endif

    fd = open(KING_SEMANTIC_DNS_STATE_LOCK_FILE, flags, 0600);
    if (fd < 0) {
        return FAILURE;
    }

    if (fstat(fd, &lock_stat) != 0 || !S_ISREG(lock_stat.st_mode)) {
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

static void king_semantic_dns_state_lock_release(int lock_fd)
{
    if (lock_fd < 0) {
        return;
    }

    (void) flock(lock_fd, LOCK_UN);
    close(lock_fd);
}

static int king_semantic_dns_state_refresh_locked(void)
{
    if (!king_semantic_dns_state_path_is_regular_file()) {
        return SUCCESS;
    }

    return king_semantic_dns_state_load();
}

int king_semantic_dns_state_has_regular_snapshot(void)
{
    return king_semantic_dns_state_path_is_regular_file();
}

int king_semantic_dns_state_transaction_begin(int *lock_fd_out)
{
    if (lock_fd_out == NULL || !king_semantic_dns_runtime.initialized) {
        return FAILURE;
    }

    *lock_fd_out = -1;

    if (king_semantic_dns_state_lock_acquire(lock_fd_out) != SUCCESS) {
        return FAILURE;
    }

    if (king_semantic_dns_state_refresh_locked() != SUCCESS) {
        king_semantic_dns_state_lock_release(*lock_fd_out);
        *lock_fd_out = -1;
        return FAILURE;
    }

    return SUCCESS;
}

void king_semantic_dns_state_transaction_end(int lock_fd)
{
    king_semantic_dns_state_lock_release(lock_fd);
}

int king_semantic_dns_state_persist_locked(void)
{
    return king_semantic_dns_state_save();
}

int king_semantic_dns_state_save(void)
{
    FILE *fp = NULL;
    int fd;
    char tmp_template[PATH_MAX];
    uint32_t magic = KING_SEMANTIC_DNS_STATE_MAGIC;
    uint32_t version = KING_SEMANTIC_DNS_STATE_VERSION;
    uint32_t payload_len = 0;
    zval state_payload;
    zend_string *serialized_payload = NULL;

    ZVAL_UNDEF(&state_payload);

    if (!king_semantic_dns_runtime.initialized) {
        return FAILURE;
    }

    if (king_semantic_dns_state_ensure_directory() != SUCCESS) {
        return FAILURE;
    }

    if (php_check_open_basedir(KING_SEMANTIC_DNS_STATE_FILE) != 0) {
        return FAILURE;
    }

    if (snprintf(tmp_template, sizeof(tmp_template), "%s/.state.XXXXXX", KING_SEMANTIC_DNS_STATE_DIR) >= (int) sizeof(tmp_template)) {
        return FAILURE;
    }

    if (php_check_open_basedir(tmp_template) != 0) {
        return FAILURE;
    }

    fd = mkstemp(tmp_template);
    if (fd < 0) {
        return FAILURE;
    }

    if (fchmod(fd, 0600) != 0) {
        close(fd);
        unlink(tmp_template);
        return FAILURE;
    }

    fp = fdopen(fd, "wb");
    if (fp == NULL) {
        close(fd);
        unlink(tmp_template);
        return FAILURE;
    }

    if (fwrite(&magic, sizeof(magic), 1, fp) != 1 || fwrite(&version, sizeof(version), 1, fp) != 1) {
        fclose(fp);
        unlink(tmp_template);
        return FAILURE;
    }

    /* Write the native mother_nodes list */
    if (fwrite(&king_semantic_dns_runtime.config.mother_node_count, sizeof(uint32_t), 1, fp) != 1) {
        fclose(fp);
        unlink(tmp_template);
        return FAILURE;
    }
    if (
        king_semantic_dns_runtime.config.mother_node_count > 0
        && king_semantic_dns_runtime.config.mother_nodes != NULL
        && fwrite(
            king_semantic_dns_runtime.config.mother_nodes,
            sizeof(king_mother_node_t),
            king_semantic_dns_runtime.config.mother_node_count,
            fp
        ) != king_semantic_dns_runtime.config.mother_node_count
    ) {
        fclose(fp);
        unlink(tmp_template);
        return FAILURE;
    }

    /* Persist scalar runtime fields after the topology snapshot. */
    if (
        fwrite(&king_semantic_dns_runtime.last_discovered_node_count, sizeof(zend_long), 1, fp) != 1
        || fwrite(&king_semantic_dns_runtime.last_synced_node_count, sizeof(zend_long), 1, fp) != 1
    ) {
        fclose(fp);
        unlink(tmp_template);
        return FAILURE;
    }

    if (king_semantic_dns_export_state_payload(&state_payload) != SUCCESS) {
        fclose(fp);
        unlink(tmp_template);
        return FAILURE;
    }

    serialized_payload = king_semantic_dns_state_serialize_zval(&state_payload);
    zval_ptr_dtor(&state_payload);
    if (
        serialized_payload == NULL
        || ZSTR_LEN(serialized_payload) > KING_SEMANTIC_DNS_STATE_MAX_PAYLOAD_BYTES
    ) {
        if (serialized_payload != NULL) {
            zend_string_release(serialized_payload);
        }
        fclose(fp);
        unlink(tmp_template);
        return FAILURE;
    }

    payload_len = (uint32_t) ZSTR_LEN(serialized_payload);
    if (
        fwrite(&payload_len, sizeof(payload_len), 1, fp) != 1
        || (payload_len > 0 && fwrite(ZSTR_VAL(serialized_payload), 1, payload_len, fp) != payload_len)
    ) {
        zend_string_release(serialized_payload);
        fclose(fp);
        unlink(tmp_template);
        return FAILURE;
    }
    zend_string_release(serialized_payload);

    if (fclose(fp) != 0) {
        unlink(tmp_template);
        return FAILURE;
    }

    if (rename(tmp_template, KING_SEMANTIC_DNS_STATE_FILE) != 0) {
        unlink(tmp_template);
        return FAILURE;
    }

    return SUCCESS;
}

int king_semantic_dns_state_load(void)
{
    FILE *fp = NULL;
    int fd;
    struct stat state_stat;
    king_mother_node_t *loaded_mother_nodes = NULL;
    uint32_t loaded_mother_node_count = 0;
    zend_long loaded_last_discovered = 0;
    zend_long loaded_last_synced = 0;
    uint32_t magic, version, node_count;
    uint32_t payload_len = 0;
    unsigned char *payload_buffer = NULL;
    zval state_payload;
    zend_bool has_state_payload = 0;

    ZVAL_UNDEF(&state_payload);

    if (!king_semantic_dns_runtime.initialized) {
        return FAILURE;
    }

    if (king_semantic_dns_state_ensure_directory() != SUCCESS) {
        return FAILURE;
    }

    fd = open(
        KING_SEMANTIC_DNS_STATE_FILE,
        O_RDONLY
#ifdef O_NOFOLLOW
        | O_NOFOLLOW
#endif
    );
    if (fd < 0) {
        return FAILURE; /* expected on first run */
    }

    if (fstat(fd, &state_stat) != 0 || !S_ISREG(state_stat.st_mode)) {
        close(fd);
        return FAILURE;
    }

    fp = fdopen(fd, "rb");
    if (fp == NULL) {
        close(fd);
        return FAILURE;
    }

    if (fread(&magic, sizeof(magic), 1, fp) != 1 || magic != KING_SEMANTIC_DNS_STATE_MAGIC) {
        fclose(fp);
        return FAILURE; /* expected on first run */
    }

    if (fread(&version, sizeof(version), 1, fp) != 1 || version != KING_SEMANTIC_DNS_STATE_VERSION) {
        fclose(fp);
        return FAILURE;
    }

    /* Restore mother nodes topology */
    if (fread(&node_count, sizeof(uint32_t), 1, fp) == 1) {
        if (node_count > 0) {
            if (node_count > KING_SEMANTIC_DNS_STATE_MAX_MOTHER_NODES) {
                fclose(fp);
                return FAILURE;
            }

            loaded_mother_nodes = pecalloc(node_count, sizeof(king_mother_node_t), 1);
            if (loaded_mother_nodes == NULL) {
                fclose(fp);
                return FAILURE;
            }

            if (fread(loaded_mother_nodes, sizeof(king_mother_node_t), node_count, fp) != node_count) {
                pefree(loaded_mother_nodes, 1);
                fclose(fp);
                return FAILURE;
            }

            loaded_mother_node_count = node_count;
        }
    } else {
        fclose(fp);
        return FAILURE;
    }

    if (
        fread(&loaded_last_discovered, sizeof(zend_long), 1, fp) != 1
        || fread(&loaded_last_synced, sizeof(zend_long), 1, fp) != 1
    ) {
        if (loaded_mother_nodes != NULL) {
            pefree(loaded_mother_nodes, 1);
        }
        fclose(fp);
        return FAILURE;
    }

    if (fread(&payload_len, sizeof(payload_len), 1, fp) == 1) {
        if (payload_len > KING_SEMANTIC_DNS_STATE_MAX_PAYLOAD_BYTES) {
            if (loaded_mother_nodes != NULL) {
                pefree(loaded_mother_nodes, 1);
            }
            fclose(fp);
            return FAILURE;
        }

        if (payload_len > 0) {
            payload_buffer = emalloc(payload_len);
            if (fread(payload_buffer, 1, payload_len, fp) != payload_len) {
                efree(payload_buffer);
                if (loaded_mother_nodes != NULL) {
                    pefree(loaded_mother_nodes, 1);
                }
                fclose(fp);
                return FAILURE;
            }

            if (king_semantic_dns_state_unserialize_zval(payload_buffer, payload_len, &state_payload) != SUCCESS) {
                efree(payload_buffer);
                if (loaded_mother_nodes != NULL) {
                    pefree(loaded_mother_nodes, 1);
                }
                fclose(fp);
                return FAILURE;
            }

            efree(payload_buffer);
            has_state_payload = 1;
        }
    } else if (!feof(fp)) {
        if (loaded_mother_nodes != NULL) {
            pefree(loaded_mother_nodes, 1);
        }
        fclose(fp);
        return FAILURE;
    }

    fclose(fp);

    if (king_semantic_dns_runtime.config.mother_nodes != NULL) {
        pefree(king_semantic_dns_runtime.config.mother_nodes, 1);
    }

    king_semantic_dns_runtime.config.mother_nodes = loaded_mother_nodes;
    king_semantic_dns_runtime.config.mother_node_count = loaded_mother_node_count;
    king_semantic_dns_runtime.last_discovered_node_count = loaded_last_discovered;
    king_semantic_dns_runtime.last_synced_node_count = loaded_last_synced;

    if (has_state_payload) {
        if (king_semantic_dns_import_state_payload(&state_payload) != SUCCESS) {
            zval_ptr_dtor(&state_payload);
            return FAILURE;
        }
        zval_ptr_dtor(&state_payload);
    }

    return SUCCESS;
}
