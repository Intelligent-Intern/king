/*
 * =========================================================================
 * FILENAME:   src/core/introspection.c
 * PROJECT:    king
 *
 * PURPOSE:
 * Keeps the runtime introspection runtime in one translation unit while
 * splitting the former monolith into bounded, domain-focused fragments.
 * This preserves the current runtime behavior and static helper visibility
 * without letting a single source file grow without bound.
 * =========================================================================
 */

#include "include/config/router_and_loadbalancer/base_layer.h"
#include "include/pipeline_orchestrator/orchestrator.h"
#include "include/telemetry/telemetry.h"
#include <errno.h>
#include <math.h>
#include <stdio.h>
#include <stdlib.h>
#include "introspection/prelude.inc"
#include "introspection/telemetry.inc"
#include "introspection/object_store.inc"
#include "introspection/semantic_dns.inc"
#include "introspection/proto_api.inc"
#include "introspection/system.inc"

typedef struct _king_gguf_topk_entry {
    zend_long row;
    double score;
} king_gguf_topk_entry;

typedef struct _king_gguf_tensor_meta {
    zend_long absolute_offset;
    zend_long row_count;
    zend_long row_size;
    zend_long ne0;
    zend_long type;
} king_gguf_tensor_meta;

static uint16_t king_gguf_u16le(const unsigned char *ptr)
{
    return (uint16_t) ptr[0] | ((uint16_t) ptr[1] << 8);
}

static uint32_t king_gguf_u32le(const unsigned char *ptr)
{
    return (uint32_t) ptr[0]
        | ((uint32_t) ptr[1] << 8)
        | ((uint32_t) ptr[2] << 16)
        | ((uint32_t) ptr[3] << 24);
}

static float king_gguf_half_to_float(uint16_t h)
{
    uint32_t sign = ((uint32_t) h & 0x8000u) << 16;
    uint32_t exponent = ((uint32_t) h >> 10) & 0x1Fu;
    uint32_t mantissa = (uint32_t) h & 0x03FFu;
    uint32_t bits;
    float value;

    if (exponent == 0) {
        if (mantissa == 0) {
            bits = sign;
        } else {
            exponent = 127 - 15 + 1;
            while ((mantissa & 0x0400u) == 0) {
                mantissa <<= 1;
                exponent--;
            }
            mantissa &= 0x03FFu;
            bits = sign | (exponent << 23) | (mantissa << 13);
        }
    } else if (exponent == 0x1Fu) {
        bits = sign | 0x7F800000u | (mantissa << 13);
    } else {
        bits = sign | ((exponent + (127 - 15)) << 23) | (mantissa << 13);
    }

    memcpy(&value, &bits, sizeof(value));
    return value;
}

static float king_gguf_f32le(const unsigned char *ptr)
{
    uint32_t bits = king_gguf_u32le(ptr);
    float value;

    memcpy(&value, &bits, sizeof(value));
    return value;
}

static zend_result king_gguf_require_long(HashTable *table, const char *key, size_t key_len, zend_long *value_out)
{
    zval *value = zend_hash_str_find(table, key, key_len);

    if (value == NULL) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "king_gguf_tensor_scan() requires tensor['%s'].",
            key
        );
        return FAILURE;
    }

    *value_out = zval_get_long(value);
    return SUCCESS;
}

static zend_result king_gguf_optional_long(HashTable *table, const char *key, size_t key_len, zend_long *value_out)
{
    zval *value = zend_hash_str_find(table, key, key_len);

    if (value == NULL) {
        return FAILURE;
    }

    *value_out = zval_get_long(value);
    return SUCCESS;
}

static zend_result king_gguf_copy_input_vector(HashTable *input, size_t needed, double **vector_out)
{
    zval *entry;
    double *vector;
    size_t index = 0;

    if (zend_hash_num_elements(input) < needed) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "king_gguf_tensor_scan() input vector is too short for tensor width %zu.",
            needed
        );
        return FAILURE;
    }

    vector = (double *) safe_emalloc(needed, sizeof(double), 0);

    ZEND_HASH_FOREACH_VAL(input, entry) {
        if (index >= needed) {
            break;
        }
        vector[index++] = zval_get_double(entry);
    } ZEND_HASH_FOREACH_END();

    if (index < needed) {
        efree(vector);
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "king_gguf_tensor_scan() input vector copy was truncated."
        );
        return FAILURE;
    }

    *vector_out = vector;
    return SUCCESS;
}

static void king_gguf_q4k_scale_min(int index, const unsigned char *scales, int *scale_out, int *min_out)
{
    if (index < 4) {
        *scale_out = (int) (scales[index] & 0x3F);
        *min_out = (int) (scales[index + 4] & 0x3F);
        return;
    }

    *scale_out = (int) (((scales[index + 4] & 0x0F) << 2) | ((scales[index - 4] >> 6) & 0x03));
    *min_out = (int) ((((scales[index + 4] >> 4) & 0x0F) << 2) | ((scales[index] >> 6) & 0x03));
}

static double king_gguf_row_dot_f32(const unsigned char *raw, size_t ne0, const double *input)
{
    double sum = 0.0;
    size_t i;

    for (i = 0; i < ne0; ++i) {
        sum += (double) king_gguf_f32le(raw + (i * 4)) * input[i];
    }

    return sum;
}

static double king_gguf_row_dot_f16(const unsigned char *raw, size_t ne0, const double *input)
{
    double sum = 0.0;
    size_t i;

    for (i = 0; i < ne0; ++i) {
        sum += (double) king_gguf_half_to_float(king_gguf_u16le(raw + (i * 2))) * input[i];
    }

    return sum;
}

static double king_gguf_row_dot_q8_0(const unsigned char *raw, size_t ne0, const double *input)
{
    double sum = 0.0;
    size_t blocks = ne0 / 32;
    size_t block;
    size_t input_offset = 0;
    const unsigned char *cursor = raw;

    for (block = 0; block < blocks; ++block) {
        double d = (double) king_gguf_half_to_float(king_gguf_u16le(cursor));
        size_t j;

        cursor += 2;
        for (j = 0; j < 32; ++j) {
            int q = (int8_t) cursor[j];
            sum += (d * (double) q) * input[input_offset + j];
        }
        cursor += 32;
        input_offset += 32;
    }

    return sum;
}

static double king_gguf_row_dot_q4_k(const unsigned char *raw, size_t ne0, const double *input)
{
    double sum = 0.0;
    size_t blocks = ne0 / 256;
    size_t block;
    const unsigned char *cursor = raw;

    for (block = 0; block < blocks; ++block) {
        double d = (double) king_gguf_half_to_float(king_gguf_u16le(cursor));
        double dmin = (double) king_gguf_half_to_float(king_gguf_u16le(cursor + 2));
        const unsigned char *scales = cursor + 4;
        const unsigned char *q = cursor + 16;
        int scale_index = 0;
        size_t q_offset = 0;
        size_t chunk;

        for (chunk = 0; chunk < 4; ++chunk) {
            int sc1;
            int min1_scale;
            int sc2;
            int min2_scale;
            double d1;
            double min1;
            double d2;
            double min2;
            size_t base = (block * 256) + (chunk * 64);
            size_t lane;

            king_gguf_q4k_scale_min(scale_index + 0, scales, &sc1, &min1_scale);
            king_gguf_q4k_scale_min(scale_index + 1, scales, &sc2, &min2_scale);

            d1 = d * (double) sc1;
            min1 = dmin * (double) min1_scale;
            d2 = d * (double) sc2;
            min2 = dmin * (double) min2_scale;

            for (lane = 0; lane < 32; ++lane) {
                unsigned char byte = q[q_offset + lane];
                sum += ((d1 * (double) (byte & 0x0F)) - min1) * input[base + lane];
            }
            for (lane = 0; lane < 32; ++lane) {
                unsigned char byte = q[q_offset + lane];
                sum += ((d2 * (double) ((byte >> 4) & 0x0F)) - min2) * input[base + 32 + lane];
            }

            q_offset += 32;
            scale_index += 2;
        }

        cursor += 144;
    }

    return sum;
}

