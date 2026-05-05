/*
 * =========================================================================
 * FILENAME:   src/client/http2/libcurl.inc
 * PROJECT:    king
 *
 * PURPOSE:
 * Lazy libcurl runtime loading and HTTP/2 session-pool management for the
 * active client surface. This file resolves the required libcurl symbols,
 * verifies HTTP/2 support at runtime, and maintains the per-origin multi
 * handle cache that backs connection reuse and bounded concurrent streams.
 * =========================================================================
 */

static zend_result king_http2_load_symbol(
    void **target,
    const char *name)
{
    *target = dlsym(king_http2_libcurl.handle, name);
    if (*target == NULL) {
        snprintf(
            king_http2_libcurl.load_error,
            sizeof(king_http2_libcurl.load_error),
            "Failed to load libcurl symbol '%s'.",
            name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static void king_http2_try_load_symbol(
    void **target,
    const char *name)
{
    *target = dlsym(king_http2_libcurl.handle, name);
}

static void king_http2_libcurl_close_runtime_handle(void)
{
    if (king_http2_libcurl.handle != NULL) {
        dlclose(king_http2_libcurl.handle);
        king_http2_libcurl.handle = NULL;
    }
}

static zend_result king_http2_ensure_libcurl_ready(void)
{
    const char *const candidates[] = {KING_LIBCURL_RUNTIME_CANDIDATES};
    size_t i;
    curl_version_info_data *version_info;

    if (king_http2_libcurl.ready) {
        return SUCCESS;
    }

    if (king_http2_libcurl.load_attempted) {
        return FAILURE;
    }

    king_http2_libcurl.load_attempted = true;

    for (i = 0; candidates[i] != NULL; ++i) {
        king_http2_libcurl.handle = dlopen(candidates[i], RTLD_LAZY | RTLD_LOCAL);
        if (king_http2_libcurl.handle != NULL) {
            break;
        }
    }

    if (king_http2_libcurl.handle == NULL) {
        snprintf(
            king_http2_libcurl.load_error,
            sizeof(king_http2_libcurl.load_error),
            "Failed to load %s for the active HTTP/2 runtime.",
            KING_LIBCURL_RUNTIME_CANDIDATE_NAMES
        );
        return FAILURE;
    }

    if (king_http2_load_symbol((void **) &king_http2_libcurl.curl_global_init_fn, "curl_global_init") != SUCCESS
        || king_http2_load_symbol((void **) &king_http2_libcurl.curl_global_cleanup_fn, "curl_global_cleanup") != SUCCESS
        || king_http2_load_symbol((void **) &king_http2_libcurl.curl_easy_init_fn, "curl_easy_init") != SUCCESS
        || king_http2_load_symbol((void **) &king_http2_libcurl.curl_easy_cleanup_fn, "curl_easy_cleanup") != SUCCESS
        || king_http2_load_symbol((void **) &king_http2_libcurl.curl_easy_reset_fn, "curl_easy_reset") != SUCCESS
        || king_http2_load_symbol((void **) &king_http2_libcurl.curl_easy_setopt_fn, "curl_easy_setopt") != SUCCESS
        || king_http2_load_symbol((void **) &king_http2_libcurl.curl_easy_perform_fn, "curl_easy_perform") != SUCCESS
        || king_http2_load_symbol((void **) &king_http2_libcurl.curl_easy_getinfo_fn, "curl_easy_getinfo") != SUCCESS
        || king_http2_load_symbol((void **) &king_http2_libcurl.curl_easy_strerror_fn, "curl_easy_strerror") != SUCCESS
        || king_http2_load_symbol((void **) &king_http2_libcurl.curl_multi_init_fn, "curl_multi_init") != SUCCESS
        || king_http2_load_symbol((void **) &king_http2_libcurl.curl_multi_add_handle_fn, "curl_multi_add_handle") != SUCCESS
        || king_http2_load_symbol((void **) &king_http2_libcurl.curl_multi_remove_handle_fn, "curl_multi_remove_handle") != SUCCESS
        || king_http2_load_symbol((void **) &king_http2_libcurl.curl_multi_wait_fn, "curl_multi_wait") != SUCCESS
        || king_http2_load_symbol((void **) &king_http2_libcurl.curl_multi_perform_fn, "curl_multi_perform") != SUCCESS
        || king_http2_load_symbol((void **) &king_http2_libcurl.curl_multi_cleanup_fn, "curl_multi_cleanup") != SUCCESS
        || king_http2_load_symbol((void **) &king_http2_libcurl.curl_multi_info_read_fn, "curl_multi_info_read") != SUCCESS
        || king_http2_load_symbol((void **) &king_http2_libcurl.curl_multi_strerror_fn, "curl_multi_strerror") != SUCCESS
        || king_http2_load_symbol((void **) &king_http2_libcurl.curl_multi_setopt_fn, "curl_multi_setopt") != SUCCESS
        || king_http2_load_symbol((void **) &king_http2_libcurl.curl_slist_append_fn, "curl_slist_append") != SUCCESS
        || king_http2_load_symbol((void **) &king_http2_libcurl.curl_slist_free_all_fn, "curl_slist_free_all") != SUCCESS
        || king_http2_load_symbol((void **) &king_http2_libcurl.curl_version_info_fn, "curl_version_info") != SUCCESS) {
        king_http2_libcurl_close_runtime_handle();
        return FAILURE;
    }

    king_http2_try_load_symbol(
        (void **) &king_http2_libcurl.curl_pushheader_bynum_fn,
        "curl_pushheader_bynum"
    );

    if (king_http2_libcurl.curl_global_init_fn(CURL_GLOBAL_DEFAULT) != CURLE_OK) {
        snprintf(
            king_http2_libcurl.load_error,
            sizeof(king_http2_libcurl.load_error),
            "curl_global_init() failed for the active HTTP/2 runtime."
        );
        king_http2_libcurl_close_runtime_handle();
        return FAILURE;
    }

    version_info = king_http2_libcurl.curl_version_info_fn(CURLVERSION_NOW);
    if (version_info == NULL || (version_info->features & CURL_VERSION_HTTP2) == 0) {
        snprintf(
            king_http2_libcurl.load_error,
            sizeof(king_http2_libcurl.load_error),
            "The loaded libcurl runtime does not advertise HTTP/2 support."
        );
        king_http2_libcurl.curl_global_cleanup_fn();
        king_http2_libcurl_close_runtime_handle();
        return FAILURE;
    }

    king_http2_libcurl.ready = true;
    return SUCCESS;
}

static void king_http2_pool_entry_destroy(king_http2_pool_entry_t *entry)
{
    if (entry == NULL) {
        return;
    }

    if (entry->multi != NULL && king_http2_libcurl.curl_multi_cleanup_fn != NULL) {
        king_http2_libcurl.curl_multi_cleanup_fn(entry->multi);
    }
    if (entry->origin != NULL) {
        efree(entry->origin);
    }

    efree(entry);
}

static king_http2_pool_entry_t *king_http2_pool_find(const char *origin)
{
    king_http2_pool_entry_t *entry;

    for (entry = king_http2_pool; entry != NULL; entry = entry->next) {
        if (strcmp(entry->origin, origin) == 0) {
            return entry;
        }
    }

    return NULL;
}

static void king_http2_pool_remove(king_http2_pool_entry_t *target)
{
    king_http2_pool_entry_t *entry = king_http2_pool;
    king_http2_pool_entry_t *prev = NULL;

    while (entry != NULL) {
        if (entry == target) {
            if (prev == NULL) {
                king_http2_pool = entry->next;
            } else {
                prev->next = entry->next;
            }

            king_http2_pool_entry_destroy(entry);
            return;
        }

        prev = entry;
        entry = entry->next;
    }
}

static void king_http2_pool_cleanup(void)
{
    king_http2_pool_entry_t *entry = king_http2_pool;

    while (entry != NULL) {
        king_http2_pool_entry_t *next = entry->next;
        king_http2_pool_entry_destroy(entry);
        entry = next;
    }

    king_http2_pool = NULL;
}

static king_http2_pool_entry_t *king_http2_pool_get_or_create(const char *origin)
{
    king_http2_pool_entry_t *entry;

    entry = king_http2_pool_find(origin);
    if (entry != NULL) {
        return entry;
    }

    entry = ecalloc(1, sizeof(*entry));
    entry->origin = estrdup(origin);
    entry->multi = king_http2_libcurl.curl_multi_init_fn();

    if (entry->multi == NULL) {
        efree(entry->origin);
        efree(entry);
        return NULL;
    }

    entry->next = king_http2_pool;
    king_http2_pool = entry;

    return entry;
}

static zend_result king_http2_pool_prepare_multi(
    king_http2_pool_entry_t *pool_entry,
    const king_http2_request_options_t *options,
    const char *function_name)
{
    CURLMcode multi_code;

    multi_code = king_http2_libcurl.curl_multi_setopt_fn(
        pool_entry->multi,
        CURLMOPT_MAX_HOST_CONNECTIONS,
        1L
    );
    if (multi_code != CURLM_OK && multi_code != CURLM_UNKNOWN_OPTION) {
        king_http2_throw(
            king_ce_system_exception,
            "%s() failed to configure the active libcurl HTTP/2 session pool: %s",
            function_name,
            king_http2_libcurl.curl_multi_strerror_fn(multi_code)
        );
        return FAILURE;
    }

    multi_code = king_http2_libcurl.curl_multi_setopt_fn(
        pool_entry->multi,
        CURLMOPT_MAXCONNECTS,
        1L
    );
    if (multi_code != CURLM_OK && multi_code != CURLM_UNKNOWN_OPTION) {
        king_http2_throw(
            king_ce_system_exception,
            "%s() failed to configure the active libcurl HTTP/2 connection cache: %s",
            function_name,
            king_http2_libcurl.curl_multi_strerror_fn(multi_code)
        );
        return FAILURE;
    }

    if (options->http2_max_concurrent_streams > 0) {
        multi_code = king_http2_libcurl.curl_multi_setopt_fn(
            pool_entry->multi,
            CURLMOPT_MAX_CONCURRENT_STREAMS,
            (long) options->http2_max_concurrent_streams
        );
        if (multi_code != CURLM_OK && multi_code != CURLM_UNKNOWN_OPTION) {
            king_http2_throw(
                king_ce_system_exception,
                "%s() failed to configure the active libcurl HTTP/2 concurrent stream limit: %s",
                function_name,
                king_http2_libcurl.curl_multi_strerror_fn(multi_code)
            );
            return FAILURE;
        }
    }

    return SUCCESS;
}

static CURL *king_http2_easy_create(void)
{
    return king_http2_libcurl.curl_easy_init_fn();
}

static void king_http2_easy_destroy(CURL *easy)
{
    if (easy != NULL && king_http2_libcurl.curl_easy_cleanup_fn != NULL) {
        king_http2_libcurl.curl_easy_cleanup_fn(easy);
    }
}

void king_http2_pool_request_shutdown(void)
{
    king_http2_pool_cleanup();
}

void king_http2_pool_module_shutdown(void)
{
    king_http2_pool_cleanup();

    if (king_http2_libcurl.ready && king_http2_libcurl.curl_global_cleanup_fn != NULL) {
        king_http2_libcurl.curl_global_cleanup_fn();
    }

    king_http2_libcurl_close_runtime_handle();
    memset(&king_http2_libcurl, 0, sizeof(king_http2_libcurl));
}
