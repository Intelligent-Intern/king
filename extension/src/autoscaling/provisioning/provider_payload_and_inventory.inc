static void king_autoscaling_json_append_optional_tags(
    smart_str *buffer,
    zend_bool *first_field,
    const char *instance_tags)
{
    char *work;
    char *saveptr = NULL;
    char *pair;
    zend_bool first_label = 1;

    if (!*first_field) {
        smart_str_appendc(buffer, ',');
    }
    *first_field = 0;

    smart_str_appendl(buffer, "\"labels\":{", 10);
    king_autoscaling_json_append_kv_string(buffer, &first_label, "managed_by", "king");
    king_autoscaling_json_append_kv_string(buffer, &first_label, "managed_runtime", "autoscaling");

    if (instance_tags == NULL || instance_tags[0] == '\0') {
        smart_str_appendc(buffer, '}');
        return;
    }

    work = estrdup(instance_tags);
    pair = strtok_r(work, ",", &saveptr);
    while (pair != NULL) {
        char *separator = strchr(pair, '=');
        if (separator != NULL) {
            *separator = '\0';
            separator++;
            king_autoscaling_trim_token(pair);
            king_autoscaling_trim_token(separator);
            if (pair[0] != '\0' && separator[0] != '\0') {
                king_autoscaling_json_append_kv_string(buffer, &first_label, pair, separator);
            }
        }
        pair = strtok_r(NULL, ",", &saveptr);
    }
    efree(work);

    smart_str_appendc(buffer, '}');
}

static void king_autoscaling_json_append_optional_id_array(
    smart_str *buffer,
    zend_bool *first_field,
    const char *key,
    const char *csv_values,
    zend_bool wrap_in_firewall_objects)
{
    char *work;
    char *saveptr = NULL;
    char *token;
    zend_bool first_item = 1;

    if (csv_values == NULL || csv_values[0] == '\0') {
        return;
    }

    if (!*first_field) {
        smart_str_appendc(buffer, ',');
    }
    *first_field = 0;

    smart_str_appendc(buffer, '"');
    smart_str_appends(buffer, key);
    smart_str_appendl(buffer, "\":[", 3);

    work = estrdup(csv_values);
    token = strtok_r(work, ",", &saveptr);
    while (token != NULL) {
        king_autoscaling_trim_token(token);
        if (token[0] != '\0') {
            if (!first_item) {
                smart_str_appendc(buffer, ',');
            }
            first_item = 0;

            if (wrap_in_firewall_objects) {
                smart_str_appendl(buffer, "{\"firewall\":", 12);
            }

            if (king_autoscaling_string_is_integer(token)) {
                smart_str_appends(buffer, token);
            } else {
                smart_str_appendc(buffer, '"');
                king_autoscaling_json_append_escaped(buffer, token);
                smart_str_appendc(buffer, '"');
            }

            if (wrap_in_firewall_objects) {
                smart_str_appendc(buffer, '}');
            }
        }
        token = strtok_r(NULL, ",", &saveptr);
    }
    efree(work);

    smart_str_appendc(buffer, ']');
}

static void king_autoscaling_json_append_optional_bootstrap(
    smart_str *buffer,
    zend_bool *first_field)
{
    smart_str generated = {0};

    if (
        king_autoscaling_runtime.config.bootstrap_user_data != NULL
        && king_autoscaling_runtime.config.bootstrap_user_data[0] != '\0'
    ) {
        king_autoscaling_json_append_kv_string(
            buffer,
            first_field,
            "user_data",
            king_autoscaling_runtime.config.bootstrap_user_data
        );
        return;
    }

    if (
        king_autoscaling_runtime.config.prepared_release_url == NULL
        || king_autoscaling_runtime.config.prepared_release_url[0] == '\0'
        || king_autoscaling_runtime.config.join_endpoint == NULL
        || king_autoscaling_runtime.config.join_endpoint[0] == '\0'
    ) {
        return;
    }

    smart_str_appends(&generated, "#cloud-config\nruncmd:\n");
    smart_str_appends(&generated, "  - [\"sh\",\"-lc\",\"king-agent join --controller '");
    king_autoscaling_json_append_escaped(&generated, king_autoscaling_runtime.config.join_endpoint);
    smart_str_appends(&generated, "' --release '");
    king_autoscaling_json_append_escaped(
        &generated,
        king_autoscaling_runtime.config.prepared_release_url
    );
    smart_str_appends(&generated, "'\"]\n");
    smart_str_0(&generated);

    king_autoscaling_json_append_kv_string(
        buffer,
        first_field,
        "user_data",
        ZSTR_VAL(generated.s)
    );

    smart_str_free(&generated);
}

