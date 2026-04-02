/*
 * Proto-registry default support slice: answers whether one runtime field
 * kind can carry a persisted default and fetches input field values from the
 * array-or-object payload shapes the active runtime accepts.
 */

static bool king_proto_runtime_field_kind_supports_default(
    king_proto_runtime_field_kind kind
)
{
    switch (kind) {
        case KING_PROTO_RUNTIME_FIELD_MESSAGE:
        case KING_PROTO_RUNTIME_FIELD_MAP:
        case KING_PROTO_RUNTIME_FIELD_UNSUPPORTED:
            return false;

        default:
            return true;
    }
}

static zval *king_proto_runtime_fetch_input_value(zval *data, zend_string *field_name)
{
    if (Z_TYPE_P(data) == IS_ARRAY) {
        return zend_hash_find(Z_ARRVAL_P(data), field_name);
    }

    if (Z_TYPE_P(data) == IS_OBJECT) {
        return zend_hash_find(Z_OBJPROP_P(data), field_name);
    }

    return NULL;
}
