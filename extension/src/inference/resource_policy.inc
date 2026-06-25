static bool king_inference_gpu_enabled(zval *config)
{
    zval *gpu = king_inference_array_find(config, "gpu");

    return gpu != NULL
        && Z_TYPE_P(gpu) == IS_ARRAY
        && king_inference_bool_from_array(gpu, "enabled", false);
}

static zend_long king_inference_max_gpu_layers(zval *config)
{
    zval *gpu = king_inference_array_find(config, "gpu");

    if (gpu == NULL || Z_TYPE_P(gpu) != IS_ARRAY) {
        return 0;
    }

    return king_inference_long_from_array(gpu, "max_gpu_layers", 0);
}
