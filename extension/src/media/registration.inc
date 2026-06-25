/*
 * Media registration aggregation. The media translation unit includes this
 * module boundary instead of leaving registration in the core bootstrap.
 */

void king_media_register_classes(void)
{
    king_ce_rtp_socket = king_register_class_with_flags(
        "King\\RTP\\Socket",
        NULL,
        king_rtp_socket_class_methods,
        ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
}

void king_media_init_object_handlers(void)
{
    king_rtp_socket_object_handlers_init();
}
