/*
 * Map codec storage slice: fetches or creates the array-backed storage that
 * decoded map entries use on the target array or object.
 */

static zval *king_proto_runtime_fetch_or_init_map_storage(
    zval *target,
    bool decode_as_object,
    const king_proto_runtime_field *field
)
{
    zval *existing_values;

    if (decode_as_object) {
        existing_values = zend_hash_find(Z_OBJPROP_P(target), field->name);
        if (existing_values != NULL) {
            return existing_values;
        }
    } else {
        existing_values = zend_hash_find(Z_ARRVAL_P(target), field->name);
        if (existing_values != NULL) {
            return existing_values;
        }
    }

    {
        zval map_values;

        array_init(&map_values);
        if (decode_as_object) {
            return zend_hash_update(Z_OBJPROP_P(target), field->name, &map_values);
        }

        return zend_hash_update(Z_ARRVAL_P(target), field->name, &map_values);
    }
}
