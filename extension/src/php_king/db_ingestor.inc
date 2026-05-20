/*
 * Host-local database ingestion primitive.
 *
 * king_db_ingest() serializes a userland writer callback across PHP worker
 * processes by acquiring a kernel-backed advisory lock before executing it.
 * This gives SQLite and other single-writer stores a King-owned ingestion lane
 * without forcing every application to invent its own writer service.
 */

#include <errno.h>
#include <fcntl.h>
#include <stdbool.h>
#include <stdio.h>
#include <string.h>
#include <sys/file.h>
#include <sys/stat.h>
#include <unistd.h>
#include "Zend/zend_smart_str.h"

static zend_long king_db_ingestor_option_long(HashTable *options, const char *key, zend_long fallback)
{
    zval *value = options != NULL ? zend_hash_str_find(options, key, strlen(key)) : NULL;
    if (value == NULL || Z_TYPE_P(value) == IS_NULL) {
        return fallback;
    }

    zval copy;
    ZVAL_COPY(&copy, value);
    convert_to_long(&copy);
    zend_long result = Z_LVAL(copy);
    zval_ptr_dtor(&copy);
    return result;
}

static zend_string *king_db_ingestor_option_string(HashTable *options, const char *key)
{
    zval *value = options != NULL ? zend_hash_str_find(options, key, strlen(key)) : NULL;
    if (value == NULL || Z_TYPE_P(value) == IS_NULL) {
        return NULL;
    }

    return zval_get_string(value);
}

static zend_string *king_db_ingestor_default_lock_path(zend_string *name)
{
    const char *tmpdir = getenv("TMPDIR");
    if (tmpdir == NULL || tmpdir[0] == '\0') {
        tmpdir = "/tmp";
    }

    smart_str path = {0};
    smart_str_appends(&path, tmpdir);
    if (path.s == NULL || ZSTR_VAL(path.s)[ZSTR_LEN(path.s) - 1] != '/') {
        smart_str_appendc(&path, '/');
    }
    smart_str_appends(&path, "king-db-ingestor-");

    const char *raw = ZSTR_VAL(name);
    size_t raw_len = ZSTR_LEN(name);
    for (size_t i = 0; i < raw_len; i++) {
        unsigned char ch = (unsigned char) raw[i];
        if ((ch >= 'A' && ch <= 'Z')
            || (ch >= 'a' && ch <= 'z')
            || (ch >= '0' && ch <= '9')
            || ch == '.'
            || ch == '_'
            || ch == '-') {
            smart_str_appendc(&path, (char) ch);
        } else {
            smart_str_appendc(&path, '_');
        }
    }

    smart_str_appends(&path, ".lock");
    smart_str_0(&path);
    return path.s;
}

static zend_string *king_db_ingestor_lock_path(zend_string *name, HashTable *options)
{
    zend_string *configured = king_db_ingestor_option_string(options, "lock_path");
    if (configured != NULL) {
        if (ZSTR_LEN(configured) > 0) {
            return configured;
        }
        zend_string_release(configured);
    }

    return king_db_ingestor_default_lock_path(name);
}

static bool king_db_ingestor_acquire_lock(int fd, zend_long timeout_ms, zend_long poll_us)
{
    if (timeout_ms < 0) {
        timeout_ms = 0;
    }
    if (poll_us < 1000) {
        poll_us = 1000;
    }

    zend_long max_attempts = timeout_ms == 0 ? 1 : ((timeout_ms * 1000) / poll_us) + 1;
    if (max_attempts < 1) {
        max_attempts = 1;
    }

    for (zend_long attempt = 0; attempt < max_attempts; attempt++) {
        if (flock(fd, LOCK_EX | LOCK_NB) == 0) {
            return true;
        }

        if (errno != EWOULDBLOCK && errno != EAGAIN && errno != EINTR) {
            return false;
        }

        if (attempt + 1 < max_attempts) {
            usleep((useconds_t) poll_us);
        }
    }

    errno = ETIMEDOUT;
    return false;
}

PHP_FUNCTION(king_db_ingest)
{
    zend_string *name = NULL;
    zend_fcall_info fci;
    zend_fcall_info_cache fci_cache;
    HashTable *options = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STR(name)
        Z_PARAM_FUNC(fci, fci_cache)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_HT_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (ZSTR_LEN(name) == 0) {
        zend_argument_value_error(1, "must not be empty");
        RETURN_THROWS();
    }

    zend_string *lock_path = king_db_ingestor_lock_path(name, options);
    if (lock_path == NULL || ZSTR_LEN(lock_path) == 0) {
        if (lock_path != NULL) {
            zend_string_release(lock_path);
        }
        zend_throw_exception(king_ce_runtime_exception, "king_db_ingest could not resolve a lock path", 0);
        RETURN_THROWS();
    }

    zend_long timeout_ms = king_db_ingestor_option_long(options, "timeout_ms", 15000);
    zend_long poll_us = king_db_ingestor_option_long(options, "poll_us", 5000);

    int fd = open(ZSTR_VAL(lock_path), O_CREAT | O_RDWR | O_CLOEXEC, 0600);
    if (fd < 0) {
        char message[512];
        snprintf(message, sizeof(message), "king_db_ingest could not open lock file '%s': %s", ZSTR_VAL(lock_path), strerror(errno));
        king_set_error(message);
        zend_string_release(lock_path);
        zend_throw_exception(king_ce_runtime_exception, message, errno);
        RETURN_THROWS();
    }

    if (!king_db_ingestor_acquire_lock(fd, timeout_ms, poll_us)) {
        char message[512];
        snprintf(message, sizeof(message), "king_db_ingest timed out waiting for lock '%s': %s", ZSTR_VAL(lock_path), strerror(errno));
        king_set_error(message);
        close(fd);
        zend_string_release(lock_path);
        zend_throw_exception(king_ce_timeout_exception, message, errno);
        RETURN_THROWS();
    }

    zval callback_result;
    ZVAL_UNDEF(&callback_result);
    fci.retval = &callback_result;
    fci.params = NULL;
    fci.param_count = 0;
    zend_result call_result = zend_call_function(&fci, &fci_cache);

    flock(fd, LOCK_UN);
    close(fd);
    zend_string_release(lock_path);

    if (call_result != SUCCESS) {
        if (!EG(exception)) {
            zend_throw_exception(king_ce_runtime_exception, "king_db_ingest writer callback failed", 0);
        }
        RETURN_THROWS();
    }

    if (EG(exception)) {
        if (!Z_ISUNDEF(callback_result)) {
            zval_ptr_dtor(&callback_result);
        }
        RETURN_THROWS();
    }

    RETURN_ZVAL(&callback_result, 1, 1);
}
