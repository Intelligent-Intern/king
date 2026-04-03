static size_t king_autoscaling_http_write_callback(
    char *contents,
    size_t size,
    size_t nmemb,
    void *userdata)
{
    size_t bytes = size * nmemb;
    king_autoscaling_http_buffer_t *buffer = userdata;

    /* Enforce response size limit to prevent memory exhaustion DoS */
    if (buffer->bytes + bytes > KING_AUTOSCALING_HTTP_MAX_RESPONSE_SIZE) {
        /* Return 0 to signal error and abort the transfer */
        return 0;
    }

    smart_str_appendl(&buffer->data, contents, bytes);
    buffer->bytes += bytes;
    return bytes;
}

static void king_autoscaling_json_append_escaped(smart_str *buffer, const char *value)
{
    const unsigned char *cursor = (const unsigned char *) (value != NULL ? value : "");

    while (*cursor != '\0') {
        switch (*cursor) {
            case '\\':
                smart_str_appendl(buffer, "\\\\", 2);
                break;
            case '"':
                smart_str_appendl(buffer, "\\\"", 2);
                break;
            case '\n':
                smart_str_appendl(buffer, "\\n", 2);
                break;
            case '\r':
                smart_str_appendl(buffer, "\\r", 2);
                break;
            case '\t':
                smart_str_appendl(buffer, "\\t", 2);
                break;
            default:
                smart_str_appendc(buffer, (char) *cursor);
                break;
        }
        cursor++;
    }
}

static void king_autoscaling_json_append_kv_string(
    smart_str *buffer,
    zend_bool *first_field,
    const char *key,
    const char *value)
{
    if (!*first_field) {
        smart_str_appendc(buffer, ',');
    }
    *first_field = 0;

    smart_str_appendc(buffer, '"');
    smart_str_appends(buffer, key);
    smart_str_appendl(buffer, "\":\"", 3);
    king_autoscaling_json_append_escaped(buffer, value);
    smart_str_appendc(buffer, '"');
}

static void king_autoscaling_json_append_kv_long(
    smart_str *buffer,
    zend_bool *first_field,
    const char *key,
    zend_long value)
{
    char raw[64];

    snprintf(raw, sizeof(raw), "%ld", (long) value);

    if (!*first_field) {
        smart_str_appendc(buffer, ',');
    }
    *first_field = 0;

    smart_str_appendc(buffer, '"');
    smart_str_appends(buffer, key);
    smart_str_appendl(buffer, "\":", 2);
    smart_str_appends(buffer, raw);
}

static void king_autoscaling_trim_token(char *value)
{
    size_t length;

    if (value == NULL) {
        return;
    }

    while (*value == ' ' || *value == '\t') {
        memmove(value, value + 1, strlen(value));
    }

    length = strlen(value);
    while (
        length > 0
        && (
            value[length - 1] == ' '
            || value[length - 1] == '\t'
            || value[length - 1] == '\r'
            || value[length - 1] == '\n'
        )
    ) {
        value[length - 1] = '\0';
        length--;
    }
}

static zend_bool king_autoscaling_string_is_integer(const char *value)
{
    const unsigned char *cursor;

    if (value == NULL || value[0] == '\0') {
        return 0;
    }

    cursor = (const unsigned char *) value;
    while (*cursor != '\0') {
        if (*cursor < '0' || *cursor > '9') {
            return 0;
        }
        cursor++;
    }

    return 1;
}

