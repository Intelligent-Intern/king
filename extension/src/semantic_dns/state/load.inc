int king_semantic_dns_state_load(void)
{
    FILE *fp = NULL;
    int fd;
    struct stat state_stat;
    king_mother_node_t *loaded_mother_nodes = NULL;
    uint32_t loaded_mother_node_count = 0;
    zend_long loaded_last_discovered = 0;
    zend_long loaded_last_synced = 0;
    uint32_t magic, version, node_count;
    uint32_t payload_len = 0;
    unsigned char *payload_buffer = NULL;
    zval state_payload;
    zend_bool has_state_payload = 0;

    ZVAL_UNDEF(&state_payload);

    if (!king_semantic_dns_runtime.initialized) {
        return FAILURE;
    }

    if (king_semantic_dns_state_ensure_directory() != SUCCESS) {
        return FAILURE;
    }

    fd = open(
        KING_SEMANTIC_DNS_STATE_FILE,
        O_RDONLY
#ifdef O_NOFOLLOW
        | O_NOFOLLOW
#endif
    );
    if (fd < 0) {
        return FAILURE; /* expected on first run */
    }

    if (fstat(fd, &state_stat) != 0 || !S_ISREG(state_stat.st_mode)) {
        close(fd);
        return FAILURE;
    }

    fp = fdopen(fd, "rb");
    if (fp == NULL) {
        close(fd);
        return FAILURE;
    }

    if (fread(&magic, sizeof(magic), 1, fp) != 1 || magic != KING_SEMANTIC_DNS_STATE_MAGIC) {
        fclose(fp);
        return FAILURE; /* expected on first run */
    }

    if (fread(&version, sizeof(version), 1, fp) != 1 || version != KING_SEMANTIC_DNS_STATE_VERSION) {
        fclose(fp);
        return FAILURE;
    }

    /* Restore mother nodes topology */
    if (fread(&node_count, sizeof(uint32_t), 1, fp) == 1) {
        if (node_count > 0) {
            if (node_count > KING_SEMANTIC_DNS_STATE_MAX_MOTHER_NODES) {
                fclose(fp);
                return FAILURE;
            }

            loaded_mother_nodes = pecalloc(node_count, sizeof(king_mother_node_t), 1);
            if (loaded_mother_nodes == NULL) {
                fclose(fp);
                return FAILURE;
            }

            if (fread(loaded_mother_nodes, sizeof(king_mother_node_t), node_count, fp) != node_count) {
                pefree(loaded_mother_nodes, 1);
                fclose(fp);
                return FAILURE;
            }

            loaded_mother_node_count = node_count;
        }
    } else {
        fclose(fp);
        return FAILURE;
    }

    if (
        fread(&loaded_last_discovered, sizeof(zend_long), 1, fp) != 1
        || fread(&loaded_last_synced, sizeof(zend_long), 1, fp) != 1
    ) {
        if (loaded_mother_nodes != NULL) {
            pefree(loaded_mother_nodes, 1);
        }
        fclose(fp);
        return FAILURE;
    }

    if (fread(&payload_len, sizeof(payload_len), 1, fp) == 1) {
        if (payload_len > KING_SEMANTIC_DNS_STATE_MAX_PAYLOAD_BYTES) {
            if (loaded_mother_nodes != NULL) {
                pefree(loaded_mother_nodes, 1);
            }
            fclose(fp);
            return FAILURE;
        }

        if (payload_len > 0) {
            payload_buffer = emalloc(payload_len);
            if (fread(payload_buffer, 1, payload_len, fp) != payload_len) {
                efree(payload_buffer);
                if (loaded_mother_nodes != NULL) {
                    pefree(loaded_mother_nodes, 1);
                }
                fclose(fp);
                return FAILURE;
            }

            if (king_semantic_dns_state_unserialize_zval(payload_buffer, payload_len, &state_payload) != SUCCESS) {
                efree(payload_buffer);
                if (loaded_mother_nodes != NULL) {
                    pefree(loaded_mother_nodes, 1);
                }
                fclose(fp);
                return FAILURE;
            }

            efree(payload_buffer);
            has_state_payload = 1;
        }
    } else if (!feof(fp)) {
        if (loaded_mother_nodes != NULL) {
            pefree(loaded_mother_nodes, 1);
        }
        fclose(fp);
        return FAILURE;
    }

    fclose(fp);

    if (king_semantic_dns_runtime.config.mother_nodes != NULL) {
        pefree(king_semantic_dns_runtime.config.mother_nodes, 1);
    }

    king_semantic_dns_runtime.config.mother_nodes = loaded_mother_nodes;
    king_semantic_dns_runtime.config.mother_node_count = loaded_mother_node_count;
    king_semantic_dns_runtime.last_discovered_node_count = loaded_last_discovered;
    king_semantic_dns_runtime.last_synced_node_count = loaded_last_synced;

    if (has_state_payload) {
        if (king_semantic_dns_import_state_payload(&state_payload) != SUCCESS) {
            zval_ptr_dtor(&state_payload);
            return FAILURE;
        }
        zval_ptr_dtor(&state_payload);
    }

    return SUCCESS;
}

