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

#include "../core/introspection/proto_registry.inc"
#include "../core/introspection/proto_codec.inc"

static void king_iibin_throw_batch_encode_error(
    zend_string *schema_name,
    size_t record_index
)
{
    zend_object *previous_exception = EG(exception);

    if (previous_exception != NULL) {
        EG(exception) = NULL;
    }

    zend_throw_exception_ex(
        king_ce_validation_exception,
        0,
        "Batch encoding failed at record index %zu for schema '%s'.",
        record_index,
        ZSTR_VAL(schema_name)
    );

    if (previous_exception != NULL) {
        if (EG(exception) != NULL) {
            zend_exception_set_previous(EG(exception), previous_exception);
        } else {
            EG(exception) = previous_exception;
        }
    }
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

zend_result king_iibin_encode_batch(
    zend_string *schema_name,
    zval *records,
    zval *encoded_records
)
{
    king_proto_runtime_schema *runtime_schema;
    zval *record;
    size_t record_index = 0;

    if (!king_proto_registry_has_schema(schema_name)) {
        king_throw_proto_schema_not_defined(schema_name, "batch encoding");
        return FAILURE;
    }

    runtime_schema = king_proto_registry_get_runtime_schema(schema_name);
    if (runtime_schema == NULL) {
        king_throw_proto_schema_registered_but_unavailable(schema_name, "batch encoding");
        return FAILURE;
    }

    array_init_size(encoded_records, zend_hash_num_elements(Z_ARRVAL_P(records)));

    ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(records), record) {
        smart_str encoded = {0};
        zval encoded_value;

        if (!king_proto_runtime_encode_schema_payload(
                &encoded,
                schema_name,
                runtime_schema,
                record
            )) {
            smart_str_free(&encoded);
            zval_ptr_dtor(encoded_records);
            ZVAL_UNDEF(encoded_records);
            king_iibin_throw_batch_encode_error(schema_name, record_index);
            return FAILURE;
        }

        if (encoded.s == NULL) {
            ZVAL_EMPTY_STRING(&encoded_value);
        } else {
            smart_str_0(&encoded);
            ZVAL_STR(&encoded_value, encoded.s);
        }

        add_next_index_zval(encoded_records, &encoded_value);
        record_index++;
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}
