static zend_string *king_semantic_dns_state_serialize_zval(zval *value)
{
    smart_str buffer = {0};
    php_serialize_data_t var_hash;

    if (value == NULL) {
        return NULL;
    }

    PHP_VAR_SERIALIZE_INIT(var_hash);
    php_var_serialize(&buffer, value, &var_hash);
    PHP_VAR_SERIALIZE_DESTROY(var_hash);
    smart_str_0(&buffer);

    return buffer.s;
}

static zend_bool king_semantic_dns_state_value_tree_is_safe(
    zval *value,
    HashTable *seen_arrays
)
{
    zval *entry;
    zend_ulong array_key;

    if (value == NULL) {
        return 0;
    }

    switch (Z_TYPE_P(value)) {
        case IS_NULL:
        case IS_FALSE:
        case IS_TRUE:
        case IS_LONG:
        case IS_DOUBLE:
        case IS_STRING:
            return 1;
        case IS_ARRAY:
            array_key = (zend_ulong) (uintptr_t) Z_ARR_P(value);
            if (zend_hash_index_exists(seen_arrays, array_key)) {
                return 1;
            }
            zend_hash_index_add_empty_element(seen_arrays, array_key);
            ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(value), entry) {
                if (!king_semantic_dns_state_value_tree_is_safe(entry, seen_arrays)) {
                    return 0;
                }
            } ZEND_HASH_FOREACH_END();
            return 1;
        case IS_REFERENCE:
        case IS_OBJECT:
        case IS_RESOURCE:
        default:
            return 0;
    }
}

static int king_semantic_dns_state_unserialize_zval(
    const unsigned char *buffer,
    size_t buffer_len,
    zval *return_value
)
{
    const unsigned char *cursor = buffer;
    const unsigned char *end = buffer + buffer_len;
    php_unserialize_data_t var_hash;
    HashTable allowed_classes;
    HashTable seen_arrays;
    zend_result result = FAILURE;

    if (buffer == NULL || return_value == NULL) {
        return FAILURE;
    }

    ZVAL_NULL(return_value);
    PHP_VAR_UNSERIALIZE_INIT(var_hash);
    zend_hash_init(&allowed_classes, 0, NULL, NULL, 0);
    php_var_unserialize_set_allowed_classes(var_hash, &allowed_classes);
    if (!php_var_unserialize(
            return_value,
            &cursor,
            end,
            &var_hash
        )) {
        goto cleanup;
    }
    if (cursor != end) {
        goto cleanup;
    }
    zend_hash_init(&seen_arrays, 0, NULL, NULL, 0);
    if (!king_semantic_dns_state_value_tree_is_safe(return_value, &seen_arrays)) {
        zend_hash_destroy(&seen_arrays);
        goto cleanup;
    }
    zend_hash_destroy(&seen_arrays);
    result = SUCCESS;

cleanup:
    PHP_VAR_UNSERIALIZE_DESTROY(var_hash);
    zend_hash_destroy(&allowed_classes);
    if (result != SUCCESS) {
        zval_ptr_dtor(return_value);
        ZVAL_NULL(return_value);
    }

    return result;
}

static int king_semantic_dns_state_dir_is_secure(const struct stat *st)
{
    mode_t perms;

    if (st == NULL || !S_ISDIR(st->st_mode)) {
        return FAILURE;
    }

    if (st->st_uid != geteuid()) {
        return FAILURE;
    }

    perms = st->st_mode & 0777;
    if ((perms & 0077) != 0 || perms != 0700) {
        return FAILURE;
    }

    return SUCCESS;
}

static const char *king_semantic_dns_state_file_path(void)
{
    if (king_semantic_dns_runtime.initialized
        && king_semantic_dns_runtime.config.state_path[0] != '\0') {
        return king_semantic_dns_runtime.config.state_path;
    }

    return KING_SEMANTIC_DNS_DEFAULT_STATE_FILE;
}