static zend_result king_autoscaling_build_hetzner_create_payload(
    const char *node_name,
    smart_str *payload)
{
    zend_bool first_field = 1;

    if (
        king_autoscaling_runtime.config.instance_type == NULL
        || king_autoscaling_runtime.config.instance_type[0] == '\0'
        || king_autoscaling_runtime.config.instance_image_id == NULL
        || king_autoscaling_runtime.config.instance_image_id[0] == '\0'
    ) {
        snprintf(
            king_autoscaling_runtime.last_error,
            sizeof(king_autoscaling_runtime.last_error),
            "Hetzner autoscaling requires both instance_type and instance_image_id."
        );
        return FAILURE;
    }

    smart_str_appendc(payload, '{');
    king_autoscaling_json_append_kv_string(payload, &first_field, "name", node_name);
    king_autoscaling_json_append_kv_string(
        payload,
        &first_field,
        "server_type",
        king_autoscaling_runtime.config.instance_type
    );
    king_autoscaling_json_append_kv_string(
        payload,
        &first_field,
        "image",
        king_autoscaling_runtime.config.instance_image_id
    );

    if (
        king_autoscaling_runtime.config.region != NULL
        && king_autoscaling_runtime.config.region[0] != '\0'
    ) {
        king_autoscaling_json_append_kv_string(
            payload,
            &first_field,
            "location",
            king_autoscaling_runtime.config.region
        );
    }

    if (
        king_autoscaling_runtime.config.placement_group_id != NULL
        && king_autoscaling_runtime.config.placement_group_id[0] != '\0'
        && king_autoscaling_string_is_integer(king_autoscaling_runtime.config.placement_group_id)
    ) {
        king_autoscaling_json_append_kv_long(
            payload,
            &first_field,
            "placement_group",
            ZEND_STRTOL(king_autoscaling_runtime.config.placement_group_id, NULL, 10)
        );
    }

    king_autoscaling_json_append_optional_bootstrap(payload, &first_field);
    king_autoscaling_json_append_optional_tags(
        payload,
        &first_field,
        king_autoscaling_runtime.config.instance_tags
    );
    king_autoscaling_json_append_optional_id_array(
        payload,
        &first_field,
        "networks",
        king_autoscaling_runtime.config.network_config,
        0
    );
    king_autoscaling_json_append_optional_id_array(
        payload,
        &first_field,
        "firewalls",
        king_autoscaling_runtime.config.firewall_ids,
        1
    );

    smart_str_appendc(payload, '}');
    smart_str_0(payload);
    return SUCCESS;
}

