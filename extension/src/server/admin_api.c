/*
 * =========================================================================
 * FILENAME:   src/server/admin_api.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Keeps the existing admin-listener snapshot contract and adds a bounded
 * one-shot on-wire admin leaf for the current runtime. Callers can still use
 * the function as a pure local state marker, while `accept_timeout_ms > 0`
 * enables one real TCP/TLS+mTLS admin request so auth, reload, and failure
 * reporting can be verified against real clients.
 * =========================================================================
 */

#include "php.h"
#include "php_king.h"
#include "include/client/session.h"
#include "include/config/config.h"
#include "include/config/dynamic_admin_api/base_layer.h"
#include "include/config/security_and_traffic/base_layer.h"
#include "include/server/admin_api.h"
#include "include/server/session.h"
#include "include/server/tls.h"

#include "main/php_network.h"
#include "main/php_streams.h"

#include <arpa/inet.h>
#include <ctype.h>
#include <errno.h>
#include <limits.h>
#include <netdb.h>
#include <poll.h>
#include <stdarg.h>
#include <stdio.h>
#include <string.h>
#include <strings.h>
#include <sys/socket.h>
#include <sys/types.h>
#include <time.h>
#include <unistd.h>

#include "control.inc"

#ifndef KING_SERVER_ADMIN_API_PATH_MAX
# ifdef PATH_MAX
#  define KING_SERVER_ADMIN_API_PATH_MAX PATH_MAX
# else
#  define KING_SERVER_ADMIN_API_PATH_MAX 1024
# endif
#endif

#define KING_SERVER_ADMIN_API_DEFAULT_ACCEPT_TIMEOUT_MS 0L
#define KING_SERVER_ADMIN_API_MAX_ACCEPT_TIMEOUT_MS 10000L
#define KING_SERVER_ADMIN_API_DEFAULT_IO_TIMEOUT_MS 1000L
#define KING_SERVER_ADMIN_API_MAX_REQUEST_HEAD_BYTES 16384
#define KING_SERVER_ADMIN_API_MAX_REQUEST_LINE_BYTES 2048

typedef struct _king_server_admin_api_config {
    const char *bind_host;
    size_t bind_host_len;
    zend_long port;
    const char *auth_mode;
    size_t auth_mode_len;
    const char *ca_file;
    size_t ca_file_len;
    const char *cert_file;
    size_t cert_file_len;
    const char *key_file;
    size_t key_file_len;
    zend_long accept_timeout_ms;
    bool enabled;
} king_server_admin_api_config_t;

typedef enum _king_server_admin_api_route {
    KING_SERVER_ADMIN_API_ROUTE_HEALTH = 1,
    KING_SERVER_ADMIN_API_ROUTE_RELOAD_TLS = 2,
    KING_SERVER_ADMIN_API_ROUTE_UNKNOWN = 3
} king_server_admin_api_route_t;

typedef struct _king_server_admin_api_request {
    king_server_admin_api_route_t route;
    char method[16];
    char path[128];
    char reload_cert_file[KING_SERVER_ADMIN_API_PATH_MAX];
    size_t reload_cert_file_len;
    char reload_key_file[KING_SERVER_ADMIN_API_PATH_MAX];
    size_t reload_key_file_len;
} king_server_admin_api_request_t;

typedef enum _king_server_admin_api_wait_result {
    KING_SERVER_ADMIN_API_WAIT_ERROR = -1,
    KING_SERVER_ADMIN_API_WAIT_TIMEOUT = 0,
    KING_SERVER_ADMIN_API_WAIT_READY = 1
} king_server_admin_api_wait_result_t;


#include "admin_api/config_and_validation.inc"
#include "admin_api/socket_transport.inc"
#include "admin_api/tls_streams.inc"
#include "admin_api/request_routing.inc"
#include "admin_api/public_api.inc"
