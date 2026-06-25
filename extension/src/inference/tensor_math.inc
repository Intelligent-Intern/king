typedef struct _king_inference_tensor_native_view {
    zval *descriptor;
    zval *dimensions;
    king_inference_tensor_type_info type_info;
    zend_ulong rank;
    zend_ulong type;
    zend_ulong elements;
    zend_ulong relative_offset;
    zend_ulong absolute_offset;
    zend_ulong byte_length;
    unsigned char *data;
} king_inference_tensor_native_view;

static uint16_t king_inference_tensor_read_u16(const unsigned char *bytes)
{
    return (uint16_t) (((uint16_t) bytes[0]) | ((uint16_t) bytes[1] << 8));
}

static uint32_t king_inference_tensor_read_u32(const unsigned char *bytes)
{
    return ((uint32_t) bytes[0])
        | ((uint32_t) bytes[1] << 8)
        | ((uint32_t) bytes[2] << 16)
        | ((uint32_t) bytes[3] << 24);
}

static uint64_t king_inference_tensor_read_u64(const unsigned char *bytes)
{
    return ((uint64_t) bytes[0])
        | ((uint64_t) bytes[1] << 8)
        | ((uint64_t) bytes[2] << 16)
        | ((uint64_t) bytes[3] << 24)
        | ((uint64_t) bytes[4] << 32)
        | ((uint64_t) bytes[5] << 40)
        | ((uint64_t) bytes[6] << 48)
        | ((uint64_t) bytes[7] << 56);
}

static float king_inference_tensor_float_from_bits(uint32_t bits)
{
    float value;
    memcpy(&value, &bits, sizeof(value));
    return value;
}

static double king_inference_tensor_double_from_bits(uint64_t bits)
{
    double value;
    memcpy(&value, &bits, sizeof(value));
    return value;
}

static float king_inference_tensor_f16_to_float(uint16_t half)
{
    uint32_t sign = ((uint32_t) half & 0x8000U) << 16;
    uint32_t mantissa = (uint32_t) half & 0x03ffU;
    int exponent = (int) ((half >> 10) & 0x1fU);
    uint32_t bits;

    if (exponent == 0) {
        if (mantissa == 0) {
            return king_inference_tensor_float_from_bits(sign);
        }
        while ((mantissa & 0x0400U) == 0) {
            mantissa <<= 1;
            exponent--;
        }
        exponent++;
        mantissa &= 0x03ffU;
        bits = sign | ((uint32_t) (exponent + 112) << 23) | (mantissa << 13);
        return king_inference_tensor_float_from_bits(bits);
    }

    if (exponent == 31) {
        bits = sign | 0x7f800000U | (mantissa << 13);
        return king_inference_tensor_float_from_bits(bits);
    }

    bits = sign | ((uint32_t) (exponent + 112) << 23) | (mantissa << 13);
    return king_inference_tensor_float_from_bits(bits);
}

static void king_inference_tensor_qk4_scale_min(
    const unsigned char *scales,
    zend_ulong group,
    int *scale,
    int *minimum
) {
    if (group < 4) {
        *scale = (int) (scales[group] & 0x3fU);
        *minimum = (int) (scales[group + 4] & 0x3fU);
        return;
    }

    *scale = (int) ((scales[group + 4] & 0x0fU) | ((scales[group - 4] >> 6) << 4));
    *minimum = (int) ((scales[group + 4] >> 4) | ((scales[group] >> 6) << 4));
}

static double king_inference_tensor_q4_k_value(const unsigned char *block, zend_ulong in_block)
{
    const unsigned char *scales = block + 4;
    const unsigned char *qs = block + 16;
    zend_ulong group = in_block / 32;
    zend_ulong in_group = in_block & 31;
    unsigned char packed = qs[(group / 2) * 32 + in_group];
    int scale;
    int minimum;
    int quant = (int) ((group & 1) == 0 ? (packed & 0x0fU) : (packed >> 4));
    double d = (double) king_inference_tensor_f16_to_float(king_inference_tensor_read_u16(block));
    double dmin = (double) king_inference_tensor_f16_to_float(king_inference_tensor_read_u16(block + 2));

    king_inference_tensor_qk4_scale_min(scales, group, &scale, &minimum);
    return d * (double) scale * (double) quant - dmin * (double) minimum;
}

