/*
 * Runtime loader for SaxonC. King intentionally does not link against SaxonC
 * at build time; deployments provide the SaxonC shared object next to the King
 * module, through KING_SAXONC_LIBRARY, SAXONC_HOME, or the platform linker path.
 */

typedef struct sxnc_environment sxnc_environment;
typedef struct sxnc_processor sxnc_processor;
typedef struct sxnc_parameter sxnc_parameter;
typedef struct sxnc_property sxnc_property;

typedef void (*king_saxonc_init_fn)(
    sxnc_environment **environi,
    sxnc_processor **proc,
    sxnc_parameter **param,
    sxnc_property **prop,
    int cap,
    int propCap
);
typedef void (*king_saxonc_free_fn)(
    sxnc_environment **environi,
    sxnc_processor **proc,
    sxnc_parameter **param,
    sxnc_property **prop
);
typedef const char *(*king_saxonc_version_fn)(
    sxnc_environment *environi,
    sxnc_processor *proc
);
typedef const char *(*king_saxonc_variant_fn)(
    sxnc_environment *environi,
    sxnc_processor *proc
);
typedef const char *(*king_saxonc_error_fn)(sxnc_environment *environi);
typedef const char *(*king_saxonc_xslt_apply_fn)(
    sxnc_environment *environi,
    sxnc_processor *proc,
    char *cwd,
    char *source,
    char *stylesheet,
    sxnc_parameter *parameters,
    sxnc_property *properties,
    int parLen,
    int propLen
);
typedef void (*king_saxonc_xslt_save_fn)(
    sxnc_environment *environi,
    sxnc_processor *proc,
    char *cwd,
    char *source,
    char *stylesheet,
    char *outputfile,
    sxnc_parameter *parameters,
    sxnc_property *properties,
    int parLen,
    int propLen
);
typedef void (*king_saxonc_set_property_fn)(
    sxnc_property **properties,
    int *propLen,
    int *propCap,
    const char *name,
    const char *value
);

typedef struct _king_saxonc_runtime_api_t {
    void *handle;
    zend_bool ready;
    zend_bool load_attempted;
    char loaded_library[PATH_MAX];
    char load_error[512];
    king_saxonc_init_fn initSaxonc_fn;
    king_saxonc_free_fn freeSaxonc_fn;
    king_saxonc_version_fn version_fn;
    king_saxonc_variant_fn getProductVariantAndVersion_fn;
    king_saxonc_error_fn c_getErrorMessage_fn;
    king_saxonc_xslt_apply_fn xsltApplyStylesheet_fn;
    king_saxonc_xslt_save_fn xsltSaveResultToFile_fn;
    king_saxonc_set_property_fn setProperty_fn;
} king_saxonc_runtime_api_t;

static king_saxonc_runtime_api_t king_saxonc = {0};

static zend_result king_saxonc_load_symbol(void **target, const char *name)
{
    *target = dlsym(king_saxonc.handle, name);
    if (*target == NULL) {
        snprintf(
            king_saxonc.load_error,
            sizeof(king_saxonc.load_error),
            "Failed to load SaxonC symbol '%s'.",
            name
        );
        return FAILURE;
    }

    return SUCCESS;
}

static void king_saxonc_close_runtime_handle(void)
{
    if (king_saxonc.handle != NULL) {
        dlclose(king_saxonc.handle);
    }
    memset(&king_saxonc, 0, sizeof(king_saxonc));
}

static void king_saxonc_close_runtime_handle_preserve_error(void)
{
    char load_error[sizeof(king_saxonc.load_error)];

    snprintf(load_error, sizeof(load_error), "%s", king_saxonc.load_error);
    king_saxonc_close_runtime_handle();
    snprintf(king_saxonc.load_error, sizeof(king_saxonc.load_error), "%s", load_error);
    king_saxonc.load_attempted = 1;
}

static zend_result king_saxonc_build_module_relative_candidate(
    char *buffer,
    size_t buffer_size,
    const char *relative_path
)
{
    Dl_info info;
    const char *last_sep;
    size_t dir_len;
    size_t relative_len;

    if (dladdr((void *) king_saxonc_build_module_relative_candidate, &info) == 0
        || info.dli_fname == NULL
        || info.dli_fname[0] == '\0') {
        return FAILURE;
    }

    last_sep = strrchr(info.dli_fname, '/');
    if (last_sep == NULL) {
        return FAILURE;
    }

    dir_len = (size_t) (last_sep - info.dli_fname);
    relative_len = strlen(relative_path);
    if (dir_len + 1 + relative_len + 1 > buffer_size) {
        return FAILURE;
    }

    memcpy(buffer, info.dli_fname, dir_len);
    buffer[dir_len] = '/';
    memcpy(buffer + dir_len + 1, relative_path, relative_len + 1);

    return SUCCESS;
}

