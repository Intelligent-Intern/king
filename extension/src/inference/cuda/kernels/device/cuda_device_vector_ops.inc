/*
 * CUDA device-side vector operations for native King GPU inference.
 */

typedef int (*king_inference_cuda_memcpy_dtoh_fn)(
    void *dst_host,
    king_inference_cuda_device_ptr src_device,
    size_t byte_count
);

static const char king_inference_cuda_device_vector_ops_source[] =
"extern \"C\" __global__ void king_vector_add(\n"
"    const float *left,\n"
"    const float *right,\n"
"    float *output,\n"
"    unsigned int length\n"
") {\n"
"    unsigned int index = blockIdx.x * blockDim.x + threadIdx.x;\n"
"    if (left != 0 && right != 0 && output != 0 && index < length) {\n"
"        output[index] = left[index] + right[index];\n"
"    }\n"
"}\n"
"extern \"C\" __global__ void king_vector_mul(\n"
"    const float *left,\n"
"    const float *right,\n"
"    float *output,\n"
"    unsigned int length\n"
") {\n"
"    unsigned int index = blockIdx.x * blockDim.x + threadIdx.x;\n"
"    if (left != 0 && right != 0 && output != 0 && index < length) {\n"
"        output[index] = left[index] * right[index];\n"
"    }\n"
"}\n"
"extern \"C\" __global__ void king_vector_scale(\n"
"    const float *input,\n"
"    float scale,\n"
"    float *output,\n"
"    unsigned int length\n"
") {\n"
"    unsigned int index = blockIdx.x * blockDim.x + threadIdx.x;\n"
"    if (input != 0 && output != 0 && index < length) {\n"
"        output[index] = input[index] * scale;\n"
"    }\n"
"}\n"
"extern \"C\" __global__ void king_vector_silu(\n"
"    const float *input,\n"
"    float *output,\n"
"    unsigned int length\n"
") {\n"
"    unsigned int index = blockIdx.x * blockDim.x + threadIdx.x;\n"
"    if (input != 0 && output != 0 && index < length) {\n"
"        float value = input[index];\n"
"        output[index] = value / (1.0f + __expf(-value));\n"
"    }\n"
"}\n"
"extern \"C\" __global__ void king_vector_slice(\n"
"    const float *input,\n"
"    unsigned int offset,\n"
"    float *output,\n"
"    unsigned int length\n"
") {\n"
"    unsigned int index = blockIdx.x * blockDim.x + threadIdx.x;\n"
"    if (input != 0 && output != 0 && index < length) {\n"
"        output[index] = input[offset + index];\n"
"    }\n"
"}\n"
"extern \"C\" __global__ void king_vector_copy_to_offset(\n"
"    const float *input,\n"
"    float *output,\n"
"    unsigned int output_offset,\n"
"    unsigned int length\n"
") {\n"
"    unsigned int index = blockIdx.x * blockDim.x + threadIdx.x;\n"
"    if (input != 0 && output != 0 && index < length) {\n"
"        output[output_offset + index] = input[index];\n"
"    }\n"
"}\n";

static void king_inference_cuda_device_vector_ops_reset_fields(king_inference_model_object *model)
{
    model->cuda_device_vector_ops_nvrtc_handle = NULL;
    model->cuda_device_vector_ops_module = NULL;
    model->cuda_vector_add_function = NULL;
    model->cuda_vector_mul_function = NULL;
    model->cuda_vector_scale_function = NULL;
    model->cuda_vector_silu_function = NULL;
    model->cuda_vector_slice_function = NULL;
    model->cuda_vector_copy_to_offset_function = NULL;
    model->cuda_device_vector_ops_result = 0;
    model->cuda_device_vector_ops_error[0] = '\0';
    model->cuda_device_vector_ops_launch_count = 0;
    model->cuda_device_vector_ops_last_length = 0;
    model->cuda_device_vector_ops_attempted = false;
    model->cuda_device_vector_ops_available = false;
    model->cuda_device_vector_ops_nvrtc_available = false;
    model->cuda_device_vector_ops_module_loaded = false;
    model->cuda_vector_add_available = false;
    model->cuda_vector_mul_available = false;
    model->cuda_vector_scale_available = false;
    model->cuda_vector_silu_available = false;
    model->cuda_vector_slice_available = false;
    model->cuda_vector_copy_to_offset_available = false;
}