static double king_inference_tensor_q5_k_value(const unsigned char *block, zend_ulong in_block)
{
    const unsigned char *scales = block + 4;
    const unsigned char *qh = block + 16;
    const unsigned char *qs = block + 48;
    zend_ulong group = in_block / 32;
    zend_ulong in_group = in_block & 31;
    zend_ulong high_index = group * 32 + in_group;
    unsigned char packed = qs[(group / 2) * 32 + in_group];
    int scale;
    int minimum;
    int high = (int) ((qh[high_index >> 3] >> (high_index & 7)) & 1U);
    int quant = (int) ((group & 1) == 0 ? (packed & 0x0fU) : (packed >> 4)) | (high << 4);
    double d = (double) king_inference_tensor_f16_to_float(king_inference_tensor_read_u16(block));
    double dmin = (double) king_inference_tensor_f16_to_float(king_inference_tensor_read_u16(block + 2));

    king_inference_tensor_qk4_scale_min(scales, group, &scale, &minimum);
    return d * (double) scale * (double) quant - dmin * (double) minimum;
}

static double king_inference_tensor_q6_k_value(const unsigned char *block, zend_ulong in_block)
{
    zend_ulong chunk = in_block / 128;
    zend_ulong within = in_block & 127;
    zend_ulong lane = within & 31;
    zend_ulong quadrant = within / 32;
    const unsigned char *ql = block + chunk * 64;
    const unsigned char *qh = block + 128 + chunk * 32;
    const int8_t *scales = (const int8_t *) (block + 192 + chunk * 8);
    double d = (double) king_inference_tensor_f16_to_float(king_inference_tensor_read_u16(block + 208));
    int quant;
    int scale;

    switch (quadrant) {
        case 0:
            quant = (int) ((ql[lane] & 0x0fU) | ((qh[lane] & 0x03U) << 4));
            scale = scales[lane / 16];
            break;
        case 1:
            quant = (int) ((ql[lane + 32] & 0x0fU) | ((qh[lane] & 0x0cU) << 2));
            scale = scales[2 + lane / 16];
            break;
        case 2:
            quant = (int) ((ql[lane] >> 4) | (qh[lane] & 0x30U));
            scale = scales[4 + lane / 16];
            break;
        default:
            quant = (int) ((ql[lane + 32] >> 4) | ((qh[lane] & 0xc0U) >> 2));
            scale = scales[6 + lane / 16];
            break;
    }

    return d * (double) scale * (double) (quant - 32);
}

static zend_result king_inference_tensor_dimension_at(
    zval *dimensions,
    zend_ulong index,
    zend_ulong *value
) {
    zval *dimension;

    dimension = zend_hash_index_find(Z_ARRVAL_P(dimensions), index);
    if (dimension == NULL || Z_TYPE_P(dimension) != IS_LONG || Z_LVAL_P(dimension) <= 0) {
        return FAILURE;
    }

    *value = (zend_ulong) Z_LVAL_P(dimension);
    return SUCCESS;
}

