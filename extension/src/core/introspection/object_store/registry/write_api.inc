PHP_FUNCTION(king_object_store_put)
{
    zend_string *object_id;
    zend_string *payload;
    zval *options = NULL;
    king_object_metadata_t metadata;
    int mutation_lock_fd = -1;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STR(object_id)
        Z_PARAM_STR(payload)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    memset(&metadata, 0, sizeof(metadata));
    if (object_id) {
        strncpy(metadata.object_id, ZSTR_VAL(object_id), sizeof(metadata.object_id) - 1);
    }
    metadata.content_length = ZSTR_LEN(payload);
    metadata.created_at = time(NULL);
    metadata.modified_at = metadata.created_at;

    if (!king_object_store_runtime.initialized) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Object-store registry is unavailable."
        );
        RETURN_THROWS();
    }

    {
        const char *object_id_error = king_object_store_public_object_id_validate_zstr(object_id);

        if (object_id_error != NULL) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "%s", object_id_error);
            RETURN_THROWS();
        }
    }

    if (!king_object_store_registry_acquire_mutation_lock(
            ZSTR_VAL(object_id),
            &mutation_lock_fd,
            "king_object_store_put"
        )) {
        RETURN_THROWS();
    }

    if (!king_object_store_registry_validate_capacity_for_size(
            ZSTR_VAL(object_id),
            (uint64_t) ZSTR_LEN(payload),
            "king_object_store_put"
        )) {
        king_object_store_registry_release_mutation_lock(&mutation_lock_fd);
        RETURN_THROWS();
    }

    if (!king_object_store_registry_apply_put_options(
            options,
            payload,
            &metadata,
            "king_object_store_put"
        )
        || !king_object_store_registry_check_write_preconditions(
            ZSTR_VAL(object_id),
            options,
            "king_object_store_put"
        )) {
        king_object_store_registry_release_mutation_lock(&mutation_lock_fd);
        RETURN_THROWS();
    }

    {
        int result = king_object_store_write_object(
            ZSTR_VAL(object_id),
            ZSTR_VAL(payload),
            ZSTR_LEN(payload),
            &metadata
        );

        if (result != SUCCESS) {
            king_object_store_registry_release_mutation_lock(&mutation_lock_fd);
            king_object_store_registry_throw_primary_failure(
                result,
                "Primary object-store backend write failed."
            );
            RETURN_THROWS();
        }
    }

    king_object_store_registry_release_mutation_lock(&mutation_lock_fd);
    RETURN_TRUE;
}

PHP_FUNCTION(king_object_store_put_from_stream)
{
    zend_string *object_id;
    zval *stream_zv;
    zval *options = NULL;
    php_stream *stream;
    king_object_metadata_t metadata;
    char temp_path[1024];
    char computed_sha256[65];
    uint64_t content_length = 0;
    zend_bool staged = 0;
    int mutation_lock_fd = -1;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STR(object_id)
        Z_PARAM_ZVAL(stream_zv)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (!king_object_store_runtime.initialized) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Object-store registry is unavailable."
        );
        RETURN_THROWS();
    }

    {
        const char *object_id_error = king_object_store_public_object_id_validate_zstr(object_id);

        if (object_id_error != NULL) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "%s", object_id_error);
            RETURN_THROWS();
        }
    }

    stream = king_object_store_registry_fetch_stream_resource(stream_zv, 2, "king_object_store_put_from_stream");
    if (stream == NULL) {
        RETURN_THROWS();
    }

    if (!king_object_store_registry_acquire_mutation_lock(
            ZSTR_VAL(object_id),
            &mutation_lock_fd,
            "king_object_store_put_from_stream"
        )) {
        RETURN_THROWS();
    }

    memset(&metadata, 0, sizeof(metadata));
    strncpy(metadata.object_id, ZSTR_VAL(object_id), sizeof(metadata.object_id) - 1);
    if (!king_object_store_registry_apply_put_metadata_options(
            options,
            &metadata,
            "king_object_store_put_from_stream"
        )) {
        king_object_store_registry_release_mutation_lock(&mutation_lock_fd);
        RETURN_THROWS();
    }
    if (!king_object_store_registry_stage_stream_to_temp_file(
            stream,
            "king_object_store_put_from_stream",
            temp_path,
            sizeof(temp_path),
            0,
            &content_length,
            computed_sha256
        )) {
        king_object_store_registry_release_mutation_lock(&mutation_lock_fd);
        RETURN_THROWS();
    }
    staged = 1;

    if (!king_object_store_registry_check_write_preconditions(
            ZSTR_VAL(object_id),
            options,
            "king_object_store_put_from_stream"
        )
        || !king_object_store_registry_validate_capacity_for_size(
            ZSTR_VAL(object_id),
            content_length,
            "king_object_store_put_from_stream"
        )) {
        king_object_store_registry_release_mutation_lock(&mutation_lock_fd);
        unlink(temp_path);
        RETURN_THROWS();
    }

    metadata.content_length = content_length;
    if (!king_object_store_registry_apply_put_integrity_option(
            options,
            computed_sha256,
            &metadata,
            "king_object_store_put_from_stream"
        )) {
        king_object_store_registry_release_mutation_lock(&mutation_lock_fd);
        if (staged) {
            unlink(temp_path);
        }
        RETURN_THROWS();
    }

    {
        int result = king_object_store_write_object_from_file(
            ZSTR_VAL(object_id),
            temp_path,
            &metadata
        );

        if (result != SUCCESS) {
            king_object_store_registry_release_mutation_lock(&mutation_lock_fd);
            if (staged) {
                unlink(temp_path);
            }
            king_object_store_registry_throw_primary_failure(
                result,
                "Primary object-store backend write failed."
            );
            RETURN_THROWS();
        }
    }

    if (staged) {
        unlink(temp_path);
    }
    king_object_store_registry_release_mutation_lock(&mutation_lock_fd);
    RETURN_TRUE;
}

