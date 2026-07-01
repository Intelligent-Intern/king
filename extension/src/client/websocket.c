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
#include "client/websocket.h"
#include "client/websocket_arginfo.h"
#include "config/config.h"
#include "config/app_http3_websockets_webtransport/base_layer.h"

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

#include "websocket/state_and_queue.inc"
#include "websocket/transport_io.inc"
#include "websocket/frame_receive.inc"
#include "websocket/handshake.inc"
#include "websocket/config_and_state.inc"
#include "websocket/api.inc"
