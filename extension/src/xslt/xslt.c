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
#include "xslt/arginfo/index.h"
#include "xslt/xslt.h"
#include "Zend/zend_exceptions.h"
#include <dlfcn.h>
#include <fcntl.h>
#include <limits.h>
#include <stdbool.h>
#include <stdlib.h>
#include <string.h>
#include <sys/stat.h>
#include <unistd.h>

#ifndef PATH_MAX
#define PATH_MAX 4096
#endif

#include "saxonc_loader.inc"
#include "state.inc"
#include "php_binding.inc"
#include "registration.inc"

static zend_string *king_xslt_realpath_checked_or_throw(
    zend_string *path,
    const char *label,
    bool require_directory
)
{
    char resolved[PATH_MAX];
    struct stat path_stat;

    if (ZSTR_LEN(path) == 0 || memchr(ZSTR_VAL(path), '\0', ZSTR_LEN(path)) != NULL) {
        char message[256];
        snprintf(message, sizeof(message), "%s must not be empty.", label);
        zend_throw_exception(king_ce_validation_exception, message, 0);
        return NULL;
    }

    if (realpath(ZSTR_VAL(path), resolved) == NULL) {
        char message[512];
        snprintf(
            message,
            sizeof(message),
            "%s is not a readable local %s: %s",
            label,
            require_directory ? "directory" : "file",
            ZSTR_VAL(path)
        );
        zend_throw_exception(king_ce_validation_exception, message, 0);
        return NULL;
    }

    if (stat(resolved, &path_stat) != 0) {
        char message[512];
        snprintf(message, sizeof(message), "%s could not be inspected: %s", label, resolved);
        zend_throw_exception(king_ce_validation_exception, message, 0);
        return NULL;
    }

    if (require_directory && !S_ISDIR(path_stat.st_mode)) {
        char message[512];
        snprintf(message, sizeof(message), "%s must be a local directory: %s", label, resolved);
        zend_throw_exception(king_ce_validation_exception, message, 0);
        return NULL;
    }
    if (!require_directory && !S_ISREG(path_stat.st_mode)) {
        char message[512];
        snprintf(message, sizeof(message), "%s must be a local file: %s", label, resolved);
        zend_throw_exception(king_ce_validation_exception, message, 0);
        return NULL;
    }

    if (access(resolved, require_directory ? (R_OK | X_OK) : R_OK) != 0) {
        char message[512];
        snprintf(
            message,
            sizeof(message),
            "%s is not %s: %s",
            label,
            require_directory ? "accessible" : "readable",
            resolved
        );
        zend_throw_exception(king_ce_validation_exception, message, 0);
        return NULL;
    }

    return zend_string_init(resolved, strlen(resolved), 0);
}

static zend_string *king_xslt_realpath_file_or_throw(zend_string *path, const char *label)
{
    return king_xslt_realpath_checked_or_throw(path, label, false);
}