PHP_FUNCTION(king_object_store_begin_resumable_upload)
{
    zend_string *object_id;
    zval *options = NULL;
    king_object_metadata_t metadata;
    king_object_store_upload_status_t status;
    char expected_integrity_sha256[65];
    char upload_id[65];
    char error[512];
    int mutation_lock_fd = -1;

    ZEND_PARSE_PARAMETERS_START(1, 2)
        Z_PARAM_STR(object_id)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (!king_object_store_runtime.initialized) {
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "Object-store registry is unavailable.");
        RETURN_THROWS();
    }
    {
        const char *object_id_error = king_object_store_public_object_id_validate_zstr(object_id);

        if (object_id_error != NULL) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "%s", object_id_error);
            RETURN_THROWS();
        }
    }

    memset(&metadata, 0, sizeof(metadata));
    memset(&status, 0, sizeof(status));
    memset(expected_integrity_sha256, 0, sizeof(expected_integrity_sha256));
    metadata.created_at = time(NULL);
    metadata.modified_at = metadata.created_at;
    strncpy(metadata.object_id, ZSTR_VAL(object_id), sizeof(metadata.object_id) - 1);

    if (!king_object_store_registry_acquire_mutation_lock(
            ZSTR_VAL(object_id),
            &mutation_lock_fd,
            "king_object_store_begin_resumable_upload"
        )) {
        RETURN_THROWS();
    }

    if (!king_object_store_registry_apply_put_metadata_options(
            options,
            &metadata,
            "king_object_store_begin_resumable_upload"
        )
        || !king_object_store_registry_check_write_preconditions(
            ZSTR_VAL(object_id),
            options,
            "king_object_store_begin_resumable_upload"
        )
        || !king_object_store_registry_copy_sha256_option(
            options,
            "integrity_sha256",
            expected_integrity_sha256,
            "king_object_store_begin_resumable_upload"
        )) {
        king_object_store_registry_release_mutation_lock(&mutation_lock_fd);
        RETURN_THROWS();
    }

    {
        int result = king_object_store_begin_upload_session(
            ZSTR_VAL(object_id),
            &metadata,
            expected_integrity_sha256,
            mutation_lock_fd,
            upload_id,
            &status,
            error,
            sizeof(error)
        );

        if (result != SUCCESS) {
            king_object_store_registry_release_mutation_lock(&mutation_lock_fd);
            if (result == KING_OBJECT_STORE_RESULT_UNAVAILABLE) {
                king_object_store_registry_throw_classified_primary_message(king_ce_runtime_exception, error);
            } else {
                king_object_store_registry_throw_classified_primary_message(king_ce_system_exception, error);
            }
            RETURN_THROWS();
        }
    }
    mutation_lock_fd = -1;

    king_object_store_registry_upload_status_to_array(return_value, &status);
}

