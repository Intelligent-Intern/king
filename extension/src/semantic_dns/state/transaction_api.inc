int king_semantic_dns_state_has_regular_snapshot(void)
{
    return king_semantic_dns_state_path_is_regular_file();
}

int king_semantic_dns_state_transaction_begin(int *lock_fd_out)
{
    zval local_payload;
    zend_bool have_local_payload = 0;

    if (lock_fd_out == NULL || !king_semantic_dns_runtime.initialized) {
        return FAILURE;
    }

    ZVAL_UNDEF(&local_payload);
    *lock_fd_out = -1;

    if (
        king_semantic_dns_registry_initialized
        && king_semantic_dns_export_state_payload(&local_payload) == SUCCESS
    ) {
        have_local_payload = 1;
    }

    if (king_semantic_dns_state_lock_acquire(lock_fd_out) != SUCCESS) {
        if (have_local_payload) {
            zval_ptr_dtor(&local_payload);
        }
        return FAILURE;
    }

    if (king_semantic_dns_state_refresh_locked() != SUCCESS) {
        if (have_local_payload) {
            zval_ptr_dtor(&local_payload);
        }
        king_semantic_dns_state_lock_release(*lock_fd_out);
        *lock_fd_out = -1;
        return FAILURE;
    }

    if (
        have_local_payload
        && king_semantic_dns_merge_missing_state_payload(&local_payload) != SUCCESS
    ) {
        zval_ptr_dtor(&local_payload);
        king_semantic_dns_state_lock_release(*lock_fd_out);
        *lock_fd_out = -1;
        return FAILURE;
    }

    if (have_local_payload) {
        zval_ptr_dtor(&local_payload);
    }

    return SUCCESS;
}

void king_semantic_dns_state_transaction_end(int lock_fd)
{
    king_semantic_dns_state_lock_release(lock_fd);
}

int king_semantic_dns_state_persist_locked(void)
{
    return king_semantic_dns_state_save();
}