static zend_result king_inference_tensor_native_view_resolve(
    king_inference_model_object *model,
    zend_string *name,
    king_inference_tensor_native_view *view
) {
    zend_ulong byte_end = 0;

    memset(view, 0, sizeof(*view));
    if (!model->native_map_loaded || model->native_map == NULL || model->native_map_size == 0) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "King inference tensor math requires a native-mapped model artifact."
        );
        return FAILURE;
    }
    if (Z_ISUNDEF(model->tensor_index) || Z_TYPE(model->tensor_index) != IS_ARRAY) {
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "King inference model has no native tensor index.");
        return FAILURE;
    }

    view->descriptor = zend_hash_find(Z_ARRVAL(model->tensor_index), name);
    if (view->descriptor == NULL) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "King inference tensor '%s' was not found in the model index.",
            ZSTR_VAL(name)
        );
        return FAILURE;
    }
    view->dimensions = zend_hash_str_find(Z_ARRVAL_P(view->descriptor), "dimensions", sizeof("dimensions") - 1);
    if (view->dimensions == NULL || Z_TYPE_P(view->dimensions) != IS_ARRAY) {
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "King inference tensor '%s' has invalid dimensions.", ZSTR_VAL(name));
        return FAILURE;
    }
    if (!king_inference_tensor_descriptor_ulong(view->descriptor, "rank", sizeof("rank") - 1, &view->rank)
        || !king_inference_tensor_descriptor_ulong(view->descriptor, "type", sizeof("type") - 1, &view->type)
        || !king_inference_tensor_descriptor_ulong(view->descriptor, "elements", sizeof("elements") - 1, &view->elements)
        || !king_inference_tensor_descriptor_ulong(view->descriptor, "relative_offset", sizeof("relative_offset") - 1, &view->relative_offset)) {
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "King inference tensor '%s' has an invalid descriptor.", ZSTR_VAL(name));
        return FAILURE;
    }
    if (!king_inference_tensor_type_lookup(view->type, &view->type_info)
        || view->type_info.block_size == 0
        || view->type_info.type_size == 0) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "King inference tensor '%s' uses unsupported tensor type %lu.",
            ZSTR_VAL(name),
            view->type
        );
        return FAILURE;
    }
    if (!king_inference_tensor_add_checked(model->gguf.tensor_data_offset, view->relative_offset, &view->absolute_offset)) {
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "King inference tensor '%s' has an overflowing data offset.", ZSTR_VAL(name));
        return FAILURE;
    }
    if (!king_inference_tensor_mul_checked(
            view->elements == 0 ? 0 : 1 + ((view->elements - 1) / view->type_info.block_size),
            view->type_info.type_size,
            &view->byte_length)
        || !king_inference_tensor_add_checked(view->absolute_offset, view->byte_length, &byte_end)
        || byte_end > model->gguf.file_size
        || byte_end > (zend_ulong) model->native_map_size) {
        zend_throw_exception_ex(king_ce_runtime_exception, 0, "King inference tensor '%s' is outside the mapped model artifact.", ZSTR_VAL(name));
        return FAILURE;
    }

    view->data = ((unsigned char *) model->native_map) + view->absolute_offset;
    return SUCCESS;
}

