int king_semantic_dns_state_write_snapshot_file(const char *path, zval *payload)
{
    FILE *fp = NULL;
    int fd = -1;
    char tmp_template[PATH_MAX];
    zend_string *serialized_payload = NULL;

    if (path == NULL || path[0] == '\0' || payload == NULL) {
        return FAILURE;
    }

    if (king_semantic_dns_state_ensure_directory() != SUCCESS) {
        return FAILURE;
    }

    if (php_check_open_basedir(path) != 0) {
        return FAILURE;
    }

    if (snprintf(tmp_template, sizeof(tmp_template), "%s/.listener.XXXXXX", KING_SEMANTIC_DNS_STATE_DIR) >= (int) sizeof(tmp_template)) {
        return FAILURE;
    }

    if (php_check_open_basedir(tmp_template) != 0) {
        return FAILURE;
    }

    serialized_payload = king_semantic_dns_state_serialize_zval(payload);
    if (serialized_payload == NULL || ZSTR_LEN(serialized_payload) > KING_SEMANTIC_DNS_STATE_MAX_PAYLOAD_BYTES) {
        if (serialized_payload != NULL) {
            zend_string_release(serialized_payload);
        }
        return FAILURE;
    }

    fd = mkstemp(tmp_template);
    if (fd < 0) {
        zend_string_release(serialized_payload);
        return FAILURE;
    }

    if (fchmod(fd, 0600) != 0) {
        close(fd);
        unlink(tmp_template);
        zend_string_release(serialized_payload);
        return FAILURE;
    }

    fp = fdopen(fd, "wb");
    if (fp == NULL) {
        close(fd);
        unlink(tmp_template);
        zend_string_release(serialized_payload);
        return FAILURE;
    }

    if (ZSTR_LEN(serialized_payload) > 0
        && fwrite(ZSTR_VAL(serialized_payload), 1, ZSTR_LEN(serialized_payload), fp) != ZSTR_LEN(serialized_payload)) {
        fclose(fp);
        unlink(tmp_template);
        zend_string_release(serialized_payload);
        return FAILURE;
    }

    zend_string_release(serialized_payload);

    if (fclose(fp) != 0) {
        unlink(tmp_template);
        return FAILURE;
    }

    if (rename(tmp_template, path) != 0) {
        unlink(tmp_template);
        return FAILURE;
    }

    return SUCCESS;
}

int king_semantic_dns_state_read_snapshot_file(const char *path, zval *payload)
{
    int fd;
    FILE *fp = NULL;
    struct stat snapshot_stat;
    unsigned char *buffer = NULL;
    size_t offset = 0;

    if (path == NULL || path[0] == '\0' || payload == NULL) {
        return FAILURE;
    }

    ZVAL_NULL(payload);

    if (king_semantic_dns_state_ensure_directory() != SUCCESS) {
        return FAILURE;
    }

    if (php_check_open_basedir(path) != 0) {
        return FAILURE;
    }

    fd = open(
        path,
        O_RDONLY
#ifdef O_NOFOLLOW
        | O_NOFOLLOW
#endif
    );
    if (fd < 0) {
        return FAILURE;
    }

    if (fstat(fd, &snapshot_stat) != 0 || !S_ISREG(snapshot_stat.st_mode)) {
        close(fd);
        return FAILURE;
    }

    if (snapshot_stat.st_size < 0 || (size_t) snapshot_stat.st_size > KING_SEMANTIC_DNS_STATE_MAX_PAYLOAD_BYTES) {
        close(fd);
        return FAILURE;
    }

    fp = fdopen(fd, "rb");
    if (fp == NULL) {
        close(fd);
        return FAILURE;
    }

    if (snapshot_stat.st_size == 0) {
        fclose(fp);
        array_init(payload);
        return SUCCESS;
    }

    buffer = emalloc((size_t) snapshot_stat.st_size);
    while (offset < (size_t) snapshot_stat.st_size) {
        size_t read_count = fread(buffer + offset, 1, (size_t) snapshot_stat.st_size - offset, fp);

        if (read_count == 0) {
            if (ferror(fp)) {
                efree(buffer);
                fclose(fp);
                return FAILURE;
            }
            break;
        }

        offset += read_count;
    }

    fclose(fp);

    if (offset != (size_t) snapshot_stat.st_size) {
        efree(buffer);
        return FAILURE;
    }

    if (king_semantic_dns_state_unserialize_zval(buffer, offset, payload) != SUCCESS) {
        efree(buffer);
        return FAILURE;
    }

    efree(buffer);
    return SUCCESS;
}

int king_semantic_dns_state_remove_snapshot_file(const char *path)
{
    if (path == NULL || path[0] == '\0') {
        return FAILURE;
    }

    if (php_check_open_basedir(path) != 0) {
        return FAILURE;
    }

    if (unlink(path) == 0 || errno == ENOENT) {
        return SUCCESS;
    }

    return FAILURE;
}