static zend_result king_autoscaling_http_request(
    const char *method,
    const char *url,
    const char *token,
    const char *body,
    long *http_code_out,
    smart_str *response_out)
{
    CURL *easy;
    CURLcode curl_code;
    long http_code = 0;
    struct curl_slist *headers = NULL;
    king_autoscaling_http_buffer_t response = {0};
    char auth_header[512];

    if (king_autoscaling_ensure_libcurl_ready() != SUCCESS) {
        snprintf(
            king_autoscaling_runtime.last_error,
            sizeof(king_autoscaling_runtime.last_error),
            "%s",
            king_autoscaling_libcurl.load_error
        );
        return FAILURE;
    }

    easy = king_autoscaling_libcurl.curl_easy_init_fn();
    if (easy == NULL) {
        snprintf(
            king_autoscaling_runtime.last_error,
            sizeof(king_autoscaling_runtime.last_error),
            "Failed to create libcurl handle for autoscaling provider call."
        );
        return FAILURE;
    }

    headers = king_autoscaling_libcurl.curl_slist_append_fn(headers, "Accept: application/json");
    headers = king_autoscaling_libcurl.curl_slist_append_fn(headers, "Content-Type: application/json");
    snprintf(auth_header, sizeof(auth_header), "Authorization: Bearer %s", token);
    headers = king_autoscaling_libcurl.curl_slist_append_fn(headers, auth_header);

    king_autoscaling_libcurl.curl_easy_setopt_fn(easy, CURLOPT_URL, url);
    king_autoscaling_libcurl.curl_easy_setopt_fn(easy, CURLOPT_CUSTOMREQUEST, method);
    king_autoscaling_libcurl.curl_easy_setopt_fn(easy, CURLOPT_HTTPHEADER, headers);
    king_autoscaling_libcurl.curl_easy_setopt_fn(easy, CURLOPT_TIMEOUT_MS, KING_AUTOSCALING_HTTP_TIMEOUT_MS);
    king_autoscaling_libcurl.curl_easy_setopt_fn(easy, CURLOPT_NOSIGNAL, 1L);
    king_autoscaling_libcurl.curl_easy_setopt_fn(easy, CURLOPT_MAXFILESIZE_LARGE, (curl_off_t)KING_AUTOSCALING_HTTP_MAX_RESPONSE_SIZE);
    king_autoscaling_libcurl.curl_easy_setopt_fn(
        easy,
        CURLOPT_WRITEFUNCTION,
        king_autoscaling_http_write_callback
    );
    king_autoscaling_libcurl.curl_easy_setopt_fn(easy, CURLOPT_WRITEDATA, &response);
    king_autoscaling_libcurl.curl_easy_setopt_fn(easy, CURLOPT_USERAGENT, "king-autoscaling/1");

    if (body != NULL) {
        king_autoscaling_libcurl.curl_easy_setopt_fn(easy, CURLOPT_POSTFIELDS, body);
        king_autoscaling_libcurl.curl_easy_setopt_fn(
            easy,
            CURLOPT_POSTFIELDSIZE,
            (long) strlen(body)
        );
    }

    curl_code = king_autoscaling_libcurl.curl_easy_perform_fn(easy);
    if (curl_code != CURLE_OK) {
        snprintf(
            king_autoscaling_runtime.last_error,
            sizeof(king_autoscaling_runtime.last_error),
            "Autoscaling provider HTTP request failed: %s",
            king_autoscaling_libcurl.curl_easy_strerror_fn(curl_code)
        );
        if (headers != NULL) {
            king_autoscaling_libcurl.curl_slist_free_all_fn(headers);
        }
        king_autoscaling_libcurl.curl_easy_cleanup_fn(easy);
        smart_str_free(&response.data);
        return FAILURE;
    }

    king_autoscaling_libcurl.curl_easy_getinfo_fn(easy, CURLINFO_RESPONSE_CODE, &http_code);
    smart_str_0(&response.data);

    if (headers != NULL) {
        king_autoscaling_libcurl.curl_slist_free_all_fn(headers);
    }
    king_autoscaling_libcurl.curl_easy_cleanup_fn(easy);

    if (http_code_out != NULL) {
        *http_code_out = http_code;
    }

    if (response_out != NULL) {
        *response_out = response.data;
    } else {
        smart_str_free(&response.data);
    }

    return SUCCESS;
}

static const char *king_autoscaling_effective_server_name_prefix(void)
{
    const char *prefix = king_autoscaling_runtime.config.server_name_prefix;

    if (prefix != NULL && prefix[0] != '\0') {
        return prefix;
    }

    return "king-node";
}

static zend_bool king_autoscaling_provider_name_matches_prefix(const char *name)
{
    const char *prefix;
    size_t prefix_length;

    if (name == NULL || name[0] == '\0') {
        return 0;
    }

    prefix = king_autoscaling_effective_server_name_prefix();
    prefix_length = strlen(prefix);
    return prefix_length > 0 && strncmp(name, prefix, prefix_length) == 0;
}

static zend_bool king_autoscaling_provider_find_inventory_server(
    const zval *servers,
    zend_long server_id,
    const char **provider_status_out)
{
    zval *entry;
    zend_bool found = 0;

    if (servers == NULL || Z_TYPE_P(servers) != IS_ARRAY || server_id <= 0) {
        return 0;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(servers), entry) {
        zval *id_entry;
        zval *status_entry;
        zend_long inventory_server_id;

        if (Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }

        id_entry = zend_hash_str_find(Z_ARRVAL_P(entry), "id", sizeof("id") - 1);
        if (id_entry == NULL) {
            continue;
        }

        inventory_server_id = zval_get_long(id_entry);
        if (inventory_server_id != server_id) {
            continue;
        }

        if (provider_status_out != NULL) {
            status_entry = zend_hash_str_find(Z_ARRVAL_P(entry), "status", sizeof("status") - 1);
            *provider_status_out = status_entry != NULL && Z_TYPE_P(status_entry) == IS_STRING
                ? Z_STRVAL_P(status_entry)
                : "running";
        }

        found = 1;
        break;
    } ZEND_HASH_FOREACH_END();

    return found;
}

static void king_autoscaling_runtime_mark_node_orphaned(
    king_autoscaling_managed_node_t *node,
    time_t reconciled_at)
{
    if (node == NULL) {
        return;
    }

    node->active = 0;
    node->lifecycle_state = KING_AUTOSCALING_NODE_DELETED;
    node->deleted_at = reconciled_at > 0 ? reconciled_at : time(NULL);
    snprintf(
        node->provider_status,
        sizeof(node->provider_status),
        "%s",
        "provider_disappeared"
    );
}

