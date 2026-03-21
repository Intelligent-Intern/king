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

#include "../core/introspection/proto_registry/lookup.inc"
#include "../core/introspection/proto_registry/types.inc"
#include "../core/introspection/proto_registry/defaults.inc"
#include "../core/introspection/proto_api/schema_validation.inc"
#include "../core/introspection/proto_api/schema_runtime_build.inc"

static void king_iibin_destroy_field_name_cache(
    king_proto_runtime_schema *runtime_schema
)
{
    if (runtime_schema->fields_by_name_initialized) {
        zend_hash_destroy(&runtime_schema->fields_by_name);
        runtime_schema->fields_by_name_initialized = false;
    }
}

static zend_result king_iibin_build_field_name_cache(
    zend_string *schema_name,
    king_proto_runtime_schema *runtime_schema
)
{
    size_t i;

    king_iibin_destroy_field_name_cache(runtime_schema);

    if (runtime_schema->field_count == 0 || runtime_schema->fields == NULL) {
        return SUCCESS;
    }

    zend_hash_init(
        &runtime_schema->fields_by_name,
        runtime_schema->field_count,
        NULL,
        NULL,
        1
    );
    runtime_schema->fields_by_name_initialized = true;

    for (i = 0; i < runtime_schema->field_count; i++) {
        king_proto_runtime_field *field = &runtime_schema->fields[i];

        if (zend_hash_str_add_ptr(
                &runtime_schema->fields_by_name,
                ZSTR_VAL(field->name),
                ZSTR_LEN(field->name),
                field
            ) == NULL) {
            king_iibin_destroy_field_name_cache(runtime_schema);
            zend_throw_exception_ex(
                king_ce_system_exception,
                0,
                "Failed to compile field-name cache for schema '%s'.",
                ZSTR_VAL(schema_name)
            );
            return FAILURE;
        }
    }

    return SUCCESS;
}

static bool king_iibin_validate_schema_input_key(
    zend_string *schema_name,
    const king_proto_runtime_schema *runtime_schema,
    zend_string *field_name,
    zend_ulong numeric_key,
    bool numeric_key_present
)
{
    if (numeric_key_present || field_name == NULL) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Encoding failed: Schema '%s' received unexpected numeric field key %lu.",
            ZSTR_VAL(schema_name),
            (unsigned long) numeric_key
        );
        return false;
    }

    if (ZSTR_LEN(field_name) > 0 && ZSTR_VAL(field_name)[0] == '\0') {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Encoding failed: Schema '%s' cannot encode non-public object property keys.",
            ZSTR_VAL(schema_name)
        );
        return false;
    }

    if (runtime_schema->field_count == 0
        || !runtime_schema->fields_by_name_initialized
        || zend_hash_str_find_ptr(
            &runtime_schema->fields_by_name,
            ZSTR_VAL(field_name),
            ZSTR_LEN(field_name)
        ) == NULL) {
        zend_throw_exception_ex(
            king_ce_validation_exception,
            0,
            "Encoding failed: Schema '%s' does not define a field named '%s'.",
            ZSTR_VAL(schema_name),
            ZSTR_VAL(field_name)
        );
        return false;
    }

    return true;
}

zend_result king_iibin_compile_schema_definition(
    zend_string *schema_name,
    zval *schema_definition,
    king_proto_runtime_schema **runtime_schema_out
)
{
    bool runtime_subset_supported;
    size_t field_count;
    king_proto_runtime_schema *runtime_schema;

    *runtime_schema_out = NULL;

    if (king_proto_validate_schema_definition(
            schema_name,
            schema_definition,
            &runtime_subset_supported
        ) != SUCCESS) {
        return FAILURE;
    }

    field_count = zend_hash_num_elements(Z_ARRVAL_P(schema_definition));
    runtime_schema = pemalloc(sizeof(*runtime_schema), 1);
    memset(runtime_schema, 0, sizeof(*runtime_schema));
    runtime_schema->field_count = field_count;
    runtime_schema->runtime_subset_supported = runtime_subset_supported;

    if (runtime_subset_supported
        && field_count > 0
        && king_proto_build_runtime_schema_fields(
            runtime_schema,
            schema_name,
            schema_definition
        ) != SUCCESS) {
        king_proto_runtime_schema_free(runtime_schema);
        return FAILURE;
    }

    if (king_iibin_build_field_name_cache(schema_name, runtime_schema) != SUCCESS) {
        king_proto_runtime_schema_free(runtime_schema);
        return FAILURE;
    }

    *runtime_schema_out = runtime_schema;
    return SUCCESS;
}

bool king_iibin_validate_schema_input(
    zend_string *schema_name,
    const king_proto_runtime_schema *runtime_schema,
    zval *data
)
{
    HashTable *input_table;
    zend_string *field_name;
    zend_ulong numeric_key;
    zval *field_value;

    if (runtime_schema == NULL) {
        return false;
    }

    if (Z_TYPE_P(data) != IS_ARRAY && Z_TYPE_P(data) != IS_OBJECT) {
        return true;
    }

    input_table = Z_TYPE_P(data) == IS_ARRAY ? Z_ARRVAL_P(data) : Z_OBJPROP_P(data);

    ZEND_HASH_FOREACH_KEY_VAL(input_table, numeric_key, field_name, field_value) {
        (void) field_value;

        if (!king_iibin_validate_schema_input_key(
                schema_name,
                runtime_schema,
                field_name,
                numeric_key,
                field_name == NULL
            )) {
            return false;
        }
    } ZEND_HASH_FOREACH_END();

    return true;
}
