/*
 * Azure runtime/config slice. Normalizes endpoint and credential settings and
 * provides the shared HTTP/runtime helpers used by the Azure adapter paths.
 */

typedef struct _king_object_store_azure_runtime_config_t {
    char endpoint[512];
    char container[256];
    char access_token[1024];
    zend_bool verify_tls;
} king_object_store_azure_runtime_config_t;

static zend_result king_object_store_azure_resolve_config(
    king_object_store_azure_runtime_config_t *config,
    char *error,
    size_t error_size)
{
    zval *value;
    char account_name[128];
    size_t endpoint_len;

    if (config == NULL || error == NULL || error_size == 0) {
        return FAILURE;
    }

    memset(config, 0, sizeof(*config));
    memset(account_name, 0, sizeof(account_name));
    error[0] = '\0';

    if (Z_TYPE(king_object_store_runtime.config.cloud_credentials) != IS_ARRAY) {
        snprintf(error, error_size, "cloud_credentials must be configured for cloud_azure.");
        return FAILURE;
    }

    if (king_object_store_runtime.config.storage_root_path[0] == '\0') {
        snprintf(error, error_size, "storage_root_path is required for cloud_azure metadata sidecars.");
        return FAILURE;
    }

    value = king_object_store_cloud_credentials_find("api_endpoint");
    if (value == NULL) {
        value = king_object_store_cloud_credentials_find("endpoint");
    }
    if (value == NULL) {
        if (king_object_store_copy_cloud_string(
                king_object_store_cloud_credentials_find("account_name"),
                "account_name",
                account_name,
                sizeof(account_name),
                1,
                error,
                error_size
            ) != SUCCESS) {
            return FAILURE;
        }

        snprintf(
            config->endpoint,
            sizeof(config->endpoint),
            "https://%s.blob.core.windows.net",
            account_name
        );
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

    value = king_object_store_cloud_credentials_find("container");
    if (value == NULL) {
        value = king_object_store_cloud_credentials_find("bucket");
    }
    if (king_object_store_copy_cloud_string(
            value,
            "container",
            config->container,
            sizeof(config->container),
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

static zend_result king_object_store_azure_build_url(
    const king_object_store_azure_runtime_config_t *config,
    const char *object_id,
    zend_bool list_bucket,
    smart_str *url,
    char *error,
    size_t error_size)
{
    if (config == NULL || url == NULL) {
        snprintf(error, error_size, "Failed to build cloud_azure URL.");
        return FAILURE;
    }

    smart_str_appends(url, config->endpoint);
    smart_str_appendc(url, '/');
    smart_str_appends(url, config->container);

    if (list_bucket) {
        smart_str_appends(url, "?restype=container&comp=list");
    } else if (object_id != NULL && object_id[0] != '\0') {
        smart_str_appendc(url, '/');
        king_object_store_url_encode_component(url, object_id);
    }

    smart_str_0(url);
    return SUCCESS;
}

