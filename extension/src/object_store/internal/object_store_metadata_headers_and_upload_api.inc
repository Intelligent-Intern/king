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

int king_object_store_begin_upload_session(
    const char *object_id,
    const king_object_metadata_t *metadata,
    const char *expected_integrity_sha256,
    int adopted_lock_fd,
    char upload_id[65],
    king_object_store_upload_status_t *status_out,
    char *error,
    size_t error_size
)
{
    king_object_store_upload_session_t *session = NULL;
    king_storage_backend_t backend;
    uint8_t random_bytes[32];
    static const char hex[] = "0123456789abcdef";
    size_t i;
    int mutation_lock_fd = adopted_lock_fd;

    if (upload_id == NULL || error == NULL || error_size == 0) {
        return FAILURE;
    }

    upload_id[0] = '\0';
    error[0] = '\0';

    if (!king_object_store_runtime.initialized) {
        snprintf(error, error_size, "Object-store runtime is unavailable.");
        return FAILURE;
    }
    if (object_id == NULL || object_id[0] == '\0' || king_object_store_object_id_validate(object_id) != NULL) {
        snprintf(error, error_size, "Object-store upload sessions require a valid object id.");
        return FAILURE;
    }

    backend = king_object_store_normalize_backend(king_object_store_runtime.config.primary_backend);
    if (backend != KING_STORAGE_BACKEND_CLOUD_S3
        && backend != KING_STORAGE_BACKEND_CLOUD_GCS
        && backend != KING_STORAGE_BACKEND_CLOUD_AZURE) {
        snprintf(
            error,
            error_size,
            "Provider-native resumable upload sessions require a real cloud primary backend."
        );
        return FAILURE;
    }

    if (king_object_store_require_honest_backend("primary", backend, "resumable upload sessions") != SUCCESS) {
        snprintf(
            error,
            error_size,
            "%s",
            king_object_store_runtime.primary_adapter_error[0] != '\0'
                ? king_object_store_runtime.primary_adapter_error
                : "Primary object-store backend is unavailable for resumable upload sessions."
        );
        return KING_OBJECT_STORE_RESULT_UNAVAILABLE;
    }

    if (king_object_store_fill_random_bytes(random_bytes, sizeof(random_bytes)) != SUCCESS) {
        snprintf(error, error_size, "Failed to create a resumable upload identifier.");
        return FAILURE;
    }
    for (i = 0; i < sizeof(random_bytes); ++i) {
        upload_id[i * 2] = hex[(random_bytes[i] >> 4) & 0x0f];
        upload_id[(i * 2) + 1] = hex[random_bytes[i] & 0x0f];
    }
    upload_id[64] = '\0';

    if (king_object_store_upload_sessions_ensure() != SUCCESS) {
        snprintf(error, error_size, "Failed to initialize the resumable upload registry.");
        return FAILURE;
    }

    session = ecalloc(1, sizeof(*session));
    session->lock_fd = -1;
    snprintf(session->upload_id, sizeof(session->upload_id), "%s", upload_id);
    snprintf(session->object_id, sizeof(session->object_id), "%s", object_id);
    session->backend = backend;
    session->protocol =
        backend == KING_STORAGE_BACKEND_CLOUD_S3 ? KING_OBJECT_STORE_UPLOAD_PROTOCOL_S3_MULTIPART :
        backend == KING_STORAGE_BACKEND_CLOUD_GCS ? KING_OBJECT_STORE_UPLOAD_PROTOCOL_GCS_RESUMABLE :
        KING_OBJECT_STORE_UPLOAD_PROTOCOL_AZURE_BLOCKS;
    session->created_at = time(NULL);
    session->updated_at = session->created_at;
    session->next_part_number = 1;
    session->chunk_size_bytes = (uint64_t) king_object_store_streaming_chunk_bytes();
    session->sequential_chunks_required = 1;
    session->final_chunk_may_be_shorter = 1;
    if (metadata != NULL) {
        session->metadata = *metadata;
    }
    if (expected_integrity_sha256 != NULL && expected_integrity_sha256[0] != '\0') {
        snprintf(
            session->expected_integrity_sha256,
            sizeof(session->expected_integrity_sha256),
            "%s",
            expected_integrity_sha256
        );
    }

    if (mutation_lock_fd < 0) {
        if (king_object_store_acquire_object_lock(
                object_id,
                &mutation_lock_fd,
                error,
                error_size
            ) != SUCCESS) {
            king_object_store_upload_session_destroy_ptr(session);
            return FAILURE;
        }
    }
    session->lock_fd = mutation_lock_fd;

    PHP_SHA256Init(&session->sha256_ctx);
    zend_hash_init(&session->provider_parts, 8, NULL, ZVAL_PTR_DTOR, 0);

    if (king_object_store_upload_session_assign_paths(session) != SUCCESS) {
        snprintf(error, error_size, "Failed to allocate a resumable upload assembly file.");
        king_object_store_upload_session_destroy_ptr(session);
        return FAILURE;
    }
    {
        FILE *assembly_fp = fopen(session->assembly_path, "wb");
        if (assembly_fp == NULL) {
            snprintf(error, error_size, "Failed to create a resumable upload assembly file.");
            king_object_store_upload_session_destroy_ptr(session);
            return FAILURE;
        }
        fclose(assembly_fp);
    }

    memset(&session->existing_metadata, 0, sizeof(session->existing_metadata));
    if (king_object_store_backend_read_metadata(object_id, &session->existing_metadata) == SUCCESS) {
        session->existed_before = 1;
        session->old_size = session->existing_metadata.content_length;
    }

    switch (backend) {
        case KING_STORAGE_BACKEND_CLOUD_S3:
            if (king_object_store_s3_begin_upload_session(
                    object_id,
                    metadata,
                    session->provider_token,
                    sizeof(session->provider_token),
                    error,
                    error_size
                ) != SUCCESS) {
                king_object_store_upload_session_destroy_ptr(session);
                return FAILURE;
            }
            break;
        case KING_STORAGE_BACKEND_CLOUD_GCS:
            if (king_object_store_gcs_begin_upload_session(
                    object_id,
                    metadata,
                    session->provider_token,
                    sizeof(session->provider_token),
                    error,
                    error_size
                ) != SUCCESS) {
                king_object_store_upload_session_destroy_ptr(session);
                return FAILURE;
            }
            break;
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            if (king_object_store_azure_begin_upload_session(
                    object_id,
                    metadata,
                    session->provider_token,
                    sizeof(session->provider_token),
                    error,
                    error_size
                ) != SUCCESS) {
                king_object_store_upload_session_destroy_ptr(session);
                return FAILURE;
            }
            break;
        default:
            snprintf(error, error_size, "Unsupported primary backend for resumable upload sessions.");
            king_object_store_upload_session_destroy_ptr(session);
            return FAILURE;
    }

    if (king_object_store_upload_session_persist(session, error, error_size) != SUCCESS) {
        char abort_error[256];

        abort_error[0] = '\0';
        switch (backend) {
            case KING_STORAGE_BACKEND_CLOUD_S3:
                (void) king_object_store_s3_abort_upload_session(
                    session->object_id,
                    session->provider_token,
                    abort_error,
                    sizeof(abort_error)
                );
                break;
            case KING_STORAGE_BACKEND_CLOUD_GCS:
                if (session->provider_token[0] != '\0') {
                    (void) king_object_store_gcs_abort_upload_session(
                        session->provider_token,
                        abort_error,
                        sizeof(abort_error)
                    );
                }
                break;
            case KING_STORAGE_BACKEND_CLOUD_AZURE:
                break;
            default:
                break;
        }
        king_object_store_upload_session_destroy_ptr(session);
        return FAILURE;
    }

    if (king_object_store_upload_session_store(session) != SUCCESS) {
        snprintf(error, error_size, "Failed to store resumable upload session state.");
        king_object_store_upload_session_remove_persisted_state(session);
        king_object_store_upload_session_destroy_ptr(session);
        return FAILURE;
    }

    king_object_store_set_backend_runtime_result("primary", backend, SUCCESS, NULL);
    king_object_store_upload_session_export_status(session, status_out);
    return SUCCESS;
}

