/*
 * Proto-registry registry-helper slice: exports the small helpers for
 * listing registered names and throwing the consistent runtime/validation
 * errors used by the active proto registry surface.
 */

static void king_proto_return_registered_names(zval *return_value, HashTable *registry)
{
    zend_string *name;
    zval *count;

    array_init(return_value);

    ZEND_HASH_FOREACH_STR_KEY_VAL(registry, name, count) {
        if (name != NULL) {
            add_next_index_str(return_value, zend_string_copy(name));
        }
        (void) count;
    } ZEND_HASH_FOREACH_END();
}

static void king_throw_proto_registry_unavailable(const char *what)
{
    zend_throw_exception_ex(
        king_ce_runtime_exception,
        0,
        "Proto registries are unavailable during %s.",
        what
    );
}

static void king_throw_proto_schema_not_defined(
    zend_string *schema_name,
    const char *operation
)
{
    zend_throw_exception_ex(
        king_ce_validation_exception,
        0,
        "Schema '%s' not defined for %s.",
        ZSTR_VAL(schema_name),
        operation
    );
}

static void king_throw_proto_schema_registered_but_unavailable(
    zend_string *schema_name,
    const char *operation
)
{
    zend_throw_exception_ex(
        king_ce_runtime_exception,
        0,
        "Schema '%s' is registered, but %s is not wired in the current runtime.",
        ZSTR_VAL(schema_name),
        operation
    );
}
