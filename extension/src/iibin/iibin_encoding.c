/*
 * Legacy IIBIN encode entry helper. Delegates schema lookup and payload
 * encoding to the current runtime registry/codec slices while keeping the
 * public king_iibin_encode() bridge stable.
 */

#include "php_king.h"
#include "include/iibin/iibin_internal.h"

static const char * const king_proto_primitive_types[] = {
    "double",
    "float",
    "int64",
    "uint64",
    "int32",
    "uint32",
    "sint32",
    "sint64",
    "fixed64",
    "sfixed64",
    "fixed32",
    "sfixed32",
    "bool",
    "string",
    "bytes"
};

/* Float/double functions now in shared header */

#include "../core/introspection/proto_registry.inc"
#include "../core/introspection/proto_codec.inc"

/* Batch encode: encode multiple records to amortize PHP↔C overhead */
zend_result king_iibin_encode_batch(
    zend_string *schema_name,
    zval *records,
    zval *encoded_out
)
{
    king_proto_runtime_schema *runtime_schema;
    zval *record;
    smart_str encoded = {0};

    if (!king_proto_registry_has_schema(schema_name)) {
        king_throw_proto_schema_not_defined(schema_name, "batch encoding");
        return FAILURE;
    }

    runtime_schema = king_proto_registry_get_runtime_schema(schema_name);
    if (runtime_schema == NULL) {
        king_throw_proto_schema_registered_but_unavailable(schema_name, "batch encoding");
        return FAILURE;
    }

    /* Batch encode: varint length-prefixed records */
    smart_str all_buf = {0};
    int count = 0;

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(records), record) {
        smart_str rec_buf = {0};
        if (!king_proto_runtime_encode_schema_payload(&rec_buf, schema_name, runtime_schema, record)) {
            if (rec_buf.s) smart_str_free(&rec_buf);
            smart_str_free(&all_buf);
            return FAILURE;
        }
        if (rec_buf.s) {
            uint32_t rec_len = (uint32_t)ZSTR_LEN(rec_buf.s);
            king_proto_encode_varint(&all_buf, rec_len);
            smart_str_append(&all_buf, rec_buf.s);
            smart_str_free(&rec_buf);
        }
        count++;
    } ZEND_HASH_FOREACH_END();

    if (all_buf.s) {
        smart_str_0(&all_buf);
        ZVAL_STRINGL(encoded_out, ZSTR_VAL(all_buf.s), ZSTR_LEN(all_buf.s));
        smart_str_free(&all_buf);
    }

    return SUCCESS;
}

zend_result king_iibin_encode(
    zend_string *schema_name,
    zval *data,
    smart_str *encoded_out
)
{
    king_proto_runtime_schema *runtime_schema;

    if (!king_proto_registry_has_schema(schema_name)) {
        king_throw_proto_schema_not_defined(schema_name, "encoding");
        return FAILURE;
    }

    runtime_schema = king_proto_registry_get_runtime_schema(schema_name);
    if (runtime_schema == NULL) {
        king_throw_proto_schema_registered_but_unavailable(schema_name, "encoding");
        return FAILURE;
    }

    if (!king_proto_runtime_encode_schema_payload(
            encoded_out,
            schema_name,
            runtime_schema,
            data
        )) {
        smart_str_free(encoded_out);
        return FAILURE;
    }

    return SUCCESS;
}
