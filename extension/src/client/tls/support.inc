static void king_tls_replace_persistent_string(char **target, const char *value)
{
    if (*target != NULL) {
        pefree(*target, 1);
    }

    *target = pestrdup(value != NULL ? value : "", 1);
}

static zend_result king_tls_validate_readable_path(
    const char *path,
    size_t path_len,
    const char *function_name,
    const char *label)
{
    char message[KING_ERR_LEN];

    if (path == NULL) {
        snprintf(
            message,
            sizeof(message),
            "%s() requires a %s path.",
            function_name,
            label
        );
        king_set_error(message);
        return FAILURE;
    }

    if (path_len > 0 && VCWD_ACCESS(path, R_OK) != 0) {
        snprintf(
            message,
            sizeof(message),
            "%s() %s path is not accessible or does not exist.",
            function_name,
            label
        );
        king_set_error(message);
        return FAILURE;
    }

    return SUCCESS;
}
