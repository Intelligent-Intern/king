/*
 * CUDA quantized matrix/vector kernels for native King GPU inference.
 */

typedef int king_inference_nvrtc_result;
typedef void *king_inference_nvrtc_program;

typedef king_inference_nvrtc_result (*king_inference_nvrtc_create_program_fn)(
    king_inference_nvrtc_program *program,
    const char *source,
    const char *name,
    int header_count,
    const char * const *headers,
    const char * const *include_names
);
typedef king_inference_nvrtc_result (*king_inference_nvrtc_compile_program_fn)(
    king_inference_nvrtc_program program,
    int option_count,
    const char * const *options
);
typedef king_inference_nvrtc_result (*king_inference_nvrtc_get_ptx_size_fn)(
    king_inference_nvrtc_program program,
    size_t *ptx_size
);
typedef king_inference_nvrtc_result (*king_inference_nvrtc_get_ptx_fn)(
    king_inference_nvrtc_program program,
    char *ptx
);
typedef king_inference_nvrtc_result (*king_inference_nvrtc_destroy_program_fn)(
    king_inference_nvrtc_program *program
);

typedef int (*king_inference_cuda_module_load_data_fn)(void **module, const void *image);
typedef int (*king_inference_cuda_module_get_function_fn)(
    void **function,
    void *module,
    const char *name
);
typedef int (*king_inference_cuda_module_unload_fn)(void *module);
typedef int (*king_inference_cuda_launch_kernel_fn)(
    void *function,
    unsigned int grid_x,
    unsigned int grid_y,
    unsigned int grid_z,
    unsigned int block_x,
    unsigned int block_y,
    unsigned int block_z,
    unsigned int shared_memory_bytes,
    void *stream,
    void **kernel_params,
    void **extra
);
typedef int (*king_inference_cuda_ctx_synchronize_fn)(void);

static const char king_inference_cuda_q8_0_matvec_source[] =
"extern \"C\" __device__ float king_half_to_float(unsigned short h) {\n"
"    unsigned int sign = ((unsigned int) (h & 0x8000u)) << 16;\n"
"    unsigned int mantissa = (unsigned int) (h & 0x03ffu);\n"
"    int exponent = (int) ((h >> 10) & 0x1fu);\n"
"    unsigned int bits;\n"
"    if (exponent == 0) {\n"
"        if (mantissa == 0u) {\n"
"            bits = sign;\n"
"        } else {\n"
"            while ((mantissa & 0x0400u) == 0u) {\n"
"                mantissa <<= 1;\n"
"                exponent--;\n"
"            }\n"
"            exponent++;\n"
"            mantissa &= 0x03ffu;\n"
"            bits = sign | ((unsigned int) (exponent + 112) << 23) | (mantissa << 13);\n"
"        }\n"
"        return __uint_as_float(bits);\n"
"    }\n"
"    if (exponent == 31) {\n"
"        bits = sign | 0x7f800000u | (mantissa << 13);\n"
"        return __uint_as_float(bits);\n"
"    }\n"
"    bits = sign | ((unsigned int) (exponent + 112) << 23) | (mantissa << 13);\n"
"    return __uint_as_float(bits);\n"
"}\n"
"extern \"C\" __global__ void king_q8_0_matvec(\n"
"    const unsigned char *weights,\n"
"    const float *vector,\n"
"    float *output,\n"
"    unsigned int columns,\n"
"    unsigned int rows\n"
") {\n"
"    unsigned int row = blockIdx.x;\n"
"    unsigned int lane_id = threadIdx.x;\n"
"    __shared__ float partials[128];\n"
"    if (row >= rows || columns == 0u) {\n"
"        return;\n"
"    }\n"
"    unsigned int blocks_per_row = (columns + 31u) >> 5;\n"
"    const unsigned char *row_weights = weights + ((unsigned long long) row * blocks_per_row * 34ull);\n"
"    float sum = 0.0f;\n"
"    for (unsigned int col = lane_id; col < columns; col += blockDim.x) {\n"
"        unsigned int block = col >> 5;\n"
"        unsigned int lane = col & 31u;\n"
"        const unsigned char *qblock = row_weights + ((unsigned long long) block * 34ull);\n"
"        unsigned short raw_scale = (unsigned short) qblock[0] | ((unsigned short) qblock[1] << 8);\n"
"        float scale = king_half_to_float(raw_scale);\n"
"        signed char quant = ((const signed char *) (qblock + 2))[lane];\n"
"        sum += ((float) quant * scale) * vector[col];\n"
"    }\n"
"    partials[lane_id] = sum;\n"
"    __syncthreads();\n"
"    for (unsigned int stride = blockDim.x >> 1; stride > 0u; stride >>= 1) {\n"
"        if (lane_id < stride) {\n"
"            partials[lane_id] += partials[lane_id + stride];\n"
"        }\n"
"        __syncthreads();\n"
"    }\n"
"    if (lane_id == 0u) {\n"
"        output[row] = partials[0];\n"
"    }\n"
"}\n";