static void king_inference_cuda_device_vector_ops_set_error(
    king_inference_model_object *model,
    int result,
    const char *message
) {
    if (message == NULL || message[0] == '\0') {
        message = "unknown_cuda_device_vector_ops_error";
    }

    model->cuda_device_vector_ops_result = result;
    snprintf(model->cuda_device_vector_ops_error, sizeof(model->cuda_device_vector_ops_error), "%s", message);
}

static bool king_inference_cuda_device_vector_ops_driver_symbol(
    king_inference_model_object *model,
    const char *name,
    void **symbol
) {
    *symbol = NULL;
    if (model->cuda_driver_handle == NULL) {
        king_inference_cuda_device_vector_ops_set_error(model, -1, "cuda_driver_handle_unavailable");
        return false;
    }

    *symbol = dlsym(model->cuda_driver_handle, name);
    if (*symbol != NULL) {
        return true;
    }

    king_inference_cuda_device_vector_ops_set_error(model, -1, name);
    return false;
}

static bool king_inference_cuda_device_vector_numeric_compare_enabled(zval *config)
{
    zval *gpu = king_inference_array_find(config, "gpu");
    zval *debug = gpu != NULL && Z_TYPE_P(gpu) == IS_ARRAY
        ? king_inference_array_find(gpu, "debug")
        : NULL;
    zval *enabled = debug != NULL && Z_TYPE_P(debug) == IS_ARRAY
        ? king_inference_array_find(debug, "numeric_compare_enabled")
        : NULL;

    if (enabled != NULL && (Z_TYPE_P(enabled) == IS_TRUE || Z_TYPE_P(enabled) == IS_FALSE)) {
        return zend_is_true(enabled);
    }

    return king_high_perf_compute_ai_config.inference_cuda_numeric_compare_enable;
}

static zend_ulong king_inference_cuda_device_vector_numeric_compare_max_values(zval *config)
{
    zval *gpu = king_inference_array_find(config, "gpu");
    zval *debug = gpu != NULL && Z_TYPE_P(gpu) == IS_ARRAY
        ? king_inference_array_find(gpu, "debug")
        : NULL;
    zval *configured = debug != NULL && Z_TYPE_P(debug) == IS_ARRAY
        ? king_inference_array_find(debug, "numeric_compare_max_values")
        : NULL;
    zend_long max_values = king_high_perf_compute_ai_config.inference_cuda_numeric_compare_max_values;

    if (configured != NULL && Z_TYPE_P(configured) == IS_LONG && Z_LVAL_P(configured) > 0) {
        max_values = Z_LVAL_P(configured);
    }
    if (max_values <= 0) {
        max_values = 1;
    }
    if (max_values > 1024) {
        max_values = 1024;
    }

    return (zend_ulong) max_values;
}

static void king_inference_cuda_device_vector_numeric_compare_status(
    king_inference_model_object *model,
    zval *return_value
) {
    bool enabled = king_inference_cuda_device_vector_numeric_compare_enabled(&model->config);
    bool available = enabled
        && model->cuda_driver_handle != NULL
        && model->cuda_device_allocator_available;

    array_init(return_value);
    add_assoc_bool(return_value, "enabled", enabled);
    add_assoc_bool(return_value, "available", available);
    add_assoc_bool(return_value, "public_api", false);
    add_assoc_bool(return_value, "unavailable_by_default", true);
    add_assoc_long(
        return_value,
        "max_values",
        (zend_long) king_inference_cuda_device_vector_numeric_compare_max_values(&model->config)
    );
    add_assoc_string(return_value, "scope", "internal_cuda_device_vector_readback_compare");
    add_assoc_string(return_value, "readback", "cuMemcpyDtoH");
    add_assoc_string(return_value, "status", enabled ? (available ? "ready" : "unavailable") : "disabled");
}