static zend_result king_inference_tensor_value_at(
    king_inference_tensor_native_view *view,
    zend_ulong index,
    double *value
) {
    const unsigned char *block;
    zend_ulong block_index;
    zend_ulong in_block;
    uint16_t scale_half;
    double scale;

    if (index >= view->elements) {
        return FAILURE;
    }

    switch (view->type) {
        case 0:
            *value = (double) king_inference_tensor_float_from_bits(
                king_inference_tensor_read_u32(view->data + (index * 4))
            );
            return SUCCESS;
        case 1:
            *value = (double) king_inference_tensor_f16_to_float(
                king_inference_tensor_read_u16(view->data + (index * 2))
            );
            return SUCCESS;
        case 2:
            block_index = index / 32;
            in_block = index % 32;
            block = view->data + (block_index * 18);
            scale = (double) king_inference_tensor_f16_to_float(king_inference_tensor_read_u16(block));
            {
                unsigned char packed = block[2 + (in_block & 15)];
                int quant = (int) (in_block < 16 ? (packed & 0x0fU) : (packed >> 4)) - 8;
                *value = scale * (double) quant;
            }
            return SUCCESS;
        case 3:
            block_index = index / 32;
            in_block = index % 32;
            block = view->data + (block_index * 20);
            scale = (double) king_inference_tensor_f16_to_float(king_inference_tensor_read_u16(block));
            {
                double minimum = (double) king_inference_tensor_f16_to_float(king_inference_tensor_read_u16(block + 2));
                unsigned char packed = block[4 + (in_block & 15)];
                int quant = (int) (in_block < 16 ? (packed & 0x0fU) : (packed >> 4));
                *value = minimum + (scale * (double) quant);
            }
            return SUCCESS;
        case 8:
            block_index = index / 32;
            in_block = index % 32;
            block = view->data + (block_index * 34);
            scale_half = king_inference_tensor_read_u16(block);
            scale = (double) king_inference_tensor_f16_to_float(scale_half);
            *value = scale * (double) ((int8_t) block[2 + in_block]);
            return SUCCESS;
        case 12:
            block_index = index / 256;
            in_block = index & 255;
            *value = king_inference_tensor_q4_k_value(view->data + block_index * 144, in_block);
            return SUCCESS;
        case 13:
            block_index = index / 256;
            in_block = index & 255;
            *value = king_inference_tensor_q5_k_value(view->data + block_index * 176, in_block);
            return SUCCESS;
        case 14:
            block_index = index / 256;
            in_block = index & 255;
            *value = king_inference_tensor_q6_k_value(view->data + block_index * 210, in_block);
            return SUCCESS;
        case 24:
            *value = (double) ((int8_t) view->data[index]);
            return SUCCESS;
        case 25:
            *value = (double) ((int16_t) king_inference_tensor_read_u16(view->data + (index * 2)));
            return SUCCESS;
        case 26:
            *value = (double) ((int32_t) king_inference_tensor_read_u32(view->data + (index * 4)));
            return SUCCESS;
        case 27:
            *value = (double) ((int64_t) king_inference_tensor_read_u64(view->data + (index * 8)));
            return SUCCESS;
        case 28:
            *value = king_inference_tensor_double_from_bits(king_inference_tensor_read_u64(view->data + (index * 8)));
            return SUCCESS;
        case 30:
            *value = (double) king_inference_tensor_float_from_bits(
                ((uint32_t) king_inference_tensor_read_u16(view->data + (index * 2))) << 16
            );
            return SUCCESS;
    }

    zend_throw_exception_ex(
        king_ce_runtime_exception,
        0,
        "King inference tensor math does not yet support tensor type %s.",
        view->type_info.name
    );
    return FAILURE;
}

static zend_ulong king_inference_tensor_option_ulong(
    zval *options,
    const char *key,
    zend_ulong fallback
) {
    zval *option;

    if (options == NULL || Z_TYPE_P(options) != IS_ARRAY) {
        return fallback;
    }

    option = king_inference_array_find(options, key);
    if (option == NULL || Z_TYPE_P(option) != IS_LONG || Z_LVAL_P(option) < 0) {
        return fallback;
    }

    return (zend_ulong) Z_LVAL_P(option);
}

static zend_result king_inference_tensor_dequantize_array(
    king_inference_model_object *model,
    zend_string *name,
    zval *options,
    zval *return_value
) {
    king_inference_tensor_native_view view;
    zend_ulong offset = king_inference_tensor_option_ulong(options, "offset", 0);
    zend_ulong count = king_inference_tensor_option_ulong(options, "count", 32);
    zend_ulong max_values = king_inference_tensor_option_ulong(options, "max_values", 4096);
    zend_ulong i;
    zval values;

    if (king_inference_tensor_native_view_resolve(model, name, &view) != SUCCESS) {
        return FAILURE;
    }
    if (count == 0) {
        count = view.type_info.block_size;
    }
    if (count > max_values || offset > view.elements || count > view.elements - offset) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "King inference tensor dequantize range is outside tensor bounds or max_values."
        );
        return FAILURE;
    }

    array_init(&values);
    for (i = 0; i < count; i++) {
        double value;
        if (king_inference_tensor_value_at(&view, offset + i, &value) != SUCCESS) {
            zval_ptr_dtor(&values);
            return FAILURE;
        }
        add_next_index_double(&values, value);
    }

    array_init(return_value);
    add_assoc_str(return_value, "tensor", zend_string_copy(name));
    add_assoc_string(return_value, "type_name", view.type_info.name);
    add_assoc_long(return_value, "offset", (zend_long) offset);
    add_assoc_long(return_value, "count", (zend_long) count);
    add_assoc_long(return_value, "elements", (zend_long) view.elements);
    add_assoc_long(return_value, "block_size", (zend_long) view.type_info.block_size);
    add_assoc_zval(return_value, "values", &values);
    return SUCCESS;
}

