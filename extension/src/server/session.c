/*
 * =========================================================================
 * FILENAME:   src/server/session.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Activates the first server-side session slice on top of the unified
 * King\Session runtime. This materializes a process/thread-bound capability
 * guard, local peer-certificate access, and a server-initiated close path
 * without forking a second competing session resource model.
 * =========================================================================
 */

#include "php.h"
#include "php_king.h"
#include "include/server/session.h"
#include "zend_exceptions.h"

#include <fcntl.h>
#include <stdint.h>
#include <string.h>
#include <time.h>
#include <unistd.h>

static uint64_t king_server_session_mix64(uint64_t value)
{
    value ^= value >> 33;
    value *= 0xff51afd7ed558ccdULL;
    value ^= value >> 33;
    value *= 0xc4ceb9fe1a85ec53ULL;
    value ^= value >> 33;
    return value;
}

static zend_result king_server_session_fill_random_bytes(uint8_t *target, size_t target_len)
{
    int fd;
    ssize_t got;

    fd = open("/dev/urandom", O_RDONLY | O_CLOEXEC);
    if (fd < 0) {
        return FAILURE;
    }

    got = read(fd, target, target_len);
    close(fd);

    return got == (ssize_t) target_len ? SUCCESS : FAILURE;
}

static uint64_t king_server_session_fallback_nonce(const king_client_session_t *session)
{
    uint64_t now = (uint64_t) time(NULL);
    uint64_t pid = (uint64_t) getpid();
    uint64_t tid = king_server_session_current_tid();
    uint64_t ptr = (uint64_t) (uintptr_t) session;

    return king_server_session_mix64(now ^ (pid << 32) ^ tid ^ ptr);
}

static void king_server_session_set_string_bytes(
    zend_string **slot,
    const char *value,
    size_t value_len
)
{
    if (*slot != NULL) {
        zend_string_release(*slot);
    }

    if (value == NULL) {
        *slot = NULL;
        return;
    }

    *slot = zend_string_init(value, value_len, 0);
}

static void king_server_session_throw_guard_failure(void)
{
    const char *message =
        "Session guard check failed (cross-process/thread or stale capability).";

    king_set_error(message);
    zend_throw_exception_ex(
        king_ce_runtime_exception != NULL ? king_ce_runtime_exception : zend_ce_exception,
        0,
        "%s",
        message
    );
}

void king_server_session_prepare(king_client_session_t *session)
{
    if (session == NULL) {
        return;
    }

    session->server_owner_pid = (zend_long) getpid();
    session->server_owner_tid = king_server_session_current_tid();
    session->server_close_initiated = false;
    session->server_close_error_code = 0;

    if (king_server_session_fill_random_bytes(
            (uint8_t *) &session->server_cap_nonce,
            sizeof(session->server_cap_nonce)
        ) != SUCCESS) {
        session->server_cap_nonce = king_server_session_fallback_nonce(session);
    }

    king_server_session_set_close_reason(session, "", 0);
    king_server_session_set_peer_cert_subject(session, NULL, 0);
}

zend_long king_server_session_capability(const king_client_session_t *session)
{
    uint64_t mixed;
    zend_long capability;

    if (session == NULL) {
        return 0;
    }

    mixed = king_server_session_mix64(
        session->server_cap_nonce
        ^ ((uint64_t) (uint32_t) session->server_owner_pid << 32)
        ^ session->server_owner_tid
    );

    capability = (zend_long) (mixed & 0x7fffffffffffffffULL);
    return capability > 0 ? capability : 0x4b494e47L;
}

bool king_server_session_capability_is_valid(
    const king_client_session_t *session,
    zend_long capability
)
{
    if (session == NULL || capability <= 0) {
        return false;
    }

    if (session->server_owner_pid != (zend_long) getpid()) {
        return false;
    }

    if (session->server_owner_tid != king_server_session_current_tid()) {
        return false;
    }

    return capability == king_server_session_capability(session);
}

void king_server_session_rotate_capability(king_client_session_t *session)
{
    if (session == NULL) {
        return;
    }

    if (king_server_session_fill_random_bytes(
            (uint8_t *) &session->server_cap_nonce,
            sizeof(session->server_cap_nonce)
        ) != SUCCESS) {
        session->server_cap_nonce = king_server_session_fallback_nonce(session);
    }
}

void king_server_session_set_peer_cert_subject(
    king_client_session_t *session,
    const char *subject,
    size_t subject_len
)
{
    if (session == NULL) {
        return;
    }

    king_server_session_set_string_bytes(
        &session->server_peer_cert_subject,
        subject,
        subject != NULL ? subject_len : 0
    );
}

void king_server_session_set_close_reason(
    king_client_session_t *session,
    const char *reason,
    size_t reason_len
)
{
    if (session == NULL) {
        return;
    }

    king_server_session_set_string_bytes(
        &session->server_close_reason,
        reason != NULL ? reason : "",
        reason != NULL ? reason_len : 0
    );
}

PHP_FUNCTION(king_session_get_peer_cert_subject)
{
    zval *zsession;
    zend_long capability;
    king_client_session_t *session;

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_ZVAL(zsession)
        Z_PARAM_LONG(capability)
    ZEND_PARSE_PARAMETERS_END();

    session = king_client_session_fetch_resource(zsession, 1);
    if (session == NULL) {
        RETURN_THROWS();
    }

    if (!king_server_session_capability_is_valid(session, capability)) {
        king_server_session_throw_guard_failure();
        RETURN_THROWS();
    }

    king_set_error("");

    if (session->server_peer_cert_subject == NULL) {
        RETURN_NULL();
    }

    RETURN_STR_COPY(session->server_peer_cert_subject);
}

PHP_FUNCTION(king_session_close_server_initiated)
{
    zval *zsession;
    zend_long capability;
    zend_long error_code = 0;
    char *reason = NULL;
    size_t reason_len = 0;
    king_client_session_t *session;

    ZEND_PARSE_PARAMETERS_START(2, 4)
        Z_PARAM_ZVAL(zsession)
        Z_PARAM_LONG(capability)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(error_code)
        Z_PARAM_STRING(reason, reason_len)
    ZEND_PARSE_PARAMETERS_END();

    if (error_code < 0) {
        zend_argument_value_error(3, "must be greater than or equal to 0");
        RETURN_THROWS();
    }

    session = king_client_session_fetch_resource(zsession, 1);
    if (session == NULL) {
        RETURN_THROWS();
    }

    if (!king_server_session_capability_is_valid(session, capability)) {
        king_server_session_throw_guard_failure();
        RETURN_THROWS();
    }

    if (session->is_closed) {
        king_set_error("");
        RETURN_FALSE;
    }

    king_client_session_close_socket(session);
    session->is_closed = true;
    session->last_activity_at = time(NULL);
    session->server_close_initiated = true;
    session->server_close_error_code = error_code;
    king_server_session_set_close_reason(session, reason, reason_len);
    king_server_session_rotate_capability(session);

    king_set_error("");
    RETURN_TRUE;
}
