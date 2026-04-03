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

