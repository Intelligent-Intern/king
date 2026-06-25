/*
 * SaxonC-backed XSLT runtime. This exposes a narrow native primitive for
 * running XSLT 2.0/3.0 stylesheets from PHP without spawning Java or shelling
 * out to a validator process.
 */

#ifdef HAVE_CONFIG_H
#  include "config.h"
#endif

#include "php.h"
#include "php_king.h"
#include "runtime/saxonc_candidates.h"
#include "xslt/xslt.h"
#include "Zend/zend_exceptions.h"
#include <dlfcn.h>
#include <limits.h>
#include <stdbool.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>

#ifndef PATH_MAX
#define PATH_MAX 4096
#endif

#include "saxonc_loader.inc"

static zend_string *king_xslt_realpath_or_throw(zend_string *path, const char *label)
{
    char resolved[PATH_MAX];

    if (ZSTR_LEN(path) == 0) {
        zend_throw_exception(king_ce_validation_exception, label, 0);
        return NULL;
    }

    if (realpath(ZSTR_VAL(path), resolved) == NULL) {
        char message[512];
        snprintf(message, sizeof(message), "%s is not a readable local file: %s", label, ZSTR_VAL(path));
        zend_throw_exception(king_ce_validation_exception, message, 0);
        return NULL;
    }

    if (access(resolved, R_OK) != 0) {
        char message[512];
        snprintf(message, sizeof(message), "%s is not readable: %s", label, resolved);
        zend_throw_exception(king_ce_validation_exception, message, 0);
        return NULL;
    }

    return zend_string_init(resolved, strlen(resolved), 0);
}

static zend_string *king_xslt_dirname_from_path(zend_string *path)
{
    const char *value = ZSTR_VAL(path);
    const char *slash = strrchr(value, '/');

    if (slash == NULL) {
        char cwd[PATH_MAX];
        if (getcwd(cwd, sizeof(cwd)) == NULL) {
            return zend_string_init(".", 1, 0);
        }
        return zend_string_init(cwd, strlen(cwd), 0);
    }

    if (slash == value) {
        return zend_string_init("/", 1, 0);
    }

    return zend_string_init(value, (size_t) (slash - value), 0);
}

static zend_string *king_xslt_absolute_output_path(zend_string *path)
{
    char cwd[PATH_MAX];
    size_t cwd_len;
    size_t path_len;

    if (ZSTR_LEN(path) > 0 && ZSTR_VAL(path)[0] == '/') {
        return zend_string_copy(path);
    }

    if (getcwd(cwd, sizeof(cwd)) == NULL) {
        zend_throw_exception(king_ce_runtime_exception, "Current working directory could not be resolved for XSLT output.", 0);
        return NULL;
    }

    cwd_len = strlen(cwd);
    path_len = ZSTR_LEN(path);
    if (cwd_len + 1 + path_len >= PATH_MAX) {
        zend_throw_exception(king_ce_validation_exception, "XSLT output path is too long.", 0);
        return NULL;
    }

    return strpprintf(0, "%s/%s", cwd, ZSTR_VAL(path));
}

static zend_string *king_xslt_cwd_from_options(zval *options, zend_string *stylesheet_path)
{
    zval *cwd_value;
    zend_string *cwd_string;
    zend_string *resolved;

    if (options != NULL
        && Z_TYPE_P(options) == IS_ARRAY
        && (cwd_value = zend_hash_str_find(Z_ARRVAL_P(options), "cwd", sizeof("cwd") - 1)) != NULL
        && Z_TYPE_P(cwd_value) != IS_NULL) {
        cwd_string = zval_get_string(cwd_value);
        resolved = king_xslt_realpath_or_throw(cwd_string, "XSLT cwd");
        zend_string_release(cwd_string);
        return resolved;
    }

    return king_xslt_dirname_from_path(stylesheet_path);
}

static bool king_xslt_option_value_is_stringable(zval *value)
{
    if (value == NULL) {
        return false;
    }

    switch (Z_TYPE_P(value)) {
        case IS_NULL:
        case IS_FALSE:
        case IS_TRUE:
        case IS_LONG:
        case IS_DOUBLE:
        case IS_STRING:
            return true;
        default:
            return false;
    }
}

