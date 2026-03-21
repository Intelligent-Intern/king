static int king_proto_validate_schema_definition(
    zend_string *schema_name,
    zval *schema_definition,
    bool *runtime_subset_supported
)
{
    HashTable seen_tags;
    zend_string *field_name;
    zval *field_options;

    *runtime_subset_supported = true;
    zend_hash_init(&seen_tags, 8, NULL, NULL, 0);

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(schema_definition), field_name, field_options) {
        if (king_proto_validate_schema_field_definition(
                schema_name,
                field_name,
                field_options,
                &seen_tags,
                runtime_subset_supported
            ) != SUCCESS) {
            zend_hash_destroy(&seen_tags);
            return FAILURE;
        }
    } ZEND_HASH_FOREACH_END();

    zend_hash_destroy(&seen_tags);
    return SUCCESS;
}