static zend_string *king_xslt_realpath_directory_or_throw(zend_string *path, const char *label)
{
    return king_xslt_realpath_checked_or_throw(path, label, true);
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
        resolved = king_xslt_realpath_directory_or_throw(cwd_string, "XSLT cwd");
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
        if (ZSTR_LEN(key)<2 || ZSTR_VAL(key)[0]!='!') {
            zend_throw_exception(king_ce_validation_exception, "Only SaxonC serialization properties prefixed with ! are accepted.", 0);
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
        if (king_saxonc_set_property(properties, property_len, ZSTR_VAL(key), ZSTR_VAL(value)) != SUCCESS) {
            zend_string_release(value);
            zend_throw_exception(king_ce_runtime_exception, "SaxonC property allocation failed.", 0);
            return FAILURE;
        }
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
    *source_abs = king_xslt_realpath_file_or_throw(source_path, "XSLT source XML");
    if (*source_abs == NULL) {
        return FAILURE;
    }

    *stylesheet_abs = king_xslt_realpath_file_or_throw(stylesheet_path, "XSLT stylesheet");
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

static zend_string *king_xslt_temporary_output_path(zend_string *output_abs)
{
    char path_template[PATH_MAX];
    int fd;

    if (ZSTR_LEN(output_abs) + sizeof(".kingtmpXXXXXX") >= PATH_MAX) {
        zend_throw_exception(king_ce_validation_exception, "XSLT output temporary path is too long.", 0);
        return NULL;
    }

    snprintf(path_template, sizeof(path_template), "%s.kingtmpXXXXXX", ZSTR_VAL(output_abs));
    fd = mkstemp(path_template);
    if (fd < 0) {
        zend_throw_exception(king_ce_runtime_exception, "XSLT output temporary file could not be created.", 0);
        return NULL;
    }

    close(fd);
    if (unlink(path_template) != 0) {
        zend_throw_exception(king_ce_runtime_exception, "XSLT output temporary file could not be prepared.", 0);
        return NULL;
    }

    return zend_string_init(path_template, strlen(path_template), 0);
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
    sxnc_environment *environment=NULL;
    sxnc_processor *processor=NULL;
    sxnc_property *properties=NULL;
    const char *product;
    array_init(return_value);
    add_assoc_string(return_value,"engine","saxonc");
    add_assoc_string(return_value,"abi","saxonc-core-13");
    add_assoc_string(return_value,"library_env","KING_SAXONC_LIBRARY");
    add_assoc_string(return_value,"home_env","SAXONC_HOME");
    add_assoc_string(return_value,"resource_policy","file-only; trusted stylesheets; OS sandbox required for path isolation");
    if(king_saxonc_ensure_ready()!=SUCCESS) {
        add_assoc_bool(return_value,"available",0);
        add_assoc_string(return_value,"error",king_saxonc.load_error);
        return;
    }
    if(king_saxonc_context_create(&environment,&processor)!=SUCCESS) {
        add_assoc_bool(return_value,"available",0);
        add_assoc_string(return_value,"error","SaxonC Graal isolate or processor initialization failed.");
        king_saxonc_context_free(&environment,&processor,&properties,0); return;
    }
    product=king_saxonc.product(environment->thread,(void *)(intptr_t)processor->value);
    add_assoc_bool(return_value,"available",product!=NULL);
    add_assoc_string(return_value,"loaded_library",king_saxonc.loaded_library);
    if(product) add_assoc_string(return_value,"product",product);
    king_saxonc_context_free(&environment,&processor,&properties,0);
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

    if(king_saxonc_context_create(&environment,&processor)!=SUCCESS) {
        king_saxonc_context_free(&environment,&processor,&properties,property_len);
        zend_string_release(source_abs); zend_string_release(stylesheet_abs); zend_string_release(cwd);
        zend_throw_exception(king_ce_runtime_exception,"SaxonC Graal isolate or processor could not be initialized.",0);
        return FAILURE;
    }

    if (king_xslt_apply_properties_from_options(&properties, &property_len, &property_cap, options) != SUCCESS) {
        king_saxonc_context_free(&environment,&processor,&properties,property_len);
        zend_string_release(source_abs);
        zend_string_release(stylesheet_abs);
        zend_string_release(cwd);
        return FAILURE;
    }

    result = king_saxonc_apply(environment,processor,ZSTR_VAL(cwd),ZSTR_VAL(source_abs),ZSTR_VAL(stylesheet_abs),properties,property_len);

    if (result == NULL) {
        const char *message = environment->error[0] ? environment->error : "SaxonC XSLT transformation failed.";
        zend_string *saxon_message = zend_string_init(message, strlen(message), 0);
        king_saxonc_context_free(&environment,&processor,&properties,property_len);
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

    king_saxonc_context_free(&environment,&processor,&properties,property_len);
    zend_string_release(source_abs);
    zend_string_release(stylesheet_abs);
    zend_string_release(cwd);

    return SUCCESS;
}

zend_result king_xslt_transform_to_file_result(
    zend_string *source_path, zend_string *stylesheet_path, zend_string *output_path,
    zval *options, zval *return_value
) {
    zend_string *output_abs=NULL, *temp=NULL; zval result; zval *content;
    FILE *file=NULL; int fd; size_t len; bool written=false; struct stat st;
    if(!ZSTR_LEN(output_path) || memchr(ZSTR_VAL(output_path),'\0',ZSTR_LEN(output_path))) {
        zend_throw_exception(king_ce_validation_exception,"XSLT output path must be non-empty and contain no NUL bytes.",0); return FAILURE;
    }
    output_abs=king_xslt_absolute_output_path(output_path); if(!output_abs) return FAILURE;
    if(stat(ZSTR_VAL(output_abs),&st)==0 && !S_ISREG(st.st_mode)) {
        zend_string_release(output_abs);
        zend_throw_exception(king_ce_validation_exception,"XSLT output path must be a local file path.",0); return FAILURE;
    }
    temp=king_xslt_temporary_output_path(output_abs); if(!temp){zend_string_release(output_abs);return FAILURE;}
    ZVAL_UNDEF(&result);
    if(king_xslt_transform_file_result(source_path,stylesheet_path,options,&result)!=SUCCESS) goto fail;
    content=zend_hash_str_find(Z_ARRVAL(result),"result",sizeof("result")-1);
    fd=open(ZSTR_VAL(temp),O_WRONLY|O_CREAT|O_EXCL,0600);
    if(fd>=0) { file=fdopen(fd,"w"); if(!file) close(fd); }
    if(file) {
        len=Z_STRLEN_P(content);
        written=fwrite(Z_STRVAL_P(content),1,len,file)==len;
        if(fclose(file)!=0) written=false;
    }
    if(!written || rename(ZSTR_VAL(temp),ZSTR_VAL(output_abs))!=0) {
        zend_throw_exception(king_ce_runtime_exception,"XSLT output could not be written atomically.",0); goto fail;
    }
    array_init(return_value); add_assoc_bool(return_value,"ok",1); add_assoc_string(return_value,"engine","saxonc");
    add_assoc_string(return_value,"loaded_library",king_saxonc.loaded_library);
    add_assoc_str(return_value,"output_file",zend_string_copy(output_abs));
    zval_ptr_dtor(&result); zend_string_release(temp); zend_string_release(output_abs); return SUCCESS;
fail:
    unlink(ZSTR_VAL(temp));
    if(!Z_ISUNDEF(result)) zval_ptr_dtor(&result);
    zend_string_release(temp); zend_string_release(output_abs); return FAILURE;
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
