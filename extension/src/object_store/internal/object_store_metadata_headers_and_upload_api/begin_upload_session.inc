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

