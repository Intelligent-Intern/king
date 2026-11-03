/* SaxonC 13 core ABI. The legacy convenience C API discards saxon_error_t
 * (c_getErrorMessage() always returns NULL in 13.1.1), so call the supported
 * core entry points directly and propagate structured failures to PHP.
 * ABI declarations mirror Saxonica's shipped saxonc-core-he.h/saxon_error.h.
 * SaxonC is optional and dynamically loaded: no JVM process is launched.
 */
#include <stdint.h>
typedef struct { int status; const char *error_code; const char *message;
    const char *system_id; int line_number; int num_of_errors; } king_saxon_error;
typedef struct { void *isolate; void *thread; char error[2048]; char *result; } sxnc_environment;
typedef struct { int64_t value; } sxnc_processor;
typedef struct { char *unused; } sxnc_parameter;
typedef struct { char *name; char *value; } sxnc_property;
typedef struct {
    void *handle; zend_bool ready, load_attempted;
    char loaded_library[PATH_MAX], load_error[512];
    int (*create_isolate)(void *, void **, void **);
    int (*tear_down)(void *);
    int64_t (*create_processor)(void *, int);
    char *(*product)(void *, void *);
    int64_t (*create_data)(void *, int);
    void (*add_property)(void *, void *, char *, char *, king_saxon_error *);
    int64_t (*configure)(void *, void *, char *, char *, king_saxon_error *);
    void (*destroy)(void *, void *);
    char *(*transform)(void *, void *, char *, char *, char *, void *, king_saxon_error *);
} king_saxonc_runtime_api_t;
static king_saxonc_runtime_api_t king_saxonc = {0};
static zend_result king_saxonc_load_symbol(void **target, const char *name) {
    *target = dlsym(king_saxonc.handle, name);
    if (!*target) { snprintf(king_saxonc.load_error,sizeof(king_saxonc.load_error),
        "Unsupported SaxonC ABI: missing '%s'; install SaxonC HE core 13.1.1 or newer 13.x.",name); return FAILURE; }
    return SUCCESS;
}
static void king_saxonc_close_runtime_handle(void) {
    if (king_saxonc.handle) dlclose(king_saxonc.handle);
    memset(&king_saxonc,0,sizeof(king_saxonc));
}
static void king_saxonc_close_runtime_handle_preserve_error(void) {
    char error[512]; snprintf(error,sizeof(error),"%s",king_saxonc.load_error);
    king_saxonc_close_runtime_handle();
    snprintf(king_saxonc.load_error,sizeof(king_saxonc.load_error),"%s",error);
    king_saxonc.load_attempted=1;
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

    king_saxonc.handle = dlopen(path, RTLD_NOW | RTLD_LOCAL);
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


static zend_result king_saxonc_ensure_ready(void) {
    const char *explicit_path=getenv("KING_SAXONC_LIBRARY"), *home=getenv("SAXONC_HOME");
    const char *const candidates[]={KING_SAXONC_RUNTIME_CANDIDATES}; char path[PATH_MAX]; size_t i;
    if (king_saxonc.ready) return SUCCESS;
    if (king_saxonc.load_attempted) return FAILURE;
    king_saxonc.load_attempted=1;
    /* An explicit deployment choice must never silently fall back. */
    if (explicit_path && *explicit_path) {
        if (!king_saxonc_try_dlopen(explicit_path)) {
            snprintf(king_saxonc.load_error,sizeof(king_saxonc.load_error),"Cannot load KING_SAXONC_LIBRARY: %.350s",dlerror());
            return FAILURE;
        }
    } else {
#if defined(__APPLE__)
        const char *core="libsaxonc-core-he.dylib";
#else
        const char *core="libsaxonc-core-he.so";
#endif
        if (home && *home) {
            snprintf(path,sizeof(path),"%s/%s",home,core); king_saxonc_try_dlopen(path);
            if (!king_saxonc.handle) { snprintf(path,sizeof(path),"%s/SaxonCCoreHE/lib/%s",home,core); king_saxonc_try_dlopen(path); }
        }
        if (!king_saxonc.handle && king_saxonc_build_module_relative_candidate(path,sizeof(path),core)==SUCCESS) king_saxonc_try_dlopen(path);
        for(i=0; !king_saxonc.handle && candidates[i];i++) king_saxonc_try_dlopen(candidates[i]);
    }
    if (!king_saxonc.handle) {
        snprintf(king_saxonc.load_error,sizeof(king_saxonc.load_error),"SaxonC 13 core unavailable. Set KING_SAXONC_LIBRARY to libsaxonc-core-he.so from SaxonC HE 13.1.1+."); return FAILURE;
    }
#define KING_SAXON_SYMBOL(field,name) if(king_saxonc_load_symbol((void **)&king_saxonc.field,name)!=SUCCESS) goto unsupported
    KING_SAXON_SYMBOL(create_isolate,"graal_create_isolate");
    KING_SAXON_SYMBOL(tear_down,"graal_tear_down_isolate");
    KING_SAXON_SYMBOL(create_processor,"createSaxonProcessor");
    KING_SAXON_SYMBOL(product,"j_c_getProductVersion");
    KING_SAXON_SYMBOL(create_data,"createProcessorDataWithCapacity");
    KING_SAXON_SYMBOL(add_property,"addProcessorPropertyPair");
    KING_SAXON_SYMBOL(configure,"j_applyPropertyToConfiguration");
    KING_SAXON_SYMBOL(destroy,"j_handles_destroy");
    KING_SAXON_SYMBOL(transform,"c_xsltApplyStylesheet");
#undef KING_SAXON_SYMBOL
    king_saxonc.ready=1; return SUCCESS;
unsupported: king_saxonc_close_runtime_handle_preserve_error(); return FAILURE;
}
static void king_saxonc_record_error(sxnc_environment *env, king_saxon_error *error) {
    snprintf(env->error,sizeof(env->error),"%s%s%s%s%s (line %d)",
        error->error_code?error->error_code:"SaxonC", error->message?": ":"",
        error->message?error->message:"native XSLT operation failed",
        error->system_id?" in ":"",error->system_id?error->system_id:"",error->line_number);
}
static zend_result king_saxonc_context_create(sxnc_environment **env,sxnc_processor **proc) {
    *env=calloc(1,sizeof(**env)); *proc=calloc(1,sizeof(**proc));
    if (!*env || !*proc) return FAILURE;
    if (king_saxonc.create_isolate(NULL,&(*env)->isolate,&(*env)->thread)!=0) return FAILURE;
    (*proc)->value=king_saxonc.create_processor((*env)->thread,0);
    if ((*proc)->value<=0) return FAILURE;
    return SUCCESS;
}
static void king_saxonc_context_free(sxnc_environment **env,sxnc_processor **proc,sxnc_property **props,int prop_len) {
    int i;
    for(i=0;*props && i<prop_len;i++){free((*props)[i].name);free((*props)[i].value);} free(*props); *props=NULL;
    if (*env) {
        /* c_xsltApplyStylesheet returns isolate-owned memory. Copy to a Zend
         * string before teardown; libc free() is invalid for this pointer. */
        if ((*env)->thread) {
            if (*proc && (*proc)->value>0) king_saxonc.destroy((*env)->thread,(void *)(intptr_t)(*proc)->value);
            king_saxonc.tear_down((*env)->thread);
        }
        free(*env); *env=NULL;
    }
    free(*proc); *proc=NULL;
}
static zend_result king_saxonc_set_property(sxnc_property **props,int *len,const char *name,const char *value) {
    sxnc_property *next=realloc(*props,(*len+1)*sizeof(**props)); if(!next) return FAILURE;
    *props=next; next[*len].name=strdup(name); next[*len].value=strdup(value); (*len)++; return SUCCESS;
}
static const char *king_saxonc_apply(sxnc_environment *env,sxnc_processor *proc,char *cwd,char *source,char *style,sxnc_property *props,int len) {
    king_saxon_error error={0}; int i; int64_t data;
    /* Network and extension calls are disabled for every processor. Local
     * resource reads remain possible: deploy trusted rules in an OS sandbox. */
    king_saxonc.configure(env->thread,(void *)(intptr_t)proc->value,"http://saxon.sf.net/feature/allowedProtocols","file",&error);
    if(error.status) {king_saxonc_record_error(env,&error);return NULL;}
    king_saxonc.configure(env->thread,(void *)(intptr_t)proc->value,"http://saxon.sf.net/feature/allow-external-functions","false",&error);
    if(error.status) {king_saxonc_record_error(env,&error);return NULL;}
    king_saxonc.configure(env->thread,(void *)(intptr_t)proc->value,"http://saxon.sf.net/feature/parserFeature?uri=http%3A//apache.org/xml/features/disallow-doctype-decl","true",&error);
    if(error.status) {king_saxonc_record_error(env,&error);return NULL;}
    data=king_saxonc.create_data(env->thread,len);
    if(data<=0){snprintf(env->error,sizeof(env->error),"SaxonC property container could not be created.");return NULL;}
    for(i=0;i<len;i++) {
        king_saxonc.add_property(env->thread,(void *)(intptr_t)data,props[i].name,props[i].value,&error);
        if(error.status) {king_saxonc_record_error(env,&error);king_saxonc.destroy(env->thread,(void *)(intptr_t)data);return NULL;}
    }
    env->result=king_saxonc.transform(env->thread,(void *)(intptr_t)proc->value,cwd,source,style,(void *)(intptr_t)data,&error);
    king_saxonc.destroy(env->thread,(void *)(intptr_t)data);
    if(error.status || !env->result) {king_saxonc_record_error(env,&error);return NULL;}
    return env->result;
}
