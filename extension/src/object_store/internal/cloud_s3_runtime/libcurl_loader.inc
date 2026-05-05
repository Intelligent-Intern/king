/*
 * Shared cloud HTTP runtime for S3-first adapters. Holds bounded response
 * buffers plus the common request/signing/runtime helpers reused by cloud
 * provider backends.
 */

typedef struct _king_object_store_http_response_t {
    smart_str body;
    smart_str headers;
    size_t body_bytes;
    size_t header_bytes;
    zend_bool body_limit_exceeded;
    zend_bool header_limit_exceeded;
    long status_code;
} king_object_store_http_response_t;

typedef struct _king_object_store_libcurl_api_t {
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
} king_object_store_libcurl_api_t;

typedef struct _king_object_store_s3_runtime_config_t {
    char endpoint[512];
    char bucket[256];
    char region[64];
    char access_key[128];
    char secret_key[256];
    char session_token[256];
    zend_bool path_style;
    zend_bool verify_tls;
} king_object_store_s3_runtime_config_t;

#define KING_OBJECT_STORE_HTTP_TIMEOUT_MS 15000L
#define KING_OBJECT_STORE_HTTP_MAX_BODY_BYTES (16 * 1024 * 1024)
#define KING_OBJECT_STORE_HTTP_MAX_HEADER_BYTES (128 * 1024)

static king_object_store_libcurl_api_t king_object_store_libcurl = {0};

static void king_object_store_reset_libcurl_runtime_state(void)
{
    memset(&king_object_store_libcurl, 0, sizeof(king_object_store_libcurl));
}

static void king_object_store_close_libcurl_runtime_handle(void)
{
    if (king_object_store_libcurl.handle != NULL) {
        dlclose(king_object_store_libcurl.handle);
        king_object_store_libcurl.handle = NULL;
    }
}

static zend_result king_object_store_load_libcurl_symbol(void **target, const char *name)
{
    *target = dlsym(king_object_store_libcurl.handle, name);
    if (*target == NULL) {
        snprintf(
            king_object_store_libcurl.load_error,
            sizeof(king_object_store_libcurl.load_error),
            "Failed to load libcurl symbol '%s' for cloud object-store operations.",
            name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_object_store_ensure_libcurl_ready(void)
{
    const char *const candidates[] = {KING_LIBCURL_RUNTIME_CANDIDATES};
    size_t index;

    if (king_object_store_libcurl.ready) {
        return SUCCESS;
    }

    if (king_object_store_libcurl.load_attempted) {
        return FAILURE;
    }

    king_object_store_libcurl.load_attempted = 1;

    for (index = 0; candidates[index] != NULL; index++) {
        king_object_store_libcurl.handle = dlopen(candidates[index], RTLD_LAZY | RTLD_LOCAL);
        if (king_object_store_libcurl.handle != NULL) {
            break;
        }
    }

    if (king_object_store_libcurl.handle == NULL) {
        snprintf(
            king_object_store_libcurl.load_error,
            sizeof(king_object_store_libcurl.load_error),
            "Failed to load %s for cloud object-store operations.",
            KING_LIBCURL_RUNTIME_CANDIDATE_NAMES
        );
        return FAILURE;
    }

    if (king_object_store_load_libcurl_symbol(
            (void **) &king_object_store_libcurl.curl_global_init_fn,
            "curl_global_init"
        ) != SUCCESS
        || king_object_store_load_libcurl_symbol(
            (void **) &king_object_store_libcurl.curl_global_cleanup_fn,
            "curl_global_cleanup"
        ) != SUCCESS
        || king_object_store_load_libcurl_symbol(
            (void **) &king_object_store_libcurl.curl_easy_init_fn,
            "curl_easy_init"
        ) != SUCCESS
        || king_object_store_load_libcurl_symbol(
            (void **) &king_object_store_libcurl.curl_easy_cleanup_fn,
            "curl_easy_cleanup"
        ) != SUCCESS
        || king_object_store_load_libcurl_symbol(
            (void **) &king_object_store_libcurl.curl_easy_setopt_fn,
            "curl_easy_setopt"
        ) != SUCCESS
        || king_object_store_load_libcurl_symbol(
            (void **) &king_object_store_libcurl.curl_easy_perform_fn,
            "curl_easy_perform"
        ) != SUCCESS
        || king_object_store_load_libcurl_symbol(
            (void **) &king_object_store_libcurl.curl_easy_getinfo_fn,
            "curl_easy_getinfo"
        ) != SUCCESS
        || king_object_store_load_libcurl_symbol(
            (void **) &king_object_store_libcurl.curl_easy_strerror_fn,
            "curl_easy_strerror"
        ) != SUCCESS
        || king_object_store_load_libcurl_symbol(
            (void **) &king_object_store_libcurl.curl_slist_append_fn,
            "curl_slist_append"
        ) != SUCCESS
        || king_object_store_load_libcurl_symbol(
            (void **) &king_object_store_libcurl.curl_slist_free_all_fn,
            "curl_slist_free_all"
        ) != SUCCESS) {
        king_object_store_close_libcurl_runtime_handle();
        return FAILURE;
    }

    if (king_object_store_libcurl.curl_global_init_fn(CURL_GLOBAL_DEFAULT) != CURLE_OK) {
        snprintf(
            king_object_store_libcurl.load_error,
            sizeof(king_object_store_libcurl.load_error),
            "curl_global_init() failed for cloud object-store operations."
        );
        king_object_store_close_libcurl_runtime_handle();
        return FAILURE;
    }

    king_object_store_libcurl.ready = 1;
    return SUCCESS;
}
