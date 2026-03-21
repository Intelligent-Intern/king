PHP_FUNCTION(king_autoscaling_get_status)
{
    ZEND_PARSE_PARAMETERS_NONE();

    array_init(return_value);
    add_assoc_string(return_value, "provider",
        king_safe_string(king_cloud_autoscale_config.provider));
    add_assoc_string(return_value, "region",
        king_safe_string(king_cloud_autoscale_config.region));
    add_assoc_long(return_value, "min_nodes",
        king_cloud_autoscale_config.min_nodes);
    add_assoc_long(return_value, "max_nodes",
        king_cloud_autoscale_config.max_nodes);
    add_assoc_long(return_value, "scale_up_cpu_threshold_percent",
        king_cloud_autoscale_config.scale_up_cpu_threshold_percent);
    add_assoc_long(return_value, "scale_down_cpu_threshold_percent",
        king_cloud_autoscale_config.scale_down_cpu_threshold_percent);
    add_assoc_string(return_value, "scale_up_policy",
        king_safe_string(king_cloud_autoscale_config.scale_up_policy));
    add_assoc_long(return_value, "cooldown_period_sec",
        king_cloud_autoscale_config.cooldown_period_sec);
    add_assoc_long(return_value, "idle_node_timeout_sec",
        king_cloud_autoscale_config.idle_node_timeout_sec);
    add_assoc_string(return_value, "instance_type",
        king_safe_string(king_cloud_autoscale_config.instance_type));
    add_assoc_string(return_value, "instance_image_id",
        king_safe_string(king_cloud_autoscale_config.instance_image_id));
    add_assoc_string(return_value, "network_config",
        king_safe_string(king_cloud_autoscale_config.network_config));
    add_assoc_string(return_value, "instance_tags",
        king_safe_string(king_cloud_autoscale_config.instance_tags));
}

PHP_FUNCTION(king_autoscaling_get_metrics)
{
    ZEND_PARSE_PARAMETERS_NONE();

    /*
     * The skeleton build has autoscaling policy/config state, but no active
     * metrics collector, sample history, or monitoring thread. Expose a stable
     * empty list instead of FALSE until real runtime metrics exist.
     */
    array_init(return_value);
}