static const char *king_autoscaling_json_find_server_section(const char *json)
{
    const char *server = strstr(json, "\"server\"");
    if (server == NULL) {
        return json;
    }

    server = strchr(server, '{');
    return server != NULL ? server : json;
}

static zend_result king_autoscaling_json_extract_long(
    const char *json,
    const char *key,
    zend_long *value_out)
{
    char needle[64];
    const char *section;
    const char *cursor;

    snprintf(needle, sizeof(needle), "\"%s\"", key);
    section = king_autoscaling_json_find_server_section(json);
    cursor = strstr(section, needle);
    if (cursor == NULL) {
        return FAILURE;
    }

    cursor = strchr(cursor, ':');
    if (cursor == NULL) {
        return FAILURE;
    }
    cursor++;
    while (*cursor == ' ' || *cursor == '\t') {
        cursor++;
    }

    *value_out = ZEND_STRTOL(cursor, NULL, 10);
    return SUCCESS;
}

static zend_result king_autoscaling_json_extract_string(
    const char *json,
    const char *key,
    char *buffer,
    size_t buffer_size)
{
    char needle[64];
    const char *section;
    const char *cursor;
    const char *start;
    const char *end;
    size_t length;

    if (buffer == NULL || buffer_size == 0) {
        return FAILURE;
    }

    snprintf(needle, sizeof(needle), "\"%s\"", key);
    section = king_autoscaling_json_find_server_section(json);
    cursor = strstr(section, needle);
    if (cursor == NULL) {
        return FAILURE;
    }

    cursor = strchr(cursor, ':');
    if (cursor == NULL) {
        return FAILURE;
    }

    start = strchr(cursor, '"');
    if (start == NULL) {
        return FAILURE;
    }
    start++;
    end = strchr(start, '"');
    if (end == NULL) {
        return FAILURE;
    }

    length = (size_t) (end - start);
    if (length >= buffer_size) {
        length = buffer_size - 1;
    }

    memcpy(buffer, start, length);
    buffer[length] = '\0';
    return SUCCESS;
}

static void king_autoscaling_set_reconcile_warning(const char *message)
{
    snprintf(
        king_autoscaling_runtime.last_warning,
        sizeof(king_autoscaling_runtime.last_warning),
        "%s",
        message != NULL ? message : ""
    );
}