static double king_gguf_row_dot_q6_k(const unsigned char *raw, size_t ne0, const double *input)
{
    double sum = 0.0;
    size_t blocks = ne0 / 256;
    size_t block;
    const unsigned char *cursor = raw;

    for (block = 0; block < blocks; ++block) {
        const unsigned char *ql = cursor;
        const unsigned char *qh = cursor + 128;
        const signed char *scales = (const signed char *) (cursor + 192);
        double d = (double) king_gguf_half_to_float(king_gguf_u16le(cursor + 208));
        size_t ql_offset = 0;
        size_t qh_offset = 0;
        size_t scale_offset = 0;
        size_t chunk;

        for (chunk = 0; chunk < 2; ++chunk) {
            size_t base = (block * 256) + (chunk * 128);
            size_t lane;

            for (lane = 0; lane < 32; ++lane) {
                int group = (int) (lane / 16);
                int qh_byte = (int) qh[qh_offset + lane];
                int ql_a = (int) ql[ql_offset + lane];
                int ql_b = (int) ql[ql_offset + 32 + lane];
                int q1 = ((ql_a & 0x0F) | (((qh_byte >> 0) & 0x03) << 4)) - 32;
                int q2 = ((ql_b & 0x0F) | (((qh_byte >> 2) & 0x03) << 4)) - 32;
                int q3 = (((ql_a >> 4) & 0x0F) | (((qh_byte >> 4) & 0x03) << 4)) - 32;
                int q4 = (((ql_b >> 4) & 0x0F) | (((qh_byte >> 6) & 0x03) << 4)) - 32;

                sum += (d * (double) scales[scale_offset + group + 0] * (double) q1) * input[base + lane];
                sum += (d * (double) scales[scale_offset + group + 2] * (double) q2) * input[base + 32 + lane];
                sum += (d * (double) scales[scale_offset + group + 4] * (double) q3) * input[base + 64 + lane];
                sum += (d * (double) scales[scale_offset + group + 6] * (double) q4) * input[base + 96 + lane];
            }

            ql_offset += 64;
            qh_offset += 32;
            scale_offset += 8;
        }

        cursor += 210;
    }

    return sum;
}

static double king_gguf_row_dot(
    const unsigned char *raw,
    zend_long tensor_type,
    size_t ne0,
    const double *input
)
{
    switch (tensor_type) {
        case 0:
            return king_gguf_row_dot_f32(raw, ne0, input);
        case 1:
            return king_gguf_row_dot_f16(raw, ne0, input);
        case 8:
            return king_gguf_row_dot_q8_0(raw, ne0, input);
        case 12:
            return king_gguf_row_dot_q4_k(raw, ne0, input);
        case 14:
            return king_gguf_row_dot_q6_k(raw, ne0, input);
        default:
            return NAN;
    }
}

static int king_gguf_topk_desc_compare(const void *left, const void *right)
{
    const king_gguf_topk_entry *lhs = (const king_gguf_topk_entry *) left;
    const king_gguf_topk_entry *rhs = (const king_gguf_topk_entry *) right;

    if (lhs->score < rhs->score) {
        return 1;
    }
    if (lhs->score > rhs->score) {
        return -1;
    }
    if (lhs->row < rhs->row) {
        return -1;
    }
    if (lhs->row > rhs->row) {
        return 1;
    }
    return 0;
}

static zend_result king_voltron_copy_numeric_vector(
    HashTable *table,
    double **vector_out,
    size_t *count_out,
    const char *function_name,
    const char *label
)
{
    zval *entry;
    size_t count = zend_hash_num_elements(table);
    size_t index = 0;
    double *vector;

    vector = (double *) safe_emalloc(count > 0 ? count : 1, sizeof(double), 0);

    ZEND_HASH_FOREACH_VAL(table, entry) {
        vector[index++] = zval_get_double(entry);
    } ZEND_HASH_FOREACH_END();

    if (index != count) {
        efree(vector);
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s failed to copy numeric vector '%s'.",
            function_name,
            label
        );
        return FAILURE;
    }

    *vector_out = vector;
    *count_out = count;
    return SUCCESS;
}

static zend_result king_voltron_parse_tensor_meta(
    zval *meta_value,
    const char *label,
    king_gguf_tensor_meta *meta_out,
    const char *function_name
)
{
    HashTable *table;

    if (meta_value == NULL || Z_TYPE_P(meta_value) != IS_ARRAY) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s requires layer['%s'] tensor metadata.",
            function_name,
            label
        );
        return FAILURE;
    }

    table = Z_ARRVAL_P(meta_value);
    if (king_gguf_require_long(table, "absolute_offset", sizeof("absolute_offset") - 1, &meta_out->absolute_offset) != SUCCESS
        || king_gguf_require_long(table, "row_count", sizeof("row_count") - 1, &meta_out->row_count) != SUCCESS
        || king_gguf_require_long(table, "row_size", sizeof("row_size") - 1, &meta_out->row_size) != SUCCESS
        || king_gguf_require_long(table, "ne0", sizeof("ne0") - 1, &meta_out->ne0) != SUCCESS
        || king_gguf_require_long(table, "type", sizeof("type") - 1, &meta_out->type) != SUCCESS) {
        return FAILURE;
    }

    if (meta_out->absolute_offset < 0 || meta_out->row_count <= 0 || meta_out->row_size <= 0 || meta_out->ne0 <= 0) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s received invalid tensor metadata for '%s'.",
            function_name,
            label
        );
        return FAILURE;
    }

    return SUCCESS;
}

static void king_voltron_decode_row_f32(const unsigned char *raw, size_t ne0, double *out)
{
    size_t i;

    for (i = 0; i < ne0; ++i) {
        out[i] = (double) king_gguf_f32le(raw + (i * 4));
    }
}

static void king_voltron_decode_row_f16(const unsigned char *raw, size_t ne0, double *out)
{
    size_t i;

    for (i = 0; i < ne0; ++i) {
        out[i] = (double) king_gguf_half_to_float(king_gguf_u16le(raw + (i * 2)));
    }
}

static void king_voltron_decode_row_q8_0(const unsigned char *raw, size_t ne0, double *out)
{
    size_t blocks = ne0 / 32;
    size_t block;
    size_t cursor = 0;
    size_t out_index = 0;

    for (block = 0; block < blocks; ++block) {
        double d = (double) king_gguf_half_to_float(king_gguf_u16le(raw + cursor));
        size_t lane;

        cursor += 2;
        for (lane = 0; lane < 32; ++lane) {
            int q = (int8_t) raw[cursor + lane];
            out[out_index++] = d * (double) q;
        }
        cursor += 32;
    }
}

