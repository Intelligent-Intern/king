/*
 * =========================================================================
 * FILENAME:   src/client/websocket.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Active local WebSocket client/runtime surface. This file owns the
 * procedural and OO connect/send/receive/ping/close APIs, real client-side
 * handshake and frame I/O over PHP streams, and the bounded local message
 * queue used by both live client sockets and server-upgrade-backed resources.
 * =========================================================================
 */

#include "php.h"
#include "php_king.h"
#include "include/client/websocket.h"
#include "include/config/config.h"
#include "include/config/app_http3_websockets_webtransport/base_layer.h"

#include "Zend/zend_smart_str.h"
#include "ext/standard/base64.h"
#include "ext/standard/sha1.h"
#include "zend_exceptions.h"
#include "ext/standard/url.h"

#include <stdint.h>
#include <stdarg.h>
#include <stdio.h>
#include <string.h>
#include <strings.h>
#include <time.h>

#define KING_WS_PING_MAX_PAYLOAD_LEN 125
#define KING_WS_CLOSE_REASON_MAX_LEN 123
#define KING_WS_HTTP_LINE_MAX 4096
#define KING_WS_OPCODE_CONTINUATION 0x0
#define KING_WS_OPCODE_TEXT 0x1
#define KING_WS_OPCODE_BINARY 0x2
#define KING_WS_OPCODE_CLOSE 0x8
#define KING_WS_OPCODE_PING 0x9
#define KING_WS_OPCODE_PONG 0xA

static const char *king_websocket_accept_magic =
    "258EAFA5-E914-47DA-95CA-C5AB0DC85B11";

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_King_WebSocket_Connection___construct, 0, 0, 1)
    ZEND_ARG_TYPE_INFO(0, url, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, headers, IS_ARRAY, 1, "null")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_ARRAY, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Connection_send, 0, 1, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, message, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Connection_sendBinary, 0, 1, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO(0, payload, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Connection_receive, 0, 0, IS_STRING, 1)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, timeout_ms, IS_LONG, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Connection_ping, 0, 0, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, data, IS_STRING, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Connection_close, 0, 0, IS_VOID, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, code, IS_LONG, 0, "1000")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, reason, IS_STRING, 1, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_King_WebSocket_Connection_getInfo, 0, 0, IS_ARRAY, 0)
ZEND_END_ARG_INFO()


#include "websocket/state_and_queue.inc"
#include "websocket/transport_io.inc"
#include "websocket/frame_receive.inc"
#include "websocket/handshake.inc"
#include "websocket/config_and_state.inc"
#include "websocket/api.inc"
