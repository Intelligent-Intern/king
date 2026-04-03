int king_semantic_dns_state_save(void)
{
    FILE *fp = NULL;
    int fd;
    char tmp_template[PATH_MAX];
    uint32_t magic = KING_SEMANTIC_DNS_STATE_MAGIC;
    uint32_t version = KING_SEMANTIC_DNS_STATE_VERSION;
    uint32_t payload_len = 0;
    zval state_payload;
    zend_string *serialized_payload = NULL;

    ZVAL_UNDEF(&state_payload);

    if (!king_semantic_dns_runtime.initialized) {
        return FAILURE;
    }

    if (king_semantic_dns_state_ensure_directory() != SUCCESS) {
        return FAILURE;
    }

    if (php_check_open_basedir(KING_SEMANTIC_DNS_STATE_FILE) != 0) {
        return FAILURE;
    }

    if (snprintf(tmp_template, sizeof(tmp_template), "%s/.state.XXXXXX", KING_SEMANTIC_DNS_STATE_DIR) >= (int) sizeof(tmp_template)) {
        return FAILURE;
    }

    if (php_check_open_basedir(tmp_template) != 0) {
        return FAILURE;
    }

    fd = mkstemp(tmp_template);
    if (fd < 0) {
        return FAILURE;
    }

    if (fchmod(fd, 0600) != 0) {
        close(fd);
        unlink(tmp_template);
        return FAILURE;
    }

    fp = fdopen(fd, "wb");
    if (fp == NULL) {
        close(fd);
        unlink(tmp_template);
        return FAILURE;
    }

    if (fwrite(&magic, sizeof(magic), 1, fp) != 1 || fwrite(&version, sizeof(version), 1, fp) != 1) {
        fclose(fp);
        unlink(tmp_template);
        return FAILURE;
    }

    /* Write the native mother_nodes list */
    if (fwrite(&king_semantic_dns_runtime.config.mother_node_count, sizeof(uint32_t), 1, fp) != 1) {
        fclose(fp);
        unlink(tmp_template);
        return FAILURE;
    }
    if (
        king_semantic_dns_runtime.config.mother_node_count > 0
        && king_semantic_dns_runtime.config.mother_nodes != NULL
        && fwrite(
            king_semantic_dns_runtime.config.mother_nodes,
            sizeof(king_mother_node_t),
            king_semantic_dns_runtime.config.mother_node_count,
            fp
        ) != king_semantic_dns_runtime.config.mother_node_count
    ) {
        fclose(fp);
        unlink(tmp_template);
        return FAILURE;
    }

    /* Persist scalar runtime fields after the topology snapshot. */
    if (
        fwrite(&king_semantic_dns_runtime.last_discovered_node_count, sizeof(zend_long), 1, fp) != 1
        || fwrite(&king_semantic_dns_runtime.last_synced_node_count, sizeof(zend_long), 1, fp) != 1
    ) {
        fclose(fp);
        unlink(tmp_template);
        return FAILURE;
    }

    if (king_semantic_dns_export_state_payload(&state_payload) != SUCCESS) {
        fclose(fp);
        unlink(tmp_template);
        return FAILURE;
    }

    serialized_payload = king_semantic_dns_state_serialize_zval(&state_payload);
    zval_ptr_dtor(&state_payload);
    if (
        serialized_payload == NULL
        || ZSTR_LEN(serialized_payload) > KING_SEMANTIC_DNS_STATE_MAX_PAYLOAD_BYTES
    ) {
        if (serialized_payload != NULL) {
            zend_string_release(serialized_payload);
        }
        fclose(fp);
        unlink(tmp_template);
        return FAILURE;
    }

    payload_len = (uint32_t) ZSTR_LEN(serialized_payload);
    if (
        fwrite(&payload_len, sizeof(payload_len), 1, fp) != 1
        || (payload_len > 0 && fwrite(ZSTR_VAL(serialized_payload), 1, payload_len, fp) != payload_len)
    ) {
        zend_string_release(serialized_payload);
        fclose(fp);
        unlink(tmp_template);
        return FAILURE;
    }
    zend_string_release(serialized_payload);

    if (fclose(fp) != 0) {
        unlink(tmp_template);
        return FAILURE;
    }

    if (rename(tmp_template, KING_SEMANTIC_DNS_STATE_FILE) != 0) {
        unlink(tmp_template);
        return FAILURE;
    }

    return SUCCESS;
}

