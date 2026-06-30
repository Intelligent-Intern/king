static void king_inference_native_mapping_release(king_inference_model_object *model)
{
    if (model->native_map != NULL && model->native_map_size > 0) {
        munmap(model->native_map, model->native_map_size);
    }

    model->native_map = NULL;
    model->native_map_size = 0;
    model->native_map_loaded = false;
}

static zend_result king_inference_native_map_artifact(
    zend_string *path,
    zend_ulong file_size,
    void **map_out,
    size_t *map_size_out
) {
    int fd;
    void *mapped;

    *map_out = NULL;
    *map_size_out = 0;

    if (file_size == 0 || file_size > (zend_ulong) SIZE_MAX) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Model artifact '%s' is too large to map into this process.",
            ZSTR_VAL(path)
        );
        return FAILURE;
    }

    fd = open(ZSTR_VAL(path), O_RDONLY);
    if (fd < 0) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Model artifact '%s' could not be opened for native mapping.",
            ZSTR_VAL(path)
        );
        return FAILURE;
    }

    mapped = mmap(NULL, (size_t) file_size, PROT_READ, MAP_PRIVATE, fd, 0);
    close(fd);

    if (mapped == MAP_FAILED) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "Model artifact '%s' could not be memory-mapped for King native inference.",
            ZSTR_VAL(path)
        );
        return FAILURE;
    }

    *map_out = mapped;
    *map_size_out = (size_t) file_size;
    return SUCCESS;
}