static zend_result king_inference_cuda_device_vector_numeric_compare(
    king_inference_model_object *model,
    const char *label,
    king_inference_cuda_device_ptr device_vector,
    zend_ulong length,
    const float *expected,
    zend_ulong expected_length,
    double tolerance,
    zval *return_value
) {
    king_inference_cuda_memcpy_dtoh_fn cu_memcpy_dtoh;
    zend_ulong max_values;
    zend_ulong compare_count;
    size_t readback_bytes;
    float *actual;
    double max_abs_diff = 0.0;
    bool matched = true;
    zend_ulong matched_values = 0;
    int result;
    zval samples;

    array_init(return_value);
    add_assoc_string(return_value, "label", label != NULL ? label : "");
    add_assoc_bool(return_value, "enabled", king_inference_cuda_device_vector_numeric_compare_enabled(&model->config));
    add_assoc_bool(return_value, "public_api", false);
    add_assoc_string(return_value, "scope", "internal_cuda_device_vector_readback_compare");

    if (!king_inference_cuda_device_vector_numeric_compare_enabled(&model->config)) {
        add_assoc_string(return_value, "status", "disabled");
        return SUCCESS;
    }
    if (device_vector == 0 || expected == NULL || length == 0 || expected_length == 0) {
        add_assoc_string(return_value, "status", "invalid_arguments");
        add_assoc_bool(return_value, "matched", false);
        return FAILURE;
    }
    if (!king_inference_cuda_device_allocator_set_current(model)) {
        add_assoc_string(return_value, "status", "cuda_context_current_failed");
        add_assoc_bool(return_value, "matched", false);
        return FAILURE;
    }

    cu_memcpy_dtoh = (king_inference_cuda_memcpy_dtoh_fn) dlsym(model->cuda_driver_handle, "cuMemcpyDtoH_v2");
    if (cu_memcpy_dtoh == NULL) {
        cu_memcpy_dtoh = (king_inference_cuda_memcpy_dtoh_fn) dlsym(model->cuda_driver_handle, "cuMemcpyDtoH");
    }
    if (cu_memcpy_dtoh == NULL) {
        king_inference_cuda_device_vector_ops_set_error(model, -1, "cuMemcpyDtoH_unavailable");
        add_assoc_string(return_value, "status", "cuMemcpyDtoH_unavailable");
        add_assoc_bool(return_value, "matched", false);
        return FAILURE;
    }

    max_values = king_inference_cuda_device_vector_numeric_compare_max_values(&model->config);
    compare_count = length < expected_length ? length : expected_length;
    compare_count = compare_count < max_values ? compare_count : max_values;
    readback_bytes = (size_t) compare_count * sizeof(float);
    actual = emalloc(readback_bytes);
    result = cu_memcpy_dtoh(actual, device_vector, readback_bytes);
    if (result != 0) {
        efree(actual);
        king_inference_cuda_device_vector_ops_set_error(model, result, "cuMemcpyDtoH_failed");
        add_assoc_string(return_value, "status", "cuMemcpyDtoH_failed");
        add_assoc_long(return_value, "result", result);
        add_assoc_bool(return_value, "matched", false);
        return FAILURE;
    }

    array_init(&samples);
    for (zend_ulong i = 0; i < compare_count; i++) {
        double diff = fabs((double) actual[i] - (double) expected[i]);
        zval sample;

        if (diff > tolerance) {
            matched = false;
        } else {
            matched_values++;
        }
        if (diff > max_abs_diff) {
            max_abs_diff = diff;
        }

        array_init(&sample);
        add_assoc_long(&sample, "index", (zend_long) i);
        add_assoc_double(&sample, "actual", (double) actual[i]);
        add_assoc_double(&sample, "expected", (double) expected[i]);
        add_assoc_double(&sample, "abs_diff", diff);
        add_next_index_zval(&samples, &sample);
    }

    efree(actual);
    add_assoc_string(return_value, "status", matched ? "matched" : "mismatch");
    add_assoc_bool(return_value, "matched", matched);
    add_assoc_long(return_value, "compared_values", (zend_long) compare_count);
    add_assoc_long(return_value, "matched_values", (zend_long) matched_values);
    add_assoc_long(return_value, "readback_bytes", (zend_long) readback_bytes);
    add_assoc_double(return_value, "tolerance", tolerance);
    add_assoc_double(return_value, "max_abs_diff", max_abs_diff);
    add_assoc_zval(return_value, "samples", &samples);

    return matched ? SUCCESS : FAILURE;
}

