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

static const char king_inference_cuda_quantized_matvec_source[] =
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
"extern \"C\" __device__ void king_qk4_scale_min(const unsigned char *scales, unsigned int group, int *scale, int *minimum) {\n"
"    if (group < 4u) {\n"
"        *scale = (int) (scales[group] & 0x3fu);\n"
"        *minimum = (int) (scales[group + 4u] & 0x3fu);\n"
"        return;\n"
"    }\n"
"    *scale = (int) ((scales[group + 4u] & 0x0fu) | ((scales[group - 4u] >> 6) << 4));\n"
"    *minimum = (int) ((scales[group + 4u] >> 4) | ((scales[group] >> 6) << 4));\n"
"}\n"
"extern \"C\" __device__ float king_quantized_value(\n"
"    const unsigned char *row_weights,\n"
"    unsigned int tensor_type,\n"
"    unsigned int col\n"
") {\n"
"    if (tensor_type == 8u) {\n"
"        unsigned int block_index = col >> 5;\n"
"        unsigned int lane = col & 31u;\n"
"        const unsigned char *qblock = row_weights + ((unsigned long long) block_index * 34ull);\n"
"        unsigned short raw_scale = (unsigned short) qblock[0] | ((unsigned short) qblock[1] << 8);\n"
"        signed char quant = ((const signed char *) (qblock + 2))[lane];\n"
"        return ((float) quant) * king_half_to_float(raw_scale);\n"
"    }\n"
"    if (tensor_type == 6u) {\n"
"        unsigned int block_index = col >> 5;\n"
"        unsigned int in_block = col & 31u;\n"
"        const unsigned char *qblock = row_weights + ((unsigned long long) block_index * 22ull);\n"
"        unsigned int high_bits = (unsigned int) qblock[2] | ((unsigned int) qblock[3] << 8) | ((unsigned int) qblock[4] << 16) | ((unsigned int) qblock[5] << 24);\n"
"        unsigned char packed = qblock[6u + (in_block & 15u)];\n"
"        int low = (int) (in_block < 16u ? (packed & 0x0fu) : (packed >> 4));\n"
"        int high = (int) ((high_bits >> in_block) & 1u);\n"
"        int quant = (low | (high << 4)) - 16;\n"
"        unsigned short raw_scale = (unsigned short) qblock[0] | ((unsigned short) qblock[1] << 8);\n"
"        return ((float) quant) * king_half_to_float(raw_scale);\n"
"    }\n"
"    if (tensor_type == 12u) {\n"
"        unsigned int block_index = col >> 8;\n"
"        unsigned int in_block = col & 255u;\n"
"        unsigned int group = in_block >> 5;\n"
"        unsigned int in_group = in_block & 31u;\n"
"        const unsigned char *qblock = row_weights + ((unsigned long long) block_index * 144ull);\n"
"        const unsigned char *scales = qblock + 4;\n"
"        const unsigned char *qs = qblock + 16;\n"
"        unsigned char packed = qs[(group >> 1) * 32u + in_group];\n"
"        int scale;\n"
"        int minimum;\n"
"        int quant = (int) ((group & 1u) == 0u ? (packed & 0x0fu) : (packed >> 4));\n"
"        float d = king_half_to_float((unsigned short) qblock[0] | ((unsigned short) qblock[1] << 8));\n"
"        float dmin = king_half_to_float((unsigned short) qblock[2] | ((unsigned short) qblock[3] << 8));\n"
"        king_qk4_scale_min(scales, group, &scale, &minimum);\n"
"        return d * ((float) scale) * ((float) quant) - dmin * ((float) minimum);\n"
"    }\n"
"    if (tensor_type == 14u) {\n"
"        unsigned int block_index = col >> 8;\n"
"        unsigned int in_block = col & 255u;\n"
"        unsigned int chunk = in_block >> 7;\n"
"        unsigned int within = in_block & 127u;\n"
"        unsigned int lane = within & 31u;\n"
"        unsigned int quadrant = within >> 5;\n"
"        const unsigned char *qblock = row_weights + ((unsigned long long) block_index * 210ull);\n"
"        const unsigned char *ql = qblock + chunk * 64u;\n"
"        const unsigned char *qh = qblock + 128u + chunk * 32u;\n"
"        const signed char *scales = (const signed char *) (qblock + 192u + chunk * 8u);\n"
"        int quant;\n"
"        int scale;\n"
"        if (quadrant == 0u) {\n"
"            quant = (int) ((ql[lane] & 0x0fu) | ((qh[lane] & 0x03u) << 4));\n"
"            scale = (int) scales[lane / 16u];\n"
"        } else if (quadrant == 1u) {\n"
"            quant = (int) ((ql[lane + 32u] & 0x0fu) | ((qh[lane] & 0x0cu) << 2));\n"
"            scale = (int) scales[2u + lane / 16u];\n"
"        } else if (quadrant == 2u) {\n"
"            quant = (int) ((ql[lane] >> 4) | (qh[lane] & 0x30u));\n"
"            scale = (int) scales[4u + lane / 16u];\n"
"        } else {\n"
"            quant = (int) ((ql[lane + 32u] >> 4) | ((qh[lane] & 0xc0u) >> 2));\n"
"            scale = (int) scales[6u + lane / 16u];\n"
"        }\n"
"        float d = king_half_to_float((unsigned short) qblock[208] | ((unsigned short) qblock[209] << 8));\n"
"        return d * ((float) scale) * ((float) (quant - 32));\n"
"    }\n"
"    return 0.0f;\n"
"}\n"
"extern \"C\" __global__ void king_quantized_matvec(\n"
"    const unsigned char *weights,\n"
"    const float *vector,\n"
"    float *output,\n"
"    unsigned int columns,\n"
"    unsigned int rows,\n"
"    unsigned int tensor_type,\n"
"    unsigned int block_size,\n"
"    unsigned int type_size\n"
") {\n"
"    unsigned int row = blockIdx.x;\n"
"    unsigned int lane_id = threadIdx.x;\n"
"    __shared__ float partials[128];\n"
"    if (tensor_type == 8u) {\n"
"        unsigned int group_id = threadIdx.x >> 5;\n"
"        unsigned int group_lane = threadIdx.x & 31u;\n"
"        unsigned int q8_row = blockIdx.x * 4u + group_id;\n"
"        bool active = q8_row < rows && columns != 0u;\n"
"        unsigned int blocks_per_row = (columns + block_size - 1u) / block_size;\n"
"        const unsigned char *row_weights = weights + ((unsigned long long) q8_row * blocks_per_row * (unsigned long long) type_size);\n"
"        float sum = 0.0f;\n"
"        if (active) {\n"
"            for (unsigned int col = group_lane; col < columns; col += 32u) {\n"
"                unsigned int block_index = col >> 5;\n"
"                unsigned int lane = col & 31u;\n"
"                const unsigned char *qblock = row_weights + ((unsigned long long) block_index * 34ull);\n"
"                unsigned short raw_scale = (unsigned short) qblock[0] | ((unsigned short) qblock[1] << 8);\n"
"                signed char quant = ((const signed char *) (qblock + 2))[lane];\n"
"                sum += ((float) quant) * king_half_to_float(raw_scale) * vector[col];\n"
"            }\n"
"        }\n"
"        for (unsigned int stride = 16u; stride > 0u; stride >>= 1) {\n"
"            sum += __shfl_down_sync(0xffffffffu, sum, stride);\n"
"        }\n"
"        if (active && group_lane == 0u) {\n"
"            output[q8_row] = sum;\n"
"        }\n"
"        return;\n"
"    }\n"
"    if (row >= rows || columns == 0u) {\n"
"        return;\n"
"    }\n"
"    unsigned int blocks_per_row = (columns + block_size - 1u) / block_size;\n"
"    const unsigned char *row_weights = weights + ((unsigned long long) row * blocks_per_row * (unsigned long long) type_size);\n"
"    float sum = 0.0f;\n"
"    for (unsigned int col = lane_id; col < columns; col += blockDim.x) {\n"
"        sum += king_quantized_value(row_weights, tensor_type, col) * vector[col];\n"
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
"}\n"
"extern \"C\" __global__ void king_quantized_matvec_batch(\n"
"    const unsigned char *weights,\n"
"    const float *vectors,\n"
"    float *output,\n"
"    unsigned int columns,\n"
"    unsigned int rows,\n"
"    unsigned int batch_count,\n"
"    unsigned int tensor_type,\n"
"    unsigned int block_size,\n"
"    unsigned int type_size\n"
") {\n"
"    unsigned int batch = blockIdx.y;\n"
"    unsigned int row = blockIdx.x;\n"
"    unsigned int lane_id = threadIdx.x;\n"
"    const float *vector;\n"
"    __shared__ float partials[128];\n"
"    if (batch >= batch_count || columns == 0u) {\n"
"        return;\n"
"    }\n"
"    vector = vectors + ((unsigned long long) batch * (unsigned long long) columns);\n"
"    if (tensor_type == 8u) {\n"
"        unsigned int group_id = threadIdx.x >> 5;\n"
"        unsigned int group_lane = threadIdx.x & 31u;\n"
"        unsigned int q8_row = blockIdx.x * 4u + group_id;\n"
"        bool active = q8_row < rows;\n"
"        unsigned int blocks_per_row = (columns + block_size - 1u) / block_size;\n"
"        const unsigned char *row_weights = weights + ((unsigned long long) q8_row * blocks_per_row * (unsigned long long) type_size);\n"
"        float sum = 0.0f;\n"
"        if (active) {\n"
"            for (unsigned int col = group_lane; col < columns; col += 32u) {\n"
"                unsigned int block_index = col >> 5;\n"
"                unsigned int lane = col & 31u;\n"
"                const unsigned char *qblock = row_weights + ((unsigned long long) block_index * 34ull);\n"
"                unsigned short raw_scale = (unsigned short) qblock[0] | ((unsigned short) qblock[1] << 8);\n"
"                signed char quant = ((const signed char *) (qblock + 2))[lane];\n"
"                sum += ((float) quant) * king_half_to_float(raw_scale) * vector[col];\n"
"            }\n"
"        }\n"
"        for (unsigned int stride = 16u; stride > 0u; stride >>= 1) {\n"
"            sum += __shfl_down_sync(0xffffffffu, sum, stride);\n"
"        }\n"
"        if (active && group_lane == 0u) {\n"
"            output[((unsigned long long) batch * (unsigned long long) rows) + q8_row] = sum;\n"
"        }\n"
"        return;\n"
"    }\n"
"    if (row >= rows) {\n"
"        return;\n"
"    }\n"
"    unsigned int blocks_per_row = (columns + block_size - 1u) / block_size;\n"
"    const unsigned char *row_weights = weights + ((unsigned long long) row * blocks_per_row * (unsigned long long) type_size);\n"
"    float sum = 0.0f;\n"
"    for (unsigned int col = lane_id; col < columns; col += blockDim.x) {\n"
"        sum += king_quantized_value(row_weights, tensor_type, col) * vector[col];\n"
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
"        output[((unsigned long long) batch * (unsigned long long) rows) + row] = partials[0];\n"
"    }\n"
"}\n";

