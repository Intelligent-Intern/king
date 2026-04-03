
const char *king_object_store_object_id_validate(const char *object_id)
{
    const unsigned char *cursor;

    if (object_id == NULL || object_id[0] == '\0') {
        return "Object ID must be between 1 and 127 bytes.";
    }

    if (strlen(object_id) > 127) {
        return "Object ID must be between 1 and 127 bytes.";
    }

    if (strstr(object_id, "..") != NULL) {
        return "Object ID must not contain traversal sequences.";
    }

    if (strchr(object_id, '/') != NULL || strchr(object_id, '\\') != NULL) {
        return "Object ID must not contain path separator characters.";
    }

    for (cursor = (const unsigned char *) object_id; *cursor != '\0'; cursor++) {
        if (*cursor < 0x20 || *cursor == 0x7f) {
            return "Object ID must not contain control characters.";
        }
    }

    return NULL;
}

const char *king_object_store_public_object_id_validate_zstr(const zend_string *object_id)
{
    const unsigned char *cursor;
    size_t index;

    if (object_id == NULL || ZSTR_LEN(object_id) == 0 || ZSTR_LEN(object_id) > 127) {
        return "Object ID must be between 1 and 127 bytes.";
    }

    cursor = (const unsigned char *) ZSTR_VAL(object_id);
    for (index = 0; index < ZSTR_LEN(object_id); index++) {
        if (cursor[index] < 0x20 || cursor[index] == 0x7f) {
            return "Object ID is invalid for object-store paths.";
        }
    }

    return king_object_store_object_id_validate(ZSTR_VAL(object_id)) == NULL
        ? NULL
        : "Object ID is invalid for object-store paths.";
}

/* --- Config helpers --- */

void king_object_store_config_clear(king_object_store_config_t *config)
{
    if (config == NULL) {
        return;
    }
    if (config->cdn_config.edge_nodes != NULL) {
        pefree(config->cdn_config.edge_nodes, 1);
        config->cdn_config.edge_nodes = NULL;
        config->cdn_config.edge_node_count = 0;
    }
    if (Z_TYPE(config->cloud_credentials) != IS_UNDEF) {
        zval_ptr_dtor(&config->cloud_credentials);
        ZVAL_UNDEF(&config->cloud_credentials);
    }
}

static void king_object_store_config_copy(
    king_object_store_config_t *target,
    const king_object_store_config_t *source
)
{
    memset(target, 0, sizeof(*target));
    ZVAL_UNDEF(&target->cloud_credentials);
    target->primary_backend       = king_object_store_normalize_backend(source->primary_backend);
    target->backup_backend        = king_object_store_normalize_backend(source->backup_backend);
    strncpy(target->storage_root_path, source->storage_root_path, sizeof(target->storage_root_path) - 1);
    target->max_storage_size_bytes = source->max_storage_size_bytes;
    target->replication_factor    = source->replication_factor;
    target->chunk_size_kb         = source->chunk_size_kb;
    target->enable_deduplication  = source->enable_deduplication;
    target->enable_encryption     = source->enable_encryption;
    target->enable_compression    = source->enable_compression;
    target->cdn_config            = source->cdn_config;
    if (Z_TYPE(source->cloud_credentials) != IS_UNDEF) {
        ZVAL_COPY(&target->cloud_credentials, (zval *) &source->cloud_credentials);
    }
}

/* --- String conversion helpers (satisfy include/object_store/object_store.h declarations) --- */

const char *king_storage_backend_to_string(king_storage_backend_t backend)
{
    backend = king_object_store_normalize_backend(backend);

    switch (backend) {
        case KING_STORAGE_BACKEND_LOCAL_FS:     return "local_fs";
        case KING_STORAGE_BACKEND_DISTRIBUTED:  return "distributed";
        case KING_STORAGE_BACKEND_CLOUD_S3:     return "cloud_s3";
        case KING_STORAGE_BACKEND_CLOUD_GCS:    return "cloud_gcs";
        case KING_STORAGE_BACKEND_CLOUD_AZURE:  return "cloud_azure";
        default:                                return "unknown";
    }
}

const char *king_object_type_to_string(king_object_type_t type)
{
    switch (type) {
        case KING_OBJECT_TYPE_STATIC_ASSET:    return "static_asset";
        case KING_OBJECT_TYPE_DYNAMIC_CONTENT: return "dynamic_content";
        case KING_OBJECT_TYPE_MEDIA_FILE:      return "media_file";
        case KING_OBJECT_TYPE_DOCUMENT:        return "document";
        case KING_OBJECT_TYPE_CACHE_ENTRY:     return "cache_entry";
        case KING_OBJECT_TYPE_BINARY_DATA:     return "binary_data";
        default:                               return "unknown";
    }
}

const char *king_cache_policy_to_string(king_cache_policy_t policy)
{
    switch (policy) {
        case KING_CACHE_POLICY_NO_CACHE:      return "no_cache";
        case KING_CACHE_POLICY_CACHE_CONTROL: return "cache_control";
        case KING_CACHE_POLICY_ETAG:          return "etag";
        case KING_CACHE_POLICY_LAST_MODIFIED: return "last_modified";
        case KING_CACHE_POLICY_AGGRESSIVE:    return "aggressive";
        case KING_CACHE_POLICY_SMART_CDN:     return "smart_cdn";
        default:                              return "unknown";
    }
}

const char *king_object_store_upload_protocol_to_string(king_object_store_upload_protocol_t protocol)
{
    switch (protocol) {
        case KING_OBJECT_STORE_UPLOAD_PROTOCOL_S3_MULTIPART:  return "s3_multipart";
        case KING_OBJECT_STORE_UPLOAD_PROTOCOL_GCS_RESUMABLE: return "gcs_resumable";
        case KING_OBJECT_STORE_UPLOAD_PROTOCOL_AZURE_BLOCKS:  return "azure_blocks";
        default:                                              return "unknown";
    }
}

/* --- System lifecycle --- */