static bool king_inference_cuda_device_vector_ops_nvrtc_symbol(
    king_inference_model_object *model,
    const char *name,
    void **symbol
) {
    *symbol = NULL;
    if (model->cuda_device_vector_ops_nvrtc_handle == NULL) {
        king_inference_cuda_device_vector_ops_set_error(model, -1, "nvrtc_handle_unavailable");
        return false;
    }

    *symbol = dlsym(model->cuda_device_vector_ops_nvrtc_handle, name);
    if (*symbol != NULL) {
        return true;
    }

    king_inference_cuda_device_vector_ops_set_error(model, -1, name);
    return false;
}

static bool king_inference_cuda_device_vector_ops_open_nvrtc(king_inference_model_object *model)
{
    const char *libraries[] = {"libnvrtc.so", "libnvrtc.so.12", "libnvrtc.so.11", NULL};
    size_t index;

    for (index = 0; libraries[index] != NULL; index++) {
        model->cuda_device_vector_ops_nvrtc_handle = dlopen(libraries[index], RTLD_LAZY | RTLD_LOCAL);
        if (model->cuda_device_vector_ops_nvrtc_handle != NULL) {
            model->cuda_device_vector_ops_nvrtc_available = true;
            return true;
        }
    }

    king_inference_cuda_device_vector_ops_set_error(model, -1, "libnvrtc_unavailable");
    return false;
}

static bool king_inference_cuda_device_vector_ops_compile_ptx(
    king_inference_model_object *model,
    char **ptx_out,
    size_t *ptx_size_out
) {
    king_inference_nvrtc_create_program_fn nvrtc_create_program;
    king_inference_nvrtc_compile_program_fn nvrtc_compile_program;
    king_inference_nvrtc_get_ptx_size_fn nvrtc_get_ptx_size;
    king_inference_nvrtc_get_ptx_fn nvrtc_get_ptx;
    king_inference_nvrtc_destroy_program_fn nvrtc_destroy_program;
    king_inference_nvrtc_program program = NULL;
    const char *options[] = {"--std=c++11", "--gpu-architecture=compute_89", "--use_fast_math"};
    char *ptx;
    size_t ptx_size = 0;
    king_inference_nvrtc_result result;

    *ptx_out = NULL;
    *ptx_size_out = 0;
    if (!king_inference_cuda_device_vector_ops_nvrtc_symbol(model, "nvrtcCreateProgram", (void **) &nvrtc_create_program)
        || !king_inference_cuda_device_vector_ops_nvrtc_symbol(model, "nvrtcCompileProgram", (void **) &nvrtc_compile_program)
        || !king_inference_cuda_device_vector_ops_nvrtc_symbol(model, "nvrtcGetPTXSize", (void **) &nvrtc_get_ptx_size)
        || !king_inference_cuda_device_vector_ops_nvrtc_symbol(model, "nvrtcGetPTX", (void **) &nvrtc_get_ptx)
        || !king_inference_cuda_device_vector_ops_nvrtc_symbol(model, "nvrtcDestroyProgram", (void **) &nvrtc_destroy_program)) {
        return false;
    }

    result = nvrtc_create_program(
        &program,
        king_inference_cuda_device_vector_ops_source,
        "king_device_vector_ops.cu",
        0,
        NULL,
        NULL
    );
    if (result != 0 || program == NULL) {
        king_inference_cuda_device_vector_ops_set_error(
            model,
            result,
            result != 0 ? "nvrtcCreateProgram_failed" : "nvrtc_program_unavailable"
        );
        return false;
    }
    result = nvrtc_compile_program(program, 3, options);
    if (result != 0) {
        (void) nvrtc_destroy_program(&program);
        king_inference_cuda_device_vector_ops_set_error(model, result, "nvrtcCompileProgram_failed");
        return false;
    }
    result = nvrtc_get_ptx_size(program, &ptx_size);
    if (result != 0 || ptx_size == 0) {
        (void) nvrtc_destroy_program(&program);
        king_inference_cuda_device_vector_ops_set_error(
            model,
            result,
            result != 0 ? "nvrtcGetPTXSize_failed" : "nvrtc_ptx_empty"
        );
        return false;
    }
    ptx = emalloc(ptx_size);
    result = nvrtc_get_ptx(program, ptx);
    (void) nvrtc_destroy_program(&program);
    if (result != 0) {
        efree(ptx);
        king_inference_cuda_device_vector_ops_set_error(model, result, "nvrtcGetPTX_failed");
        return false;
    }

    *ptx_out = ptx;
    *ptx_size_out = ptx_size;
    return true;
}