static bool king_inference_cuda_quantized_matvec_type_supported(zend_ulong type)
{
    return type == 6 || type == 8 || type == 12 || type == 14;
}

static void king_inference_cuda_quantized_matvec_reset_fields(king_inference_model_object *model)
{
    model->cuda_nvrtc_handle = NULL;
    model->cuda_quantized_matvec_module = NULL;
    model->cuda_q8_0_matvec_function = NULL;
    model->cuda_quantized_matvec_batch_function = NULL;
    model->cuda_quantized_matvec_result = 0;
    model->cuda_quantized_matvec_error[0] = '\0';
    model->cuda_quantized_matvec_launch_count = 0;
    model->cuda_quantized_matvec_batch_launch_count = 0;
    model->cuda_quantized_matvec_attempted = false;
    model->cuda_quantized_matvec_available = false;
    model->cuda_quantized_matvec_nvrtc_available = false;
    model->cuda_quantized_matvec_module_loaded = false;
    model->cuda_quantized_matvec_q8_0_available = false;
    model->cuda_quantized_matvec_batch_available = false;
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
    const char *options[] = {"--std=c++11", "--gpu-architecture=compute_89", "--use_fast_math"};
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
        king_inference_cuda_quantized_matvec_source,
        "king_quantized_matvec.cu",
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

    result = nvrtc_compile_program(program, 3, options);
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
    void *batch_function = NULL;
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

    result = cu_module_get_function(&function, module, "king_quantized_matvec");
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
    result = cu_module_get_function(&batch_function, module, "king_quantized_matvec_batch");
    if (result != 0 || batch_function == NULL) {
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
            result != 0 ? "cuModuleGetFunction_batch_failed" : "cuda_quantized_matvec_batch_kernel_unavailable"
        );
        return;
    }

    model->cuda_quantized_matvec_module = module;
    model->cuda_q8_0_matvec_function = function;
    model->cuda_quantized_matvec_batch_function = batch_function;
    model->cuda_quantized_matvec_module_loaded = true;
    model->cuda_quantized_matvec_q8_0_available = true;
    model->cuda_quantized_matvec_batch_available = true;
    model->cuda_quantized_matvec_available = true;
    model->cuda_quantized_matvec_result = 0;
    model->cuda_quantized_matvec_error[0] = '\0';
}

