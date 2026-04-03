static int king_object_store_distributed_read_range_internal(
    const char *object_id,
    size_t offset,
    size_t length,
    zend_bool has_length,
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
    size_t bytes_to_read;
    king_object_metadata_t resolved_metadata;

    if (!king_object_store_runtime.initialized || object_id == NULL || data == NULL || data_size == NULL) {
        return FAILURE;
    }
    if (king_object_store_object_id_validate(object_id) != NULL) {
        return FAILURE;
    }
    if (!king_object_store_distributed_root_available("range read", error, error_size)) {
        return FAILURE;
    }
    if (king_object_store_build_distributed_path(file_path, sizeof(file_path), object_id) != SUCCESS) {
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "range read",
            object_id,
            "could not build the distributed payload path."
        );
        return FAILURE;
    }

    *data = NULL;
    *data_size = 0;

    if (king_object_store_open_regular_file_stream_readonly(file_path, &fp, &st) != SUCCESS) {
        if (errno == ENOENT) {
            return KING_OBJECT_STORE_RESULT_MISS;
        }
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "range read",
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
        fclose(fp);
        return KING_OBJECT_STORE_RESULT_VALIDATION;
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
    } else if (king_object_store_meta_read(object_id, &resolved_metadata) == SUCCESS
        && king_object_store_metadata_is_expired_now(&resolved_metadata)) {
        fclose(fp);
        return FAILURE;
    }

    bytes_to_read = (size_t) ((uint64_t) st.st_size - (uint64_t) offset);
    if (has_length && length < bytes_to_read) {
        bytes_to_read = length;
    }

    if (fseeko(fp, (off_t) offset, SEEK_SET) != 0) {
        fclose(fp);
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "range read",
            object_id,
            "could not seek to the requested offset."
        );
        return FAILURE;
    }

    *data = pecalloc(1, bytes_to_read + 1, 1);
    if (*data == NULL) {
        fclose(fp);
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "range read",
            object_id,
            "could not allocate a range-read buffer."
        );
        return FAILURE;
    }
    if (bytes_to_read > 0 && fread(*data, 1, bytes_to_read, fp) != bytes_to_read) {
        pefree(*data, 1);
        *data = NULL;
        fclose(fp);
        king_object_store_set_backend_filesystem_error(
            error,
            error_size,
            "distributed",
            "range read",
            object_id,
            "could not read the requested range bytes."
        );
        return FAILURE;
    }

    fclose(fp);
    *data_size = bytes_to_read;
    if (error != NULL && error_size > 0) {
        error[0] = '\0';
    }
    return SUCCESS;
}

