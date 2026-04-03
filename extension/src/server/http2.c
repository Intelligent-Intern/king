/*
 * =========================================================================
 * FILENAME:   src/server/http2.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Keeps the original local HTTP/2 listener leaf and adds a narrow one-shot
 * on-wire h2c listener slice so v1 can verify a real network-backed HTTP/2
 * request/response flow without pretending the full long-lived server stack
 * is finished yet.
 * =========================================================================
 */

#include "php.h"
#include "php_king.h"
#include "include/client/session.h"
#include "include/config/config.h"
#include "include/server/http2.h"
#include "include/server/session.h"

#include "Zend/zend_smart_str.h"

#include <arpa/inet.h>
#include <errno.h>
#include <netdb.h>
#include <poll.h>
#include <stdint.h>
#include <stdarg.h>
#include <stdio.h>
#include <string.h>
#include <sys/socket.h>
#include <sys/types.h>
#include <time.h>
#include <unistd.h>

#include "local_listener.inc"

#define KING_SERVER_HTTP2_CLIENT_PREFACE "PRI * HTTP/2.0\r\n\r\nSM\r\n\r\n"
#define KING_SERVER_HTTP2_CLIENT_PREFACE_LEN 24
#define KING_SERVER_HTTP2_MAX_FRAME_PAYLOAD_BYTES 1048576
#define KING_SERVER_HTTP2_MAX_REQUEST_BODY_BYTES 1048576

#define KING_SERVER_HTTP2_FRAME_DATA 0x0
#define KING_SERVER_HTTP2_FRAME_HEADERS 0x1
#define KING_SERVER_HTTP2_FRAME_SETTINGS 0x4
#define KING_SERVER_HTTP2_FRAME_PING 0x6
#define KING_SERVER_HTTP2_FRAME_GOAWAY 0x7

#define KING_SERVER_HTTP2_FLAG_ACK 0x1
#define KING_SERVER_HTTP2_FLAG_END_STREAM 0x1
#define KING_SERVER_HTTP2_FLAG_END_HEADERS 0x4
#define KING_SERVER_HTTP2_FLAG_PADDED 0x8
#define KING_SERVER_HTTP2_FLAG_PRIORITY 0x20

typedef struct _king_server_http2_request_state {
    zval headers;
    smart_str body;
    zend_string *method;
    zend_string *path;
    zend_string *scheme;
    zend_string *authority;
    size_t body_bytes;
    uint32_t stream_id;
    zend_bool headers_initialized;
} king_server_http2_request_state;

#include "http2/request_transport.inc"
#include "http2/hpack_codec.inc"
#include "http2/wire_frames.inc"
#include "http2/request_response_flow.inc"
#include "http2/public_api.inc"
