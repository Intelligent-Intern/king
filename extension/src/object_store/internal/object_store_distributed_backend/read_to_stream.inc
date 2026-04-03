static int king_object_store_distributed_read_to_stream_internal(
    const char *object_id,
    php_stream *destination_stream,
    size_t offset,
    size_t length,
    zend_bool has_length,
    king_object_metadata_t *metadata,
    char *error,
    size_t error_size
)
{
    char file_path[PATH_MAX];
    struct stat st;
    king_object_metadata_t resolved_metadata;
    king_object_metadata_t *metadata_ptr = metadata;
    char computed_sha256[65];
    uint64_t source_size = 0;

    if (!king_object_store_runtime.initialized || object_id == NULL || destination_stream == NULL) {
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

    if (stat(file_path, &st) != 0 || !S_ISREG(st.st_mode)) {
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
    if ((uint64_t) offset > (uint64_t) st.st_size) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "range read",
            object_id,
            "requested range starts past the end of the object."
        );
        return KING_OBJECT_STORE_RESULT_VALIDATION;
    }

    if (metadata_ptr == NULL) {
        metadata_ptr = &resolved_metadata;
    }
    if (king_object_store_meta_read(object_id, metadata_ptr) != SUCCESS) {
        memset(metadata_ptr, 0, sizeof(*metadata_ptr));
        strncpy(metadata_ptr->object_id, object_id, sizeof(metadata_ptr->object_id) - 1);
        metadata_ptr->content_length = (uint64_t) st.st_size;
        metadata_ptr->created_at = st.st_mtime;
        metadata_ptr->modified_at = st.st_mtime;
        metadata_ptr->version = 1;
        metadata_ptr->distributed_present = 1;
    } else if (king_object_store_metadata_is_expired_now(metadata_ptr)) {
        return FAILURE;
    }

    if (!has_length && offset == 0 && metadata_ptr->integrity_sha256[0] != '\0') {
        if (king_object_store_compute_sha256_hex_for_path(file_path, computed_sha256, &source_size) != SUCCESS) {
            king_object_store_set_backend_filesystem_error(
                error,
                error_size,
                "distributed",
                "read",
                object_id,
                "could not compute integrity_sha256 for the committed payload."
            );
            return FAILURE;
        }
        if (strcmp(computed_sha256, metadata_ptr->integrity_sha256) != 0
            || source_size != metadata_ptr->content_length) {
            king_object_store_set_backend_filesystem_error(
                error,
                error_size,
                "distributed",
                "read",
                object_id,
                "integrity validation failed."
            );
            return FAILURE;
        }
    }

    if (king_object_store_copy_path_to_stream(
            file_path,
            destination_stream,
            offset,
            length,
            has_length
        ) != SUCCESS) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "read",
            object_id,
            "could not stream the committed payload bytes."
        );
        return FAILURE;
    }

    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }
    return SUCCESS;
}

