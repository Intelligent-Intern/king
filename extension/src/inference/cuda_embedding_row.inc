/*
 * CUDA token embedding row loader for native King GPU inference.
 */

static const char king_inference_cuda_embedding_row_source[] =
"extern \"C\" __device__ float king_embedding_half_to_float(unsigned short h) {\n"
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
"extern \"C\" __device__ float king_embedding_bfloat16_to_float(unsigned short h) {\n"
"    unsigned int bits = ((unsigned int) h) << 16;\n"
"    return __uint_as_float(bits);\n"
"}\n"
"extern \"C\" __global__ void king_embedding_row_load(\n"
"    const unsigned char *embedding,\n"
"    float *output,\n"
"    unsigned int width,\n"
"    unsigned int rows,\n"
"    unsigned int token_id,\n"
"    unsigned int tensor_type\n"
") {\n"
"    unsigned int col = blockIdx.x * blockDim.x + threadIdx.x;\n"
"    if (embedding == 0 || output == 0 || col >= width || token_id >= rows) {\n"
"        return;\n"
"    }\n"
"    unsigned long long offset = ((unsigned long long) token_id * (unsigned long long) width) + col;\n"
"    if (tensor_type == 0u) {\n"
"        output[col] = ((const float *) embedding)[offset];\n"
"    } else if (tensor_type == 1u) {\n"
"        output[col] = king_embedding_half_to_float(((const unsigned short *) embedding)[offset]);\n"
"    } else if (tensor_type == 30u) {\n"
"        output[col] = king_embedding_bfloat16_to_float(((const unsigned short *) embedding)[offset]);\n"
"    } else if (tensor_type == 8u) {\n"
"        unsigned int blocks_per_row = (width + 31u) >> 5;\n"
"        const unsigned char *qblock = embedding\n"
"            + ((((unsigned long long) token_id * (unsigned long long) blocks_per_row) + (col >> 5)) * 34ull);\n"
"        unsigned short raw_scale = (unsigned short) qblock[0] | ((unsigned short) qblock[1] << 8);\n"
"        signed char quant = ((const signed char *) (qblock + 2))[col & 31u];\n"
"        output[col] = (float) quant * king_embedding_half_to_float(raw_scale);\n"
"    } else {\n"
"        output[col] = 0.0f;\n"
"    }\n"
"}\n";

static void king_inference_cuda_embedding_row_reset_fields(king_inference_model_object *model)
{
    model->cuda_embedding_row_nvrtc_handle = NULL;
    model->cuda_embedding_row_module = NULL;
    model->cuda_embedding_row_function = NULL;
    model->cuda_embedding_row_result = 0;
    model->cuda_embedding_row_error[0] = '\0';
    model->cuda_embedding_row_launch_count = 0;
    model->cuda_embedding_row_last_token_id = 0;
    model->cuda_embedding_row_last_width = 0;
    model->cuda_embedding_row_attempted = false;
    model->cuda_embedding_row_available = false;
    model->cuda_embedding_row_nvrtc_available = false;
    model->cuda_embedding_row_module_loaded = false;
    model->cuda_embedding_row_f32_available = false;
    model->cuda_embedding_row_f16_available = false;
    model->cuda_embedding_row_bf16_available = false;
    model->cuda_embedding_row_q8_0_available = false;
}

static void king_inference_cuda_embedding_row_set_error(
    king_inference_model_object *model,
    int result,
    const char *message
) {
    if (message == NULL || message[0] == '\0') {
        message = "unknown_cuda_embedding_row_error";
    }

    model->cuda_embedding_row_result = result;
    snprintf(model->cuda_embedding_row_error, sizeof(model->cuda_embedding_row_error), "%s", message);
}