static void king_voltron_decode_row_q4_k(const unsigned char *raw, size_t ne0, double *out)
{
    size_t blocks = ne0 / 256;
    size_t block;
    size_t cursor = 0;
    size_t out_index = 0;

    for (block = 0; block < blocks; ++block) {
        double d = (double) king_gguf_half_to_float(king_gguf_u16le(raw + cursor));
        double dmin = (double) king_gguf_half_to_float(king_gguf_u16le(raw + cursor + 2));
        const unsigned char *scales = raw + cursor + 4;
        const unsigned char *q = raw + cursor + 16;
        int scale_index = 0;
        size_t q_offset = 0;
        size_t chunk;

        for (chunk = 0; chunk < 4; ++chunk) {
            int sc1;
            int min1;
            int sc2;
            int min2;
            double d1;
            double d2;
            double min_value1;
            double min_value2;
            size_t lane;

            king_gguf_q4k_scale_min(scale_index + 0, scales, &sc1, &min1);
            king_gguf_q4k_scale_min(scale_index + 1, scales, &sc2, &min2);

            d1 = d * (double) sc1;
            d2 = d * (double) sc2;
            min_value1 = dmin * (double) min1;
            min_value2 = dmin * (double) min2;

            for (lane = 0; lane < 32; ++lane) {
                unsigned char byte = q[q_offset + lane];
                out[out_index++] = (d1 * (double) (byte & 0x0F)) - min_value1;
            }
            for (lane = 0; lane < 32; ++lane) {
                unsigned char byte = q[q_offset + lane];
                out[out_index++] = (d2 * (double) ((byte >> 4) & 0x0F)) - min_value2;
            }

            q_offset += 32;
            scale_index += 2;
        }

        cursor += 144;
    }
}

static void king_voltron_decode_row_q6_k(const unsigned char *raw, size_t ne0, double *out)
{
    size_t blocks = ne0 / 256;
    size_t block;
    size_t cursor = 0;
    size_t out_index = 0;

    for (block = 0; block < blocks; ++block) {
        const unsigned char *ql = raw + cursor;
        const unsigned char *qh = raw + cursor + 128;
        const signed char *scales = (const signed char *) (raw + cursor + 192);
        double d = (double) king_gguf_half_to_float(king_gguf_u16le(raw + cursor + 208));
        size_t ql_offset = 0;
        size_t qh_offset = 0;
        size_t scale_offset = 0;
        size_t chunk;

        for (chunk = 0; chunk < 2; ++chunk) {
            size_t lane;

            for (lane = 0; lane < 32; ++lane) {
                int group = (int) (lane / 16);
                int qh_byte = (int) qh[qh_offset + lane];
                int ql_a = (int) ql[ql_offset + lane];
                int ql_b = (int) ql[ql_offset + 32 + lane];
                int q1 = ((ql_a & 0x0F) | (((qh_byte >> 0) & 0x03) << 4)) - 32;
                int q2 = ((ql_b & 0x0F) | (((qh_byte >> 2) & 0x03) << 4)) - 32;
                int q3 = (((ql_a >> 4) & 0x0F) | (((qh_byte >> 4) & 0x03) << 4)) - 32;
                int q4 = (((ql_b >> 4) & 0x0F) | (((qh_byte >> 6) & 0x03) << 4)) - 32;

                out[out_index + lane] = d * (double) scales[scale_offset + group + 0] * (double) q1;
                out[out_index + 32 + lane] = d * (double) scales[scale_offset + group + 2] * (double) q2;
                out[out_index + 64 + lane] = d * (double) scales[scale_offset + group + 4] * (double) q3;
                out[out_index + 96 + lane] = d * (double) scales[scale_offset + group + 6] * (double) q4;
            }

            out_index += 128;
            ql_offset += 64;
            qh_offset += 32;
            scale_offset += 8;
        }

        cursor += 210;
    }
}

static zend_result king_voltron_decode_row(
    const unsigned char *raw,
    zend_long tensor_type,
    size_t ne0,
    double *out,
    const char *function_name
)
{
    switch (tensor_type) {
        case 0:
            king_voltron_decode_row_f32(raw, ne0, out);
            return SUCCESS;
        case 1:
            king_voltron_decode_row_f16(raw, ne0, out);
            return SUCCESS;
        case 8:
            king_voltron_decode_row_q8_0(raw, ne0, out);
            return SUCCESS;
        case 12:
            king_voltron_decode_row_q4_k(raw, ne0, out);
            return SUCCESS;
        case 14:
            king_voltron_decode_row_q6_k(raw, ne0, out);
            return SUCCESS;
        default:
            zend_throw_exception_ex(
                king_ce_runtime_exception,
                0,
                "%s does not support GGUF tensor type %lld.",
                function_name,
                (long long) tensor_type
            );
            return FAILURE;
    }
}

static zend_result king_voltron_read_tensor_row(
    FILE *fh,
    const char *path,
    const king_gguf_tensor_meta *meta,
    zend_long row_index,
    double **row_out,
    size_t *row_len_out,
    const char *function_name
)
{
    unsigned char *raw = NULL;
    double *decoded = NULL;
    off_t offset;

    if (row_index < 0 || row_index >= meta->row_count) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s row %lld is out of range.",
            function_name,
            (long long) row_index
        );
        return FAILURE;
    }

    raw = (unsigned char *) safe_emalloc((size_t) meta->row_size, sizeof(unsigned char), 0);
    decoded = (double *) safe_emalloc((size_t) meta->ne0, sizeof(double), 0);
    offset = (off_t) meta->absolute_offset + ((off_t) row_index * (off_t) meta->row_size);

    if (fseeko(fh, offset, SEEK_SET) != 0) {
        efree(raw);
        efree(decoded);
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s failed to seek '%s'.",
            function_name,
            path
        );
        return FAILURE;
    }

    if (fread(raw, (size_t) meta->row_size, 1, fh) != 1) {
        efree(raw);
        efree(decoded);
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s short read while decoding '%s'.",
            function_name,
            path
        );
        return FAILURE;
    }

    if (king_voltron_decode_row(raw, meta->type, (size_t) meta->ne0, decoded, function_name) != SUCCESS) {
        efree(raw);
        efree(decoded);
        return FAILURE;
    }

    efree(raw);
    *row_out = decoded;
    *row_len_out = (size_t) meta->ne0;
    return SUCCESS;
}

