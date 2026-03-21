#ifndef KING_SERVER_SESSION_H
#define KING_SERVER_SESSION_H

#include <php.h>
#include <stdint.h>
#include <stdbool.h>
#include <unistd.h>

#include "include/client/session.h"


#ifndef __has_include
#  define __has_include(x) 0
#endif

#if __has_include(<sys/syscall.h>)
#  include <sys/syscall.h>
#  ifndef SYS_gettid
#    if defined(__NR_gettid)
#      define SYS_gettid __NR_gettid
#    endif
#  endif
#endif

/* Best-effort gettid() fallback for the server-session guard. */
static inline uint64_t king_server_session_current_tid(void) {
#if defined(SYS_gettid)
    return (uint64_t)syscall(SYS_gettid);
#elif defined(__APPLE__)
    uint64_t tid64 = 0;
    pthread_threadid_np(NULL, &tid64);
    return tid64;
#else
    return (uint64_t)getpid();
#endif
}

void king_server_session_prepare(king_client_session_t *session);
zend_long king_server_session_capability(const king_client_session_t *session);
bool king_server_session_capability_is_valid(
    const king_client_session_t *session,
    zend_long capability
);
void king_server_session_rotate_capability(king_client_session_t *session);
void king_server_session_set_peer_cert_subject(
    king_client_session_t *session,
    const char *subject,
    size_t subject_len
);
void king_server_session_set_close_reason(
    king_client_session_t *session,
    const char *reason,
    size_t reason_len
);

/**
 * king_session_get_peer_cert_subject(resource|King\Session $session, int $cap) : ?string
 *
 * Returns the current peer certificate subject captured on the unified
 * `King\Session` runtime, or null if no peer certificate is attached.
 */
PHP_FUNCTION(king_session_get_peer_cert_subject);

/**
 * king_session_close_server_initiated(
 *     resource|King\Session $session,
 *     int      $cap,
 *     int      $errorCode = 0,
 *     string   $reason    = ""
 * ) : bool
 *
 * Records a server-initiated close on the unified `King\Session` runtime,
 * closes the active transport socket if one is open, and rotates the session
 * capability on success.
 */
PHP_FUNCTION(king_session_close_server_initiated);

#endif /* KING_SERVER_SESSION_H */