static zend_result king_inference_tensor_vector_from_array(
    zval *input,
    zend_ulong expected,
    zend_ulong max_input_values,
    double **vector_out
) {
    zval *entry;
    zend_ulong index = 0;
    double *vector;

    *vector_out = NULL;
    if (Z_TYPE_P(input) != IS_ARRAY || zend_hash_num_elements(Z_ARRVAL_P(input)) != expected) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference tensor matmul input vector length does not match tensor columns.");
        return FAILURE;
    }
    if (expected == 0 || expected > max_input_values || expected > (zend_ulong) (SIZE_MAX / sizeof(double))) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference tensor matmul input vector is too large.");
        return FAILURE;
    }

    vector = ecalloc((size_t) expected, sizeof(double));
    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(input), entry) {
        if (Z_TYPE_P(entry) == IS_LONG) {
            vector[index] = (double) Z_LVAL_P(entry);
        } else if (Z_TYPE_P(entry) == IS_DOUBLE) {
            vector[index] = Z_DVAL_P(entry);
        } else {
            efree(vector);
            zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference tensor matmul input vector must contain only int or float values.");
            return FAILURE;
        }
        index++;
    } ZEND_HASH_FOREACH_END();

    *vector_out = vector;
    return SUCCESS;
}

static zend_result king_inference_tensor_block_dot(
    king_inference_tensor_native_view *view,
    zend_ulong block_index,
    const double *vector,
    zend_ulong vector_offset,
    double *sum
) {
    const unsigned char *block;
    zend_ulong i;

    switch (view->type) {
        case 2:
            block = view->data + block_index * 18;
            for (i = 0; i < 32; i++) {
                unsigned char packed = block[2 + (i & 15)];
                int quant = (int) (i < 16 ? (packed & 0x0fU) : (packed >> 4)) - 8;
                double scale = (double) king_inference_tensor_f16_to_float(king_inference_tensor_read_u16(block));
                *sum += scale * (double) quant * vector[vector_offset + i];
            }
            return SUCCESS;
        case 3:
            block = view->data + block_index * 20;
            for (i = 0; i < 32; i++) {
                unsigned char packed = block[4 + (i & 15)];
                int quant = (int) (i < 16 ? (packed & 0x0fU) : (packed >> 4));
                double scale = (double) king_inference_tensor_f16_to_float(king_inference_tensor_read_u16(block));
                double minimum = (double) king_inference_tensor_f16_to_float(king_inference_tensor_read_u16(block + 2));
                *sum += (minimum + scale * (double) quant) * vector[vector_offset + i];
            }
            return SUCCESS;
        case 8:
            block = view->data + block_index * 34;
            {
                double scale = (double) king_inference_tensor_f16_to_float(king_inference_tensor_read_u16(block));
                for (i = 0; i < 32; i++) {
                    *sum += scale * (double) ((int8_t) block[2 + i]) * vector[vector_offset + i];
                }
            }
            return SUCCESS;
        case 12:
            block = view->data + block_index * 144;
            for (i = 0; i < 256; i++) {
                *sum += king_inference_tensor_q4_k_value(block, i) * vector[vector_offset + i];
            }
            return SUCCESS;
        case 13:
            block = view->data + block_index * 176;
            for (i = 0; i < 256; i++) {
                *sum += king_inference_tensor_q5_k_value(block, i) * vector[vector_offset + i];
            }
            return SUCCESS;
        case 14:
            block = view->data + block_index * 210;
            for (i = 0; i < 256; i++) {
                *sum += king_inference_tensor_q6_k_value(block, i) * vector[vector_offset + i];
            }
            return SUCCESS;
    }

    return FAILURE;
}