static zend_result king_voltron_project_tensor(
    FILE *fh,
    const char *path,
    const king_gguf_tensor_meta *meta,
    const double *input,
    size_t input_len,
    double **out,
    size_t *out_len,
    const char *function_name
)
{
    unsigned char *raw = NULL;
    double *projected = NULL;
    size_t row_index;

    if (input_len < (size_t) meta->ne0) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s input vector is too short for tensor width %lld.",
            function_name,
            (long long) meta->ne0
        );
        return FAILURE;
    }

    raw = (unsigned char *) safe_emalloc((size_t) meta->row_size, sizeof(unsigned char), 0);
    projected = (double *) safe_emalloc((size_t) meta->row_count, sizeof(double), 0);

    if (fseeko(fh, (off_t) meta->absolute_offset, SEEK_SET) != 0) {
        efree(raw);
        efree(projected);
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s failed to seek '%s'.",
            function_name,
            path
        );
        return FAILURE;
    }

    for (row_index = 0; row_index < (size_t) meta->row_count; ++row_index) {
        double score;

        if (fread(raw, (size_t) meta->row_size, 1, fh) != 1) {
            efree(raw);
            efree(projected);
            zend_throw_exception_ex(
                king_ce_runtime_exception,
                0,
                "%s short read while projecting '%s'.",
                function_name,
                path
            );
            return FAILURE;
        }

        score = king_gguf_row_dot(raw, meta->type, (size_t) meta->ne0, input);
        if (isnan(score)) {
            efree(raw);
            efree(projected);
            zend_throw_exception_ex(
                king_ce_runtime_exception,
                0,
                "%s failed to decode tensor row for type %lld.",
                function_name,
                (long long) meta->type
            );
            return FAILURE;
        }

        projected[row_index] = score;
    }

    efree(raw);
    *out = projected;
    *out_len = (size_t) meta->row_count;
    return SUCCESS;
}

static double king_voltron_dot(const double *left, const double *right, size_t count)
{
    double sum = 0.0;
    size_t i;

    for (i = 0; i < count; ++i) {
        sum += left[i] * right[i];
    }

    return sum;
}

static void king_voltron_vec_add_inplace(double *target, const double *delta, size_t count)
{
    size_t i;

    for (i = 0; i < count; ++i) {
        target[i] += delta[i];
    }
}

static void king_voltron_rms_norm(
    const double *input,
    const double *weights,
    size_t count,
    double eps,
    double *out
)
{
    double mean = 0.0;
    double inv;
    size_t i;

    for (i = 0; i < count; ++i) {
        mean += input[i] * input[i];
    }
    mean /= (double) (count > 0 ? count : 1);

    inv = 1.0 / sqrt(mean + eps);
    for (i = 0; i < count; ++i) {
        out[i] = input[i] * inv * weights[i];
    }
}

static void king_voltron_apply_rope_flat(
    double *values,
    size_t head_count,
    size_t head_dim,
    zend_long position,
    double freq_base,
    zend_long rope_type
)
{
    size_t head;
    size_t rotary_dim = head_dim - (head_dim % 2);

    for (head = 0; head < head_count; ++head) {
        double *head_values = values + (head * head_dim);

        if (rotary_dim < 2) {
            continue;
        }

        if (rope_type == 2) {
            size_t half = rotary_dim / 2;
            size_t lane;

            for (lane = 0; lane < half; ++lane) {
                double theta = ((double) position) / pow(freq_base, ((double) (lane * 2)) / ((double) rotary_dim));
                double c = cos(theta);
                double s = sin(theta);
                double x0 = head_values[lane];
                double x1 = head_values[lane + half];

                head_values[lane] = (x0 * c) - (x1 * s);
                head_values[lane + half] = (x0 * s) + (x1 * c);
            }
        } else {
            size_t lane;

            for (lane = 0; lane + 1 < rotary_dim; lane += 2) {
                double theta = ((double) position) / pow(freq_base, ((double) lane) / ((double) rotary_dim));
                double c = cos(theta);
                double s = sin(theta);
                double x0 = head_values[lane];
                double x1 = head_values[lane + 1];

                head_values[lane] = (x0 * c) - (x1 * s);
                head_values[lane + 1] = (x0 * s) + (x1 * c);
            }
        }
    }
}

static void king_voltron_add_vector_assoc(zval *target, const char *key, const double *values, size_t count)
{
    zval vector;
    size_t i;

    array_init_size(&vector, (uint32_t) count);
    for (i = 0; i < count; ++i) {
        add_next_index_double(&vector, values[i]);
    }

    add_assoc_zval(target, key, &vector);
}

static zend_result king_voltron_attention_step(
    FILE *fh,
    const char *path,
    const king_gguf_tensor_meta *norm_meta,
    const king_gguf_tensor_meta *q_meta,
    const king_gguf_tensor_meta *k_meta,
    const king_gguf_tensor_meta *v_meta,
    const king_gguf_tensor_meta *o_meta,
    double *hidden,
    size_t hidden_len,
    const double *cache_k_in,
    const double *cache_v_in,
    size_t cache_tokens,
    zend_long position,
    size_t head_count,
    size_t head_count_kv,
    size_t head_dim,
    double rope_freq_base,
    zend_long rope_type,
    double rms_eps,
    double **cache_k_out,
    double **cache_v_out,
    size_t *cache_tokens_out,
    const char *function_name
)
{
    double *norm = NULL;
    double *x = NULL;
    double *q = NULL;
    double *k = NULL;
    double *v = NULL;
    double *ctx = NULL;
    double *proj = NULL;
    double *scores = NULL;
    double *weights = NULL;
    double *cache_k = NULL;
    double *cache_v = NULL;
    size_t norm_len = 0;
    size_t q_len = 0;
    size_t k_len = 0;
    size_t v_len = 0;
    size_t proj_len = 0;
    size_t cache_width = head_count_kv * head_dim;
    size_t total_tokens = cache_tokens + 1;
    size_t q_head;
    zend_result status = FAILURE;

    if (king_voltron_read_tensor_row(fh, path, norm_meta, 0, &norm, &norm_len, function_name) != SUCCESS) {
        goto cleanup;
    }
    if (norm_len != hidden_len || norm_meta->ne0 != (zend_long) hidden_len) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s attention norm width mismatch.",
            function_name
        );
        goto cleanup;
    }

    x = (double *) safe_emalloc(hidden_len > 0 ? hidden_len : 1, sizeof(double), 0);
    king_voltron_rms_norm(hidden, norm, hidden_len, rms_eps, x);

    if (king_voltron_project_tensor(fh, path, q_meta, x, hidden_len, &q, &q_len, function_name) != SUCCESS
        || king_voltron_project_tensor(fh, path, k_meta, x, hidden_len, &k, &k_len, function_name) != SUCCESS
        || king_voltron_project_tensor(fh, path, v_meta, x, hidden_len, &v, &v_len, function_name) != SUCCESS) {
        goto cleanup;
    }

    if (q_len != head_count * head_dim || k_len != head_count_kv * head_dim || v_len != head_count_kv * head_dim) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s attention projection dimensions do not match runtime head configuration.",
            function_name
        );
        goto cleanup;
    }

    king_voltron_apply_rope_flat(q, head_count, head_dim, position, rope_freq_base, rope_type);
    king_voltron_apply_rope_flat(k, head_count_kv, head_dim, position, rope_freq_base, rope_type);

    cache_k = (double *) safe_emalloc(cache_width * total_tokens, sizeof(double), 0);
    cache_v = (double *) safe_emalloc(cache_width * total_tokens, sizeof(double), 0);
    if (cache_tokens > 0 && cache_k_in != NULL && cache_v_in != NULL) {
        memcpy(cache_k, cache_k_in, cache_width * cache_tokens * sizeof(double));
        memcpy(cache_v, cache_v_in, cache_width * cache_tokens * sizeof(double));
    }
    memcpy(cache_k + (cache_width * cache_tokens), k, cache_width * sizeof(double));
    memcpy(cache_v + (cache_width * cache_tokens), v, cache_width * sizeof(double));

    ctx = (double *) safe_emalloc(head_count * head_dim > 0 ? head_count * head_dim : 1, sizeof(double), 0);
    memset(ctx, 0, (head_count * head_dim > 0 ? head_count * head_dim : 1) * sizeof(double));
    scores = (double *) safe_emalloc(total_tokens > 0 ? total_tokens : 1, sizeof(double), 0);
    weights = (double *) safe_emalloc(total_tokens > 0 ? total_tokens : 1, sizeof(double), 0);

    for (q_head = 0; q_head < head_count; ++q_head) {
        size_t kv_head = (head_count_kv > 0)
            ? (((head_count / head_count_kv) > 0 ? (q_head / (head_count / head_count_kv)) : q_head))
            : 0;
        double max_score = 0.0;
        double exp_sum = 0.0;
        double *ctx_head = ctx + (q_head * head_dim);
        const double *q_head_values = q + (q_head * head_dim);
        size_t token_index;

        if (kv_head >= head_count_kv) {
            kv_head = head_count_kv - 1;
        }

        for (token_index = 0; token_index < total_tokens; ++token_index) {
            const double *k_head_values = cache_k + (token_index * cache_width) + (kv_head * head_dim);
            double score = king_voltron_dot(q_head_values, k_head_values, head_dim) / sqrt((double) head_dim);
            scores[token_index] = score;
            if (token_index == 0 || score > max_score) {
                max_score = score;
            }
        }

        for (token_index = 0; token_index < total_tokens; ++token_index) {
            double weight = exp(scores[token_index] - max_score);
            weights[token_index] = weight;
            exp_sum += weight;
        }

        if (exp_sum <= 0.0) {
            exp_sum = 1.0;
        }

        for (token_index = 0; token_index < total_tokens; ++token_index) {
            const double *v_head_values = cache_v + (token_index * cache_width) + (kv_head * head_dim);
            double weight = weights[token_index] / exp_sum;
            size_t lane;

            for (lane = 0; lane < head_dim; ++lane) {
                ctx_head[lane] += weight * v_head_values[lane];
            }
        }
    }

    if (king_voltron_project_tensor(fh, path, o_meta, ctx, head_count * head_dim, &proj, &proj_len, function_name) != SUCCESS) {
        goto cleanup;
    }
    if (proj_len != hidden_len) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s attention output width mismatch.",
            function_name
        );
        goto cleanup;
    }

    king_voltron_vec_add_inplace(hidden, proj, hidden_len);
    *cache_k_out = cache_k;
    *cache_v_out = cache_v;
    *cache_tokens_out = total_tokens;
    cache_k = NULL;
    cache_v = NULL;
    status = SUCCESS;