static void king_inference_cuda_quantized_matvec_reset_fields(king_inference_model_object *model)
{
    model->cuda_nvrtc_handle = NULL;
    model->cuda_quantized_matvec_module = NULL;
    model->cuda_q8_0_matvec_function = NULL;
    model->cuda_quantized_matvec_result = 0;
    model->cuda_quantized_matvec_error[0] = '\0';
    model->cuda_quantized_matvec_launch_count = 0;
    model->cuda_quantized_matvec_attempted = false;
    model->cuda_quantized_matvec_available = false;
    model->cuda_quantized_matvec_nvrtc_available = false;
    model->cuda_quantized_matvec_module_loaded = false;
    model->cuda_quantized_matvec_q8_0_available = false;
}

static void king_inference_cuda_quantized_matvec_set_error(
    king_inference_model_object *model,
    int result,
    const char *message
) {
    if (message == NULL || message[0] == '\0') {
        message = "unknown_cuda_quantized_matvec_error";
    }

    model->cuda_quantized_matvec_result = result;
    snprintf(
        model->cuda_quantized_matvec_error,
        sizeof(model->cuda_quantized_matvec_error),
        "%s",
        message
    );
}

static bool king_inference_cuda_quantized_matvec_driver_symbol(
    king_inference_model_object *model,
    const char *name,
    void **symbol
) {
    *symbol = NULL;
    if (model->cuda_driver_handle == NULL) {
        king_inference_cuda_quantized_matvec_set_error(model, -1, "cuda_driver_handle_unavailable");
        return false;
    }

    *symbol = dlsym(model->cuda_driver_handle, name);
    if (*symbol != NULL) {
        return true;
    }

    king_inference_cuda_quantized_matvec_set_error(model, -1, name);
    return false;
}

static bool king_inference_cuda_quantized_matvec_nvrtc_symbol(
    king_inference_model_object *model,
    const char *name,
    void **symbol
) {
    *symbol = NULL;
    if (model->cuda_nvrtc_handle == NULL) {
        king_inference_cuda_quantized_matvec_set_error(model, -1, "nvrtc_handle_unavailable");
        return false;
    }

    *symbol = dlsym(model->cuda_nvrtc_handle, name);
    if (*symbol != NULL) {
        return true;
    }

    king_inference_cuda_quantized_matvec_set_error(model, -1, name);
    return false;
}

static bool king_inference_cuda_quantized_matvec_open_nvrtc(king_inference_model_object *model)
{
    static const char *libraries[] = {
        "libnvrtc.so.12",
        "libnvrtc.so.11.2",
        "libnvrtc.so.11.0",
        "libnvrtc.so"
    };
    size_t index;

    for (index = 0; index < sizeof(libraries) / sizeof(libraries[0]); index++) {
        model->cuda_nvrtc_handle = dlopen(libraries[index], RTLD_LAZY | RTLD_LOCAL);
        if (model->cuda_nvrtc_handle != NULL) {
            model->cuda_quantized_matvec_nvrtc_available = true;
            return true;
        }
    }

    king_inference_cuda_quantized_matvec_set_error(model, -1, "libnvrtc_unavailable");
    return false;
}