static zend_result king_autoscaling_parse_json_value_as_percent(
    const char *value_start,
    zend_long *value_out)
{
    const char *cursor;
    const char *end;
    char value_raw[64];
    char *trimmed;
    char *walk;
    double parsed;
    size_t length;

    if (value_start == NULL || value_out == NULL) {
        return FAILURE;
    }

    cursor = value_start;
    while (*cursor == ' ' || *cursor == '\t' || *cursor == '\r' || *cursor == '\n') {
        cursor++;
    }

    if (*cursor == '\"') {
        end = strchr(cursor + 1, '\"');
        if (end == NULL) {
            return FAILURE;
        }
        cursor++;
    } else {
        end = cursor;
        while (
            *end != '\0'
            && *end != ','
            && *end != '}'
            && *end != '\r'
            && *end != '\n'
            && !isspace((unsigned char) *end)
        ) {
            end++;
        }
    }

    length = (size_t) (end - cursor);
    if (length >= sizeof(value_raw)) {
        return FAILURE;
    }

    memcpy(value_raw, cursor, length);
    value_raw[length] = '\0';
    trimmed = value_raw;

    while (
        *trimmed == ' '
        || *trimmed == '\t'
        || *trimmed == '\r'
        || *trimmed == '\n'
    ) {
        memmove(trimmed, trimmed + 1, strlen(trimmed));
    }

    length = strlen(trimmed);
    while (
        length > 0
        && (
            trimmed[length - 1] == ' '
            || trimmed[length - 1] == '\t'
            || trimmed[length - 1] == '\r'
            || trimmed[length - 1] == '\n'
        )
    ) {
        trimmed[length - 1] = '\0';
        length--;
    }

    if (trimmed[0] == '\0') {
        return FAILURE;
    }

    parsed = strtod(trimmed, &walk);
    if (trimmed == walk) {
        return FAILURE;
    }

    while (isspace((unsigned char) *walk)) {
        walk++;
    }

    if (*walk == '%') {
        walk++;
        while (isspace((unsigned char) *walk)) {
            walk++;
        }
    }

    if (*walk != '\0') {
        return FAILURE;
    }

    *value_out = (zend_long) parsed;
    return SUCCESS;
}

static zend_bool king_autoscaling_is_budget_api_enabled(void)
{
    const char *path = king_autoscaling_runtime.config.hetzner_budget_path;
    return path != NULL && path[0] != '\0';
}

static zend_bool king_autoscaling_budget_metric_enabled(
    zend_long warning_threshold,
    zend_long hard_threshold)
{
    return warning_threshold > 0 || hard_threshold > 0;
}

static void king_autoscaling_build_budget_probe_url(
    char *url,
    size_t url_length)
{
    const char *endpoint = king_autoscaling_runtime.config.api_endpoint;
    const char *path = king_autoscaling_runtime.config.hetzner_budget_path;
    size_t endpoint_length;

    if (url == NULL || url_length == 0) {
        return;
    }

    if (endpoint == NULL || endpoint[0] == '\0') {
        url[0] = '\0';
        return;
    }

    while (path != NULL && (*path == ' ' || *path == '\t' || *path == '/')) {
        path++;
    }

    if (path == NULL || path[0] == '\0') {
        snprintf(url, url_length, "%s", endpoint);
        return;
    }

    endpoint_length = strlen(endpoint);
    if (endpoint[endpoint_length - 1] == '/') {
        snprintf(url, url_length, "%s%s", endpoint, path);
    } else {
        snprintf(url, url_length, "%s/%s", endpoint, path);
    }
}

/* Legacy parser implementation kept for local bisecting during development. */
#if 0
static zend_result king_autoscaling_json_extract_percent(
    const char *json,
    const char *key,
    zend_long *value_out)
{
    char needle[64];
    const char *cursor;
    const char *start;
    const char *end;
    char value_raw[64];
    char *trimmed;
    char *walk;
    double parsed;

    if (json == NULL || key == NULL || value_out == NULL) {
        return FAILURE;
    }

    snprintf(needle, sizeof(needle), "\"%s\"", key);

    cursor = strstr(json, needle);
    if (cursor == NULL) {
        return FAILURE;
    }

    while (cursor != NULL) {
        cursor = strchr(cursor + 1, ':');
        if (cursor == NULL) {
            break;
        }
        cursor++;
        while (*cursor == ' ' || *cursor == '\t' || *cursor == '\r' || *cursor == '\n') {
            cursor++;
        }

        if (*cursor == '\"') {
            start = cursor + 1;
            end = strchr(start, '"');
            if (end == NULL) {
                return FAILURE;
            }
            if ((size_t) (end - start) >= sizeof(value_raw)) {
                return FAILURE;
            }
            memcpy(value_raw, start, (size_t) (end - start));
            value_raw[end - start] = '\\0';
        } else {
            start = cursor;
            end = cursor;
            while (*end != '\\0' && *end != ',' && *end != '}' && *end != '\\n' && *end != '\\r' && !isspace((unsigned char) *end)) {
                end++;
            }
            if ((size_t) (end - start) >= sizeof(value_raw)) {
                return FAILURE;
            }
            memcpy(value_raw, start, (size_t) (end - start));
            value_raw[end - start] = '\\0';
        }

        trimmed = value_raw;
        king_autoscaling_trim_json_value(trimmed);
        if (*trimmed == '\\0') {
            return FAILURE;
        }

    parsed = strtod(trimmed, &walk);
    if (trimmed == walk) {
        return FAILURE;
    }
    while (isspace((unsigned char) *walk)) {
        walk++;
    }
    if (*walk == '%') {
        walk++;
        while (isspace((unsigned char) *walk)) {
            walk++;
        }
    }
        if (*walk != '\0') {
            return FAILURE;
        }

        *value_out = (zend_long) parsed;
        return SUCCESS;
    }

    return FAILURE;
}
}

    return FAILURE;
}
#endif