static bool king_inference_cuda_device_vector_ops_get_function(
    king_inference_model_object *model,
    void *module,
    const char *name,
    void **function_out
) {
    king_inference_cuda_module_get_function_fn cu_module_get_function;
    int result;

    if (!king_inference_cuda_device_vector_ops_driver_symbol(
            model,
            "cuModuleGetFunction",
            (void **) &cu_module_get_function
        )) {
        return false;
    }
    result = cu_module_get_function(function_out, module, name);
    if (result != 0 || *function_out == NULL) {
        king_inference_cuda_device_vector_ops_set_error(
            model,
            result,
            result != 0 ? "cuModuleGetFunction_failed" : name
        );
        return false;
    }

    return true;
}

static void king_inference_cuda_device_vector_ops_release(king_inference_model_object *model)
{
    king_inference_cuda_module_unload_fn cu_module_unload;

    if (model->cuda_device_vector_ops_module != NULL
        && model->cuda_driver_handle != NULL
        && king_inference_cuda_device_allocator_set_current(model)
        && king_inference_cuda_device_vector_ops_driver_symbol(
            model,
            "cuModuleUnload",
            (void **) &cu_module_unload
        )) {
        (void) cu_module_unload(model->cuda_device_vector_ops_module);
    }
    if (model->cuda_device_vector_ops_nvrtc_handle != NULL) {
        dlclose(model->cuda_device_vector_ops_nvrtc_handle);
    }

    king_inference_cuda_device_vector_ops_reset_fields(model);
}

