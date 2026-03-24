/*
 * =========================================================================
 * FILENAME:   src/client/session.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Native runtime client-session runtime. This owns the public
 * King\Session resource lifecycle and config-backed local state while the
 * live QUIC/TLS transport is still being transferred into the build.
 * =========================================================================
 */

#include "php.h"
#include "php_king.h"
#include "include/client/session.h"
#include "include/client/http1.h"
#include "include/server/session.h"
#include "include/config/config.h"

#include <arpa/inet.h>
#include <errno.h>
#include <fcntl.h>
#include <netdb.h>
#include <poll.h>
#include <stdarg.h>
#include <stdlib.h>
#include <stdio.h>
#include <sys/socket.h>
#include <sys/types.h>
#include <time.h>
#include <unistd.h>
#include <zend_hash.h>
#include <zend_exceptions.h>

#include "session/common.inc"
#include "session/transport.inc"
#include "session/state.inc"
#include "session/api.inc"
#include "session/object.inc"
