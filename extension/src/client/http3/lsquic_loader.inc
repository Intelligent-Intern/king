/* lsquic loader - no runtime library */

static zend_result king_http3_ensure_lsquic_ready(void)
{
    static unsigned attempted = 0;
    static unsigned ready = 0;

    if (ready) {
        return SUCCESS;
    }

    if (attempted) {
        return FAILURE;
    }

    attempted = 1;
    return FAILURE;
}