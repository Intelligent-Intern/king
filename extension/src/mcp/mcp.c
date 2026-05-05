/*
 * Native MCP runtime for King. Owns the local MCP state, the bounded
 * line-framed remote-peer protocol for request/upload/download operations and
 * the persisted transfer-state helpers that keep transfers resumable across
 * restart and multi-host execution paths.
 */
#include "include/mcp/mcp.h"
#include "include/php_king.h"
#include "include/config/mcp_and_orchestrator/base_layer.h"

#include "Zend/zend_smart_str.h"
#include "Zend/zend_hrtime.h"
#include "ext/standard/base64.h"
#include "main/php_network.h"

#include <arpa/inet.h>
#include <ctype.h>
#include <errno.h>
#include <fcntl.h>
#include <netinet/in.h>
#include <stdarg.h>
#include <string.h>
#include <sys/file.h>
#include <sys/stat.h>
#include <time.h>
#include <unistd.h>

#define KING_MCP_REMOTE_LINE_OVERHEAD 4096
#define KING_MCP_REMOTE_OP_REQUEST "REQ"
#define KING_MCP_REMOTE_OP_UPLOAD "PUT"
#define KING_MCP_REMOTE_OP_DOWNLOAD "GET"
#define KING_MCP_TRANSFER_STATE_VERSION 1


#include "mcp/state_and_validation.inc"
#include "mcp/transfer_state.inc"
#include "mcp/transport_control.inc"
#include "mcp/remote_protocol.inc"
#include "mcp/lifecycle_and_api.inc"
