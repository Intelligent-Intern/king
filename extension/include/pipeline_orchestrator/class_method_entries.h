const zend_function_entry king_pipeline_orchestrator_class_methods[] = {
    ZEND_ME_MAPPING(run, king_pipeline_orchestrator_run, arginfo_king_pipeline_run, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(runAsync, king_pipeline_orchestrator_run_async, arginfo_king_pipeline_run_async, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(dispatch, king_pipeline_orchestrator_dispatch, arginfo_king_pipeline_run, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(dispatchAsync, king_pipeline_orchestrator_dispatch_async, arginfo_king_pipeline_run_async, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(registerTool, king_pipeline_orchestrator_register_tool, arginfo_king_pipeline_register_tool, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(registerHandler, king_pipeline_orchestrator_register_handler, arginfo_king_pipeline_register_handler, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(configureLogging, king_pipeline_orchestrator_configure_logging, arginfo_king_config_array, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(workerRunNext, king_pipeline_orchestrator_worker_run_next, arginfo_king_no_args, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(resumeRun, king_pipeline_orchestrator_resume_run, arginfo_king_pipeline_get_run, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(getRun, king_pipeline_orchestrator_get_run, arginfo_king_pipeline_get_run, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    ZEND_ME_MAPPING(cancelRun, king_pipeline_orchestrator_cancel_run, arginfo_king_pipeline_get_run, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    PHP_FE_END
};
