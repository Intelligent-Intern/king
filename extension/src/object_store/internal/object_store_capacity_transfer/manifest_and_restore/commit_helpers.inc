static int king_object_store_commit_snapshot_directory(
    const char *staging_directory,
    const char *destination_directory
)
{
    char previous_directory[1024];
    zend_bool had_existing_destination = 0;

    if (staging_directory == NULL || staging_directory[0] == '\0'
        || destination_directory == NULL || destination_directory[0] == '\0') {
        return FAILURE;
    }

    previous_directory[0] = '\0';

    if (king_object_store_create_unique_directory(
            destination_directory,
            "previous",
            previous_directory,
            sizeof(previous_directory)
        ) != SUCCESS) {
        return FAILURE;
    }
    if (rmdir(previous_directory) != 0) {
        king_object_store_remove_tree(previous_directory);
        return FAILURE;
    }

    if (rename(destination_directory, previous_directory) == 0) {
        DIR *moved_directory = opendir(previous_directory);

        if (moved_directory == NULL) {
            (void) rename(previous_directory, destination_directory);
            return FAILURE;
        }

        closedir(moved_directory);
        had_existing_destination = 1;
    } else if (errno != ENOENT) {
        return FAILURE;
    }

    if (rename(staging_directory, destination_directory) != 0) {
        if (had_existing_destination) {
            (void) rename(previous_directory, destination_directory);
        }
        return FAILURE;
    }

    if (had_existing_destination) {
        (void) king_object_store_remove_tree(previous_directory);
    }

    return SUCCESS;
}

static void king_object_store_fill_fallback_metadata(
    king_object_metadata_t *metadata,
    const char *object_id,
    uint64_t content_length,
    time_t fallback_timestamp)
{
    if (metadata == NULL || object_id == NULL) {
        return;
    }

    memset(metadata, 0, sizeof(*metadata));
    strncpy(metadata->object_id, object_id, sizeof(metadata->object_id) - 1);
    metadata->content_length = content_length;
    metadata->created_at = fallback_timestamp;
    metadata->modified_at = fallback_timestamp;
}
