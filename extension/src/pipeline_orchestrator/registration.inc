#include "class_methods.inc"

void king_pipeline_orchestrator_register_classes(void)
{
    king_ce_pipeline_orchestrator = king_register_class_with_flags(
        "King\\PipelineOrchestrator",
        NULL,
        king_pipeline_orchestrator_class_methods,
        ZEND_ACC_FINAL | ZEND_ACC_NO_DYNAMIC_PROPERTIES
    );
}