static zend_result king_inference_tensor_row_dot(
    king_inference_tensor_native_view *view,
    zend_ulong base,
    zend_ulong cols,
    const double *vector,
    double *sum
) {
    zend_ulong col = 0;

    *sum = 0.0;
    while (col < cols) {
        zend_ulong global_index = base + col;
        zend_ulong block_size = view->type_info.block_size;

        if (block_size > 1
            && (global_index % block_size) == 0
            && cols - col >= block_size
            && king_inference_tensor_block_dot(
                view,
                global_index / block_size,
                vector,
                col,
                sum
            ) == SUCCESS) {
            col += block_size;
            continue;
        }

        {
            double weight;
            if (king_inference_tensor_value_at(view, global_index, &weight) != SUCCESS) {
                return FAILURE;
            }
            *sum += weight * vector[col];
        }
        col++;
    }

    return SUCCESS;
}

static zend_result king_inference_tensor_matmul_array(
    king_inference_model_object *model,
    zend_string *name,
    zval *input,
    zval *options,
    zval *return_value
) {
    king_inference_tensor_native_view view;
    zend_ulong rows = 1;
    zend_ulong cols = 0;
    zend_ulong row_offset = king_inference_tensor_option_ulong(options, "row_offset", 0);
    zend_ulong row_limit;
    zend_ulong max_output_values = king_inference_tensor_option_ulong(options, "max_output_values", 65536);
    zend_ulong max_input_values = king_inference_tensor_option_ulong(options, "max_input_values", 262144);
    zend_ulong max_operations = king_inference_tensor_option_ulong(options, "max_operations", 16777216);
    zend_ulong output_count;
    zend_ulong row;
    double *vector = NULL;
    zval output;

    if (king_inference_tensor_native_view_resolve(model, name, &view) != SUCCESS) {
        return FAILURE;
    }
    if (view.rank == 1) {
        cols = view.elements;
    } else if (view.rank == 2) {
        if (king_inference_tensor_dimension_at(view.dimensions, 0, &cols) != SUCCESS
            || king_inference_tensor_dimension_at(view.dimensions, 1, &rows) != SUCCESS) {
            zend_throw_exception_ex(king_ce_runtime_exception, 0, "King inference tensor '%s' has invalid matrix dimensions.", ZSTR_VAL(name));
            return FAILURE;
        }
    } else {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference tensor matmul currently accepts rank-1 or rank-2 tensors.");
        return FAILURE;
    }

    if (row_offset > rows) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference tensor matmul row_offset is outside tensor rows.");
        return FAILURE;
    }
    row_limit = king_inference_tensor_option_ulong(options, "row_limit", rows - row_offset);
    if (row_limit > rows - row_offset) {
        row_limit = rows - row_offset;
    }
    output_count = row_limit;
    if (output_count == 0
        || output_count > max_output_values
        || (cols > 0 && output_count > max_operations / cols)) {
        zend_throw_exception_ex(king_ce_validation_exception, 0, "King inference tensor matmul output exceeds configured limits.");
        return FAILURE;
    }
    if (king_inference_tensor_vector_from_array(input, cols, max_input_values, &vector) != SUCCESS) {
        return FAILURE;
    }

    array_init(&output);
    for (row = 0; row < output_count; row++) {
        double sum;
        zend_ulong base = (row_offset + row) * cols;

        if (king_inference_tensor_row_dot(&view, base, cols, vector, &sum) != SUCCESS) {
            efree(vector);
            zval_ptr_dtor(&output);
            return FAILURE;
        }
        add_next_index_double(&output, sum);
    }
    efree(vector);

    array_init(return_value);
    add_assoc_str(return_value, "tensor", zend_string_copy(name));
    add_assoc_string(return_value, "type_name", view.type_info.name);
    add_assoc_long(return_value, "rank", (zend_long) view.rank);
    add_assoc_long(return_value, "rows", (zend_long) rows);
    add_assoc_long(return_value, "cols", (zend_long) cols);
    add_assoc_long(return_value, "row_offset", (zend_long) row_offset);
    add_assoc_long(return_value, "output_count", (zend_long) output_count);
    add_assoc_bool(return_value, "blockwise", view.type_info.block_size > 1);
    add_assoc_bool(return_value, "complete", row_offset == 0 && output_count == rows);
    add_assoc_zval(return_value, "output", &output);
    return SUCCESS;
}