static void king_inference_cuda_device_vector_ops_init(
    king_inference_model_object *model,
    king_inference_backend_kind backend_kind
) {
    king_inference_cuda_module_load_data_fn cu_module_load_data;
    void *module = NULL;
    char *ptx = NULL;
    size_t ptx_size = 0;
    int result;

    king_inference_cuda_device_vector_ops_release(model);
    if (backend_kind != KING_INFERENCE_BACKEND_KING_NATIVE_GPU
        || !king_inference_gpu_enabled(&model->config)) {
        return;
    }

    model->cuda_device_vector_ops_attempted = true;
    if (!model->cuda_device_allocator_available) {
        king_inference_cuda_device_vector_ops_set_error(model, -1, "cuda_device_allocator_unavailable");
        return;
    }
    if (!king_inference_cuda_device_allocator_set_current(model)) {
        king_inference_cuda_device_vector_ops_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_context_current_failed"
        );
        return;
    }
    if (!king_inference_cuda_device_vector_ops_open_nvrtc(model)
        || !king_inference_cuda_device_vector_ops_compile_ptx(model, &ptx, &ptx_size)) {
        return;
    }
    if (!king_inference_cuda_device_vector_ops_driver_symbol(model, "cuModuleLoadData", (void **) &cu_module_load_data)) {
        efree(ptx);
        return;
    }

    result = cu_module_load_data(&module, ptx);
    efree(ptx);
    if (result != 0 || module == NULL) {
        king_inference_cuda_device_vector_ops_set_error(
            model,
            result,
            result != 0 ? "cuModuleLoadData_failed" : "cuda_module_unavailable"
        );
        return;
    }

    if (!king_inference_cuda_device_vector_ops_get_function(model, module, "king_vector_add", &model->cuda_vector_add_function)
        || !king_inference_cuda_device_vector_ops_get_function(model, module, "king_vector_mul", &model->cuda_vector_mul_function)
        || !king_inference_cuda_device_vector_ops_get_function(model, module, "king_vector_scale", &model->cuda_vector_scale_function)
        || !king_inference_cuda_device_vector_ops_get_function(model, module, "king_vector_silu", &model->cuda_vector_silu_function)
        || !king_inference_cuda_device_vector_ops_get_function(model, module, "king_vector_slice", &model->cuda_vector_slice_function)
        || !king_inference_cuda_device_vector_ops_get_function(
            model,
            module,
            "king_vector_copy_to_offset",
            &model->cuda_vector_copy_to_offset_function
        )) {
        king_inference_cuda_module_unload_fn cu_module_unload = NULL;

        if (king_inference_cuda_device_vector_ops_driver_symbol(
                model,
                "cuModuleUnload",
                (void **) &cu_module_unload
            )
            && cu_module_unload != NULL) {
            (void) cu_module_unload(module);
        }
        return;
    }

    model->cuda_device_vector_ops_module = module;
    model->cuda_device_vector_ops_module_loaded = true;
    model->cuda_vector_add_available = true;
    model->cuda_vector_mul_available = true;
    model->cuda_vector_scale_available = true;
    model->cuda_vector_silu_available = true;
    model->cuda_vector_slice_available = true;
    model->cuda_vector_copy_to_offset_available = true;
    model->cuda_device_vector_ops_available = true;
    model->cuda_device_vector_ops_result = 0;
    model->cuda_device_vector_ops_error[0] = '\0';
}

static zend_result king_inference_cuda_device_vector_ops_launch(
    king_inference_model_object *model,
    void *function,
    void **params,
    zend_ulong length
) {
    king_inference_cuda_launch_kernel_fn cu_launch_kernel;
    static king_inference_cuda_launch_kernel_fn cached_cu_launch_kernel = NULL;
    unsigned int block_x = 256;
    unsigned int grid_x;
    int result;

    if (!model->cuda_device_vector_ops_available || function == NULL) {
        king_inference_cuda_device_vector_ops_set_error(model, -1, "cuda_device_vector_ops_unavailable");
        return FAILURE;
    }
    if (length == 0 || length > UINT_MAX) {
        king_inference_cuda_device_vector_ops_set_error(model, -1, "cuda_device_vector_ops_shape_invalid");
        return FAILURE;
    }
    if (cached_cu_launch_kernel == NULL
        && !king_inference_cuda_device_vector_ops_driver_symbol(model, "cuLaunchKernel", (void **) &cached_cu_launch_kernel)) {
        return FAILURE;
    }
    cu_launch_kernel = cached_cu_launch_kernel;

    grid_x = (((unsigned int) length) + block_x - 1) / block_x;
    result = cu_launch_kernel(function, grid_x, 1, 1, block_x, 1, 1, 0, NULL, params, NULL);
    if (result != 0) {
        king_inference_cuda_device_vector_ops_set_error(model, result, "cuLaunchKernel_failed");
        return FAILURE;
    }
    model->cuda_device_vector_ops_launch_count++;
    model->cuda_device_vector_ops_last_length = length;
    model->cuda_device_vector_ops_result = 0;
    model->cuda_device_vector_ops_error[0] = '\0';
    return SUCCESS;
}