int king_object_store_append_upload_session_chunk(
    const char *upload_id,
    const char *source_path,
    uint64_t chunk_size,
    zend_bool is_final_chunk,
    king_object_store_upload_status_t *status_out,
    char *error,
    size_t error_size
)
{
    king_object_store_upload_session_t *session;
    uint64_t bytes_appended = 0;
    char provider_part_token[256];
    zend_bool remote_completed = 0;
    zval stored_part;

    if (error == NULL || error_size == 0) {
        return FAILURE;
    }

    error[0] = '\0';
    session = king_object_store_upload_session_find(upload_id);
    if (session == NULL) {
        snprintf(error, error_size, "Unknown resumable upload id.");
        return FAILURE;
    }
    if (session->completed || session->aborted) {
        snprintf(error, error_size, "Resumable upload '%s' is no longer writable.", upload_id);
        return FAILURE;
    }
    if (session->final_chunk_received) {
        snprintf(error, error_size, "Resumable upload '%s' already received its final chunk.", upload_id);
        return FAILURE;
    }
    if (source_path == NULL || source_path[0] == '\0' || chunk_size == 0) {
        snprintf(error, error_size, "Resumable upload chunks must contain at least one byte.");
        return FAILURE;
    }
    if (session->chunk_size_bytes > 0 && chunk_size > session->chunk_size_bytes) {
        snprintf(
            error,
            error_size,
            "Resumable upload chunk for '%s' exceeded configured chunk_size_kb limit of %" PRIu64 " bytes.",
            session->object_id,
            session->chunk_size_bytes
        );
        return FAILURE;
    }
    if (king_object_store_runtime_capacity_check_rewrite(
            session->uploaded_bytes + chunk_size,
            session->existed_before,
            session->old_size,
            error,
            error_size
        ) != SUCCESS) {
        snprintf(
            error,
            error_size,
            "Resumable upload for '%s' would exceed the configured object-store runtime capacity.",
            session->object_id
        );
        return FAILURE;
    }

    provider_part_token[0] = '\0';
    switch (session->backend) {
        case KING_STORAGE_BACKEND_CLOUD_S3:
            if (king_object_store_s3_append_upload_chunk(
                    session->object_id,
                    session->provider_token,
                    session->next_part_number,
                    source_path,
                    chunk_size,
                    provider_part_token,
                    sizeof(provider_part_token),
                    error,
                    error_size
                ) != SUCCESS) {
                return FAILURE;
            }
            break;
        case KING_STORAGE_BACKEND_CLOUD_GCS:
            if (king_object_store_gcs_append_upload_chunk(
                    session->provider_token,
                    source_path,
                    chunk_size,
                    session->next_offset,
                    is_final_chunk,
                    session->uploaded_bytes + chunk_size,
                    &remote_completed,
                    error,
                    error_size
                ) != SUCCESS) {
                return FAILURE;
            }
            break;
        case KING_STORAGE_BACKEND_CLOUD_AZURE:
            if (king_object_store_azure_append_upload_chunk(
                    session->object_id,
                    session->next_part_number,
                    source_path,
                    chunk_size,
                    provider_part_token,
                    sizeof(provider_part_token),
                    error,
                    error_size
                ) != SUCCESS) {
                return FAILURE;
            }
            break;
        default:
            snprintf(error, error_size, "Unsupported backend for resumable upload chunks.");
            return FAILURE;
    }

    if (king_object_store_append_path_to_file_and_hash(
            source_path,
            session->assembly_path,
            &session->sha256_ctx,
            &bytes_appended
        ) != SUCCESS || bytes_appended != chunk_size) {
        snprintf(error, error_size, "Resumable upload '%s' could not persist the uploaded chunk locally.", upload_id);
        return FAILURE;
    }

    if (provider_part_token[0] != '\0') {
        ZVAL_STRING(&stored_part, provider_part_token);
        zend_hash_index_update(&session->provider_parts, session->next_part_number, &stored_part);
    }

    session->uploaded_bytes += bytes_appended;
    session->next_offset += bytes_appended;
    session->next_part_number++;
    session->updated_at = time(NULL);
    session->final_chunk_received = is_final_chunk ? 1 : 0;
    if (remote_completed) {
        session->remote_completed = 1;
    }

    if (king_object_store_upload_session_persist(session, error, error_size) != SUCCESS) {
        return FAILURE;
    }

    king_object_store_set_backend_runtime_result("primary", session->backend, SUCCESS, NULL);
    king_object_store_upload_session_export_status(session, status_out);
    return SUCCESS;
}

