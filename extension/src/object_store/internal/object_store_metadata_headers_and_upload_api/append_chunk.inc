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

