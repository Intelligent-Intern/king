static int king_object_store_distributed_read_internal(
    const char *object_id,
    void **data,
    size_t *data_size,
    king_object_metadata_t *metadata,
    char *error,
    size_t error_size
)
{
    FILE *fp;
    char file_path[PATH_MAX];
    struct stat st;

    if (!king_object_store_runtime.initialized || object_id == NULL || data == NULL || data_size == NULL) {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }
    if (!king_object_store_distributed_root_available("read", error, error_size)) {
        return FAILURE;
    }
    if (king_object_store_build_distributed_path(file_path, sizeof(file_path), object_id) != SUCCESS) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "read",
            object_id,
            "could not build the distributed payload path."
        );
        return FAILURE;
    }

    if (king_object_store_open_regular_file_stream_readonly(file_path, &fp, &st) != SUCCESS) {
        if (errno == ENOENT) {
            return KING_OBJECT_STORE_RESULT_MISS;
        }
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "read",
            object_id,
            strerror(errno)
        );
        return FAILURE;
    }

    if (metadata != NULL) {
        if (king_object_store_meta_read(object_id, metadata) != SUCCESS) {
            memset(metadata, 0, sizeof(*metadata));
            strncpy(metadata->object_id, object_id, sizeof(metadata->object_id) - 1);
            metadata->content_length = (uint64_t) st.st_size;
            metadata->created_at = st.st_mtime;
            metadata->modified_at = st.st_mtime;
            metadata->version = 1;
            metadata->distributed_present = 1;
        } else if (king_object_store_metadata_is_expired_now(metadata)) {
            fclose(fp);
            return FAILURE;
        }
    }

    *data_size = (size_t) st.st_size;
    *data = pecalloc(1, *data_size + 1, 1);
    if (*data == NULL) {
        fclose(fp);
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "read",
            object_id,
            "could not allocate a read buffer."
        );
        return FAILURE;
    }

    if (fread(*data, 1, *data_size, fp) != *data_size) {
        pefree(*data, 1);
        *data = NULL;
        *data_size = 0;
        fclose(fp);
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "read",
            object_id,
            "could not read the committed payload bytes."
        );
        return FAILURE;
    }

    fclose(fp);
    if (metadata != NULL && !king_object_store_verify_integrity_buffer(*data, *data_size, metadata)) {
        pefree(*data, 1);
        *data = NULL;
        *data_size = 0;
        if (error != NULL && error_size > 0) {
            snprintf(
                error,
                error_size,
                "distributed read for '%s' failed: integrity validation failed.",
                object_id
            );
        }
        return FAILURE;
    }

    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }
    return SUCCESS;
}