static zend_result king_xslt_validate_properties_option(zval *properties_value)
{
    zend_string *key;
    zval *entry;

    if (properties_value == NULL || Z_TYPE_P(properties_value) == IS_NULL) {
        return SUCCESS;
    }
    if (Z_TYPE_P(properties_value) != IS_ARRAY) {
        zend_throw_exception(king_ce_validation_exception, "XSLT properties option must be an associative array.", 0);
        return FAILURE;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(properties_value), key, entry) {
        if (key == NULL) {
            zend_throw_exception(king_ce_validation_exception, "XSLT property names must be strings.", 0);
            return FAILURE;
        }
        if (!king_xslt_option_value_is_stringable(entry)) {
            zend_throw_exception(king_ce_validation_exception, "XSLT property values must be scalar or null.", 0);
            return FAILURE;
        }
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

zend_result king_xslt_validate_options(zval *options)
{
    zend_string *key;
    zval *entry;
    char message[256];

    if (options == NULL || Z_TYPE_P(options) == IS_NULL) {
        return SUCCESS;
    }
    if (Z_TYPE_P(options) != IS_ARRAY) {
        zend_throw_exception(king_ce_validation_exception, "XSLT options must be an array.", 0);
        return FAILURE;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(options), key, entry) {
        if (key == NULL) {
            zend_throw_exception(king_ce_validation_exception, "XSLT option names must be strings.", 0);
            return FAILURE;
        }

        if (zend_string_equals_literal(key, "cwd")) {
            if (entry != NULL && Z_TYPE_P(entry) != IS_NULL && Z_TYPE_P(entry) != IS_STRING) {
                zend_throw_exception(king_ce_validation_exception, "XSLT cwd option must be a string or null.", 0);
                return FAILURE;
            }
            continue;
        }

        if (zend_string_equals_literal(key, "properties")) {
            if (king_xslt_validate_properties_option(entry) != SUCCESS) {
                return FAILURE;
            }
            continue;
        }

        snprintf(
            message,
            sizeof(message),
            "XSLT option '%s' is not supported. Supported options are 'cwd' and 'properties'.",
            ZSTR_VAL(key)
        );
        zend_throw_exception(king_ce_validation_exception, message, 0);
        return FAILURE;
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

static zend_result king_xslt_apply_properties_from_options(
    sxnc_property **properties,
    int *property_len,
    int *property_cap,
    zval *options
)
{
    zval *properties_value;
    zval *entry;
    zend_string *key;
    zend_string *value;

    if (options == NULL || Z_TYPE_P(options) != IS_ARRAY) {
        return SUCCESS;
    }

    properties_value = zend_hash_str_find(Z_ARRVAL_P(options), "properties", sizeof("properties") - 1);
    if (properties_value == NULL || Z_TYPE_P(properties_value) == IS_NULL) {
        return SUCCESS;
    }

    ZEND_HASH_FOREACH_STR_KEY_VAL(Z_ARRVAL_P(properties_value), key, entry) {
        value = zval_get_string(entry);
        king_saxonc.setProperty_fn(
            properties,
            property_len,
            property_cap,
            ZSTR_VAL(key),
            ZSTR_VAL(value)
        );
        zend_string_release(value);
    } ZEND_HASH_FOREACH_END();

    return SUCCESS;
}

static zend_result king_xslt_prepare_context(
    zend_string *source_path,
    zend_string *stylesheet_path,
    zval *options,
    zend_string **source_abs,
    zend_string **stylesheet_abs,
    zend_string **cwd
)
{
    *source_abs = king_xslt_realpath_or_throw(source_path, "XSLT source XML");
    if (*source_abs == NULL) {
        return FAILURE;
    }

    *stylesheet_abs = king_xslt_realpath_or_throw(stylesheet_path, "XSLT stylesheet");
    if (*stylesheet_abs == NULL) {
        zend_string_release(*source_abs);
        *source_abs = NULL;
        return FAILURE;
    }

    *cwd = king_xslt_cwd_from_options(options, *stylesheet_abs);
    if (*cwd == NULL) {
        zend_string_release(*source_abs);
        zend_string_release(*stylesheet_abs);
        *source_abs = NULL;
        *stylesheet_abs = NULL;
        return FAILURE;
    }

    return SUCCESS;
}

static const char *king_xslt_saxon_error(sxnc_environment *environment)
{
    const char *message = NULL;

    if (environment != NULL && king_saxonc.c_getErrorMessage_fn != NULL) {
        message = king_saxonc.c_getErrorMessage_fn(environment);
    }

    return message != NULL && message[0] != '\0'
        ? message
        : "SaxonC XSLT transformation failed.";
}

void king_xslt_shutdown_system(void)
{
    king_saxonc_close_runtime_handle();
}

void king_xslt_add_component_info(zval *configuration)
{
    add_assoc_string(configuration, "engine", "saxonc");
    add_assoc_string(configuration, "runtime_contract", "optional_runtime_loaded_native_xslt");
    add_assoc_string(configuration, "library_env", "KING_SAXONC_LIBRARY");
    add_assoc_string(configuration, "home_env", "SAXONC_HOME");
    add_assoc_string(configuration, "supported_use", "XSLT 2.0/3.0 transformation for Schematron/SVRL pipelines");
}

void king_xslt_engine_status_array(zval *return_value)
{
    sxnc_environment *environment = NULL;
    sxnc_processor *processor = NULL;
    sxnc_parameter *parameters = NULL;
    sxnc_property *properties = NULL;
    const char *version = NULL;
    const char *variant = NULL;

    array_init(return_value);
    add_assoc_string(return_value, "engine", "saxonc");
    add_assoc_string(return_value, "library_env", "KING_SAXONC_LIBRARY");
    add_assoc_string(return_value, "home_env", "SAXONC_HOME");

    if (king_saxonc_ensure_ready() != SUCCESS) {
        add_assoc_bool(return_value, "available", 0);
        add_assoc_string(return_value, "error", king_saxonc.load_error);
        add_assoc_string(return_value, "candidate_names", KING_SAXONC_RUNTIME_CANDIDATE_NAMES);
        return;
    }

    add_assoc_bool(return_value, "available", 1);
    add_assoc_string(return_value, "loaded_library", king_saxonc.loaded_library);

    king_saxonc.initSaxonc_fn(&environment, &processor, &parameters, &properties, 0, 0);
    if (environment != NULL && processor != NULL) {
        version = king_saxonc.version_fn(environment, processor);
        variant = king_saxonc.getProductVariantAndVersion_fn(environment, processor);
    }

    if (version != NULL && version[0] != '\0') {
        add_assoc_string(return_value, "version", version);
    }
    if (variant != NULL && variant[0] != '\0') {
        add_assoc_string(return_value, "product", variant);
    }

    king_saxonc.freeSaxonc_fn(&environment, &processor, &parameters, &properties);
}

zend_result king_xslt_transform_file_result(
    zend_string *source_path,
    zend_string *stylesheet_path,
    zval *options,
    zval *return_value
)
{
    zend_string *source_abs = NULL;
    zend_string *stylesheet_abs = NULL;
    zend_string *cwd = NULL;
    sxnc_environment *environment = NULL;
    sxnc_processor *processor = NULL;
    sxnc_parameter *parameters = NULL;
    sxnc_property *properties = NULL;
    int property_len = 0;
    int property_cap = 4;
    const char *result;

    if (king_saxonc_ensure_ready() != SUCCESS) {
        zend_throw_exception(king_ce_runtime_exception, king_saxonc.load_error, 0);
        return FAILURE;
    }

    if (king_xslt_validate_options(options) != SUCCESS) {
        return FAILURE;
    }

    if (king_xslt_prepare_context(source_path, stylesheet_path, options, &source_abs, &stylesheet_abs, &cwd) != SUCCESS) {
        return FAILURE;
    }

    king_saxonc.initSaxonc_fn(&environment, &processor, &parameters, &properties, 0, property_cap);
    if (environment == NULL || processor == NULL) {
        zend_string_release(source_abs);
        zend_string_release(stylesheet_abs);
        zend_string_release(cwd);
        zend_throw_exception(king_ce_runtime_exception, "SaxonC processor could not be initialized.", 0);
        return FAILURE;
    }

    if (king_xslt_apply_properties_from_options(&properties, &property_len, &property_cap, options) != SUCCESS) {
        king_saxonc.freeSaxonc_fn(&environment, &processor, &parameters, &properties);
        zend_string_release(source_abs);
        zend_string_release(stylesheet_abs);
        zend_string_release(cwd);
        return FAILURE;
    }

    result = king_saxonc.xsltApplyStylesheet_fn(
        environment,
        processor,
        ZSTR_VAL(cwd),
        ZSTR_VAL(source_abs),
        ZSTR_VAL(stylesheet_abs),
        parameters,
        properties,
        0,
        property_len
    );

    if (result == NULL) {
        const char *message = king_xslt_saxon_error(environment);
        zend_string *saxon_message = zend_string_init(message, strlen(message), 0);
        king_saxonc.freeSaxonc_fn(&environment, &processor, &parameters, &properties);
        zend_string_release(source_abs);
        zend_string_release(stylesheet_abs);
        zend_string_release(cwd);
        zend_throw_exception(king_ce_validation_exception, ZSTR_VAL(saxon_message), 0);
        zend_string_release(saxon_message);
        return FAILURE;
    }

    array_init(return_value);
    add_assoc_bool(return_value, "ok", 1);
    add_assoc_string(return_value, "engine", "saxonc");
    add_assoc_string(return_value, "loaded_library", king_saxonc.loaded_library);
    add_assoc_string(return_value, "result", result);

    king_saxonc.freeSaxonc_fn(&environment, &processor, &parameters, &properties);
    zend_string_release(source_abs);
    zend_string_release(stylesheet_abs);
    zend_string_release(cwd);

    return SUCCESS;
}

zend_result king_xslt_transform_to_file_result(
    zend_string *source_path,
    zend_string *stylesheet_path,
    zend_string *output_path,
    zval *options,
    zval *return_value
)
{
    zend_string *source_abs = NULL;
    zend_string *stylesheet_abs = NULL;
    zend_string *output_abs = NULL;
    zend_string *cwd = NULL;
    sxnc_environment *environment = NULL;
    sxnc_processor *processor = NULL;
    sxnc_parameter *parameters = NULL;
    sxnc_property *properties = NULL;
    int property_len = 0;
    int property_cap = 4;
    const char *message;
    zend_string *saxon_message = NULL;

    if (ZSTR_LEN(output_path) == 0) {
        zend_throw_exception(king_ce_validation_exception, "XSLT output path must not be empty.", 0);
        return FAILURE;
    }

    if (king_saxonc_ensure_ready() != SUCCESS) {
        zend_throw_exception(king_ce_runtime_exception, king_saxonc.load_error, 0);
        return FAILURE;
    }

    if (king_xslt_validate_options(options) != SUCCESS) {
        return FAILURE;
    }

    if (king_xslt_prepare_context(source_path, stylesheet_path, options, &source_abs, &stylesheet_abs, &cwd) != SUCCESS) {
        return FAILURE;
    }
    output_abs = king_xslt_absolute_output_path(output_path);
    if (output_abs == NULL) {
        zend_string_release(source_abs);
        zend_string_release(stylesheet_abs);
        zend_string_release(cwd);
        return FAILURE;
    }

    king_saxonc.initSaxonc_fn(&environment, &processor, &parameters, &properties, 0, property_cap);
    if (environment == NULL || processor == NULL) {
        zend_string_release(source_abs);
        zend_string_release(stylesheet_abs);
        zend_string_release(output_abs);
        zend_string_release(cwd);
        zend_throw_exception(king_ce_runtime_exception, "SaxonC processor could not be initialized.", 0);
        return FAILURE;
    }

    if (king_xslt_apply_properties_from_options(&properties, &property_len, &property_cap, options) != SUCCESS) {
        king_saxonc.freeSaxonc_fn(&environment, &processor, &parameters, &properties);
        zend_string_release(source_abs);
        zend_string_release(stylesheet_abs);
        zend_string_release(output_abs);
        zend_string_release(cwd);
        return FAILURE;
    }

    king_saxonc.xsltSaveResultToFile_fn(
        environment,
        processor,
        ZSTR_VAL(cwd),
        ZSTR_VAL(source_abs),
        ZSTR_VAL(stylesheet_abs),
        ZSTR_VAL(output_abs),
        parameters,
        properties,
        0,
        property_len
    );

    message = king_xslt_saxon_error(environment);
    saxon_message = zend_string_init(message, strlen(message), 0);

    king_saxonc.freeSaxonc_fn(&environment, &processor, &parameters, &properties);

    if (access(ZSTR_VAL(output_abs), R_OK) != 0) {
        zend_throw_exception(king_ce_runtime_exception, ZSTR_VAL(saxon_message), 0);
        zend_string_release(source_abs);
        zend_string_release(stylesheet_abs);
        zend_string_release(output_abs);
        zend_string_release(cwd);
        zend_string_release(saxon_message);
        return FAILURE;
    }

    array_init(return_value);
    add_assoc_bool(return_value, "ok", 1);
    add_assoc_string(return_value, "engine", "saxonc");
    add_assoc_string(return_value, "loaded_library", king_saxonc.loaded_library);
    add_assoc_string(return_value, "output_file", ZSTR_VAL(output_abs));

    zend_string_release(source_abs);
    zend_string_release(stylesheet_abs);
    zend_string_release(output_abs);
    zend_string_release(cwd);
    zend_string_release(saxon_message);

    return SUCCESS;
}

PHP_FUNCTION(king_xslt_engine_status)
{
    ZEND_PARSE_PARAMETERS_NONE();

    king_xslt_engine_status_array(return_value);
}

PHP_FUNCTION(king_xslt_transform_file)
{
    zend_string *source_path;
    zend_string *stylesheet_path;
    zval *options = NULL;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STR(source_path)
        Z_PARAM_STR(stylesheet_path)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (king_xslt_transform_file_result(source_path, stylesheet_path, options, return_value) != SUCCESS) {
        RETURN_THROWS();
    }
}

PHP_FUNCTION(king_xslt_transform_to_file)
{
    zend_string *source_path;
    zend_string *stylesheet_path;
    zend_string *output_path;
    zval *options = NULL;

    ZEND_PARSE_PARAMETERS_START(3, 4)
        Z_PARAM_STR(source_path)
        Z_PARAM_STR(stylesheet_path)
        Z_PARAM_STR(output_path)
        Z_PARAM_OPTIONAL
        Z_PARAM_ARRAY_OR_NULL(options)
    ZEND_PARSE_PARAMETERS_END();

    if (king_xslt_transform_to_file_result(source_path, stylesheet_path, output_path, options, return_value) != SUCCESS) {
        RETURN_THROWS();
    }
}