static zend_bool king_saxonc_try_dlopen(const char *path)
{
    if (path == NULL || path[0] == '\0') {
        return 0;
    }

    king_saxonc.handle = dlopen(path, RTLD_LAZY | RTLD_LOCAL);
    if (king_saxonc.handle == NULL) {
        return 0;
    }

    snprintf(
        king_saxonc.loaded_library,
        sizeof(king_saxonc.loaded_library),
        "%s",
        path
    );
    return 1;
}

static zend_result king_saxonc_ensure_ready(void)
{
    const char *env_path = getenv("KING_SAXONC_LIBRARY");
    const char *home_path = getenv("SAXONC_HOME");
    char home_candidate[PATH_MAX];
    char module_runtime_candidate[PATH_MAX];
    char module_local_candidate[PATH_MAX];
    const char *const candidates[] = {KING_SAXONC_RUNTIME_CANDIDATES};
    size_t i;

    if (king_saxonc.ready) {
        return SUCCESS;
    }

    if (king_saxonc.load_attempted) {
        return FAILURE;
    }
    king_saxonc.load_attempted = 1;

    if (!king_saxonc_try_dlopen(env_path) && home_path != NULL && home_path[0] != '\0') {
        snprintf(
            home_candidate,
            sizeof(home_candidate),
#if defined(__APPLE__)
            "%s/libsaxon-hec.dylib",
#else
            "%s/libsaxon-hec.so",
#endif
            home_path
        );
        king_saxonc_try_dlopen(home_candidate);
    }

    if (king_saxonc.handle == NULL
        && king_saxonc_build_module_relative_candidate(
            module_runtime_candidate,
            sizeof(module_runtime_candidate),
#if defined(__APPLE__)
            "king/runtime/libsaxon-hec.dylib"
#else
            "king/runtime/libsaxon-hec.so"
#endif
        ) == SUCCESS) {
        king_saxonc_try_dlopen(module_runtime_candidate);
    }

    if (king_saxonc.handle == NULL
        && king_saxonc_build_module_relative_candidate(
            module_local_candidate,
            sizeof(module_local_candidate),
#if defined(__APPLE__)
            "libsaxon-hec.dylib"
#else
            "libsaxon-hec.so"
#endif
        ) == SUCCESS) {
        king_saxonc_try_dlopen(module_local_candidate);
    }

    for (i = 0; king_saxonc.handle == NULL && candidates[i] != NULL; ++i) {
        king_saxonc_try_dlopen(candidates[i]);
    }

    if (king_saxonc.handle == NULL) {
        snprintf(
            king_saxonc.load_error,
            sizeof(king_saxonc.load_error),
            "Failed to load SaxonC. Set KING_SAXONC_LIBRARY to the SaxonC shared object, set SAXONC_HOME, or install %s in the dynamic linker path.",
            KING_SAXONC_RUNTIME_CANDIDATE_NAMES
        );
        return FAILURE;
    }

    if (king_saxonc_load_symbol((void **) &king_saxonc.initSaxonc_fn, "initSaxonc") != SUCCESS
        || king_saxonc_load_symbol((void **) &king_saxonc.freeSaxonc_fn, "freeSaxonc") != SUCCESS
        || king_saxonc_load_symbol((void **) &king_saxonc.version_fn, "version") != SUCCESS
        || king_saxonc_load_symbol((void **) &king_saxonc.getProductVariantAndVersion_fn, "getProductVariantAndVersion") != SUCCESS
        || king_saxonc_load_symbol((void **) &king_saxonc.c_getErrorMessage_fn, "c_getErrorMessage") != SUCCESS
        || king_saxonc_load_symbol((void **) &king_saxonc.xsltApplyStylesheet_fn, "xsltApplyStylesheet") != SUCCESS
        || king_saxonc_load_symbol((void **) &king_saxonc.xsltSaveResultToFile_fn, "xsltSaveResultToFile") != SUCCESS
        || king_saxonc_load_symbol((void **) &king_saxonc.setProperty_fn, "setProperty") != SUCCESS) {
        king_saxonc_close_runtime_handle_preserve_error();
        return FAILURE;
    }

    king_saxonc.ready = 1;
    return SUCCESS;
}