int king_object_store_complete_upload_session(
    const char *upload_id,
    king_object_store_upload_status_t *status_out,
    char *error,
    size_t error_size
)
{
    king_object_store_upload_session_t *session;
    char completed_upload_id[65];
    king_storage_backend_t backend;

    if (error == NULL || error_size == 0) {
        return FAILURE;
    }

    error[0] = '\0';
    session = king_object_store_upload_session_find(upload_id);
    if (session == NULL) {
        snprintf(error, error_size, "Unknown resumable upload id.");
        return FAILURE;
    }
    if (session->aborted) {
        snprintf(error, error_size, "Resumable upload '%s' was aborted.", upload_id);
        return FAILURE;
    }
    if (!session->final_chunk_received) {
        snprintf(error, error_size, "Resumable upload '%s' has not received its final chunk yet.", upload_id);
        return FAILURE;
    }

    if (!session->remote_completed) {
        switch (session->backend) {
            case KING_STORAGE_BACKEND_CLOUD_S3:
                if (king_object_store_s3_complete_upload_session(
                        session->object_id,
                        session->provider_token,
                        &session->provider_parts,
                        error,
                        error_size
                    ) != SUCCESS) {
                    return FAILURE;
                }
                session->remote_completed = 1;
                session->updated_at = time(NULL);
                break;
            case KING_STORAGE_BACKEND_CLOUD_AZURE:
                if (king_object_store_azure_complete_upload_session(
                        session->object_id,
                        &session->provider_parts,
                        &session->metadata,
                        error,
                        error_size
                    ) != SUCCESS) {
                    return FAILURE;
                }
                session->remote_completed = 1;
                session->updated_at = time(NULL);
                break;
            case KING_STORAGE_BACKEND_CLOUD_GCS:
                snprintf(error, error_size, "cloud_gcs resumable uploads must finish their final payload chunk before completion.");
                return FAILURE;
            default:
                snprintf(error, error_size, "Unsupported backend for resumable upload completion.");
                return FAILURE;
        }

        if (king_object_store_upload_session_persist(session, error, error_size) != SUCCESS) {
            return FAILURE;
        }
    }

    if (king_object_store_finalize_completed_upload_session(session, status_out, error, error_size) != SUCCESS) {
        return FAILURE;
    }

    backend = session->backend;
    snprintf(completed_upload_id, sizeof(completed_upload_id), "%s", session->upload_id);
    if (king_object_store_upload_session_persist(session, error, error_size) != SUCCESS) {
        return FAILURE;
    }
    king_object_store_set_backend_runtime_result("primary", backend, SUCCESS, NULL);
    zend_hash_str_del(&king_object_store_upload_sessions, completed_upload_id, strlen(completed_upload_id));
    return SUCCESS;
}

