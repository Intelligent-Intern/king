# Contributing to King PHP Extension

Welcome to the King PHP extension development team! This document provides comprehensive guidelines for contributing to the project.

## Development Environment Setup

### Prerequisites

- PHP 8.1+ with development headers (`php-dev` package)
- Rust toolchain (latest stable)
- C compiler (GCC or Clang)
- OpenSSL development libraries
- libcurl development libraries
- Git with submodule support
- Make and autotools

### Initial Setup

```bash
# Clone the repository
git clone <repository-url> king
cd king

# Initialize submodules
git submodule update --init --recursive

# Build the extension
make build

# Run tests to verify setup
make unit
```

## Project Architecture

King follows a layered architecture with clear separation of concerns:

### Layer 1: Configuration Foundation
- **php.ini Integration**: System-wide defaults with `king.` prefix
- **Runtime Configuration**: Per-connection `King\Config` objects
- **Policy Enforcement**: Administrative controls via `king.allow_config_override`

### Layer 2: C-Native Core
- **QUIC Transport**: Native QUIC/HTTP3 implementation via quiche
- **Performance Optimization**: CPU affinity, zero-copy I/O, busy polling
- **Memory Management**: Efficient resource handling and cleanup

### Layer 3: High-Level Abstractions
- **Session Management**: `King\Session` for connection lifecycle
- **Clustering**: Multi-process supervisor with automatic restart
- **Event-Driven API**: Callback-based server implementations

### Layer 4: Service Communication
- **MCP Protocol**: Model Context Protocol for service-to-service communication
- **IIBIN Serialization**: High-performance binary serialization format
- **Schema Management**: Type-safe data contracts

### Layer 5: Workflow Engine
- **Pipeline Orchestrator**: Declarative workflow execution
- **Tool Integration**: Generic tool handlers with MCP binding
- **Parallel Execution**: Concurrent step processing

## Code Organization

### Directory Structure

```
king/
├── extension/                    # C extension source code
│   ├── config/                   # Configuration modules (18 modules)
│   ├── src/                      # Core C implementation
│   ├── include/                  # Header files
│   └── tests/                    # C-level tests
├── quiche/                       # QUIC implementation (submodule)
├── libcurl/                      # HTTP fallback (submodule)
├── infra/                        # Build and deployment infrastructure
├── tests/                        # PHP-level tests
├── benchmarks/                   # Performance benchmarks
└── stubs/                        # PHP stub files for IDE support
```

### Configuration Modules

Each configuration module follows a strict 5-file pattern:
- `default.c` - Default values and constants
- `ini.c` - php.ini parameter definitions
- `config.c` - Runtime configuration handling
- `base_layer.c` - Base layer integration
- `index.c` - Module registration

Some modules include `default.h` for shared constants.

## Development Workflow

### Making Changes

1. **Create Feature Branch**
   ```bash
   git checkout -b feature/your-feature-name
   ```

2. **Make Changes**
   - Follow coding standards (see below)
   - Add tests for new functionality
   - Update documentation as needed

3. **Test Changes**
   ```bash
   make clean
   make build
   make unit
   ```

4. **Commit Changes**
   ```bash
   git add .
   git commit -m "feat: descriptive commit message"
   ```

5. **Submit Pull Request**
   - Ensure all tests pass
   - Include clear description of changes
   - Reference any related issues

### Coding Standards

#### C Code Standards

- **Naming**: Use `king_` prefix for all public functions
- **Memory Management**: Always free allocated memory
- **Error Handling**: Use consistent error reporting patterns
- **Documentation**: Document all public functions with Doxygen comments

```c
/**
 * Validates a configuration parameter
 * 
 * @param value The value to validate
 * @param max_length Maximum allowed length
 * @return 1 if valid, 0 if invalid
 */
int king_validate_string(const char *value, size_t max_length);
```

#### PHP Code Standards

- **Namespacing**: All classes in `King\` namespace
- **Type Hints**: Use strict typing where possible
- **Documentation**: PHPDoc comments for all public methods

```php
<?php
namespace King;

/**
 * QUIC session management class
 */