static zend_result king_autoscaling_json_extract_percent_with_keys(
    const char *json,
    const char *const *keys,
    size_t key_count,
    zend_long *value_out)
{
    size_t index;
    zval decoded = {0};
    zval *entry;

    if (json == NULL || keys == NULL || value_out == NULL) {
        return FAILURE;
    }

    if (php_json_decode(&decoded, json, strlen(json), 1, 128) != SUCCESS) {
        return FAILURE;
    }

    if (Z_TYPE(decoded) != IS_ARRAY) {
        zval_ptr_dtor(&decoded);
        return FAILURE;
    }

    for (index = 0; index < key_count; index++) {
        entry = zend_hash_str_find(
            Z_ARRVAL(decoded),
            keys[index],
            strlen(keys[index])
        );
        if (entry == NULL) {
            continue;
        }

        switch (Z_TYPE_P(entry)) {
            case IS_LONG:
                *value_out = Z_LVAL_P(entry);
                zval_ptr_dtor(&decoded);
                return SUCCESS;
            case IS_DOUBLE:
                *value_out = (zend_long) Z_DVAL_P(entry);
                zval_ptr_dtor(&decoded);
                return SUCCESS;
            case IS_TRUE:
                *value_out = 1;
                zval_ptr_dtor(&decoded);
                return SUCCESS;
            case IS_FALSE:
                *value_out = 0;
                zval_ptr_dtor(&decoded);
                return SUCCESS;
            case IS_STRING: {
                zend_long value_long = 0;
                if (
                    king_autoscaling_parse_json_value_as_percent(
                        Z_STRVAL_P(entry),
                        &value_long
                    ) == SUCCESS
                ) {
                    *value_out = value_long;
                    zval_ptr_dtor(&decoded);
                    return SUCCESS;
                }
                break;
            }
            default:
                break;
        }
    }

    zval_ptr_dtor(&decoded);
    return FAILURE;
}

static zend_result king_autoscaling_json_extract_percent(
    const char *json,
    const char *key,
    zend_long *value_out)
{
    const char *keys[] = {NULL};

    if (key == NULL || key[0] == '\0') {
        return FAILURE;
    }

    keys[0] = key;
    return king_autoscaling_json_extract_percent_with_keys(json, keys, 1, value_out);
}

