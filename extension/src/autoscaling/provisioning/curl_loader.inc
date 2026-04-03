static zend_result king_autoscaling_http_request(
    const char *method,
    const char *url,
    const char *token,
    const char *body,
    long *http_code_out,
    smart_str *response_out
);

typedef enum _king_autoscaling_hetzner_budget_decision_t {
    KING_AUTOSCALING_HETZNER_BUDGET_DECISION_ALLOW = 0,
    KING_AUTOSCALING_HETZNER_BUDGET_DECISION_WARNING = 1,
    KING_AUTOSCALING_HETZNER_BUDGET_DECISION_HARD_LIMIT = 2,
    KING_AUTOSCALING_HETZNER_BUDGET_DECISION_API_ERROR = 3
} king_autoscaling_hetzner_budget_decision_t;

static king_autoscaling_libcurl_api_t king_autoscaling_libcurl = {0};

static zend_result king_autoscaling_load_symbol(void **target, const char *name)
{
    *target = dlsym(king_autoscaling_libcurl.handle, name);
    if (*target == NULL) {
        snprintf(
            king_autoscaling_libcurl.load_error,
            sizeof(king_autoscaling_libcurl.load_error),
            "Failed to load libcurl symbol '%s'.",
            name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_autoscaling_ensure_libcurl_ready(void)
{
    const char *const candidates[] = {"libcurl.so.4", "libcurl.so", NULL};
    size_t index;

    if (king_autoscaling_libcurl.ready) {
        return SUCCESS;
    }

    if (king_autoscaling_libcurl.load_attempted) {
        return FAILURE;
    }

    king_autoscaling_libcurl.load_attempted = 1;

    for (index = 0; candidates[index] != NULL; index++) {
        king_autoscaling_libcurl.handle = dlopen(candidates[index], RTLD_LAZY | RTLD_LOCAL);
        if (king_autoscaling_libcurl.handle != NULL) {
            break;
        }
    }

    if (king_autoscaling_libcurl.handle == NULL) {
        snprintf(
            king_autoscaling_libcurl.load_error,
            sizeof(king_autoscaling_libcurl.load_error),
            "Failed to load libcurl.so.4 or libcurl.so for autoscaling provider calls."
        );
        return FAILURE;
    }

    if (
        king_autoscaling_load_symbol(
            (void **) &king_autoscaling_libcurl.curl_global_init_fn,
            "curl_global_init"
        ) != SUCCESS
        || king_autoscaling_load_symbol(
            (void **) &king_autoscaling_libcurl.curl_global_cleanup_fn,
            "curl_global_cleanup"
        ) != SUCCESS
        || king_autoscaling_load_symbol(
            (void **) &king_autoscaling_libcurl.curl_easy_init_fn,
            "curl_easy_init"
        ) != SUCCESS
        || king_autoscaling_load_symbol(
            (void **) &king_autoscaling_libcurl.curl_easy_cleanup_fn,
            "curl_easy_cleanup"
        ) != SUCCESS
        || king_autoscaling_load_symbol(
            (void **) &king_autoscaling_libcurl.curl_easy_setopt_fn,
            "curl_easy_setopt"
        ) != SUCCESS
        || king_autoscaling_load_symbol(
            (void **) &king_autoscaling_libcurl.curl_easy_perform_fn,
            "curl_easy_perform"
        ) != SUCCESS
        || king_autoscaling_load_symbol(
            (void **) &king_autoscaling_libcurl.curl_easy_getinfo_fn,
            "curl_easy_getinfo"
        ) != SUCCESS
        || king_autoscaling_load_symbol(
            (void **) &king_autoscaling_libcurl.curl_easy_strerror_fn,
            "curl_easy_strerror"
        ) != SUCCESS
        || king_autoscaling_load_symbol(
            (void **) &king_autoscaling_libcurl.curl_slist_append_fn,
            "curl_slist_append"
        ) != SUCCESS
        || king_autoscaling_load_symbol(
            (void **) &king_autoscaling_libcurl.curl_slist_free_all_fn,
            "curl_slist_free_all"
        ) != SUCCESS
    ) {
        dlclose(king_autoscaling_libcurl.handle);
        king_autoscaling_libcurl.handle = NULL;
        return FAILURE;
    }

    if (king_autoscaling_libcurl.curl_global_init_fn(CURL_GLOBAL_DEFAULT) != CURLE_OK) {
        snprintf(
            king_autoscaling_libcurl.load_error,
            sizeof(king_autoscaling_libcurl.load_error),
            "curl_global_init() failed for autoscaling provider calls."
        );
        dlclose(king_autoscaling_libcurl.handle);
        king_autoscaling_libcurl.handle = NULL;
        return FAILURE;
    }

    king_autoscaling_libcurl.ready = 1;
    return SUCCESS;
}