static zend_result king_inference_cuda_vector_binary(
    king_inference_model_object *model,
    void *function,
    king_inference_cuda_device_ptr left,
    king_inference_cuda_device_ptr right,
    king_inference_cuda_device_ptr output,
    zend_ulong length
) {
    unsigned int length32 = (unsigned int) length;
    void *params[4];

    if (left == 0 || right == 0 || output == 0) {
        king_inference_cuda_device_vector_ops_set_error(model, -1, "cuda_vector_argument_invalid");
        return FAILURE;
    }
    params[0] = &left;
    params[1] = &right;
    params[2] = &output;
    params[3] = &length32;
    return king_inference_cuda_device_vector_ops_launch(model, function, params, length);
}

static zend_result king_inference_cuda_vector_add(
    king_inference_model_object *model,
    king_inference_cuda_device_ptr left,
    king_inference_cuda_device_ptr right,
    king_inference_cuda_device_ptr output,
    zend_ulong length
) {
    return king_inference_cuda_vector_binary(model, model->cuda_vector_add_function, left, right, output, length);
}

static zend_result king_inference_cuda_vector_mul(
    king_inference_model_object *model,
    king_inference_cuda_device_ptr left,
    king_inference_cuda_device_ptr right,
    king_inference_cuda_device_ptr output,
    zend_ulong length
) {
    return king_inference_cuda_vector_binary(model, model->cuda_vector_mul_function, left, right, output, length);
}

static zend_result king_inference_cuda_vector_scale(
    king_inference_model_object *model,
    king_inference_cuda_device_ptr input,
    double scale,
    king_inference_cuda_device_ptr output,
    zend_ulong length
) {
    float scale32 = (float) scale;
    unsigned int length32 = (unsigned int) length;
    void *params[4];

    if (input == 0 || output == 0 || !isfinite(scale)) {
        king_inference_cuda_device_vector_ops_set_error(model, -1, "cuda_vector_scale_argument_invalid");
        return FAILURE;
    }
    params[0] = &input;
    params[1] = &scale32;
    params[2] = &output;
    params[3] = &length32;
    return king_inference_cuda_device_vector_ops_launch(
        model,
        model->cuda_vector_scale_function,
        params,
        length
    );
}

static zend_result king_inference_cuda_vector_silu(
    king_inference_model_object *model,
    king_inference_cuda_device_ptr input,
    king_inference_cuda_device_ptr output,
    zend_ulong length
) {
    unsigned int length32 = (unsigned int) length;
    void *params[3];

    if (input == 0 || output == 0) {
        king_inference_cuda_device_vector_ops_set_error(model, -1, "cuda_vector_silu_argument_invalid");
        return FAILURE;
    }
    params[0] = &input;
    params[1] = &output;
    params[2] = &length32;
    return king_inference_cuda_device_vector_ops_launch(model, model->cuda_vector_silu_function, params, length);
}

static zend_result king_inference_cuda_vector_slice(
    king_inference_model_object *model,
    king_inference_cuda_device_ptr input,
    zend_ulong offset,
    king_inference_cuda_device_ptr output,
    zend_ulong length
) {
    unsigned int offset32;
    unsigned int length32 = (unsigned int) length;
    void *params[4];

    if (input == 0 || output == 0 || offset > UINT_MAX) {
        king_inference_cuda_device_vector_ops_set_error(model, -1, "cuda_vector_slice_argument_invalid");
        return FAILURE;
    }
    offset32 = (unsigned int) offset;
    params[0] = &input;
    params[1] = &offset32;
    params[2] = &output;
    params[3] = &length32;
    return king_inference_cuda_device_vector_ops_launch(model, model->cuda_vector_slice_function, params, length);
}

