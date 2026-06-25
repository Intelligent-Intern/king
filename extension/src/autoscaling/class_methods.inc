const zend_function_entry king_autoscaling_class_methods[] = {
    ZEND_ME_MAPPING(init, king_autoscaling_init, arginfo_king_config_array, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(startMonitoring, king_autoscaling_start_monitoring, arginfo_king_no_args, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(stopMonitoring, king_autoscaling_stop_monitoring, arginfo_king_no_args, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(getMetrics, king_autoscaling_get_metrics, arginfo_king_no_args, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(getStatus, king_autoscaling_get_status, arginfo_king_no_args, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(getNodes, king_autoscaling_get_nodes, arginfo_king_no_args, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(scaleUp, king_autoscaling_scale_up, arginfo_king_optional_instances, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(scaleDown, king_autoscaling_scale_down, arginfo_king_optional_instances, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(registerNode, king_autoscaling_register_node, arginfo_king_autoscaling_register_node, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(markNodeReady, king_autoscaling_mark_node_ready, arginfo_king_one_long, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(drainNode, king_autoscaling_drain_node, arginfo_king_one_long, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    PHP_FE_END
};
