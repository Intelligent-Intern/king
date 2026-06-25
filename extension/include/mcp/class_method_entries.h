const zend_function_entry king_mcp_class_methods[] = {
    PHP_ME(King_MCP, __construct, arginfo_class_King_MCP___construct, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_ME(King_MCP, request, arginfo_class_King_MCP_request, ZEND_ACC_PUBLIC)
    PHP_ME(King_MCP, requestAsync, arginfo_class_King_MCP_requestAsync, ZEND_ACC_PUBLIC)
    PHP_ME(King_MCP, requestIibin, arginfo_class_King_MCP_requestIibin, ZEND_ACC_PUBLIC)
    PHP_ME(King_MCP, requestIibinAsync, arginfo_class_King_MCP_requestIibinAsync, ZEND_ACC_PUBLIC)
    PHP_ME(King_MCP, uploadFromStream, arginfo_class_King_MCP_uploadFromStream, ZEND_ACC_PUBLIC)
    PHP_ME(King_MCP, downloadToStream, arginfo_class_King_MCP_downloadToStream, ZEND_ACC_PUBLIC)
    PHP_ME(King_MCP, close, arginfo_class_King_MCP_close, ZEND_ACC_PUBLIC)
    PHP_FE_END
};

const zend_function_entry king_mcp_server_class_methods[] = {
    PHP_ME(King_MCPServer, __construct, arginfo_class_King_MCPServer___construct, ZEND_ACC_PUBLIC | ZEND_ACC_CTOR)
    PHP_ME(King_MCPServer, handleJsonRpc, arginfo_class_King_MCPServer_handleJsonRpc, ZEND_ACC_PUBLIC)
    PHP_ME(King_MCPServer, handleHttp, arginfo_class_King_MCPServer_handleHttp, ZEND_ACC_PUBLIC)
    PHP_ME(King_MCPServer, runStdio, arginfo_class_King_MCPServer_runStdio, ZEND_ACC_PUBLIC)
    PHP_FE_END
};
