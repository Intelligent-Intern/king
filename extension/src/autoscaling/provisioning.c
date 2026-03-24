/*
 * src/autoscaling/provisioning.c - Autoscaling Provisioning Backends
 * =========================================================================
 */
#include "php_king.h"
#include "include/autoscaling/autoscaling.h"
#include "src/autoscaling/autoscaling_internal.h"

#include "Zend/zend_smart_str.h"

#include <curl/curl.h>
#include <dlfcn.h>
#include <errno.h>
#include <stdio.h>
#include <strings.h>
#include <time.h>

#define KING_AUTOSCALING_HTTP_TIMEOUT_MS 15000L

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

static size_t king_autoscaling_http_write_callback(
    char *contents,
    size_t size,
    size_t nmemb,
    void *userdata)
{
    size_t bytes = size * nmemb;
    king_autoscaling_http_buffer_t *buffer = userdata;

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

static void king_autoscaling_build_node_name(char *buffer, size_t buffer_size)
{
    const char *prefix = king_autoscaling_runtime.config.server_name_prefix;

    snprintf(
        buffer,
        buffer_size,
        "%s-%ld-%zu",
        (prefix != NULL && prefix[0] != '\0') ? prefix : "king-node",
        (long) time(NULL),
        king_autoscaling_runtime.managed_node_count + 1
    );
}

static size_t king_autoscaling_runtime_count_live_nodes(void)
{
    size_t index;
    size_t live_nodes = 0;

    for (index = 0; index < king_autoscaling_runtime.managed_node_count; index++) {
        king_autoscaling_managed_node_t *node = &king_autoscaling_runtime.managed_nodes[index];
        if (
            node->lifecycle_state != KING_AUTOSCALING_NODE_DELETED
            && node->deleted_at == 0
        ) {
            live_nodes++;
        }
    }

    return live_nodes;
}

static size_t king_autoscaling_runtime_count_deletable_nodes(void)
{
    size_t index;
    size_t deletable_nodes = 0;

    for (index = 0; index < king_autoscaling_runtime.managed_node_count; index++) {
        king_autoscaling_managed_node_t *node = &king_autoscaling_runtime.managed_nodes[index];

        if (node->deleted_at > 0 || node->lifecycle_state == KING_AUTOSCALING_NODE_DELETED) {
            continue;
        }

        if (
            node->lifecycle_state == KING_AUTOSCALING_NODE_PROVISIONED
            || node->lifecycle_state == KING_AUTOSCALING_NODE_REGISTERED
            || node->lifecycle_state == KING_AUTOSCALING_NODE_DRAINING
        ) {
            deletable_nodes++;
        }
    }

    return deletable_nodes;
}

static king_autoscaling_managed_node_t *king_autoscaling_runtime_pick_delete_candidate(void)
{
    size_t index = king_autoscaling_runtime.managed_node_count;
    king_autoscaling_managed_node_t *node = king_autoscaling_runtime_pick_draining_node();

    if (node != NULL) {
        return node;
    }

    while (index > 0) {
        index--;
        node = &king_autoscaling_runtime.managed_nodes[index];
        if (node->deleted_at > 0 || node->lifecycle_state == KING_AUTOSCALING_NODE_DELETED) {
            continue;
        }
        if (
            node->lifecycle_state == KING_AUTOSCALING_NODE_PROVISIONED
            || node->lifecycle_state == KING_AUTOSCALING_NODE_REGISTERED
        ) {
            return node;
        }
    }

    return NULL;
}

static uint32_t king_autoscaling_cap_scale_up(uint32_t requested)
{
    uint32_t capped;
    zend_long max_nodes = king_autoscaling_runtime.config.max_nodes;
    uint32_t current_total = (uint32_t) (1 + king_autoscaling_runtime_count_live_nodes());

    if (requested == 0) {
        return 0;
    }

    capped = requested;
    if ((zend_long) current_total >= max_nodes) {
        return 0;
    }

    if ((zend_long) (current_total + capped) > max_nodes) {
        capped = (uint32_t) (max_nodes - current_total);
    }

    return capped;
}

static uint32_t king_autoscaling_cap_scale_down(uint32_t requested)
{
    uint32_t capped;
    size_t active_nodes = king_autoscaling_runtime_count_active_nodes();
    size_t minimum_managed = king_autoscaling_runtime.config.min_nodes > 1
        ? (size_t) (king_autoscaling_runtime.config.min_nodes - 1)
        : 0;

    if (requested == 0) {
        return 0;
    }

    capped = requested;
    if (active_nodes <= minimum_managed) {
        return 0;
    }

    if ((size_t) capped > (active_nodes - minimum_managed)) {
        capped = (uint32_t) (active_nodes - minimum_managed);
    }

    return capped;
}

static zend_result king_autoscaling_simulate_scale_up(uint32_t count)
{
    uint32_t index;

    for (index = 0; index < count; index++) {
        char node_name[128];
        zend_long server_id;

        king_autoscaling_build_node_name(node_name, sizeof(node_name));
        server_id = (zend_long) (time(NULL) * 1000 + king_autoscaling_runtime.managed_node_count + 1);

        if (king_autoscaling_runtime_append_node(server_id, node_name, "simulated_running", time(NULL), 1) != SUCCESS) {
            return FAILURE;
        }
    }

    return SUCCESS;
}

static zend_result king_autoscaling_simulate_scale_down(uint32_t count)
{
    uint32_t index;

    for (index = 0; index < count; index++) {
        king_autoscaling_managed_node_t *node = king_autoscaling_runtime_pick_active_node();
        if (node == NULL) {
            break;
        }

        node->active = 0;
        node->lifecycle_state = KING_AUTOSCALING_NODE_DELETED;
        node->deleted_at = time(NULL);
        snprintf(node->provider_status, sizeof(node->provider_status), "%s", "deleted");
    }

    return SUCCESS;
}

static zend_result king_autoscaling_hetzner_scale_up(uint32_t count)
{
    uint32_t index;
    const char *token = king_autoscaling_runtime.config.hetzner_api_token;

    if (token == NULL || token[0] == '\0') {
        snprintf(
            king_autoscaling_runtime.last_error,
            sizeof(king_autoscaling_runtime.last_error),
            "Hetzner autoscaling requires king.cluster_autoscale_hetzner_api_token on the controller node."
        );
        return FAILURE;
    }

    for (index = 0; index < count; index++) {
        char node_name[128];
        char url[512];
        char server_status[32] = "running";
        zend_long server_id = 0;
        long http_code = 0;
        smart_str payload = {0};
        smart_str response = {0};

        king_autoscaling_build_node_name(node_name, sizeof(node_name));

        if (king_autoscaling_build_hetzner_create_payload(node_name, &payload) != SUCCESS) {
            smart_str_free(&payload);
            return FAILURE;
        }

        snprintf(
            url,
            sizeof(url),
            "%s/servers",
            king_autoscaling_runtime.config.api_endpoint
        );

        if (king_autoscaling_http_request(
            "POST",
            url,
            token,
            payload.s != NULL ? ZSTR_VAL(payload.s) : NULL,
            &http_code,
            &response
        ) != SUCCESS) {
            smart_str_free(&payload);
            smart_str_free(&response);
            return FAILURE;
        }

        smart_str_free(&payload);

        if (http_code < 200 || http_code >= 300) {
            snprintf(
                king_autoscaling_runtime.last_error,
                sizeof(king_autoscaling_runtime.last_error),
                "Hetzner create server request returned HTTP %ld.",
                http_code
            );
            smart_str_free(&response);
            return FAILURE;
        }

        if (
            response.s == NULL
            || king_autoscaling_json_extract_long(ZSTR_VAL(response.s), "id", &server_id) != SUCCESS
        ) {
            snprintf(
                king_autoscaling_runtime.last_error,
                sizeof(king_autoscaling_runtime.last_error),
                "Hetzner create server response did not include server.id."
            );
            smart_str_free(&response);
            return FAILURE;
        }

        if (response.s != NULL) {
            king_autoscaling_json_extract_string(
                ZSTR_VAL(response.s),
                "status",
                server_status,
                sizeof(server_status)
            );
        }

        if (king_autoscaling_runtime_append_node(server_id, node_name, server_status, time(NULL), 0) != SUCCESS) {
            smart_str_free(&response);
            return FAILURE;
        }

        smart_str_free(&response);
    }

    return SUCCESS;
}

static zend_result king_autoscaling_hetzner_scale_down(uint32_t count)
{
    uint32_t index;
    const char *token = king_autoscaling_runtime.config.hetzner_api_token;

    if (token == NULL || token[0] == '\0') {
        snprintf(
            king_autoscaling_runtime.last_error,
            sizeof(king_autoscaling_runtime.last_error),
            "Hetzner autoscaling requires king.cluster_autoscale_hetzner_api_token on the controller node."
        );
        return FAILURE;
    }

    for (index = 0; index < count; index++) {
        char url[512];
        long http_code = 0;
        smart_str response = {0};
        king_autoscaling_managed_node_t *node = king_autoscaling_runtime_pick_delete_candidate();

        if (node == NULL) {
            break;
        }

        snprintf(
            url,
            sizeof(url),
            "%s/servers/%ld",
            king_autoscaling_runtime.config.api_endpoint,
            (long) node->server_id
        );

        if (king_autoscaling_http_request("DELETE", url, token, NULL, &http_code, &response) != SUCCESS) {
            smart_str_free(&response);
            return FAILURE;
        }

        smart_str_free(&response);

        if (http_code < 200 || http_code >= 300) {
            snprintf(
                king_autoscaling_runtime.last_error,
                sizeof(king_autoscaling_runtime.last_error),
                "Hetzner delete server request for %ld returned HTTP %ld.",
                (long) node->server_id,
                http_code
            );
            return FAILURE;
        }

        node->active = 0;
        node->lifecycle_state = KING_AUTOSCALING_NODE_DELETED;
        node->deleted_at = time(NULL);
        snprintf(node->provider_status, sizeof(node->provider_status), "%s", "deleted");
    }

    return SUCCESS;
}

int king_autoscaling_provider_scale_up(uint32_t count)
{
    uint32_t capped_count = king_autoscaling_cap_scale_up(count);

    king_autoscaling_runtime.last_error[0] = '\0';
    king_autoscaling_runtime.last_warning[0] = '\0';

    if (capped_count == 0) {
        snprintf(
            king_autoscaling_runtime.last_warning,
            sizeof(king_autoscaling_runtime.last_warning),
            "Scale-up request did not add nodes because the cluster is already at its configured limit."
        );
        return SUCCESS;
    }

    switch (king_autoscaling_runtime.provider_kind) {
        case KING_AUTOSCALING_PROVIDER_HETZNER:
            if (king_autoscaling_hetzner_scale_up(capped_count) != SUCCESS) {
                return FAILURE;
            }
            break;
        case KING_AUTOSCALING_PROVIDER_SIMULATED:
        case KING_AUTOSCALING_PROVIDER_NONE:
        default:
            if (king_autoscaling_simulate_scale_up(capped_count) != SUCCESS) {
                return FAILURE;
            }
            break;
    }

    king_autoscaling_runtime.action_count += capped_count;
    king_autoscaling_runtime.last_scale_up_at = time(NULL);
    snprintf(
        king_autoscaling_runtime.last_action_kind,
        sizeof(king_autoscaling_runtime.last_action_kind),
        "scale_up"
    );

    king_autoscaling_runtime_sync_instance_count();
    king_autoscaling_runtime_persist_state();
    return SUCCESS;
}

int king_autoscaling_provider_scale_down(uint32_t count)
{
    uint32_t capped_count;

    king_autoscaling_runtime.last_error[0] = '\0';
    king_autoscaling_runtime.last_warning[0] = '\0';

    switch (king_autoscaling_runtime.provider_kind) {
        case KING_AUTOSCALING_PROVIDER_HETZNER:
            capped_count = (uint32_t) king_autoscaling_runtime_count_deletable_nodes();
            if (count < capped_count) {
                capped_count = count;
            }

            if (capped_count == 0) {
                size_t active_nodes = king_autoscaling_runtime_count_active_nodes();
                size_t minimum_managed = king_autoscaling_runtime.config.min_nodes > 1
                    ? (size_t) (king_autoscaling_runtime.config.min_nodes - 1)
                    : 0;

                if (active_nodes > minimum_managed) {
                    snprintf(
                        king_autoscaling_runtime.last_warning,
                        sizeof(king_autoscaling_runtime.last_warning),
                        "Scale-down requires a drained or non-admitted Hetzner node; call king_autoscaling_drain_node() first."
                    );
                } else {
                    snprintf(
                        king_autoscaling_runtime.last_warning,
                        sizeof(king_autoscaling_runtime.last_warning),
                        "Scale-down request did not remove nodes because the cluster is already at its configured floor."
                    );
                }
                return SUCCESS;
            }

            if (king_autoscaling_hetzner_scale_down(capped_count) != SUCCESS) {
                return FAILURE;
            }
            break;
        case KING_AUTOSCALING_PROVIDER_SIMULATED:
        case KING_AUTOSCALING_PROVIDER_NONE:
        default:
            capped_count = king_autoscaling_cap_scale_down(count);
            if (capped_count == 0) {
                snprintf(
                    king_autoscaling_runtime.last_warning,
                    sizeof(king_autoscaling_runtime.last_warning),
                    "Scale-down request did not remove nodes because the cluster is already at its configured floor."
                );
                return SUCCESS;
            }
            if (king_autoscaling_simulate_scale_down(capped_count) != SUCCESS) {
                return FAILURE;
            }
            break;
    }

    king_autoscaling_runtime.action_count += capped_count;
    king_autoscaling_runtime.last_scale_down_at = time(NULL);
    snprintf(
        king_autoscaling_runtime.last_action_kind,
        sizeof(king_autoscaling_runtime.last_action_kind),
        "scale_down"
    );

    king_autoscaling_runtime_sync_instance_count();
    king_autoscaling_runtime_persist_state();
    return SUCCESS;
}

PHP_FUNCTION(king_autoscaling_scale_up)
{
    zend_long instances = 1;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(instances)
    ZEND_PARSE_PARAMETERS_END();

    if (instances <= 0) {
        RETURN_FALSE;
    }

    if (king_autoscaling_provider_scale_up((uint32_t) instances) == SUCCESS) {
        RETURN_TRUE;
    }

    RETURN_FALSE;
}

PHP_FUNCTION(king_autoscaling_scale_down)
{
    zend_long instances = 1;

    ZEND_PARSE_PARAMETERS_START(0, 1)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(instances)
    ZEND_PARSE_PARAMETERS_END();

    if (instances <= 0) {
        RETURN_FALSE;
    }

    if (king_autoscaling_provider_scale_down((uint32_t) instances) == SUCCESS) {
        RETURN_TRUE;
    }

    RETURN_FALSE;
}