static bool king_inference_cuda_embedding_row_driver_symbol(
    king_inference_model_object *model,
    const char *name,
    void **symbol
) {
    *symbol = NULL;
    if (model->cuda_driver_handle == NULL) {
        king_inference_cuda_embedding_row_set_error(model, -1, "cuda_driver_handle_unavailable");
        return false;
    }

    *symbol = dlsym(model->cuda_driver_handle, name);
    if (*symbol != NULL) {
        return true;
    }

    king_inference_cuda_embedding_row_set_error(model, -1, name);
    return false;
}

static bool king_inference_cuda_embedding_row_nvrtc_symbol(
    king_inference_model_object *model,
    const char *name,
    void **symbol
) {
    *symbol = NULL;
    if (model->cuda_embedding_row_nvrtc_handle == NULL) {
        king_inference_cuda_embedding_row_set_error(model, -1, "nvrtc_handle_unavailable");
        return false;
    }

    *symbol = dlsym(model->cuda_embedding_row_nvrtc_handle, name);
    if (*symbol != NULL) {
        return true;
    }

    king_inference_cuda_embedding_row_set_error(model, -1, name);
    return false;
}

static bool king_inference_cuda_embedding_row_open_nvrtc(king_inference_model_object *model)
{
    const char *libraries[] = {
        "libnvrtc.so",
        "libnvrtc.so.12",
        "libnvrtc.so.11",
        NULL
    };
    size_t index;

    for (index = 0; libraries[index] != NULL; index++) {
        model->cuda_embedding_row_nvrtc_handle = dlopen(libraries[index], RTLD_LAZY | RTLD_LOCAL);
        if (model->cuda_embedding_row_nvrtc_handle != NULL) {
            model->cuda_embedding_row_nvrtc_available = true;
            return true;
        }
    }

    king_inference_cuda_embedding_row_set_error(model, -1, "libnvrtc_unavailable");
    return false;
}

