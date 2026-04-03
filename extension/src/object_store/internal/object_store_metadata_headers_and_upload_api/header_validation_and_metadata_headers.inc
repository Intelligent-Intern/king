/*
 * Metadata-header and upload-API helpers. Validates userland metadata/header
 * inputs and normalizes the public upload-session API contract.
 */

int king_object_store_http_header_value_validate(
    const char *field_name,
    const char *value,
    char *error,
    size_t error_size
)
{
    const unsigned char *cursor = (const unsigned char *) value;

    if (value == NULL || value[0] == '\0') {
        if (error != NULL && error_size > 0) {
            error[0] = '\0';
        }
        return SUCCESS;
    }

    while (*cursor != '\0') {
        if (*cursor == '\r' || *cursor == '\n') {
            if (error != NULL && error_size > 0) {
                snprintf(
                    error,
                    error_size,
                    "Object-store metadata field '%s' contains unsupported CR/LF characters for cloud HTTP headers.",
                    field_name != NULL && field_name[0] != '\0' ? field_name : "value"
                );
            }
            return KING_OBJECT_STORE_RESULT_VALIDATION;
        }
        cursor++;
    }

    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }
    return SUCCESS;
}

static int king_object_store_append_named_http_header(
    struct curl_slist **headers,
    const char *header_name,
    const char *field_name,
    const char *value,
    char *error,
    size_t error_size
)
{
    smart_str header = {0};
    struct curl_slist *updated_headers;
    int validation_result;

    if (headers == NULL || header_name == NULL || header_name[0] == '\0' || value == NULL) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Invalid cloud header append request.");
        }
        return FAILURE;
    }

    validation_result = king_object_store_http_header_value_validate(
        field_name,
        value,
        error,
        error_size
    );
    if (validation_result != SUCCESS) {
        return validation_result;
    }

    smart_str_appends(&header, header_name);
    smart_str_appends(&header, ": ");
    smart_str_appends(&header, value);
    smart_str_0(&header);

    updated_headers = king_object_store_libcurl.curl_slist_append_fn(
        *headers,
        ZSTR_VAL(header.s)
    );
    smart_str_free(&header);

    if (updated_headers == NULL) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to allocate cloud HTTP header storage.");
        }
        return FAILURE;
    }

    *headers = updated_headers;
    return SUCCESS;
}