PHP_FUNCTION(king_object_store_append_resumable_upload_chunk)
{
    zend_string *upload_id;
    zval *stream_zv;
    zval *options = NULL;
    php_stream *stream;
    char temp_path[1024];
    char computed_sha256[65];
    uint64_t content_length = 0;
    zend_bool staged = 0;
    zend_bool is_final_chunk = 0;
    king_object_store_upload_status_t status;
    char error[512];

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STR(upload_id)
        Z_PARAM_ZVAL(stream_zv)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    stream = king_object_store_registry_fetch_stream_resource(
        stream_zv,
        2,
        "king_object_store_append_resumable_upload_chunk"
    );
    if (stream == NULL) {
        RETURN_THROWS();
    }
    if (!king_object_store_registry_copy_bool_option(
            options,
            "final",
            &is_final_chunk,
            "king_object_store_append_resumable_upload_chunk"
        )) {
        RETURN_THROWS();
    }
    if (!king_object_store_registry_stage_stream_to_temp_file(
            stream,
            "king_object_store_append_resumable_upload_chunk",
            temp_path,
            sizeof(temp_path),
            (uint64_t) king_object_store_runtime.config.chunk_size_kb * 1024U,
            &content_length,
            computed_sha256
        )) {
        RETURN_THROWS();
    }
    staged = 1;

    memset(&status, 0, sizeof(status));
    error[0] = '\0';
    if (king_object_store_append_upload_session_chunk(
            ZSTR_VAL(upload_id),
            temp_path,
            content_length,
            is_final_chunk,
            &status,
            error,
            sizeof(error)
        ) != SUCCESS) {
        if (staged) {
            unlink(temp_path);
        }
        if (king_object_store_registry_primary_failure_is_runtime_capacity(error)) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "%s", error);
            RETURN_THROWS();
        }
        king_object_store_registry_throw_classified_primary_message(king_ce_system_exception, error);
        RETURN_THROWS();
    }

    if (staged) {
        unlink(temp_path);
    }
    king_object_store_registry_upload_status_to_array(return_value, &status);
}

PHP_FUNCTION(king_object_store_complete_resumable_upload)
{
    zend_string *upload_id;
    king_object_store_upload_status_t status;
    char error[512];

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(upload_id)
    ZEND_PARSE_PARAMETERS_END();

    memset(&status, 0, sizeof(status));
    error[0] = '\0';
    if (king_object_store_complete_upload_session(
            ZSTR_VAL(upload_id),
            &status,
            error,
            sizeof(error)
        ) != SUCCESS) {
        if (king_object_store_registry_primary_failure_is_runtime_capacity(error)) {
            zend_throw_exception_ex(king_ce_validation_exception, 0, "%s", error);
            RETURN_THROWS();
        }
        king_object_store_registry_throw_classified_primary_message(king_ce_system_exception, error);
        RETURN_THROWS();
    }

    king_object_store_registry_upload_status_to_array(return_value, &status);
}

PHP_FUNCTION(king_object_store_abort_resumable_upload)
{
    zend_string *upload_id;
    char error[512];

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(upload_id)
    ZEND_PARSE_PARAMETERS_END();

    error[0] = '\0';
    if (king_object_store_abort_upload_session(
            ZSTR_VAL(upload_id),
            error,
            sizeof(error)
        ) != SUCCESS) {
        king_object_store_registry_throw_classified_primary_message(king_ce_system_exception, error);
        RETURN_THROWS();
    }

    RETURN_TRUE;
}

PHP_FUNCTION(king_object_store_get_resumable_upload_status)
{
    zend_string *upload_id;
    king_object_store_upload_status_t status;
    char error[512];

    ZEND_PARSE_PARAMETERS_START(1, 1)
        Z_PARAM_STR(upload_id)
    ZEND_PARSE_PARAMETERS_END();

    memset(&status, 0, sizeof(status));
    error[0] = '\0';
    if (king_object_store_get_upload_session_status(
            ZSTR_VAL(upload_id),
            &status,
            error,
            sizeof(error)
        ) != SUCCESS) {
        RETURN_FALSE;
    }

    king_object_store_registry_upload_status_to_array(return_value, &status);
}

