static zval *king_inference_openai_request_field(zval *request, const char *name)
{
    return king_inference_array_find(request, name);
}

static bool king_inference_openai_request_path_is(zval *request, const char *path)
{
    zval *uri = king_inference_openai_request_field(request, "uri");
    size_t path_len = strlen(path);

    if (uri == NULL) {
        uri = king_inference_openai_request_field(request, "path");
    }
    if (uri == NULL || Z_TYPE_P(uri) != IS_STRING) {
        return false;
    }

    return Z_STRLEN_P(uri) == path_len
        ? memcmp(Z_STRVAL_P(uri), path, path_len) == 0
        : Z_STRLEN_P(uri) > path_len
            && memcmp(Z_STRVAL_P(uri), path, path_len) == 0
            && Z_STRVAL_P(uri)[path_len] == '?';
}

static zend_string *king_inference_openai_request_path_suffix(zval *request, const char *prefix)
{
    zval *uri = king_inference_openai_request_field(request, "uri");
    size_t prefix_len = strlen(prefix);
    const char *start;
    const char *end;

    if (uri == NULL) {
        uri = king_inference_openai_request_field(request, "path");
    }
    if (uri == NULL || Z_TYPE_P(uri) != IS_STRING || Z_STRLEN_P(uri) <= prefix_len) {
        return NULL;
    }
    if (memcmp(Z_STRVAL_P(uri), prefix, prefix_len) != 0) {
        return NULL;
    }

    start = Z_STRVAL_P(uri) + prefix_len;
    end = memchr(start, '?', Z_STRLEN_P(uri) - prefix_len);
    if (end == NULL) {
        end = Z_STRVAL_P(uri) + Z_STRLEN_P(uri);
    }
    if (end <= start) {
        return NULL;
    }

    return zend_string_init(start, (size_t) (end - start), 0);
}

static bool king_inference_openai_request_method_is(zval *request, const char *method)
{
    zval *actual = king_inference_openai_request_field(request, "method");

    return actual != NULL && Z_TYPE_P(actual) == IS_STRING && strcasecmp(Z_STRVAL_P(actual), method) == 0;
}