class Session
{
    /**
     * Establishes a QUIC connection
     * 
     * @param string $host Target hostname
     * @param int $port Target port
     * @param Config $config Connection configuration
     */
    public function __construct(string $host, int $port, Config $config) {
        // Implementation
    }
}
```

### Testing Guidelines

#### Unit Tests

- **C Tests**: Test individual functions and components
- **PHP Tests**: Test PHP API functionality
- **Integration Tests**: Test end-to-end functionality
- **Performance Tests**: Benchmark critical paths

#### Test Structure

```
tests/
├── unit/
│   ├── c/                        # C-level unit tests
│   └── php/                      # PHP-level unit tests
├── integration/                  # Integration tests
└── performance/                  # Performance benchmarks
```

#### Writing Tests

```php
<?php
// PHP test example
class SessionTest extends PHPUnit\Framework\TestCase
{
    public function testConnectionEstablishment(): void
    {
        $config = new King\Config(['verify_peer' => false]);
        $session = new King\Session('localhost', 4433, $config);
        
        $this->assertTrue($session->isConnected());
        $session->close();
    }
}
```

### Configuration Development

When adding new configuration parameters:

1. **Choose Appropriate Module**: Add to existing or create new config module
2. **Follow 5-File Pattern**: Implement all required files
3. **Add Validation**: Create appropriate validation function
4. **Update Documentation**: Document new parameters
5. **Add Tests**: Test parameter validation and usage

### Performance Considerations

- **Memory Efficiency**: Minimize allocations in hot paths
- **CPU Optimization**: Use CPU affinity and scheduling policies
- **Network Efficiency**: Leverage zero-copy I/O where possible
- **Concurrency**: Design for multi-process and fiber-based concurrency

## Build System

### Build Targets

```bash
make build      # Build extension and dependencies
make unit       # Run unit tests
make clean      # Clean build artifacts
make tree       # Show project structure
make help       # Show available targets
```

### Manual Build Process

```bash
# Build quiche
cd quiche
cargo build --release --features ffi,pkg-config-meta,qlog

# Build libcurl
cd libcurl
./buildconf
./configure --enable-static --with-openssl --enable-http3
make

# Build extension
cd extension
phpize
./configure --with-king
make
```

## Debugging

### Debug Build

```bash
# Build with debug symbols
cd extension
phpize
./configure --with-king --enable-debug
make
```

### Memory Debugging

```bash
# Run with Valgrind
valgrind --tool=memcheck --leak-check=full php test_script.php
```

### Performance Profiling

```bash
# Profile with perf
perf record php test_script.php
perf report
```

## Documentation

### Code Documentation

- **C Code**: Use Doxygen-style comments
- **PHP Code**: Use PHPDoc comments
- **Configuration**: Document all parameters in module files

### User Documentation

- **README.md**: Keep updated with new features
- **Examples**: Add practical usage examples
- **API Reference**: Maintain comprehensive API documentation

## Release Process

### Version Numbering

King follows semantic versioning (SemVer):
- **Major**: Breaking changes
- **Minor**: New features, backward compatible
- **Patch**: Bug fixes, backward compatible

### Release Checklist

1. Update version numbers
2. Update CHANGELOG.md
3. Run full test suite
4. Build and test on all supported platforms
5. Create release tag
6. Update documentation

## Community Guidelines

### Code of Conduct

- Be respectful and inclusive
- Focus on constructive feedback
- Help newcomers learn the codebase
- Maintain professional communication

### Getting Help

- **Documentation**: Check existing docs first
- **Issues**: Search existing issues before creating new ones
- **Discussions**: Use GitHub Discussions for questions
- **Code Review**: Participate in code review process

## Advanced Topics

### Adding New Protocols

When adding support for new protocols:

1. Create protocol-specific module in `src/`
2. Add configuration parameters
3. Implement C-level protocol handling
4. Create PHP API wrapper
5. Add comprehensive tests
6. Update documentation

### Performance Optimization

Key areas for optimization:
- **Memory allocation patterns**
- **System call reduction**
- **Cache-friendly data structures**
- **SIMD instruction usage**
- **Lock-free algorithms**

### Security Considerations

- **Input validation**: Validate all external input
- **Memory safety**: Prevent buffer overflows
- **Cryptographic security**: Use secure random number generation
- **Network security**: Implement proper TLS validation

## Troubleshooting

### Common Build Issues

1. **Missing dependencies**: Install development packages
2. **Submodule issues**: Update submodules recursively
3. **PHP version**: Ensure compatible PHP version
4. **Compiler errors**: Check GCC/Clang compatibility

### Runtime Issues

1. **Extension loading**: Check php.ini configuration
2. **Memory leaks**: Use Valgrind for detection
3. **Performance issues**: Profile with appropriate tools
4. **Network issues**: Check firewall and routing

Thank you for contributing to King! Your efforts help make PHP a first-class language for high-performance systems programming.