int king_semantic_dns_state_build_directory_path(char *dest, size_t dest_len)
{
    const char *state_path = king_semantic_dns_state_file_path();
    const char *last_separator;
    size_t dir_len;

    if (dest == NULL || dest_len == 0 || state_path == NULL || state_path[0] != '/') {
        return FAILURE;
    }

    last_separator = strrchr(state_path, '/');
    if (last_separator == NULL || last_separator[1] == '\0') {
        return FAILURE;
    }

    dir_len = (last_separator == state_path)
        ? 1
        : (size_t) (last_separator - state_path);
    if (dir_len == 0 || dir_len >= dest_len) {
        return FAILURE;
    }

    memcpy(dest, state_path, dir_len);
    dest[dir_len] = '\0';
    return SUCCESS;
}

static int king_semantic_dns_state_build_lock_path(char *dest, size_t dest_len)
{
    const char *state_path = king_semantic_dns_state_file_path();

    if (dest == NULL || dest_len == 0 || state_path == NULL || state_path[0] == '\0') {
        return FAILURE;
    }

    if (snprintf(dest, dest_len, "%s.lock", state_path) >= (int) dest_len) {
        dest[0] = '\0';
        return FAILURE;
    }

    return SUCCESS;
}

static int king_semantic_dns_state_build_tmp_template(char *dest, size_t dest_len)
{
    char state_dir[PATH_MAX];

    if (dest == NULL || dest_len == 0) {
        return FAILURE;
    }

    if (king_semantic_dns_state_build_directory_path(state_dir, sizeof(state_dir)) != SUCCESS) {
        return FAILURE;
    }

    if (snprintf(dest, dest_len, "%s/.state.XXXXXX", state_dir) >= (int) dest_len) {
        dest[0] = '\0';
        return FAILURE;
    }

    return SUCCESS;
}

static int king_semantic_dns_state_ensure_directory(void)
{
    struct stat st;
    char state_dir[PATH_MAX];

    if (king_semantic_dns_state_build_directory_path(state_dir, sizeof(state_dir)) != SUCCESS) {
        return FAILURE;
    }

    if (php_check_open_basedir(state_dir) != 0) {
        return FAILURE;
    }

    if (mkdir(state_dir, 0700) != 0 && errno != EEXIST) {
        return FAILURE;
    }

    if (lstat(state_dir, &st) != 0) {
        return FAILURE;
    }

    return king_semantic_dns_state_dir_is_secure(&st);
}

static int king_semantic_dns_state_path_is_regular_file(void)
{
    struct stat state_stat;
    const char *state_path = king_semantic_dns_state_file_path();

    if (php_check_open_basedir(state_path) != 0) {
        return 0;
    }

    if (lstat(state_path, &state_stat) != 0) {
        return 0;
    }

    return S_ISREG(state_stat.st_mode) ? 1 : 0;
}

static int king_semantic_dns_state_lock_acquire(int *lock_fd_out)
{
    int flags = O_RDWR | O_CREAT;
    int fd;
    struct stat lock_stat;
    char lock_path[PATH_MAX];

    if (lock_fd_out == NULL) {
        return FAILURE;
    }

    *lock_fd_out = -1;

    if (king_semantic_dns_state_ensure_directory() != SUCCESS) {
        return FAILURE;
    }

    if (king_semantic_dns_state_build_lock_path(lock_path, sizeof(lock_path)) != SUCCESS) {
        return FAILURE;
    }

    if (php_check_open_basedir(lock_path) != 0) {
        return FAILURE;
    }

#ifdef O_CLOEXEC
    flags |= O_CLOEXEC;
#endif
#ifdef O_NOFOLLOW
    flags |= O_NOFOLLOW;
#endif

    fd = open(lock_path, flags, 0600);
    if (fd < 0) {
        return FAILURE;
    }

    if (fstat(fd, &lock_stat) != 0 || !S_ISREG(lock_stat.st_mode)) {
        close(fd);
        return FAILURE;
    }

    if (flock(fd, LOCK_EX) != 0) {
        close(fd);
        return FAILURE;
    }

    *lock_fd_out = fd;
    return SUCCESS;
}

static void king_semantic_dns_state_lock_release(int lock_fd)
{
    if (lock_fd < 0) {
        return;
    }

    (void) flock(lock_fd, LOCK_UN);
    close(lock_fd);
}

static int king_semantic_dns_state_refresh_locked(void)
{
    if (!king_semantic_dns_state_path_is_regular_file()) {
        return SUCCESS;
    }

    return king_semantic_dns_state_load();
}