cleanup:
    if (norm != NULL) {
        efree(norm);
    }
    if (x != NULL) {
        efree(x);
    }
    if (q != NULL) {
        efree(q);
    }
    if (k != NULL) {
        efree(k);
    }
    if (v != NULL) {
        efree(v);
    }
    if (ctx != NULL) {
        efree(ctx);
    }
    if (proj != NULL) {
        efree(proj);
    }
    if (scores != NULL) {
        efree(scores);
    }
    if (weights != NULL) {
        efree(weights);
    }
    if (cache_k != NULL) {
        efree(cache_k);
    }
    if (cache_v != NULL) {
        efree(cache_v);
    }

    return status;
}

static zend_result king_voltron_ffn_step(
    FILE *fh,
    const char *path,
    const king_gguf_tensor_meta *norm_meta,
    const king_gguf_tensor_meta *gate_meta,
    const king_gguf_tensor_meta *up_meta,
    const king_gguf_tensor_meta *down_meta,
    double *hidden,
    size_t hidden_len,
    double rms_eps,
    const char *function_name
)
{
    double *norm = NULL;
    double *x = NULL;
    double *gate = NULL;
    double *up = NULL;
    double *act = NULL;
    double *down = NULL;
    size_t norm_len = 0;
    size_t gate_len = 0;
    size_t up_len = 0;
    size_t down_len = 0;
    size_t i;
    zend_result status = FAILURE;

    if (king_voltron_read_tensor_row(fh, path, norm_meta, 0, &norm, &norm_len, function_name) != SUCCESS) {
        goto cleanup;
    }
    if (norm_len != hidden_len || norm_meta->ne0 != (zend_long) hidden_len) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s FFN norm width mismatch.",
            function_name
        );
        goto cleanup;
    }

    x = (double *) safe_emalloc(hidden_len > 0 ? hidden_len : 1, sizeof(double), 0);
    king_voltron_rms_norm(hidden, norm, hidden_len, rms_eps, x);

    if (king_voltron_project_tensor(fh, path, gate_meta, x, hidden_len, &gate, &gate_len, function_name) != SUCCESS
        || king_voltron_project_tensor(fh, path, up_meta, x, hidden_len, &up, &up_len, function_name) != SUCCESS) {
        goto cleanup;
    }

    if (gate_len != up_len) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s FFN gate/up projection mismatch.",
            function_name
        );
        goto cleanup;
    }

    act = (double *) safe_emalloc(gate_len > 0 ? gate_len : 1, sizeof(double), 0);
    for (i = 0; i < gate_len; ++i) {
        double g = gate[i];
        act[i] = (g / (1.0 + exp(-g))) * up[i];
    }

    if (king_voltron_project_tensor(fh, path, down_meta, act, gate_len, &down, &down_len, function_name) != SUCCESS) {
        goto cleanup;
    }
    if (down_len != hidden_len) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s FFN down projection width mismatch.",
            function_name
        );
        goto cleanup;
    }

    king_voltron_vec_add_inplace(hidden, down, hidden_len);
    status = SUCCESS;

cleanup:
    if (norm != NULL) {
        efree(norm);
    }
    if (x != NULL) {
        efree(x);
    }
    if (gate != NULL) {
        efree(gate);
    }
    if (up != NULL) {
        efree(up);
    }
    if (act != NULL) {
        efree(act);
    }
    if (down != NULL) {
        efree(down);
    }

    return status;
}

