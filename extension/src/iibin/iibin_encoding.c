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

/* Batch encode: encode multiple records at once to amortize PHP↔C overhead */
zend_result king_iibin_encode_batch(
    zend_string *schema_name,
    zval *records,  /* array of data */
    zval *encoded_out /* array of encoded strings */
)
{
    king_proto_runtime_schema *runtime_schema;
    zval *record;
    smart_str encoded = {0};
    int count = 0;

    if (!king_proto_registry_has_schema(schema_name)) {
        king_throw_proto_schema_not_defined(schema_name, "batch encoding");
        return FAILURE;
    }

    runtime_schema = king_proto_registry_get_runtime_schema(schema_name);
    if (runtime_schema == NULL) {
        king_throw_proto_schema_registered_but_unavailable(schema_name, "batch encoding");
        return FAILURE;
    }

    array_init(encoded_out);

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(records), record) {
        smart_str_free(&encoded);
        encoded = (smart_str){0};
        if (!king_proto_runtime_encode_schema_payload(&encoded, schema_name, runtime_schema, record)) {
            zend_hash_destroy(Z_ARRVAL_P(encoded_out));
            return FAILURE;
        }
        if (encoded.s) {
            smart_str_0(&encoded);
            zend_hash_str_add(Z_ARRVAL_P(encoded_out), ZSTR_VAL(encoded.s), ZSTR_LEN(encoded.s), &encoded);
        }
        count++;
    } ZEND_HASH_FOREACH_END();

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
