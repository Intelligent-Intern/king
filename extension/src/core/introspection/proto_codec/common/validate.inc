/*
 * Common codec validation aggregator: composes type validation and range
 * validation for one runtime field value before encoding proceeds.
 */

static bool king_proto_runtime_validate_field_value(
    const king_proto_runtime_field *field,
    zval *value
)
{
    if (!king_proto_runtime_validate_field_value_type(field, value)) {
        return false;
    }

    return king_proto_runtime_validate_field_value_range(field, value);
}