static king_autoscaling_hetzner_budget_decision_t king_autoscaling_check_hetzner_budget_limits(void)
{
    const zend_bool spend_enabled = king_autoscaling_budget_metric_enabled(
        king_autoscaling_runtime.config.spend_warning_threshold_percent,
        king_autoscaling_runtime.config.spend_hard_limit_percent
    );
    const zend_bool quota_enabled = king_autoscaling_budget_metric_enabled(
        king_autoscaling_runtime.config.quota_warning_threshold_percent,
        king_autoscaling_runtime.config.quota_hard_limit_percent
    );
    const char *token = king_autoscaling_runtime.config.hetzner_api_token;
    long http_code = 0;
    char url[512];
    smart_str response = {0};
    zend_long spend_usage = 0;
    zend_long quota_usage = 0;
    zend_bool api_error = 0;
    king_autoscaling_hetzner_budget_decision_t decision = KING_AUTOSCALING_HETZNER_BUDGET_DECISION_ALLOW;

    if (!spend_enabled && !quota_enabled) {
        king_autoscaling_runtime.spend_usage_percent = -1;
        king_autoscaling_runtime.quota_usage_percent = -1;
        king_autoscaling_runtime.spend_status = KING_AUTOSCALING_BUDGET_STATUS_DISABLED;
        king_autoscaling_runtime.quota_status = KING_AUTOSCALING_BUDGET_STATUS_DISABLED;
        king_autoscaling_runtime.budget_probe_error[0] = '\0';
        return KING_AUTOSCALING_HETZNER_BUDGET_DECISION_ALLOW;
    }

    if (token == NULL || token[0] == '\0') {
        snprintf(
            king_autoscaling_runtime.budget_probe_error,
            sizeof(king_autoscaling_runtime.budget_probe_error),
            "Budget probe skipped because the Hetzner API token is not configured."
        );
        king_autoscaling_runtime.spend_status = KING_AUTOSCALING_BUDGET_STATUS_DISABLED;
        king_autoscaling_runtime.quota_status = KING_AUTOSCALING_BUDGET_STATUS_DISABLED;
        king_autoscaling_runtime.spend_usage_percent = -1;
        king_autoscaling_runtime.quota_usage_percent = -1;
        return KING_AUTOSCALING_HETZNER_BUDGET_DECISION_WARNING;
    }

    if (!king_autoscaling_is_budget_api_enabled()) {
        king_autoscaling_runtime.spend_status = KING_AUTOSCALING_BUDGET_STATUS_DISABLED;
        king_autoscaling_runtime.quota_status = KING_AUTOSCALING_BUDGET_STATUS_DISABLED;
        king_autoscaling_runtime.spend_usage_percent = -1;
        king_autoscaling_runtime.quota_usage_percent = -1;
        king_autoscaling_runtime.budget_probe_error[0] = '\0';
        return KING_AUTOSCALING_HETZNER_BUDGET_DECISION_ALLOW;
    }

    king_autoscaling_build_budget_probe_url(url, sizeof(url));
    if (url[0] == '\0') {
        return KING_AUTOSCALING_HETZNER_BUDGET_DECISION_ALLOW;
    }

    if (king_autoscaling_http_request(
        "GET",
        url,
        token,
        NULL,
        &http_code,
        &response
    ) != SUCCESS) {
        king_autoscaling_runtime.spend_status = KING_AUTOSCALING_BUDGET_STATUS_API_ERROR;
        king_autoscaling_runtime.quota_status = KING_AUTOSCALING_BUDGET_STATUS_API_ERROR;
        king_autoscaling_runtime.spend_usage_percent = -1;
        king_autoscaling_runtime.quota_usage_percent = -1;
        snprintf(
            king_autoscaling_runtime.budget_probe_error,
            sizeof(king_autoscaling_runtime.budget_probe_error),
            "Hetzner budget API request failed: %s",
            king_autoscaling_runtime.last_error
        );
        smart_str_free(&response);
        return KING_AUTOSCALING_HETZNER_BUDGET_DECISION_API_ERROR;
    }

    if (http_code < 200 || http_code >= 300) {
        king_autoscaling_runtime.spend_status = KING_AUTOSCALING_BUDGET_STATUS_API_ERROR;
        king_autoscaling_runtime.quota_status = KING_AUTOSCALING_BUDGET_STATUS_API_ERROR;
        king_autoscaling_runtime.spend_usage_percent = -1;
        king_autoscaling_runtime.quota_usage_percent = -1;
        snprintf(
            king_autoscaling_runtime.budget_probe_error,
            sizeof(king_autoscaling_runtime.budget_probe_error),
            "Hetzner budget API returned HTTP %ld.",
            http_code
        );
        smart_str_free(&response);
        return KING_AUTOSCALING_HETZNER_BUDGET_DECISION_API_ERROR;
    }

    if (response.s != NULL) {
        static const char *spend_keys[] = {"spend", "spend_percent", "spend_usage_percent", "spend_usage", NULL};
        static const char *quota_keys[] = {"quota", "quota_percent", "quota_usage_percent", "quota_usage", NULL};
        size_t spend_key_count = (sizeof(spend_keys) / sizeof(spend_keys[0])) - 1;
        size_t quota_key_count = (sizeof(quota_keys) / sizeof(quota_keys[0])) - 1;

        if (spend_enabled) {
            if (king_autoscaling_json_extract_percent_with_keys(
                ZSTR_VAL(response.s),
                spend_keys,
                spend_key_count,
                &spend_usage
            ) == SUCCESS) {
                king_autoscaling_runtime.spend_usage_percent = spend_usage;
                if (spend_usage < king_autoscaling_runtime.config.spend_warning_threshold_percent) {
                    king_autoscaling_runtime.spend_status = KING_AUTOSCALING_BUDGET_STATUS_OK;
                } else if (
                    king_autoscaling_runtime.config.spend_hard_limit_percent > 0
                    && spend_usage >= king_autoscaling_runtime.config.spend_hard_limit_percent
                ) {
                    king_autoscaling_runtime.spend_status = KING_AUTOSCALING_BUDGET_STATUS_HARD_LIMIT;
                    decision = KING_AUTOSCALING_HETZNER_BUDGET_DECISION_HARD_LIMIT;
                } else {
                    king_autoscaling_runtime.spend_status = KING_AUTOSCALING_BUDGET_STATUS_WARNING;
                    if (decision != KING_AUTOSCALING_HETZNER_BUDGET_DECISION_HARD_LIMIT) {
                        decision = KING_AUTOSCALING_HETZNER_BUDGET_DECISION_WARNING;
                    }
                }
            } else {
                king_autoscaling_runtime.spend_status = KING_AUTOSCALING_BUDGET_STATUS_API_ERROR;
                king_autoscaling_runtime.spend_usage_percent = -1;
                api_error = 1;
            }
        } else {
            king_autoscaling_runtime.spend_status = KING_AUTOSCALING_BUDGET_STATUS_DISABLED;
            king_autoscaling_runtime.spend_usage_percent = -1;
        }

        if (quota_enabled) {
            if (king_autoscaling_json_extract_percent_with_keys(
                ZSTR_VAL(response.s),
                quota_keys,
                quota_key_count,
                &quota_usage
            ) == SUCCESS) {
                king_autoscaling_runtime.quota_usage_percent = quota_usage;
                if (quota_usage < king_autoscaling_runtime.config.quota_warning_threshold_percent) {
                    king_autoscaling_runtime.quota_status = KING_AUTOSCALING_BUDGET_STATUS_OK;
                } else if (
                    king_autoscaling_runtime.config.quota_hard_limit_percent > 0
                    && quota_usage >= king_autoscaling_runtime.config.quota_hard_limit_percent
                ) {
                    king_autoscaling_runtime.quota_status = KING_AUTOSCALING_BUDGET_STATUS_HARD_LIMIT;
                    decision = KING_AUTOSCALING_HETZNER_BUDGET_DECISION_HARD_LIMIT;
                } else {
                    king_autoscaling_runtime.quota_status = KING_AUTOSCALING_BUDGET_STATUS_WARNING;
                    if (decision != KING_AUTOSCALING_HETZNER_BUDGET_DECISION_HARD_LIMIT) {
                        decision = KING_AUTOSCALING_HETZNER_BUDGET_DECISION_WARNING;
                    }
                }
            } else {
                king_autoscaling_runtime.quota_status = KING_AUTOSCALING_BUDGET_STATUS_API_ERROR;
                king_autoscaling_runtime.quota_usage_percent = -1;
                api_error = 1;
            }
        } else {
            king_autoscaling_runtime.quota_status = KING_AUTOSCALING_BUDGET_STATUS_DISABLED;
            king_autoscaling_runtime.quota_usage_percent = -1;
        }
    } else {
        king_autoscaling_runtime.spend_status = KING_AUTOSCALING_BUDGET_STATUS_API_ERROR;
        king_autoscaling_runtime.quota_status = KING_AUTOSCALING_BUDGET_STATUS_API_ERROR;
        king_autoscaling_runtime.spend_usage_percent = -1;
        king_autoscaling_runtime.quota_usage_percent = -1;
        api_error = 1;
        snprintf(
            king_autoscaling_runtime.budget_probe_error,
            sizeof(king_autoscaling_runtime.budget_probe_error),
            "Hetzner budget API response did not include expected fields."
        );
    }

    if (response.s != NULL) {
        smart_str_free(&response);
    }

    if (decision == KING_AUTOSCALING_HETZNER_BUDGET_DECISION_HARD_LIMIT) {
        king_autoscaling_runtime.budget_probe_error[0] = '\0';
        return decision;
    }

    if (
        decision != KING_AUTOSCALING_HETZNER_BUDGET_DECISION_WARNING
        && api_error == 1
    ) {
        king_autoscaling_runtime.budget_probe_error[0] = '\0';
        snprintf(
            king_autoscaling_runtime.budget_probe_error,
            sizeof(king_autoscaling_runtime.budget_probe_error),
            "Hetzner budget API response did not include all expected fields."
        );
        return KING_AUTOSCALING_HETZNER_BUDGET_DECISION_API_ERROR;
    }

    king_autoscaling_runtime.budget_probe_error[0] = '\0';
    return KING_AUTOSCALING_HETZNER_BUDGET_DECISION_ALLOW;
}