static bool king_inference_cuda_quantized_matvec_compile_ptx(
    king_inference_model_object *model,
    char **ptx,
    size_t *ptx_size
) {
    king_inference_nvrtc_create_program_fn nvrtc_create_program;
    king_inference_nvrtc_compile_program_fn nvrtc_compile_program;
    king_inference_nvrtc_get_ptx_size_fn nvrtc_get_ptx_size;
    king_inference_nvrtc_get_ptx_fn nvrtc_get_ptx;
    king_inference_nvrtc_destroy_program_fn nvrtc_destroy_program;
    king_inference_nvrtc_program program = NULL;
    const char *options[] = {"--std=c++11", "--gpu-architecture=compute_52"};
    king_inference_nvrtc_result result;

    *ptx = NULL;
    *ptx_size = 0;

    if (!king_inference_cuda_quantized_matvec_nvrtc_symbol(
            model,
            "nvrtcCreateProgram",
            (void **) &nvrtc_create_program
        )
        || !king_inference_cuda_quantized_matvec_nvrtc_symbol(
            model,
            "nvrtcCompileProgram",
            (void **) &nvrtc_compile_program
        )
        || !king_inference_cuda_quantized_matvec_nvrtc_symbol(
            model,
            "nvrtcGetPTXSize",
            (void **) &nvrtc_get_ptx_size
        )
        || !king_inference_cuda_quantized_matvec_nvrtc_symbol(
            model,
            "nvrtcGetPTX",
            (void **) &nvrtc_get_ptx
        )
        || !king_inference_cuda_quantized_matvec_nvrtc_symbol(
            model,
            "nvrtcDestroyProgram",
            (void **) &nvrtc_destroy_program
        )) {
        return false;
    }

    result = nvrtc_create_program(
        &program,
        king_inference_cuda_q8_0_matvec_source,
        "king_q8_0_matvec.cu",
        0,
        NULL,
        NULL
    );
    if (result != 0 || program == NULL) {
        king_inference_cuda_quantized_matvec_set_error(
            model,
            result,
            result != 0 ? "nvrtcCreateProgram_failed" : "nvrtcProgram_unavailable"
        );
        return false;
    }

    result = nvrtc_compile_program(program, 2, options);
    if (result != 0) {
        (void) nvrtc_destroy_program(&program);
        king_inference_cuda_quantized_matvec_set_error(model, result, "nvrtcCompileProgram_failed");
        return false;
    }

    result = nvrtc_get_ptx_size(program, ptx_size);
    if (result != 0 || *ptx_size == 0) {
        (void) nvrtc_destroy_program(&program);
        king_inference_cuda_quantized_matvec_set_error(
            model,
            result,
            result != 0 ? "nvrtcGetPTXSize_failed" : "nvrtc_ptx_empty"
        );
        return false;
    }

    *ptx = emalloc(*ptx_size);
    result = nvrtc_get_ptx(program, *ptx);
    (void) nvrtc_destroy_program(&program);
    if (result != 0) {
        efree(*ptx);
        *ptx = NULL;
        *ptx_size = 0;
        king_inference_cuda_quantized_matvec_set_error(model, result, "nvrtcGetPTX_failed");
        return false;
    }

    return true;
}

static void king_inference_cuda_quantized_matvec_release(king_inference_model_object *model)
{
    king_inference_cuda_module_unload_fn cu_module_unload;

    if (model->cuda_quantized_matvec_module != NULL
        && model->cuda_driver_handle != NULL
        && model->cuda_context != NULL
        && king_inference_cuda_device_allocator_set_current(model)
        && king_inference_cuda_quantized_matvec_driver_symbol(
            model,
            "cuModuleUnload",
            (void **) &cu_module_unload
        )) {
        (void) cu_module_unload(model->cuda_quantized_matvec_module);
    }

    if (model->cuda_nvrtc_handle != NULL) {
        dlclose(model->cuda_nvrtc_handle);
    }

    king_inference_cuda_quantized_matvec_reset_fields(model);
}

