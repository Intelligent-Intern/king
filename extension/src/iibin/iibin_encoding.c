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

static uint32_t king_proto_float_to_bits(float value)
{
    uint32_t bits;

    memcpy(&bits, &value, sizeof(bits));
    return bits;
}

static float king_proto_bits_to_float(uint32_t bits)
{
    float value;

    memcpy(&value, &bits, sizeof(value));
    return value;
}

static uint64_t king_proto_double_to_bits(double value)
{
    uint64_t bits;

    memcpy(&bits, &value, sizeof(bits));
    return bits;
}

static double king_proto_bits_to_double(uint64_t bits)
{
    double value;

    memcpy(&value, &bits, sizeof(value));
    return value;
}

#include "../core/introspection/proto_registry.inc"
#include "../core/introspection/proto_codec.inc"

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
