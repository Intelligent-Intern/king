static int king_object_store_append_path_to_file_and_hash(
    const char *source_path,
    const char *destination_path,
    PHP_SHA256_CTX *sha_ctx,
    uint64_t *bytes_appended_out
)
{
    FILE *source_fp = NULL;
    FILE *destination_fp = NULL;
    unsigned char *buffer = NULL;
    size_t chunk_bytes;
    uint64_t appended = 0;
    int rc = FAILURE;

    if (source_path == NULL || destination_path == NULL || sha_ctx == NULL) {
        return FAILURE;
    }

    source_fp = fopen(source_path, "rb");
    if (source_fp == NULL) {
        return FAILURE;
    }

    destination_fp = fopen(destination_path, "ab");
    if (destination_fp == NULL) {
        fclose(source_fp);
        return FAILURE;
    }

    chunk_bytes = king_object_store_streaming_chunk_bytes();
    buffer = emalloc(chunk_bytes);

    while (1) {
        size_t bytes_read = fread(buffer, 1, chunk_bytes, source_fp);

        if (bytes_read == 0) {
            if (feof(source_fp)) {
                rc = SUCCESS;
                break;
            }
            goto cleanup;
        }

        if (fwrite(buffer, 1, bytes_read, destination_fp) != bytes_read) {
            goto cleanup;
        }

        PHP_SHA256Update(sha_ctx, buffer, bytes_read);
        appended += (uint64_t) bytes_read;
    }

cleanup:
    if (buffer != NULL) {
        efree(buffer);
    }
    if (source_fp != NULL) {
        fclose(source_fp);
    }
    if (destination_fp != NULL) {
        fclose(destination_fp);
    }
    if (bytes_appended_out != NULL) {
        *bytes_appended_out = appended;
    }

    return rc;
}

static int king_object_store_finalize_completed_upload_session(
    king_object_store_upload_session_t *session,
    king_object_store_upload_status_t *status_out,
    char *error,
    size_t error_size
)
{
    PHP_SHA256_CTX final_ctx;
    unsigned char digest[32];
    static const char hex[] = "0123456789abcdef";
    char computed_sha256[65];
    king_object_metadata_t final_metadata;
    size_t i;

    if (session == NULL || error == NULL || error_size == 0) {
        return FAILURE;
    }

    if (session->completed) {
        king_object_store_upload_session_export_status(session, status_out);
        return SUCCESS;
    }

    final_ctx = session->sha256_ctx;
    PHP_SHA256Final(digest, &final_ctx);
    for (i = 0; i < sizeof(digest); ++i) {
        computed_sha256[i * 2] = hex[(digest[i] >> 4) & 0x0f];
        computed_sha256[(i * 2) + 1] = hex[digest[i] & 0x0f];
    }
    computed_sha256[64] = '\0';

    if (session->expected_integrity_sha256[0] != '\0'
        && strcmp(session->expected_integrity_sha256, computed_sha256) != 0) {
        snprintf(
            error,
            error_size,
            "Resumable upload integrity validation failed for '%s'.",
            session->object_id
        );
        return FAILURE;
    }
    if (king_object_store_runtime_capacity_check_rewrite(
            session->uploaded_bytes,
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

    final_metadata = session->metadata;
    king_object_store_metadata_mark_backend_present(&final_metadata, session->backend, 1);
    king_object_store_finalize_metadata_for_write(
        session->object_id,
        session->uploaded_bytes,
        computed_sha256,
        &final_metadata,
        session->existed_before ? &session->existing_metadata : NULL
    );

    if (king_object_store_meta_write(session->object_id, &final_metadata) != SUCCESS) {
        snprintf(
            error,
            error_size,
            "Resumable upload for '%s' committed remotely but local metadata sidecar write failed.",
            session->object_id
        );
        return FAILURE;
    }

    king_object_store_apply_local_fs_counters_for_rewrite(
        session->object_id,
        session->uploaded_bytes,
        session->existed_before,
        session->old_size
    );
    /* Any committed primary write invalidates retained CDN state for that object. */
    king_object_store_invalidate_cdn_cache_entry(session->object_id);

    if (king_object_store_runtime.config.backup_backend != session->backend) {
        if (king_object_store_backup_object_from_file_to_backend(
                session->object_id,
                session->assembly_path,
                &final_metadata,
                king_object_store_runtime.config.backup_backend
            ) != SUCCESS) {
            if (king_object_store_runtime.config.replication_factor > 0) {
                king_object_store_update_replication_status(session->object_id, 3);
            }
            snprintf(
                error,
                error_size,
                "Resumable upload for '%s' committed remotely but backup operation failed.",
                session->object_id
            );
            return FAILURE;
        }
    }

    if (king_object_store_runtime.config.replication_factor > 0) {
        if (king_object_store_replicate_object(
                session->object_id,
                king_object_store_runtime.config.replication_factor
            ) != SUCCESS) {
            snprintf(
                error,
                error_size,
                "Resumable upload for '%s' committed remotely but replication target was not reached.",
                session->object_id
            );
            return FAILURE;
        }
    }

    session->metadata = final_metadata;
    session->completed = 1;
    session->remote_completed = 1;
    session->updated_at = time(NULL);
    king_object_store_upload_session_export_status(session, status_out);
    return SUCCESS;
}

static void king_object_store_release_read_buffer(void **data, size_t *data_size)
{
    if (data == NULL || *data == NULL) {
        return;
    }

    pefree(*data, 1);
    *data = NULL;
    if (data_size != NULL) {
        *data_size = 0;
    }
}