static void king_inference_cuda_quantized_matvec_init(
    king_inference_model_object *model,
    king_inference_backend_kind backend_kind
) {
    king_inference_cuda_module_load_data_fn cu_module_load_data;
    king_inference_cuda_module_get_function_fn cu_module_get_function;
    char *ptx = NULL;
    size_t ptx_size = 0;
    void *module = NULL;
    void *function = NULL;
    int result;

    king_inference_cuda_quantized_matvec_release(model);
    if (backend_kind != KING_INFERENCE_BACKEND_KING_NATIVE_GPU
        || !king_inference_gpu_enabled(&model->config)) {
        return;
    }

    model->cuda_quantized_matvec_attempted = true;
    if (!model->cuda_weight_cache_ready) {
        king_inference_cuda_quantized_matvec_set_error(model, -1, "cuda_weight_cache_unavailable");
        return;
    }
    if (!king_inference_cuda_device_allocator_set_current(model)) {
        king_inference_cuda_quantized_matvec_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_context_current_failed"
        );
        return;
    }
    if (!king_inference_cuda_quantized_matvec_open_nvrtc(model)
        || !king_inference_cuda_quantized_matvec_compile_ptx(model, &ptx, &ptx_size)) {
        return;
    }
    if (!king_inference_cuda_quantized_matvec_driver_symbol(
            model,
            "cuModuleLoadData",
            (void **) &cu_module_load_data
        )
        || !king_inference_cuda_quantized_matvec_driver_symbol(
            model,
            "cuModuleGetFunction",
            (void **) &cu_module_get_function
        )) {
        efree(ptx);
        return;
    }

    result = cu_module_load_data(&module, ptx);
    efree(ptx);
    if (result != 0 || module == NULL) {
        king_inference_cuda_quantized_matvec_set_error(
            model,
            result,
            result != 0 ? "cuModuleLoadData_failed" : "cuda_module_unavailable"
        );
        return;
    }

    result = cu_module_get_function(&function, module, "king_q8_0_matvec");
    if (result != 0 || function == NULL) {
        king_inference_cuda_module_unload_fn cu_module_unload = NULL;

        if (king_inference_cuda_quantized_matvec_driver_symbol(
                model,
                "cuModuleUnload",
                (void **) &cu_module_unload
            )
            && cu_module_unload != NULL) {
            (void) cu_module_unload(module);
        }
        king_inference_cuda_quantized_matvec_set_error(
            model,
            result,
            result != 0 ? "cuModuleGetFunction_failed" : "cuda_q8_0_kernel_unavailable"
        );
        return;
    }

    model->cuda_quantized_matvec_module = module;
    model->cuda_q8_0_matvec_function = function;
    model->cuda_quantized_matvec_module_loaded = true;
    model->cuda_quantized_matvec_q8_0_available = true;
    model->cuda_quantized_matvec_available = true;
    model->cuda_quantized_matvec_result = 0;
    model->cuda_quantized_matvec_error[0] = '\0';
}

static zend_result king_inference_cuda_quantized_matvec_q8_0(
    king_inference_model_object *model,
    zend_string *tensor_name,
    king_inference_cuda_device_ptr input_vector,
    zend_ulong input_length,
    king_inference_cuda_device_ptr output_vector,
    zend_ulong rows
) {
    king_inference_cuda_weight_upload *upload;
    king_inference_cuda_launch_kernel_fn cu_launch_kernel;
    king_inference_cuda_ctx_synchronize_fn cu_ctx_synchronize;
    zval *descriptor;
    zval *dimensions;
    zend_ulong type;
    zend_ulong rank;
    zend_ulong cols;
    zend_ulong tensor_rows;
    unsigned int columns32;
    unsigned int rows32;
    unsigned int block_x = 128;
    unsigned int grid_x;
    void *params[5];
    int result;

    if (!model->cuda_quantized_matvec_available || model->cuda_q8_0_matvec_function == NULL) {
        king_inference_cuda_quantized_matvec_set_error(model, -1, "cuda_quantized_matvec_unavailable");
        return FAILURE;
    }
    if (tensor_name == NULL || input_vector == 0 || output_vector == 0) {
        king_inference_cuda_quantized_matvec_set_error(model, -1, "cuda_quantized_matvec_argument_invalid");
        return FAILURE;
    }
    if (input_length == 0 || input_length > UINT_MAX || rows == 0 || rows > UINT_MAX) {
        king_inference_cuda_quantized_matvec_set_error(model, -1, "cuda_quantized_matvec_shape_invalid");
        return FAILURE;
    }

    descriptor = king_inference_tensor_index_descriptor(model, tensor_name);
    dimensions = descriptor != NULL
        ? zend_hash_str_find(Z_ARRVAL_P(descriptor), "dimensions", sizeof("dimensions") - 1)
        : NULL;
    if (descriptor == NULL
        || dimensions == NULL
        || Z_TYPE_P(dimensions) != IS_ARRAY
        || !king_inference_tensor_descriptor_ulong(descriptor, "rank", sizeof("rank") - 1, &rank)
        || !king_inference_tensor_descriptor_ulong(descriptor, "type", sizeof("type") - 1, &type)
        || rank != 2
        || type != 8
        || king_inference_tensor_dimension_at(dimensions, 0, &cols) != SUCCESS
        || king_inference_tensor_dimension_at(dimensions, 1, &tensor_rows) != SUCCESS
        || cols != input_length
        || rows > tensor_rows) {
        king_inference_cuda_quantized_matvec_set_error(model, -1, "cuda_q8_0_tensor_descriptor_invalid");
        return FAILURE;
    }

    upload = king_inference_cuda_weight_cache_find(model, tensor_name, true);
    if (upload == NULL || upload->device_ptr == 0) {
        king_inference_cuda_quantized_matvec_set_error(model, -1, "cuda_q8_0_weight_not_uploaded");
        return FAILURE;
    }
    if (!king_inference_cuda_device_allocator_set_current(model)
        || !king_inference_cuda_quantized_matvec_driver_symbol(
            model,
            "cuLaunchKernel",
            (void **) &cu_launch_kernel
        )
        || !king_inference_cuda_quantized_matvec_driver_symbol(
            model,
            "cuCtxSynchronize",
            (void **) &cu_ctx_synchronize
        )) {
        return FAILURE;
    }

    columns32 = (unsigned int) input_length;
    rows32 = (unsigned int) rows;
    grid_x = rows32;
    params[0] = &upload->device_ptr;
    params[1] = &input_vector;
    params[2] = &output_vector;
    params[3] = &columns32;
    params[4] = &rows32;

    result = cu_launch_kernel(
        model->cuda_q8_0_matvec_function,
        grid_x,
        1,
        1,
        block_x,
        1,
        1,
        0,
        NULL,
        params,
        NULL
    );
    if (result != 0) {
        king_inference_cuda_quantized_matvec_set_error(model, result, "cuLaunchKernel_failed");
        return FAILURE;
    }

    result = cu_ctx_synchronize();
    if (result != 0) {
        king_inference_cuda_quantized_matvec_set_error(model, result, "cuCtxSynchronize_failed");
        return FAILURE;
    }

    model->cuda_quantized_matvec_launch_count++;
    model->cuda_quantized_matvec_result = 0;
    model->cuda_quantized_matvec_error[0] = '\0';
    return SUCCESS;
}