int king_autoscaling_provider_reconcile_inventory(void)
{
    char url[512];
    long http_code = 0;
    smart_str response = {0};
    zval decoded = {0};
    zval *servers = NULL;
    zval *entry;
    const char *token = king_autoscaling_runtime.config.hetzner_api_token;
    zend_bool recovered_any = 0;
    size_t added_nodes = 0;
    size_t reconciled_nodes = 0;
    size_t pending_missing_nodes = 0;
    time_t reconciled_at = time(NULL);
    size_t index;

    if (
        king_autoscaling_runtime.provider_kind != KING_AUTOSCALING_PROVIDER_HETZNER
    ) {
        return SUCCESS;
    }

    if (
        token == NULL || token[0] == '\0'
        || king_autoscaling_runtime.config.api_endpoint == NULL
        || king_autoscaling_runtime.config.api_endpoint[0] == '\0'
    ) {
        return SUCCESS;
    }

    snprintf(
        url,
        sizeof(url),
        "%s/servers",
        king_autoscaling_runtime.config.api_endpoint
    );

    if (king_autoscaling_http_request("GET", url, token, NULL, &http_code, &response) != SUCCESS) {
        char warning[256];

        snprintf(
            warning,
            sizeof(warning),
            "Hetzner inventory reconcile skipped: %s",
            king_autoscaling_runtime.last_error
        );
        king_autoscaling_set_reconcile_warning(warning);
        king_autoscaling_runtime.last_error[0] = '\0';
        smart_str_free(&response);
        return SUCCESS;
    }

    if (http_code < 200 || http_code >= 300 || response.s == NULL) {
        char warning[256];

        snprintf(
            warning,
            sizeof(warning),
            "Hetzner inventory reconcile skipped: list servers returned HTTP %ld.",
            http_code
        );
        king_autoscaling_set_reconcile_warning(warning);
        smart_str_free(&response);
        return SUCCESS;
    }

    if (php_json_decode(&decoded, ZSTR_VAL(response.s), ZSTR_LEN(response.s), 1, 128) != SUCCESS) {
        king_autoscaling_set_reconcile_warning(
            "Hetzner inventory reconcile skipped: list servers returned invalid JSON."
        );
        smart_str_free(&response);
        return SUCCESS;
    }

    smart_str_free(&response);

    if (Z_TYPE(decoded) != IS_ARRAY) {
        zval_ptr_dtor(&decoded);
        king_autoscaling_set_reconcile_warning(
            "Hetzner inventory reconcile skipped: list servers returned a malformed payload."
        );
        return SUCCESS;
    }

    servers = zend_hash_str_find(Z_ARRVAL(decoded), "servers", sizeof("servers") - 1);
    if (servers == NULL || Z_TYPE_P(servers) != IS_ARRAY) {
        zval_ptr_dtor(&decoded);
        king_autoscaling_set_reconcile_warning(
            "Hetzner inventory reconcile skipped: list servers did not include a servers array."
        );
        return SUCCESS;
    }

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(servers), entry) {
        zval *id_entry;
        zval *name_entry;
        zval *status_entry;
        zend_long server_id;

        if (Z_TYPE_P(entry) != IS_ARRAY) {
            continue;
        }

        id_entry = zend_hash_str_find(Z_ARRVAL_P(entry), "id", sizeof("id") - 1);
        name_entry = zend_hash_str_find(Z_ARRVAL_P(entry), "name", sizeof("name") - 1);
        status_entry = zend_hash_str_find(Z_ARRVAL_P(entry), "status", sizeof("status") - 1);
        if (id_entry == NULL || name_entry == NULL) {
            continue;
        }
        if (Z_TYPE_P(name_entry) != IS_STRING) {
            continue;
        }
        if (!king_autoscaling_provider_name_matches_prefix(Z_STRVAL_P(name_entry))) {
            continue;
        }

        server_id = zval_get_long(id_entry);
        if (server_id <= 0 || king_autoscaling_runtime_find_node(server_id) != NULL) {
            continue;
        }

        if (king_autoscaling_runtime_append_node(
            server_id,
            Z_STRVAL_P(name_entry),
            status_entry != NULL && Z_TYPE_P(status_entry) == IS_STRING
                ? Z_STRVAL_P(status_entry)
                : "running",
            reconciled_at,
            0
        ) != SUCCESS) {
            zval_ptr_dtor(&decoded);
            return FAILURE;
        }
        recovered_any = 1;
        added_nodes++;
    } ZEND_HASH_FOREACH_END();

    for (index = 0; index < king_autoscaling_runtime.managed_node_count; index++) {
        king_autoscaling_managed_node_t *node = &king_autoscaling_runtime.managed_nodes[index];
        const char *provider_status = NULL;

        if (
            node->deleted_at > 0
            || node->lifecycle_state == KING_AUTOSCALING_NODE_DELETED
        ) {
            continue;
        }

        if (!king_autoscaling_provider_name_matches_prefix(node->name)) {
            continue;
        }

        if (!king_autoscaling_provider_find_inventory_server(servers, node->server_id, &provider_status)) {
            if (
                node->lifecycle_state == KING_AUTOSCALING_NODE_READY
                || node->lifecycle_state == KING_AUTOSCALING_NODE_DRAINING
            ) {
                king_autoscaling_runtime_mark_node_orphaned(node, reconciled_at);
                reconciled_nodes++;
            } else {
                pending_missing_nodes++;
            }
            recovered_any = 1;
            continue;
        }

        if (
            provider_status != NULL
            && provider_status[0] != '\0'
            && strcmp(node->provider_status, provider_status) != 0
        ) {
            snprintf(
                node->provider_status,
                sizeof(node->provider_status),
                "%s",
                provider_status
            );
            recovered_any = 1;
        }
    }

    zval_ptr_dtor(&decoded);

    if (recovered_any) {
        char warning[256];

        king_autoscaling_runtime_sync_instance_count();
        if (king_autoscaling_runtime_persist_state() != SUCCESS) {
            return FAILURE;
        }

        if (added_nodes > 0 || reconciled_nodes > 0 || pending_missing_nodes > 0) {
            snprintf(
                warning,
                sizeof(warning),
                "Reconciled Hetzner inventory: +%zu added, +%zu orphaned, +%zu pending-missing",
                added_nodes,
                reconciled_nodes,
                pending_missing_nodes
            );
        } else {
            snprintf(
                warning,
                sizeof(warning),
                "Reconciled Hetzner inventory state with no delta."
            );
        }
        king_autoscaling_set_reconcile_warning(warning);
    }

    king_autoscaling_runtime.state_load_incomplete = 0;
    return SUCCESS;
}
