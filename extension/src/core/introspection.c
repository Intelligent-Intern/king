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
