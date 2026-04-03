static zval *king_object_store_cloud_credentials_find(const char *key)
{
    zval *credentials;

    credentials = &king_object_store_runtime.config.cloud_credentials;
    if (Z_TYPE_P(credentials) != IS_ARRAY) {
        return NULL;
    }

    return zend_hash_str_find(
        Z_ARRVAL_P(credentials),
        key,
        strlen(key)
    );
}

static zend_result king_object_store_copy_cloud_string(
    zval *source,
    const char *field_name,
    char *destination,
    size_t destination_size,
    zend_bool required,
    char *error,
    size_t error_size)
{
    if (source == NULL) {
        if (required) {
            snprintf(error, error_size, "cloud_credentials.%s is required for cloud_s3.", field_name);
            return FAILURE;
        }
        return SUCCESS;
    }

    if (Z_TYPE_P(source) != IS_STRING || Z_STRLEN_P(source) == 0) {
        snprintf(error, error_size, "cloud_credentials.%s must be a non-empty string for cloud_s3.", field_name);
        return FAILURE;
    }

    if ((size_t) Z_STRLEN_P(source) >= destination_size) {
        snprintf(error, error_size, "cloud_credentials.%s exceeds the maximum supported length for cloud_s3.", field_name);
        return FAILURE;
    }

    snprintf(destination, destination_size, "%s", Z_STRVAL_P(source));
    return SUCCESS;
}

static zend_result king_object_store_copy_cloud_bool(
    zval *source,
    const char *field_name,
    zend_bool default_value,
    zend_bool *destination,
    char *error,
    size_t error_size)
{
    if (source == NULL) {
        *destination = default_value;
        return SUCCESS;
    }

    if (Z_TYPE_P(source) != IS_TRUE && Z_TYPE_P(source) != IS_FALSE) {
        snprintf(error, error_size, "cloud_credentials.%s must be a boolean for cloud_s3.", field_name);
        return FAILURE;
    }

    *destination = (Z_TYPE_P(source) == IS_TRUE) ? 1 : 0;
    return SUCCESS;
}

static zend_result king_object_store_s3_resolve_config(
    king_object_store_s3_runtime_config_t *config,
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
        snprintf(error, error_size, "cloud_credentials must be configured for cloud_s3.");
        return FAILURE;
    }

    if (king_object_store_runtime.config.storage_root_path[0] == '\0') {
        snprintf(error, error_size, "storage_root_path is required for cloud_s3 metadata sidecars.");
        return FAILURE;
    }

    value = king_object_store_cloud_credentials_find("api_endpoint");
    if (value == NULL) {
        value = king_object_store_cloud_credentials_find("endpoint");
    }
    if (king_object_store_copy_cloud_string(value, "api_endpoint", config->endpoint, sizeof(config->endpoint), 1, error, error_size) != SUCCESS) {
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

    if (king_object_store_copy_cloud_string(
            king_object_store_cloud_credentials_find("access_key"),
            "access_key",
            config->access_key,
            sizeof(config->access_key),
            1,
            error,
            error_size
        ) != SUCCESS) {
        return FAILURE;
    }

    if (king_object_store_copy_cloud_string(
            king_object_store_cloud_credentials_find("secret_key"),
            "secret_key",
            config->secret_key,
            sizeof(config->secret_key),
            1,
            error,
            error_size
        ) != SUCCESS) {
        return FAILURE;
    }

    if (king_object_store_copy_cloud_string(
            king_object_store_cloud_credentials_find("region"),
            "region",
            config->region,
            sizeof(config->region),
            0,
            error,
            error_size
        ) != SUCCESS) {
        return FAILURE;
    }
    if (config->region[0] == '\0') {
        snprintf(config->region, sizeof(config->region), "%s", "us-east-1");
    }

    if (king_object_store_copy_cloud_string(
            king_object_store_cloud_credentials_find("session_token"),
            "session_token",
            config->session_token,
            sizeof(config->session_token),
            0,
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

