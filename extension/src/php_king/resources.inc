/*
 * Core Zend resource-id registration orchestration. Concrete resource type
 * names and destructors stay with the subsystem that owns the native state.
 */

void king_register_resource_types(int module_number)
{
    king_client_register_resource_types(module_number);
    king_config_register_resource_types(module_number);
    king_mcp_register_resource_types(module_number);
}
