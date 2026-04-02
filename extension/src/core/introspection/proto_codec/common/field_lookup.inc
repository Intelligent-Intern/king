/*
 * Common codec field-lookup slice: resolves a runtime field by protobuf tag
 * from the tag-sorted schema layout using a bounded binary search.
 */

static const king_proto_runtime_field *king_proto_runtime_find_field_by_tag(
    const king_proto_runtime_schema *schema,
    uint32_t tag
)
{
    size_t left;
    size_t right;

    if (schema == NULL || schema->fields == NULL) {
        return NULL;
    }

    left = 0;
    right = schema->field_count;

    while (left < right) {
        size_t mid = left + ((right - left) / 2);
        uint32_t mid_tag = (uint32_t) schema->fields[mid].tag;

        if (mid_tag < tag) {
            left = mid + 1;
        } else {
            right = mid;
        }
    }

    if (left < schema->field_count && (uint32_t) schema->fields[left].tag == tag) {
        return &schema->fields[left];
    }

    return NULL;
}