static zend_result king_inference_cuda_quantized_matvec(
    king_inference_model_object *model,
    zend_string *tensor_name,
    king_inference_cuda_device_ptr input_vector,
    zend_ulong input_length,
    king_inference_cuda_device_ptr output_vector,
    zend_ulong rows
) {
    king_inference_cuda_weight_upload *upload;
    king_inference_cuda_launch_kernel_fn cu_launch_kernel;
    static king_inference_cuda_launch_kernel_fn cached_cu_launch_kernel = NULL;
    zval *descriptor;
    zval *dimensions;
    zend_ulong type;
    zend_ulong rank;
    zend_ulong cols;
    zend_ulong tensor_rows;
    king_inference_tensor_type_info type_info;
    unsigned int columns32;
    unsigned int rows32;
    unsigned int type32;
    unsigned int block_size32;
    unsigned int type_size32;
    unsigned int block_x = 128;
    unsigned int grid_x;
    void *params[8];
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
        || !king_inference_tensor_type_lookup(type, &type_info)
        || rank != 2
        || !king_inference_cuda_quantized_matvec_type_supported(type)
        || king_inference_tensor_dimension_at(dimensions, 0, &cols) != SUCCESS
        || king_inference_tensor_dimension_at(dimensions, 1, &tensor_rows) != SUCCESS
        || cols != input_length
        || rows > tensor_rows) {
        king_inference_cuda_quantized_matvec_set_error(model, -1, "cuda_quantized_tensor_descriptor_invalid");
        return FAILURE;
    }

    upload = king_inference_cuda_weight_cache_find(model, tensor_name, true);
    if (upload == NULL || upload->device_ptr == 0) {
        king_inference_cuda_quantized_matvec_set_error(model, -1, "cuda_q8_0_weight_not_uploaded");
        return FAILURE;
    }
    if (cached_cu_launch_kernel == NULL
        && !king_inference_cuda_quantized_matvec_driver_symbol(
            model,
            "cuLaunchKernel",
            (void **) &cached_cu_launch_kernel
        )) {
        return FAILURE;
    }
    cu_launch_kernel = cached_cu_launch_kernel;

    columns32 = (unsigned int) input_length;
    rows32 = (unsigned int) rows;
    type32 = (unsigned int) type;
    block_size32 = (unsigned int) type_info.block_size;
    type_size32 = (unsigned int) type_info.type_size;
    grid_x = type32 == 8u ? (rows32 + 3u) / 4u : rows32;
    params[0] = &upload->device_ptr;
    params[1] = &input_vector;
    params[2] = &output_vector;
    params[3] = &columns32;
    params[4] = &rows32;
    params[5] = &type32;
    params[6] = &block_size32;
    params[7] = &type_size32;

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

    model->cuda_quantized_matvec_launch_count++;
    model->cuda_quantized_matvec_result = 0;
    model->cuda_quantized_matvec_error[0] = '\0';
    return SUCCESS;
}