static bool king_inference_cuda_embedding_row_compile_ptx(
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
    const char *options[] = {"--std=c++11"};
    char *ptx;
    size_t ptx_size = 0;
    king_inference_nvrtc_result result;

    *ptx_out = NULL;
    *ptx_size_out = 0;
    if (!king_inference_cuda_embedding_row_nvrtc_symbol(model, "nvrtcCreateProgram", (void **) &nvrtc_create_program)
        || !king_inference_cuda_embedding_row_nvrtc_symbol(model, "nvrtcCompileProgram", (void **) &nvrtc_compile_program)
        || !king_inference_cuda_embedding_row_nvrtc_symbol(model, "nvrtcGetPTXSize", (void **) &nvrtc_get_ptx_size)
        || !king_inference_cuda_embedding_row_nvrtc_symbol(model, "nvrtcGetPTX", (void **) &nvrtc_get_ptx)
        || !king_inference_cuda_embedding_row_nvrtc_symbol(model, "nvrtcDestroyProgram", (void **) &nvrtc_destroy_program)) {
        return false;
    }

    result = nvrtc_create_program(
        &program,
        king_inference_cuda_embedding_row_source,
        "king_embedding_row.cu",
        0,
        NULL,
        NULL
    );
    if (result != 0 || program == NULL) {
        king_inference_cuda_embedding_row_set_error(
            model,
            result,
            result != 0 ? "nvrtcCreateProgram_failed" : "nvrtc_program_unavailable"
        );
        return false;
    }
    result = nvrtc_compile_program(program, 1, options);
    if (result != 0) {
        (void) nvrtc_destroy_program(&program);
        king_inference_cuda_embedding_row_set_error(model, result, "nvrtcCompileProgram_failed");
        return false;
    }
    result = nvrtc_get_ptx_size(program, &ptx_size);
    if (result != 0 || ptx_size == 0) {
        (void) nvrtc_destroy_program(&program);
        king_inference_cuda_embedding_row_set_error(
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
        king_inference_cuda_embedding_row_set_error(model, result, "nvrtcGetPTX_failed");
        return false;
    }

    *ptx_out = ptx;
    *ptx_size_out = ptx_size;
    return true;
}

static void king_inference_cuda_embedding_row_release(king_inference_model_object *model)
{
    king_inference_cuda_module_unload_fn cu_module_unload;

    if (model->cuda_embedding_row_module != NULL
        && model->cuda_driver_handle != NULL
        && king_inference_cuda_device_allocator_set_current(model)
        && king_inference_cuda_embedding_row_driver_symbol(
            model,
            "cuModuleUnload",
            (void **) &cu_module_unload
        )) {
        (void) cu_module_unload(model->cuda_embedding_row_module);
    }
    if (model->cuda_embedding_row_nvrtc_handle != NULL) {
        dlclose(model->cuda_embedding_row_nvrtc_handle);
    }

    king_inference_cuda_embedding_row_reset_fields(model);
}

static void king_inference_cuda_embedding_row_init(
    king_inference_model_object *model,
    king_inference_backend_kind backend_kind
) {
    king_inference_cuda_module_load_data_fn cu_module_load_data;
    king_inference_cuda_module_get_function_fn cu_module_get_function;
    void *module = NULL;
    void *function = NULL;
    char *ptx = NULL;
    size_t ptx_size = 0;
    int result;

    king_inference_cuda_embedding_row_release(model);
    if (backend_kind != KING_INFERENCE_BACKEND_KING_NATIVE_GPU
        || !king_inference_gpu_enabled(&model->config)) {
        return;
    }

    model->cuda_embedding_row_attempted = true;
    if (!model->cuda_weight_cache_ready) {
        king_inference_cuda_embedding_row_set_error(model, -1, "cuda_weight_cache_unavailable");
        return;
    }
    if (!king_inference_cuda_device_allocator_set_current(model)) {
        king_inference_cuda_embedding_row_set_error(
            model,
            model->cuda_device_allocator_result,
            model->cuda_device_allocator_error[0] != '\0'
                ? model->cuda_device_allocator_error
                : "cuda_context_current_failed"
        );
        return;
    }
    if (!king_inference_cuda_embedding_row_open_nvrtc(model)
        || !king_inference_cuda_embedding_row_compile_ptx(model, &ptx, &ptx_size)) {
        return;
    }
    if (!king_inference_cuda_embedding_row_driver_symbol(model, "cuModuleLoadData", (void **) &cu_module_load_data)
        || !king_inference_cuda_embedding_row_driver_symbol(model, "cuModuleGetFunction", (void **) &cu_module_get_function)) {
        efree(ptx);
        return;
    }

    result = cu_module_load_data(&module, ptx);
    efree(ptx);
    if (result != 0 || module == NULL) {
        king_inference_cuda_embedding_row_set_error(
            model,
            result,
            result != 0 ? "cuModuleLoadData_failed" : "cuda_module_unavailable"
        );
        return;
    }

    result = cu_module_get_function(&function, module, "king_embedding_row_load");
    if (result != 0 || function == NULL) {
        king_inference_cuda_module_unload_fn cu_module_unload = NULL;

        if (king_inference_cuda_embedding_row_driver_symbol(
                model,
                "cuModuleUnload",
                (void **) &cu_module_unload
            )
            && cu_module_unload != NULL) {
            (void) cu_module_unload(module);
        }
        king_inference_cuda_embedding_row_set_error(
            model,
            result,
            result != 0 ? "cuModuleGetFunction_failed" : "cuda_embedding_row_kernel_unavailable"
        );
        return;
    }

    model->cuda_embedding_row_module = module;
    model->cuda_embedding_row_function = function;
    model->cuda_embedding_row_module_loaded = true;
    model->cuda_embedding_row_f32_available = true;
    model->cuda_embedding_row_f16_available = true;
    model->cuda_embedding_row_bf16_available = true;
    model->cuda_embedding_row_q8_0_available = true;
    model->cuda_embedding_row_available = true;
    model->cuda_embedding_row_result = 0;
    model->cuda_embedding_row_error[0] = '\0';
}

static bool king_inference_cuda_embedding_row_supported_type(zend_ulong type)
{
    return type == 0 || type == 1 || type == 8 || type == 30;
}

static zend_result king_inference_cuda_embedding_row_load(
    king_inference_model_object *model,
    zend_ulong token_id,
    king_inference_cuda_device_ptr output_vector,
    zend_ulong output_length
) {
    king_inference_cuda_weight_upload *upload;
    king_inference_cuda_launch_kernel_fn cu_launch_kernel;
    king_inference_cuda_ctx_synchronize_fn cu_ctx_synchronize;
    zend_string *tensor = NULL;
    const char *status = NULL;
    zval *descriptor;
    zval *dimensions;
    zend_ulong type;
    zend_ulong rank;
    zend_ulong cols;
    zend_ulong rows;
    unsigned int width32;
    unsigned int rows32;
    unsigned int token32;
    unsigned int type32;
    unsigned int block_x = 256;
    unsigned int grid_x;
    void *params[6];
    int result;

    if (!model->cuda_embedding_row_available || model->cuda_embedding_row_function == NULL) {
        king_inference_cuda_embedding_row_set_error(model, -1, "cuda_embedding_row_loader_unavailable");
        return FAILURE;
    }
    if (output_vector == 0 || output_length == 0 || output_length > UINT_MAX || token_id > UINT_MAX) {
        king_inference_cuda_embedding_row_set_error(model, -1, "cuda_embedding_row_argument_invalid");
        return FAILURE;
    }
    if (king_inference_resolve_token_embedding_tensor_name(model, NULL, &tensor, NULL, &status) != SUCCESS
        || tensor == NULL) {
        king_inference_cuda_embedding_row_set_error(
            model,
            -1,
            status != NULL ? status : "cuda_embedding_row_tensor_not_resolved"
        );
        return FAILURE;
    }

    descriptor = king_inference_tensor_index_descriptor(model, tensor);
    dimensions = descriptor != NULL
        ? zend_hash_str_find(Z_ARRVAL_P(descriptor), "dimensions", sizeof("dimensions") - 1)
        : NULL;
    if (descriptor == NULL
        || dimensions == NULL
        || Z_TYPE_P(dimensions) != IS_ARRAY
        || !king_inference_tensor_descriptor_ulong(descriptor, "rank", sizeof("rank") - 1, &rank)
        || !king_inference_tensor_descriptor_ulong(descriptor, "type", sizeof("type") - 1, &type)
        || rank != 2
        || !king_inference_cuda_embedding_row_supported_type(type)
        || king_inference_tensor_dimension_at(dimensions, 0, &cols) != SUCCESS
        || king_inference_tensor_dimension_at(dimensions, 1, &rows) != SUCCESS
        || cols != output_length
        || cols == 0
        || cols > UINT_MAX
        || rows == 0
        || rows > UINT_MAX
        || token_id >= rows
        || (type == 8 && (cols % 32) != 0)) {
        zend_string_release(tensor);
        king_inference_cuda_embedding_row_set_error(model, -1, "cuda_embedding_row_tensor_descriptor_invalid");
        return FAILURE;
    }

    upload = king_inference_cuda_weight_cache_find(model, tensor, true);
    if (upload == NULL || upload->device_ptr == 0) {
        zend_string_release(tensor);
        king_inference_cuda_embedding_row_set_error(model, -1, "cuda_embedding_row_weight_not_uploaded");
        return FAILURE;
    }
    zend_string_release(tensor);
    if (!king_inference_cuda_device_allocator_set_current(model)
        || !king_inference_cuda_embedding_row_driver_symbol(model, "cuLaunchKernel", (void **) &cu_launch_kernel)
        || !king_inference_cuda_embedding_row_driver_symbol(model, "cuCtxSynchronize", (void **) &cu_ctx_synchronize)) {
        return FAILURE;
    }

    width32 = (unsigned int) cols;
    rows32 = (unsigned int) rows;
    token32 = (unsigned int) token_id;
    type32 = (unsigned int) type;
    grid_x = (width32 + block_x - 1) / block_x;
    params[0] = &upload->device_ptr;
    params[1] = &output_vector;
    params[2] = &width32;
    params[3] = &rows32;
    params[4] = &token32;
    params[5] = &type32;

    result = cu_launch_kernel(
        model->cuda_embedding_row_function,
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
        king_inference_cuda_embedding_row_set_error(model, result, "cuLaunchKernel_failed");
        return FAILURE;
    }

    result = cu_ctx_synchronize();
    if (result != 0) {
        king_inference_cuda_embedding_row_set_error(model, result, "cuCtxSynchronize_failed");
        return FAILURE;
    }

    model->cuda_embedding_row_launch_count++;
    model->cuda_embedding_row_last_token_id = token_id;
    model->cuda_embedding_row_last_width = output_length;
    model->cuda_embedding_row_result = 0;
    model->cuda_embedding_row_error[0] = '\0';
    return SUCCESS;
}

static void king_inference_cuda_embedding_row_add_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval loader;
    zval supported_types;

    array_init(&loader);
    add_assoc_bool(&loader, "attempted", model->cuda_embedding_row_attempted);
    add_assoc_bool(&loader, "available", model->cuda_embedding_row_available);
    add_assoc_bool(&loader, "nvrtc_available", model->cuda_embedding_row_nvrtc_available);
    add_assoc_bool(&loader, "module_loaded", model->cuda_embedding_row_module_loaded);
    add_assoc_bool(&loader, "f32_available", model->cuda_embedding_row_f32_available);
    add_assoc_bool(&loader, "f16_available", model->cuda_embedding_row_f16_available);
    add_assoc_bool(&loader, "bf16_available", model->cuda_embedding_row_bf16_available);
    add_assoc_bool(&loader, "q8_0_available", model->cuda_embedding_row_q8_0_available);
    add_assoc_long(&loader, "launches", (zend_long) model->cuda_embedding_row_launch_count);
    add_assoc_long(&loader, "last_token_id", (zend_long) model->cuda_embedding_row_last_token_id);
    add_assoc_long(&loader, "last_width", (zend_long) model->cuda_embedding_row_last_width);
    add_assoc_long(&loader, "result", model->cuda_embedding_row_result);
    add_assoc_string(&loader, "error", model->cuda_embedding_row_error);
    array_init(&supported_types);
    add_next_index_string(&supported_types, "F32");
    add_next_index_string(&supported_types, "F16");
    add_next_index_string(&supported_types, "BF16");
    add_next_index_string(&supported_types, "Q8_0");
    add_assoc_zval(&loader, "supported_types", &supported_types);
    add_assoc_zval(return_value, "embedding_row_loader", &loader);
    add_assoc_bool(return_value, "gpu_embedding_row_loader_ready", model->cuda_embedding_row_available);
}

static void king_inference_cuda_embedding_row_apply_status(
    king_inference_model_object *model,
    zval *return_value
) {
    zval *reason;
    bool reason_can_be_embedding_row;

    king_inference_cuda_embedding_row_add_status(model, return_value);
    if (!model->cuda_embedding_row_attempted
        || model->cuda_embedding_row_available
        || !model->cuda_weight_upload_complete) {
        return;
    }

    reason = zend_hash_str_find(Z_ARRVAL_P(return_value), "reason", sizeof("reason") - 1);
    reason_can_be_embedding_row = reason == NULL
        || Z_TYPE_P(reason) != IS_STRING
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_ready")
        || zend_string_equals_literal(Z_STR_P(reason), "gpu_decoder_kernel_not_implemented");
    if (reason_can_be_embedding_row) {
        king_inference_cuda_context_prepend_return_reason(
            return_value,
            "gpu_embedding_row_loader_unavailable"
        );
        add_assoc_string(return_value, "reason", "gpu_embedding_row_loader_unavailable");
    } else {
        king_inference_gpu_status_append_return_reason(return_value, "gpu_embedding_row_loader_unavailable");
    }
}