static void king_inference_cuda_quantized_matvec_add_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval matvec;
    zval supported_types;

    array_init(&matvec);
    add_assoc_bool(&matvec, "attempted", model->cuda_quantized_matvec_attempted);
    add_assoc_bool(&matvec, "available", model->cuda_quantized_matvec_available);
    add_assoc_bool(&matvec, "nvrtc_available", model->cuda_quantized_matvec_nvrtc_available);
    add_assoc_bool(&matvec, "module_loaded", model->cuda_quantized_matvec_module_loaded);
    add_assoc_bool(&matvec, "q8_0_available", model->cuda_quantized_matvec_q8_0_available);
    add_assoc_long(&matvec, "launches", (zend_long) model->cuda_quantized_matvec_launch_count);
    add_assoc_long(&matvec, "result", model->cuda_quantized_matvec_result);
    add_assoc_string(&matvec, "error", model->cuda_quantized_matvec_error);

    array_init(&supported_types);
    add_next_index_string(&supported_types, "Q8_0");
    add_assoc_zval(&matvec, "supported_types", &supported_types);
    add_assoc_zval(return_value, "quantized_matvec", &matvec);
}

static void king_inference_cuda_quantized_matvec_apply_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval *reason;
    bool reason_can_be_matvec;

    king_inference_cuda_quantized_matvec_add_status(model, return_value);
    if (!model->cuda_quantized_matvec_attempted
        || model->cuda_quantized_matvec_available
        || !model->cuda_weight_upload_complete) {
        return;
    }

    reason = zend_hash_str_find(Z_ARRVAL_P(return_value), "reason", sizeof("reason") - 1);
    reason_can_be_matvec = reason == NULL
        || Z_TYPE_P(reason) != IS_STRING
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_ready")
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_decoder_kernel_not_implemented");
    if (reason_can_be_matvec) {
        king_inference_cuda_context_prepend_return_reason(
            return_value,
            "gpu_quantized_matvec_unavailable"
        );
        add_assoc_string(return_value, "reason", "gpu_quantized_matvec_unavailable");
    } else {
        king_inference_gpu_status_append_return_reason(return_value, "gpu_quantized_matvec_unavailable");
    }
}
