/*
 * Telemetry core runtime for King. Owns trace/log pending buffers, the batched
 * export retry queue, lazy libcurl-based exporter wiring, active local span
 * stack, and the trace-context snapshot helpers that expose the live current
 * span to userland.
 */
#include "php_king.h"
#include "include/telemetry/telemetry.h"
#include "include/runtime/libcurl_candidates.h"
#include <zend_hash.h>
#include <curl/curl.h>
#include <dlfcn.h>
#include <errno.h>
#include <fcntl.h>
#include <limits.h>
#include <sys/file.h>
#include <sys/stat.h>
#include <unistd.h>
#include <ext/json/php_json.h>
#include <ext/spl/spl_exceptions.h>
#include <ext/standard/php_var.h>
#include "zend_smart_str.h"
#include "include/config/open_telemetry/base_layer.h"

static king_telemetry_config_t king_telemetry_runtime_config;
static bool king_telemetry_system_initialized = false;
static king_trace_context_t *king_current_span = NULL;
typedef struct _king_telemetry_incoming_parent_context_t {
    zend_bool active;
    char trace_id[33];
    char parent_span_id[17];
    uint8_t trace_flags;
    char trace_state[512];
} king_telemetry_incoming_parent_context_t;
static king_telemetry_incoming_parent_context_t king_telemetry_incoming_parent_context = {0};
static zval king_telemetry_pending_spans;
static zval king_telemetry_pending_logs;
static bool king_telemetry_pending_buffers_initialized = false;

typedef struct _king_telemetry_libcurl_api_t {
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
} king_telemetry_libcurl_api_t;

#define KING_TELEMETRY_HTTP_TIMEOUT_FALLBACK_MS 10000U
#define KING_TELEMETRY_EXPORT_RESULT_DROP 1
#define KING_TELEMETRY_MAX_REQUEST_SIZE (1024 * 1024) /* 1 MiB */
#define KING_TELEMETRY_MAX_RESPONSE_SIZE (1024 * 1024) /* 1 MiB */
#define KING_TELEMETRY_BYTES_PER_QUEUE_SLOT (64ULL * 1024ULL)

/* Export queue for batched telemetry data */
king_telemetry_batch_t *king_telemetry_export_queue_head = NULL;
king_telemetry_batch_t *king_telemetry_export_queue_tail = NULL;
uint32_t king_telemetry_queue_size = 0;
uint32_t king_telemetry_queue_drop_count = 0;
uint32_t king_telemetry_pending_drop_count = 0;
uint32_t king_telemetry_export_success_count = 0;
uint32_t king_telemetry_export_failure_count = 0;
static uint64_t king_telemetry_queue_bytes = 0;
static uint64_t king_telemetry_pending_bytes = 0;
static uint32_t king_telemetry_queue_high_watermark = 0;
static uint64_t king_telemetry_queue_high_water_bytes = 0;
static uint64_t king_telemetry_memory_high_water_bytes = 0;
static uint32_t king_telemetry_retry_requeue_count = 0;
static king_telemetry_export_diagnostic_t king_telemetry_last_export_diagnostic = {0};

static king_telemetry_libcurl_api_t king_telemetry_libcurl = {0};
static void king_telemetry_span_free(king_trace_context_t *span);


#include "telemetry/runtime_and_libcurl.inc"
#include "telemetry/pending_buffers_and_encoding.inc"
#include "telemetry/state_persistence.inc"
#include "telemetry/queue_and_span_runtime.inc"
#include "telemetry/php_api_and_http_transport.inc"
#include "telemetry/otlp_exporters.inc"
