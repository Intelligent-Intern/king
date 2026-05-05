/*
 * src/autoscaling/provisioning.c - Autoscaling Provider And Budget Backends
 * =========================================================================
 *
 * This file owns the provider-facing autoscaling helpers: lazy libcurl
 * loading, bounded HTTP I/O, Hetzner budget probing, provider payload/build
 * helpers, simulated scale-up/down paths, and the public PHP scale actions
 * that drive provider-side provisioning or deletion.
 * =========================================================================
 */
#include "php_king.h"
#include "include/autoscaling/autoscaling.h"
#include "include/runtime/libcurl_candidates.h"
#include "autoscaling/autoscaling_internal.h"

#include "Zend/zend_smart_str.h"

#include <ext/json/php_json.h>
#include <curl/curl.h>
#include <dlfcn.h>
#include <errno.h>
#include <stdio.h>
#include <stdlib.h>
#include <ctype.h>
#include <strings.h>
#include <time.h>

#define KING_AUTOSCALING_HTTP_TIMEOUT_MS 15000L
#define KING_AUTOSCALING_HTTP_MAX_RESPONSE_SIZE (10 * 1024 * 1024) /* 10 MiB */

typedef struct _king_autoscaling_http_buffer_t {
    smart_str data;
    size_t bytes;
} king_autoscaling_http_buffer_t;

typedef struct _king_autoscaling_libcurl_api_t {
    void *handle;
    zend_bool ready;
    zend_bool load_attempted;
    char load_error[256];
    CURLcode (*curl_global_init_fn)(long flags);
    void (*curl_global_cleanup_fn)(void);
    CURL *(*curl_easy_init_fn)(void);
    void (*curl_easy_cleanup_fn)(CURL *easy_handle);
    CURLcode (*curl_easy_setopt_fn)(CURL *easy_handle, CURLoption option, ...);
    CURLcode (*curl_easy_perform_fn)(CURL *easy_handle);
    CURLcode (*curl_easy_getinfo_fn)(CURL *easy_handle, CURLINFO info, ...);
    const char *(*curl_easy_strerror_fn)(CURLcode);
    struct curl_slist *(*curl_slist_append_fn)(struct curl_slist *, const char *);
    void (*curl_slist_free_all_fn)(struct curl_slist *);
} king_autoscaling_libcurl_api_t;


#include "provisioning/curl_loader.inc"
#include "provisioning/budget_probe.inc"
#include "provisioning/provider_payload_and_inventory.inc"
#include "provisioning/provider_scale_actions.inc"
