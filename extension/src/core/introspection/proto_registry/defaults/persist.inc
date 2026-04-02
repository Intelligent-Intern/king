/*
 * Proto-registry default persist aggregator: drives runtime default-value
 * persistence by composing the preparatory type-specific handling with the
 * remaining integer-specific persistence path.
 */

static bool king_proto_runtime_persist_default_value(
    const king_proto_runtime_field *field,
    zval *value,
    zend_string *schema_name,
    zval *default_value_out
)
{
    bool completed = false;

    ZVAL_UNDEF(default_value_out);

    if (!king_proto_runtime_persist_default_value_prepare(
        field,
        value,
        schema_name,
        default_value_out,
        &completed
    )) {
        return false;
    }

    if (completed) {
        return true;
    }

    return king_proto_runtime_persist_integer_default_value(
        field,
        value,
        schema_name,
        default_value_out
    );
}
