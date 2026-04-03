/*
 * =========================================================================
 * FILENAME:   src/server/http1.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Keeps the original local HTTP/1 listener leaf and adds a narrow one-shot
 * on-wire HTTP/1 listener slice so v1 can verify real request parsing,
 * header normalization, CORS behavior, and server-side websocket upgrades
 * without pretending the whole listener stack is long-lived yet.
 * =========================================================================
 */

#include "php.h"
#include "php_king.h"
#include "include/client/session.h"
#include "include/client/websocket.h"
#include "include/config/config.h"
#include "include/server/http1.h"
#include "include/server/session.h"
#include "include/server/websocket.h"

#include "Zend/zend_smart_str.h"

#include <arpa/inet.h>
#include <ctype.h>
#include <errno.h>
#include <netdb.h>
#include <poll.h>
#include <stdarg.h>
#include <stdio.h>
#include <string.h>
#include <sys/socket.h>
#include <sys/types.h>
#include <time.h>
#include <unistd.h>

#include "local_listener.inc"

#define KING_SERVER_HTTP1_MAX_REQUEST_HEAD_BYTES 32768
#define KING_SERVER_HTTP1_MAX_REQUEST_BODY_BYTES 1048576
#define KING_SERVER_HTTP1_DEFAULT_TIMEOUT_MS 5000L


#include "http1/request_transport.inc"
#include "http1/socket_io.inc"
#include "http1/request_parsing.inc"
#include "http1/public_api.inc"
#include "http1/websocket_server_object.inc"
