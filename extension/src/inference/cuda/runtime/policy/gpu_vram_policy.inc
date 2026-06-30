/*
 * VRAM admission helpers for King inference.
 */

typedef struct _king_inference_gpu_vram_policy {
    zend_long free_bytes;
    zend_long reserve_mb;
    zend_long reserve_bytes;
    zend_long min_free_mb;
    zend_long min_free_bytes;
    zend_long free_after_reserve_bytes;
    bool floor_met;
} king_inference_gpu_vram_policy;

static zend_long king_inference_gpu_vram_mb_to_bytes(zend_long mb)
{
    if (mb <= 0) {
        return 0;
    }
    if (mb > ZEND_LONG_MAX / 1048576) {
        return ZEND_LONG_MAX;
    }

    return mb * 1048576;
}

static king_inference_gpu_vram_policy king_inference_gpu_vram_policy_from_free(
    bool memory_info_available,
    size_t free_bytes,
    zend_long reserve_mb,
    zend_long min_free_mb
) {
    king_inference_gpu_vram_policy policy;

    policy.free_bytes = 0;
    policy.reserve_mb = reserve_mb;
    policy.reserve_bytes = king_inference_gpu_vram_mb_to_bytes(reserve_mb);
    policy.min_free_mb = min_free_mb;
    policy.min_free_bytes = king_inference_gpu_vram_mb_to_bytes(min_free_mb);
    policy.free_after_reserve_bytes = 0;
    policy.floor_met = false;

    if (!memory_info_available) {
        return policy;
    }

    policy.free_bytes = free_bytes > (size_t) ZEND_LONG_MAX
        ? ZEND_LONG_MAX
        : (zend_long) free_bytes;
    policy.free_after_reserve_bytes = policy.free_bytes > policy.reserve_bytes
        ? policy.free_bytes - policy.reserve_bytes
        : 0;
    policy.floor_met = policy.free_bytes >= policy.min_free_bytes;

    return policy;
}
