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