static void king_gguf_tensor_scan_impl(INTERNAL_FUNCTION_PARAMETERS)
{
    char *path = NULL;
    size_t path_len = 0;
    zval *tensor = NULL;
    zval *input = NULL;
    zval *options = NULL;
    zend_long absolute_offset = 0;
    zend_long row_count = 0;
    zend_long row_size = 0;
    zend_long ne0 = 0;
    zend_long tensor_type = -1;
    zend_long row_start = 0;
    zend_long row_limit = -1;
    zend_long top_k = 0;
    double *input_vector = NULL;
    unsigned char *raw = NULL;
    king_gguf_topk_entry *best = NULL;
    FILE *fh = NULL;
    size_t scan_count;
    size_t row_index;
    int status_ok = 0;

    ZEND_PARSE_PARAMETERS_START(3, 4)
        Z_PARAM_STRING(path, path_len)
        Z_PARAM_ARRAY(tensor)
        Z_PARAM_ARRAY(input)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    (void) path_len;

    if (king_gguf_require_long(Z_ARRVAL_P(tensor), "absolute_offset", sizeof("absolute_offset") - 1, &absolute_offset) != SUCCESS
        || king_gguf_require_long(Z_ARRVAL_P(tensor), "row_count", sizeof("row_count") - 1, &row_count) != SUCCESS
        || king_gguf_require_long(Z_ARRVAL_P(tensor), "row_size", sizeof("row_size") - 1, &row_size) != SUCCESS
        || king_gguf_require_long(Z_ARRVAL_P(tensor), "ne0", sizeof("ne0") - 1, &ne0) != SUCCESS
        || king_gguf_require_long(Z_ARRVAL_P(tensor), "type", sizeof("type") - 1, &tensor_type) != SUCCESS) {
        RETURN_THROWS();
    }

    if (absolute_offset < 0 || row_count < 0 || row_size <= 0 || ne0 <= 0) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "king_gguf_tensor_scan() received invalid tensor metadata."
        );
        RETURN_THROWS();
    }

    if (options != NULL) {
        (void) king_gguf_optional_long(Z_ARRVAL_P(options), "row_start", sizeof("row_start") - 1, &row_start);
        (void) king_gguf_optional_long(Z_ARRVAL_P(options), "row_limit", sizeof("row_limit") - 1, &row_limit);
        (void) king_gguf_optional_long(Z_ARRVAL_P(options), "top_k", sizeof("top_k") - 1, &top_k);
    }

    if (row_start < 0 || row_start > row_count) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "king_gguf_tensor_scan() row_start %lld is out of range for row_count %lld.",
            (long long) row_start,
            (long long) row_count
        );
        RETURN_THROWS();
    }

    if (row_limit < 0) {
        row_limit = row_count - row_start;
    }

    if (row_limit < 0 || row_start + row_limit > row_count) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "king_gguf_tensor_scan() row_limit %lld is out of range for row_start %lld and row_count %lld.",
            (long long) row_limit,
            (long long) row_start,
            (long long) row_count
        );
        RETURN_THROWS();
    }

    if (tensor_type != 0 && tensor_type != 1 && tensor_type != 8 && tensor_type != 12 && tensor_type != 14) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "king_gguf_tensor_scan() does not support GGUF tensor type %lld.",
            (long long) tensor_type
        );
        RETURN_THROWS();
    }

    if (king_gguf_copy_input_vector(Z_ARRVAL_P(input), (size_t) ne0, &input_vector) != SUCCESS) {
        RETURN_THROWS();
    }

    fh = fopen(path, "rb");
    if (fh == NULL) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "king_gguf_tensor_scan() failed to open '%s': %s",
            path,
            strerror(errno)
        );
        goto cleanup;
    }

    if (fseeko(fh, (off_t) absolute_offset + ((off_t) row_start * (off_t) row_size), SEEK_SET) != 0) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "king_gguf_tensor_scan() failed to seek '%s'.",
            path
        );
        goto cleanup;
    }

    raw = (unsigned char *) safe_emalloc((size_t) row_size, sizeof(unsigned char), 0);
    scan_count = (size_t) row_limit;

    if (top_k > 0) {
        size_t best_cap = (size_t) top_k;

        if (best_cap > scan_count) {
            best_cap = scan_count;
        }
        best = (king_gguf_topk_entry *) safe_emalloc(best_cap > 0 ? best_cap : 1, sizeof(king_gguf_topk_entry), 0);
    }

    array_init(return_value);

    for (row_index = 0; row_index < scan_count; ++row_index) {
        double score;

        if (fread(raw, (size_t) row_size, 1, fh) != 1) {
            zend_throw_exception_ex(
                king_ce_runtime_exception,
                0,
                "king_gguf_tensor_scan() short read while scanning '%s'.",
                path
            );
            goto cleanup;
        }

        score = king_gguf_row_dot(raw, tensor_type, (size_t) ne0, input_vector);
        if (isnan(score)) {
            zend_throw_exception_ex(
                king_ce_runtime_exception,
                0,
                "king_gguf_tensor_scan() failed to decode tensor row for type %lld.",
                (long long) tensor_type
            );
            goto cleanup;
        }

        if (top_k <= 0) {
            add_next_index_double(return_value, score);
        }
    }

    if (top_k > 0) {
        size_t best_cap = ((size_t) top_k > scan_count) ? scan_count : (size_t) top_k;
        size_t best_count = 0;
        size_t min_index = 0;
        double min_score = 0.0;

        zend_hash_clean(Z_ARRVAL_P(return_value));
        if (fseeko(fh, (off_t) absolute_offset + ((off_t) row_start * (off_t) row_size), SEEK_SET) != 0) {
            zend_throw_exception_ex(
                king_ce_runtime_exception,
                0,
                "king_gguf_tensor_scan() failed to rewind '%s' for top-k aggregation.",
                path
            );
            goto cleanup;
        }

        for (row_index = 0; row_index < scan_count; ++row_index) {
            double score;

            if (fread(raw, (size_t) row_size, 1, fh) != 1) {
                zend_throw_exception_ex(
                    king_ce_runtime_exception,
                    0,
                    "king_gguf_tensor_scan() short read while rescanning '%s'.",
                    path
                );
                goto cleanup;
            }

            score = king_gguf_row_dot(raw, tensor_type, (size_t) ne0, input_vector);
            if (best_count < best_cap) {
                best[best_count].row = row_start + (zend_long) row_index;
                best[best_count].score = score;
                if (best_count == 0 || score < min_score) {
                    min_index = best_count;
                    min_score = score;
                }
                best_count++;
                continue;
            }

            if (best_cap == 0 || score <= min_score) {
                continue;
            }

            best[min_index].row = row_start + (zend_long) row_index;
            best[min_index].score = score;
            min_index = 0;
            min_score = best[0].score;

            {
                size_t i;
                for (i = 1; i < best_count; ++i) {
                    if (best[i].score < min_score) {
                        min_score = best[i].score;
                        min_index = i;
                    }
                }
            }
        }

        qsort(best, best_count, sizeof(king_gguf_topk_entry), king_gguf_topk_desc_compare);
        {
            size_t i;
            for (i = 0; i < best_count; ++i) {
                add_index_double(return_value, best[i].row, best[i].score);
            }
        }
    }

    status_ok = 1;

cleanup:
    if (fh != NULL) {
        fclose(fh);
    }
    if (raw != NULL) {
        efree(raw);
    }
    if (input_vector != NULL) {
        efree(input_vector);
    }
    if (best != NULL) {
        efree(best);
    }
    if (!status_ok && EG(exception) != NULL) {
        RETURN_THROWS();
    }
}