static zend_result king_inference_cuda_vector_copy_to_offset(
    king_inference_model_object *model,
    king_inference_cuda_device_ptr input,
    king_inference_cuda_device_ptr output,
    zend_ulong output_offset,
    zend_ulong length
) {
    unsigned int output_offset32;
    unsigned int length32 = (unsigned int) length;
    void *params[4];

    if (input == 0 || output == 0 || output_offset > UINT_MAX) {
        king_inference_cuda_device_vector_ops_set_error(
            model,
            -1,
            "cuda_vector_copy_to_offset_argument_invalid"
        );
        return FAILURE;
    }
    output_offset32 = (unsigned int) output_offset;
    params[0] = &input;
    params[1] = &output;
    params[2] = &output_offset32;
    params[3] = &length32;
    return king_inference_cuda_device_vector_ops_launch(
        model,
        model->cuda_vector_copy_to_offset_function,
        params,
        length
    );
}

static void king_inference_cuda_device_vector_ops_add_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval ops;
    zval supported_ops;
    zval compare_hook;

    array_init(&ops);
    add_assoc_bool(&ops, "attempted", model->cuda_device_vector_ops_attempted);
    add_assoc_bool(&ops, "available", model->cuda_device_vector_ops_available);
    add_assoc_bool(&ops, "nvrtc_available", model->cuda_device_vector_ops_nvrtc_available);
    add_assoc_bool(&ops, "module_loaded", model->cuda_device_vector_ops_module_loaded);
    add_assoc_bool(&ops, "add_available", model->cuda_vector_add_available);
    add_assoc_bool(&ops, "mul_available", model->cuda_vector_mul_available);
    add_assoc_bool(&ops, "scale_available", model->cuda_vector_scale_available);
    add_assoc_bool(&ops, "silu_available", model->cuda_vector_silu_available);
    add_assoc_bool(&ops, "slice_available", model->cuda_vector_slice_available);
    add_assoc_bool(&ops, "copy_to_offset_available", model->cuda_vector_copy_to_offset_available);
    add_assoc_long(&ops, "launches", (zend_long) model->cuda_device_vector_ops_launch_count);
    add_assoc_long(&ops, "last_length", (zend_long) model->cuda_device_vector_ops_last_length);
    add_assoc_long(&ops, "result", model->cuda_device_vector_ops_result);
    add_assoc_string(&ops, "error", model->cuda_device_vector_ops_error);
    array_init(&supported_ops);
    add_next_index_string(&supported_ops, "add");
    add_next_index_string(&supported_ops, "mul");
    add_next_index_string(&supported_ops, "scale");
    add_next_index_string(&supported_ops, "silu");
    add_next_index_string(&supported_ops, "slice");
    add_next_index_string(&supported_ops, "copy_to_offset");
    add_assoc_zval(&ops, "supported_ops", &supported_ops);
    king_inference_cuda_device_vector_numeric_compare_status(model, &compare_hook);
    add_assoc_zval(&ops, "numeric_compare_hook", &compare_hook);
    add_assoc_zval(return_value, "device_vector_ops", &ops);
    add_assoc_bool(return_value, "gpu_device_vector_ops_ready", model->cuda_device_vector_ops_available);
}

static void king_inference_cuda_device_vector_ops_apply_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval *reason;
    bool reason_can_be_vector_ops;

    king_inference_cuda_device_vector_ops_add_status(model, return_value);
    if (!model->cuda_device_vector_ops_attempted
        || model->cuda_device_vector_ops_available
        || !model->cuda_device_allocator_available) {
        return;
    }

    reason = zend_hash_str_find(Z_ARRVAL_P(return_value), "reason", sizeof("reason") - 1);
    reason_can_be_vector_ops = reason == NULL
        || Z_TYPE_P(reason) != IS_STRING
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_ready")
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_decoder_kernel_not_implemented");
    if (reason_can_be_vector_ops) {
        king_inference_cuda_context_prepend_return_reason(
            return_value,
            "gpu_device_vector_ops_unavailable"
        );
        add_assoc_string(return_value, "reason", "gpu_device_vector_ops_unavailable");
    } else {
        king_inference_gpu_status_append_return_reason(return_value, "gpu_device_vector_ops_unavailable");
    }
}
