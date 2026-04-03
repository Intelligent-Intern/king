/*
 * GCS runtime/config slice. Normalizes endpoint, bucket and auth settings and
 * provides the shared HTTP/runtime helpers used by the GCS adapter paths.
 */

typedef struct _king_object_store_gcs_runtime_config_t {
    char endpoint[512];
    char bucket[256];
    char access_token[1024];
    zend_bool path_style;
    zend_bool verify_tls;
} king_object_store_gcs_runtime_config_t;

static zend_result king_object_store_gcs_resolve_config(
    king_object_store_gcs_runtime_config_t *config,
    char *error,
    size_t error_size)
{
    zval *value;
    size_t endpoint_len;

    if (config == NULL || error == NULL || error_size == 0) {
        return FAILURE;
    }

    memset(config, 0, sizeof(*config));
    error[0] = '\0';

    if (Z_TYPE(king_object_store_runtime.config.cloud_credentials) != IS_ARRAY) {
        snprintf(error, error_size, "cloud_credentials must be configured for cloud_gcs.");
        return FAILURE;
    }

    if (king_object_store_runtime.config.storage_root_path[0] == '\0') {
        snprintf(error, error_size, "storage_root_path is required for cloud_gcs metadata sidecars.");
        return FAILURE;
    }

    value = king_object_store_cloud_credentials_find("api_endpoint");
    if (value == NULL) {
        value = king_object_store_cloud_credentials_find("endpoint");
    }
    if (value == NULL) {
        snprintf(config->endpoint, sizeof(config->endpoint), "%s", "https://storage.googleapis.com");
    } else if (king_object_store_copy_cloud_string(
            value,
            "api_endpoint",
            config->endpoint,
            sizeof(config->endpoint),
            1,
            error,
            error_size
        ) != SUCCESS) {
        return FAILURE;
    }

    endpoint_len = strlen(config->endpoint);
    while (endpoint_len > 0 && config->endpoint[endpoint_len - 1] == '/') {
        config->endpoint[endpoint_len - 1] = '\0';
        endpoint_len--;
    }

    if (king_object_store_copy_cloud_string(
            king_object_store_cloud_credentials_find("bucket"),
            "bucket",
            config->bucket,
            sizeof(config->bucket),
            1,
            error,
            error_size
        ) != SUCCESS) {
        return FAILURE;
    }

    value = king_object_store_cloud_credentials_find("access_token");
    if (value == NULL) {
        value = king_object_store_cloud_credentials_find("bearer_token");
    }
    if (value == NULL) {
        value = king_object_store_cloud_credentials_find("oauth_token");
    }
    if (king_object_store_copy_cloud_string(
            value,
            "access_token",
            config->access_token,
            sizeof(config->access_token),
            1,
            error,
            error_size
        ) != SUCCESS) {
        return FAILURE;
    }

    if (king_object_store_copy_cloud_bool(
            king_object_store_cloud_credentials_find("path_style"),
            "path_style",
            1,
            &config->path_style,
            error,
            error_size
        ) != SUCCESS) {
        return FAILURE;
    }

    if (king_object_store_copy_cloud_bool(
            king_object_store_cloud_credentials_find("verify_tls"),
            "verify_tls",
            1,
            &config->verify_tls,
            error,
            error_size
        ) != SUCCESS) {
        return FAILURE;
    }

    return SUCCESS;
}

static zend_result king_object_store_gcs_build_url(
    const king_object_store_gcs_runtime_config_t *config,
    const char *object_id,
    zend_bool list_bucket,
    smart_str *url,
    char *error,
    size_t error_size)
{
    const char *scheme_sep;

    if (config == NULL || url == NULL) {
        snprintf(error, error_size, "Failed to build cloud_gcs URL.");
        return FAILURE;
    }

    smart_str_appends(url, config->endpoint);

    if (config->path_style) {
        smart_str_appendc(url, '/');
        smart_str_appends(url, config->bucket);
        if (object_id != NULL && object_id[0] != '\0') {
            smart_str_appendc(url, '/');
            king_object_store_url_encode_component(url, object_id);
        }
    } else {
        scheme_sep = strstr(config->endpoint, "://");
        if (scheme_sep == NULL) {
            snprintf(error, error_size, "cloud_gcs endpoint must include a URL scheme.");
            return FAILURE;
        }

        smart_str_free(url);
        smart_str_appends(url, "");
        smart_str_appendl(url, config->endpoint, (size_t) (scheme_sep - config->endpoint + 3));
        smart_str_appends(url, config->bucket);
        smart_str_appendc(url, '.');
        smart_str_appends(url, scheme_sep + 3);
        if (object_id != NULL && object_id[0] != '\0') {
            smart_str_appendc(url, '/');
            king_object_store_url_encode_component(url, object_id);
        }
    }

    if (list_bucket) {
        smart_str_appendc(url, '/');
    }

    smart_str_0(url);
    return SUCCESS;
}

