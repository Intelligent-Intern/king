/*
 * =========================================================================
 * FILENAME:   src/client/http2.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Minimal live HTTP/2 client runtime for the active skeleton build. The
 * transport path stays honest about current limits: clear-text h2c over
 * absolute `http://` URLs and TLS-backed HTTPS/ALPN over absolute `https://`
 * URLs are active, while HTTP/3 remains a separate work item. The
 * implementation keeps the default build free of a hard libcurl link by
 * loading libcurl dynamically at runtime and anchoring per-origin HTTP/2
 * session pools on libcurl multi handles.
 * =========================================================================
 */

#include "php.h"
#include "php_king.h"
#include "include/client/http2.h"
#include "include/config/config.h"
#include "include/config/http2/base_layer.h"
#include "include/config/tcp_transport/base_layer.h"

#include "Zend/zend_smart_str.h"
#include "ext/standard/url.h"

#include <curl/curl.h>
#include <ctype.h>
#include <dlfcn.h>
#include <stdarg.h>
#include <stdbool.h>
#include <stdio.h>
#include <string.h>
#include <strings.h>
#include <time.h>
#include <zend_exceptions.h>

#define KING_HTTP2_DEFAULT_TIMEOUT_MS 15000L
#define KING_HTTP2_MULTI_WAIT_TIMEOUT_MS 100L
#define KING_HTTP2_MAX_RESPONSE_BYTES (8 * 1024 * 1024)
#define KING_HTTP2_MAX_HEADER_BYTES   (128 * 1024)

typedef struct _king_http2_request_options {
    king_cfg_t *config;
    zend_long connect_timeout_ms;
    zend_long timeout_ms;
    bool tcp_enable;
    bool tcp_keepalive_enable;
    bool tcp_nodelay_enable;
    bool http2_enable;
    bool http2_enable_push;
    bool capture_push;
    zend_long http2_max_concurrent_streams;
    bool tls_verify_peer;
    const char *tls_default_ca_file;
    const char *tls_default_cert_file;
    const char *tls_default_key_file;
    zval *cancel_token;
    const char *cancel_function_name;
    zend_class_entry *cancel_exception_ce;
} king_http2_request_options_t;

typedef struct _king_http2_response_buffer {
    smart_str body;
    zval headers;
    zend_string *status_line;
    size_t body_bytes;
    size_t header_bytes;
    bool headers_initialized;
    bool body_overflowed;
    bool header_overflowed;
} king_http2_response_buffer_t;

typedef struct _king_http2_multi_transfer {
    CURL *easy;
    king_http2_response_buffer_t response;
    zend_string *url;
    zend_string *method;
    zend_string *body_string;
    struct curl_slist *request_headers;
    char *effective_url;
    long response_code;
    long http_version;
    CURLcode curl_code;
    bool secure_transport;
    bool body_was_supplied;
    bool is_push;
    bool has_parent_request_index;
    bool completed;
    size_t request_index;
    size_t parent_request_index;
    zval promise_headers;
    bool promise_headers_initialized;
} king_http2_multi_transfer_t;

typedef struct _king_http2_push_transfer_node {
    king_http2_multi_transfer_t transfer;
    struct _king_http2_push_transfer_node *next;
} king_http2_push_transfer_node_t;

typedef struct _king_http2_multi_batch_state {
    king_http2_multi_transfer_t *request_transfers;
    size_t request_count;
    king_http2_push_transfer_node_t *push_head;
    king_http2_push_transfer_node_t *push_tail;
    size_t push_count;
    size_t open_transfers;
    const king_http2_request_options_t *options;
    const char *function_name;
    bool capture_push;
    bool push_callback_failed;
    zend_class_entry *push_error_ce;
    char push_error[KING_ERR_LEN];
} king_http2_multi_batch_state_t;

typedef struct _king_http2_pool_entry {
    char *origin;
    CURLM *multi;
    struct _king_http2_pool_entry *next;
} king_http2_pool_entry_t;

typedef struct _king_http2_libcurl_api {
    void *handle;
    bool load_attempted;
    bool ready;
    char load_error[KING_ERR_LEN];
    CURLcode (*curl_global_init_fn)(long flags);
    void (*curl_global_cleanup_fn)(void);
    CURL *(*curl_easy_init_fn)(void);
    void (*curl_easy_cleanup_fn)(CURL *easy_handle);
    void (*curl_easy_reset_fn)(CURL *easy_handle);
    CURLcode (*curl_easy_setopt_fn)(CURL *easy_handle, CURLoption option, ...);
    CURLcode (*curl_easy_perform_fn)(CURL *easy_handle);
    CURLcode (*curl_easy_getinfo_fn)(CURL *easy_handle, CURLINFO info, ...);
    const char *(*curl_easy_strerror_fn)(CURLcode);
    CURLM *(*curl_multi_init_fn)(void);
    CURLMcode (*curl_multi_add_handle_fn)(CURLM *multi_handle, CURL *curl_handle);
    CURLMcode (*curl_multi_remove_handle_fn)(CURLM *multi_handle, CURL *curl_handle);
    CURLMcode (*curl_multi_wait_fn)(
        CURLM *multi_handle,
        struct curl_waitfd extra_fds[],
        unsigned int extra_nfds,
        int timeout_ms,
        int *ret
    );
    CURLMcode (*curl_multi_perform_fn)(CURLM *multi_handle, int *running_handles);
    CURLMcode (*curl_multi_cleanup_fn)(CURLM *multi_handle);
    CURLMsg *(*curl_multi_info_read_fn)(CURLM *multi_handle, int *msgs_in_queue);
    const char *(*curl_multi_strerror_fn)(CURLMcode);
    CURLMcode (*curl_multi_setopt_fn)(CURLM *multi_handle, CURLMoption option, ...);
    struct curl_slist *(*curl_slist_append_fn)(struct curl_slist *, const char *);
    void (*curl_slist_free_all_fn)(struct curl_slist *);
    char *(*curl_pushheader_bynum_fn)(struct curl_pushheaders *headers, size_t index);
    curl_version_info_data *(*curl_version_info_fn)(CURLversion);
} king_http2_libcurl_api_t;

static king_http2_libcurl_api_t king_http2_libcurl = {0};
static king_http2_pool_entry_t *king_http2_pool = NULL;

static void king_http2_multi_transfer_destroy(king_http2_multi_transfer_t *transfer);
static zend_result king_http2_request_execute_multi_batch(
    zval *return_value,
    king_http2_pool_entry_t *pool_entry,
    king_http2_multi_transfer_t *transfers,
    size_t request_count,
    const king_http2_request_options_t *options,
    const char *function_name,
    bool unwrap_single_response
);

#include "http2/common.inc"
#include "http2/libcurl.inc"
#include "http2/response.inc"
#include "http2/request.inc"