PHP_FUNCTION(king_gguf_tensor_scan)
{
    king_gguf_tensor_scan_impl(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

PHP_FUNCTION(king_native_gguf_tensor_scan)
{
    king_gguf_tensor_scan_impl(INTERNAL_FUNCTION_PARAM_PASSTHRU);
}

PHP_FUNCTION(king_native_voltron_layer_worker)
{
    char *path = NULL;
    size_t path_len = 0;
    zval *request = NULL;
    zval *mode_value = NULL;
    zval *hidden_value = NULL;
    zval *layers_value = NULL;
    zend_string *mode = NULL;
    double *hidden = NULL;
    size_t hidden_len = 0;
    FILE *fh = NULL;
    double rms_eps = 1e-5;
    zend_long position = 0;
    zend_long head_count_long = 0;
    zend_long head_count_kv_long = 0;
    zend_long head_dim_long = 0;
    zend_long rope_type = 0;
    double rope_freq_base = 10000.0;
    size_t head_count = 0;
    size_t head_count_kv = 0;
    size_t head_dim = 0;
    zend_bool wants_attention = 0;
    zend_bool wants_ffn = 0;
    zval layers_out;
    int status_ok = 0;
    const char *function_name = "king_native_voltron_layer_worker()";

    ZEND_PARSE_PARAMETERS_START(2, 2)
        Z_PARAM_STRING(path, path_len)
        Z_PARAM_ARRAY(request)
    ZEND_PARSE_PARAMETERS_END();

    (void) path_len;

    mode_value = zend_hash_str_find(Z_ARRVAL_P(request), "mode", sizeof("mode") - 1);
    hidden_value = zend_hash_str_find(Z_ARRVAL_P(request), "hidden", sizeof("hidden") - 1);
    layers_value = zend_hash_str_find(Z_ARRVAL_P(request), "layers", sizeof("layers") - 1);

    if (mode_value == NULL || Z_TYPE_P(mode_value) != IS_STRING) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s requires request['mode'] string.",
            function_name
        );
        RETURN_THROWS();
    }
    if (hidden_value == NULL || Z_TYPE_P(hidden_value) != IS_ARRAY) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s requires request['hidden'] array.",
            function_name
        );
        RETURN_THROWS();
    }
    if (layers_value == NULL || Z_TYPE_P(layers_value) != IS_ARRAY) {
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s requires request['layers'] array.",
            function_name
        );
        RETURN_THROWS();
    }

    mode = zval_get_string(mode_value);
    if (zend_string_equals_literal(mode, "attention")) {
        wants_attention = 1;
    } else if (zend_string_equals_literal(mode, "ffn")) {
        wants_ffn = 1;
    } else if (zend_string_equals_literal(mode, "layer")) {
        wants_attention = 1;
        wants_ffn = 1;
    } else {
        zend_string_release(mode);
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s request['mode'] must be attention|ffn|layer.",
            function_name
        );
        RETURN_THROWS();
    }

    if (king_voltron_copy_numeric_vector(
            Z_ARRVAL_P(hidden_value),
            &hidden,
            &hidden_len,
            function_name,
            "hidden"
        ) != SUCCESS) {
        zend_string_release(mode);
        RETURN_THROWS();
    }

    if (wants_attention) {
        zval *value;

        value = zend_hash_str_find(Z_ARRVAL_P(request), "position", sizeof("position") - 1);
        if (value != NULL) {
            position = zval_get_long(value);
        }
        value = zend_hash_str_find(Z_ARRVAL_P(request), "head_count", sizeof("head_count") - 1);
        if (value == NULL) {
            zend_string_release(mode);
            efree(hidden);
            zend_throw_exception_ex(king_ce_runtime_exception, 0, "%s requires request['head_count'].", function_name);
            RETURN_THROWS();
        }
        head_count_long = zval_get_long(value);
        value = zend_hash_str_find(Z_ARRVAL_P(request), "head_count_kv", sizeof("head_count_kv") - 1);
        if (value == NULL) {
            zend_string_release(mode);
            efree(hidden);
            zend_throw_exception_ex(king_ce_runtime_exception, 0, "%s requires request['head_count_kv'].", function_name);
            RETURN_THROWS();
        }
        head_count_kv_long = zval_get_long(value);
        value = zend_hash_str_find(Z_ARRVAL_P(request), "head_dim", sizeof("head_dim") - 1);
        if (value == NULL) {
            zend_string_release(mode);
            efree(hidden);
            zend_throw_exception_ex(king_ce_runtime_exception, 0, "%s requires request['head_dim'].", function_name);
            RETURN_THROWS();
        }
        head_dim_long = zval_get_long(value);
        value = zend_hash_str_find(Z_ARRVAL_P(request), "rope_freq_base", sizeof("rope_freq_base") - 1);
        if (value != NULL) {
            rope_freq_base = zval_get_double(value);
        }
        value = zend_hash_str_find(Z_ARRVAL_P(request), "rope_type", sizeof("rope_type") - 1);
        if (value != NULL) {
            rope_type = zval_get_long(value);
        }
        value = zend_hash_str_find(Z_ARRVAL_P(request), "rms_eps", sizeof("rms_eps") - 1);
        if (value != NULL) {
            rms_eps = zval_get_double(value);
        }

        if (head_count_long <= 0 || head_count_kv_long <= 0 || head_dim_long <= 0) {
            zend_string_release(mode);
            efree(hidden);
            zend_throw_exception_ex(king_ce_runtime_exception, 0, "%s requires positive head_count/head_count_kv/head_dim.", function_name);
            RETURN_THROWS();
        }

        head_count = (size_t) head_count_long;
        head_count_kv = (size_t) head_count_kv_long;
        head_dim = (size_t) head_dim_long;
    } else {
        zval *value = zend_hash_str_find(Z_ARRVAL_P(request), "rms_eps", sizeof("rms_eps") - 1);
        if (value != NULL) {
            rms_eps = zval_get_double(value);
        }
    }

    fh = fopen(path, "rb");
    if (fh == NULL) {
        zend_string_release(mode);
        efree(hidden);
        zend_throw_exception_ex(
            king_ce_runtime_exception,
            0,
            "%s failed to open '%s': %s",
            function_name,
            path,
            strerror(errno)
        );
        RETURN_THROWS();
    }

    array_init(return_value);
    array_init(&layers_out);

    {
        zval *layer_value;

        ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(layers_value), layer_value) {
            HashTable *layer_table;
            zval *entry;
            zend_long layer_id = -1;

            king_gguf_tensor_meta attn_norm_meta;
            king_gguf_tensor_meta attn_q_meta;
            king_gguf_tensor_meta attn_k_meta;
            king_gguf_tensor_meta attn_v_meta;
            king_gguf_tensor_meta attn_out_meta;
            king_gguf_tensor_meta ffn_norm_meta;
            king_gguf_tensor_meta ffn_gate_meta;
            king_gguf_tensor_meta ffn_up_meta;
            king_gguf_tensor_meta ffn_down_meta;

            if (layer_value == NULL || Z_TYPE_P(layer_value) != IS_ARRAY) {
                zend_throw_exception_ex(
                    king_ce_runtime_exception,
                    0,
                    "%s request['layers'] entries must be arrays.",
                    function_name
                );
                goto cleanup;
            }

            layer_table = Z_ARRVAL_P(layer_value);
            entry = zend_hash_str_find(layer_table, "layer", sizeof("layer") - 1);
            if (entry != NULL) {
                layer_id = zval_get_long(entry);
            }

            if (wants_attention) {
                double *cache_k_in = NULL;
                double *cache_v_in = NULL;
                double *cache_k_out = NULL;
                double *cache_v_out = NULL;
                size_t cache_k_in_len = 0;
                size_t cache_v_in_len = 0;
                size_t cache_tokens = 0;
                size_t cache_tokens_out = 0;
                size_t expected_cache_len;
                zval *cache_value;
                zval layer_out;

                if (king_voltron_parse_tensor_meta(
                        zend_hash_str_find(layer_table, "attn_norm", sizeof("attn_norm") - 1),
                        "attn_norm",
                        &attn_norm_meta,
                        function_name
                    ) != SUCCESS
                    || king_voltron_parse_tensor_meta(
                        zend_hash_str_find(layer_table, "attn_q", sizeof("attn_q") - 1),
                        "attn_q",
                        &attn_q_meta,
                        function_name
                    ) != SUCCESS
                    || king_voltron_parse_tensor_meta(
                        zend_hash_str_find(layer_table, "attn_k", sizeof("attn_k") - 1),
                        "attn_k",
                        &attn_k_meta,
                        function_name
                    ) != SUCCESS
                    || king_voltron_parse_tensor_meta(
                        zend_hash_str_find(layer_table, "attn_v", sizeof("attn_v") - 1),
                        "attn_v",
                        &attn_v_meta,
                        function_name
                    ) != SUCCESS
                    || king_voltron_parse_tensor_meta(
                        zend_hash_str_find(layer_table, "attn_output", sizeof("attn_output") - 1),
                        "attn_output",
                        &attn_out_meta,
                        function_name
                    ) != SUCCESS) {
                    goto cleanup;
                }

                cache_value = zend_hash_str_find(layer_table, "cache_tokens", sizeof("cache_tokens") - 1);
                if (cache_value != NULL) {
                    zend_long cache_tokens_long = zval_get_long(cache_value);
                    if (cache_tokens_long < 0) {
                        zend_throw_exception_ex(
                            king_ce_runtime_exception,
                            0,
                            "%s layer cache_tokens must be >= 0.",
                            function_name
                        );
                        goto cleanup;
                    }
                    cache_tokens = (size_t) cache_tokens_long;
                }

                expected_cache_len = cache_tokens * head_count_kv * head_dim;

                cache_value = zend_hash_str_find(layer_table, "cache_k", sizeof("cache_k") - 1);
                if (cache_value != NULL && Z_TYPE_P(cache_value) == IS_ARRAY) {
                    if (king_voltron_copy_numeric_vector(
                            Z_ARRVAL_P(cache_value),
                            &cache_k_in,
                            &cache_k_in_len,
                            function_name,
                            "cache_k"
                        ) != SUCCESS) {
                        goto cleanup;
                    }
                }

                cache_value = zend_hash_str_find(layer_table, "cache_v", sizeof("cache_v") - 1);
                if (cache_value != NULL && Z_TYPE_P(cache_value) == IS_ARRAY) {
                    if (king_voltron_copy_numeric_vector(
                            Z_ARRVAL_P(cache_value),
                            &cache_v_in,
                            &cache_v_in_len,
                            function_name,
                            "cache_v"
                        ) != SUCCESS) {
                        if (cache_k_in != NULL) {
                            efree(cache_k_in);
                        }
                        goto cleanup;
                    }
                }

                if (cache_tokens > 0 && (cache_k_in == NULL || cache_v_in == NULL
                    || cache_k_in_len != expected_cache_len || cache_v_in_len != expected_cache_len)) {
                    if (cache_k_in != NULL) {
                        efree(cache_k_in);
                    }
                    if (cache_v_in != NULL) {
                        efree(cache_v_in);
                    }
                    zend_throw_exception_ex(
                        king_ce_runtime_exception,
                        0,
                        "%s cache_k/cache_v lengths do not match cache_tokens.",
                        function_name
                    );
                    goto cleanup;
                }

                if (king_voltron_attention_step(
                        fh,
                        path,
                        &attn_norm_meta,
                        &attn_q_meta,
                        &attn_k_meta,
                        &attn_v_meta,
                        &attn_out_meta,
                        hidden,
                        hidden_len,
                        cache_k_in,
                        cache_v_in,
                        cache_tokens,
                        position,
                        head_count,
                        head_count_kv,
                        head_dim,
                        rope_freq_base,
                        rope_type,
                        rms_eps,
                        &cache_k_out,
                        &cache_v_out,
                        &cache_tokens_out,
                        function_name
                    ) != SUCCESS) {
                    if (cache_k_in != NULL) {
                        efree(cache_k_in);
                    }
                    if (cache_v_in != NULL) {
                        efree(cache_v_in);
                    }
                    goto cleanup;
                }

                if (cache_k_in != NULL) {
                    efree(cache_k_in);
                }
                if (cache_v_in != NULL) {
                    efree(cache_v_in);
                }

                array_init(&layer_out);
                add_assoc_long(&layer_out, "layer", layer_id);
                add_assoc_long(&layer_out, "cache_tokens", (zend_long) cache_tokens_out);
                king_voltron_add_vector_assoc(&layer_out, "cache_k", cache_k_out, cache_tokens_out * head_count_kv * head_dim);
                king_voltron_add_vector_assoc(&layer_out, "cache_v", cache_v_out, cache_tokens_out * head_count_kv * head_dim);
                add_next_index_zval(&layers_out, &layer_out);

                efree(cache_k_out);
                efree(cache_v_out);
            }

            if (wants_ffn) {
                if (king_voltron_parse_tensor_meta(
                        zend_hash_str_find(layer_table, "ffn_norm", sizeof("ffn_norm") - 1),
                        "ffn_norm",
                        &ffn_norm_meta,
                        function_name
                    ) != SUCCESS
                    || king_voltron_parse_tensor_meta(
                        zend_hash_str_find(layer_table, "ffn_gate", sizeof("ffn_gate") - 1),
                        "ffn_gate",
                        &ffn_gate_meta,
                        function_name
                    ) != SUCCESS
                    || king_voltron_parse_tensor_meta(
                        zend_hash_str_find(layer_table, "ffn_up", sizeof("ffn_up") - 1),
                        "ffn_up",
                        &ffn_up_meta,
                        function_name
                    ) != SUCCESS
                    || king_voltron_parse_tensor_meta(
                        zend_hash_str_find(layer_table, "ffn_down", sizeof("ffn_down") - 1),
                        "ffn_down",
                        &ffn_down_meta,
                        function_name
                    ) != SUCCESS) {
                    goto cleanup;
                }

                if (king_voltron_ffn_step(
                        fh,
                        path,
                        &ffn_norm_meta,
                        &ffn_gate_meta,
                        &ffn_up_meta,
                        &ffn_down_meta,
                        hidden,
                        hidden_len,
                        rms_eps,
                        function_name
                    ) != SUCCESS) {
                    goto cleanup;
                }
            }
        } ZEND_HASH_FOREACH_END();
    }

    king_voltron_add_vector_assoc(return_value, "hidden", hidden, hidden_len);
    if (wants_attention) {
        add_assoc_zval(return_value, "layers", &layers_out);
    } else {
        zval_ptr_dtor(&layers_out);
    }

    status_ok = 1;

cleanup:
    if (fh != NULL) {
        fclose(fh);
    }
    if (mode != NULL) {
        zend_string_release(mode);
    }
    if (hidden != NULL) {
        efree(hidden);
    }
    if (!status_ok && EG(exception) != NULL) {
        zval_ptr_dtor(return_value);
        RETURN_THROWS();
    }
}