int king_object_store_abort_upload_session(
    const char *upload_id,
    char *error,
    size_t error_size
)
{
    king_object_store_upload_session_t *session;
    char aborted_upload_id[65];
    king_storage_backend_t backend;

    if (error == NULL || error_size == 0) {
        return FAILURE;
    }

    error[0] = '\0';
    session = king_object_store_upload_session_find(upload_id);
    if (session == NULL) {
        snprintf(error, error_size, "Unknown resumable upload id.");
        return FAILURE;
    }

    if (!session->remote_completed) {
        switch (session->backend) {
            case KING_STORAGE_BACKEND_CLOUD_S3:
                if (king_object_store_s3_abort_upload_session(
                        session->object_id,
                        session->provider_token,
                        error,
                        error_size
                    ) != SUCCESS) {
                    return FAILURE;
                }
                break;
            case KING_STORAGE_BACKEND_CLOUD_GCS:
                if (session->provider_token[0] != '\0'
                    && king_object_store_gcs_abort_upload_session(
                        session->provider_token,
                        error,
                        error_size
                    ) != SUCCESS) {
                    return FAILURE;
                }
                break;
            case KING_STORAGE_BACKEND_CLOUD_AZURE:
                break;
            default:
                snprintf(error, error_size, "Unsupported backend for resumable upload abort.");
                return FAILURE;
        }
    }

    session->aborted = 1;
    session->updated_at = time(NULL);
    backend = session->backend;
    snprintf(aborted_upload_id, sizeof(aborted_upload_id), "%s", session->upload_id);
    if (king_object_store_upload_session_persist(session, error, error_size) != SUCCESS) {
        return FAILURE;
    }
    king_object_store_set_backend_runtime_result("primary", backend, SUCCESS, NULL);
    zend_hash_str_del(&king_object_store_upload_sessions, aborted_upload_id, strlen(aborted_upload_id));
    return SUCCESS;
}

int king_object_store_get_upload_session_status(
    const char *upload_id,
    king_object_store_upload_status_t *status_out,
    char *error,
    size_t error_size
)
{
    king_object_store_upload_session_t *session;

    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }

    session = king_object_store_upload_session_find(upload_id);
    if (session == NULL) {
        if (error != NULL && error_size > 0) {
            snprintf(error, error_size, "Unknown resumable upload id.");
        }
        return FAILURE;
    }

    king_object_store_upload_session_export_status(session, status_out);
    return SUCCESS;
}