static int king_object_store_append_metadata_headers(
    struct curl_slist **headers,
    const char *prefix,
    const king_object_metadata_t *metadata,
    char *error,
    size_t error_size
)
{
    char header[512];

    if (prefix == NULL || metadata == NULL) {
        if (error != NULL && error_size > 0) {
            error[0] = '\0';
        }
        return SUCCESS;
    }

    {
        smart_str name = {0};
        int result;

        smart_str_appends(&name, prefix);
        smart_str_appends(&name, "object-id");
        smart_str_0(&name);
        result = king_object_store_append_named_http_header(
            headers,
            ZSTR_VAL(name.s),
            "object_id",
            metadata->object_id,
            error,
            error_size
        );
        smart_str_free(&name);
        if (result != SUCCESS) {
            return result;
        }
    }

    snprintf(header, sizeof(header), "%scontent-length: %" PRIu64, prefix, metadata->content_length);
    *headers = king_object_store_libcurl.curl_slist_append_fn(*headers, header);
    if (*headers == NULL) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to allocate cloud HTTP header storage.");
        }
        return FAILURE;
    }

    if (metadata->etag[0] != '\0') {
        smart_str name = {0};
        int result;

        smart_str_appends(&name, prefix);
        smart_str_appends(&name, "etag");
        smart_str_0(&name);
        result = king_object_store_append_named_http_header(
            headers,
            ZSTR_VAL(name.s),
            "etag",
            metadata->etag,
            error,
            error_size
        );
        smart_str_free(&name);
        if (result != SUCCESS) {
            return result;
        }
    }
    if (metadata->integrity_sha256[0] != '\0') {
        smart_str name = {0};
        int result;

        smart_str_appends(&name, prefix);
        smart_str_appends(&name, "integrity-sha256");
        smart_str_0(&name);
        result = king_object_store_append_named_http_header(
            headers,
            ZSTR_VAL(name.s),
            "integrity_sha256",
            metadata->integrity_sha256,
            error,
            error_size
        );
        smart_str_free(&name);
        if (result != SUCCESS) {
            return result;
        }
    }
    if (metadata->created_at > 0) {
        snprintf(header, sizeof(header), "%screated-at: %" PRId64, prefix, (int64_t) metadata->created_at);
        *headers = king_object_store_libcurl.curl_slist_append_fn(*headers, header);
        if (*headers == NULL) {
            if (error != NULL && error_size > 0) {
                snprintf(error, error_size, "Failed to allocate cloud HTTP header storage.");
            }
            return FAILURE;
        }
    }
    if (metadata->modified_at > 0) {
        snprintf(header, sizeof(header), "%smodified-at: %" PRId64, prefix, (int64_t) metadata->modified_at);
        *headers = king_object_store_libcurl.curl_slist_append_fn(*headers, header);
        if (*headers == NULL) {
            if (error != NULL && error_size > 0) {
                snprintf(error, error_size, "Failed to allocate cloud HTTP header storage.");
            }
            return FAILURE;
        }
    }
    if (metadata->expires_at > 0) {
        snprintf(header, sizeof(header), "%sexpires-at: %" PRId64, prefix, (int64_t) metadata->expires_at);
        *headers = king_object_store_libcurl.curl_slist_append_fn(*headers, header);
        if (*headers == NULL) {
            if (error != NULL && error_size > 0) {
                snprintf(error, error_size, "Failed to allocate cloud HTTP header storage.");
            }
            return FAILURE;
        }
    }

    snprintf(header, sizeof(header), "%sversion: %" PRIu64, prefix, metadata->version);
    *headers = king_object_store_libcurl.curl_slist_append_fn(*headers, header);
    if (*headers == NULL) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to allocate cloud HTTP header storage.");
        }
        return FAILURE;
    }

    snprintf(header, sizeof(header), "%sobject-type: %d", prefix, (int) metadata->object_type);
    *headers = king_object_store_libcurl.curl_slist_append_fn(*headers, header);
    if (*headers == NULL) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to allocate cloud HTTP header storage.");
        }
        return FAILURE;
    }

    snprintf(header, sizeof(header), "%scache-policy: %d", prefix, (int) metadata->cache_policy);
    *headers = king_object_store_libcurl.curl_slist_append_fn(*headers, header);
    if (*headers == NULL) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to allocate cloud HTTP header storage.");
        }
        return FAILURE;
    }

    snprintf(header, sizeof(header), "%scache-ttl-seconds: %u", prefix, (unsigned) metadata->cache_ttl_seconds);
    *headers = king_object_store_libcurl.curl_slist_append_fn(*headers, header);
    if (*headers == NULL) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to allocate cloud HTTP header storage.");
        }
        return FAILURE;
    }

    snprintf(header, sizeof(header), "%slocal-fs-present: %u", prefix, (unsigned) metadata->local_fs_present);
    *headers = king_object_store_libcurl.curl_slist_append_fn(*headers, header);
    if (*headers == NULL) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to allocate cloud HTTP header storage.");
        }
        return FAILURE;
    }

    snprintf(header, sizeof(header), "%sdistributed-present: %u", prefix, (unsigned) metadata->distributed_present);
    *headers = king_object_store_libcurl.curl_slist_append_fn(*headers, header);
    if (*headers == NULL) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to allocate cloud HTTP header storage.");
        }
        return FAILURE;
    }

    snprintf(header, sizeof(header), "%scloud-s3-present: %u", prefix, (unsigned) metadata->cloud_s3_present);
    *headers = king_object_store_libcurl.curl_slist_append_fn(*headers, header);
    if (*headers == NULL) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to allocate cloud HTTP header storage.");
        }
        return FAILURE;
    }

    snprintf(header, sizeof(header), "%scloud-gcs-present: %u", prefix, (unsigned) metadata->cloud_gcs_present);
    *headers = king_object_store_libcurl.curl_slist_append_fn(*headers, header);
    if (*headers == NULL) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to allocate cloud HTTP header storage.");
        }
        return FAILURE;
    }

    snprintf(header, sizeof(header), "%scloud-azure-present: %u", prefix, (unsigned) metadata->cloud_azure_present);
    *headers = king_object_store_libcurl.curl_slist_append_fn(*headers, header);
    if (*headers == NULL) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to allocate cloud HTTP header storage.");
        }
        return FAILURE;
    }

    snprintf(header, sizeof(header), "%sis-backed-up: %u", prefix, (unsigned) metadata->is_backed_up);
    *headers = king_object_store_libcurl.curl_slist_append_fn(*headers, header);
    if (*headers == NULL) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to allocate cloud HTTP header storage.");
        }
        return FAILURE;
    }

    snprintf(header, sizeof(header), "%sreplication-status: %u", prefix, (unsigned) metadata->replication_status);
    *headers = king_object_store_libcurl.curl_slist_append_fn(*headers, header);
    if (*headers == NULL) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to allocate cloud HTTP header storage.");
        }
        return FAILURE;
    }

    snprintf(header, sizeof(header), "%sis-distributed: %u", prefix, (unsigned) metadata->is_distributed);
    *headers = king_object_store_libcurl.curl_slist_append_fn(*headers, header);
    if (*headers == NULL) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to allocate cloud HTTP header storage.");
        }
        return FAILURE;
    }

    snprintf(
        header,
        sizeof(header),
        "%sdistribution-peer-count: %u",
        prefix,
        (unsigned) metadata->distribution_peer_count
    );
    *headers = king_object_store_libcurl.curl_slist_append_fn(*headers, header);
    if (*headers == NULL) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Failed to allocate cloud HTTP header storage.");
        }
        return FAILURE;
    }

    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }
    return SUCCESS;
}

