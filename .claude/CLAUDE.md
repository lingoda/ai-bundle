# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with the Lingoda AI Bundle repository.

## Project Overview

The **Lingoda AI Bundle** is a Symfony bundle that provides seamless integration between the Lingoda AI SDK and Symfony applications. It offers provider-specific platforms (OpenAI, Anthropic, Gemini) with type safety, dependency injection, and configuration management.

## Technology Stack

- **PHP ^8.3** - Required PHP version
- **Symfony ^6.4|^7.0** - Framework integration
- **lingoda/ai-sdk** - Core AI functionality
- **Symfony Bundle Architecture** - Configuration, DI container, commands

## Code Quality Tools & Commands

### Testing
```bash
# Run PHPUnit tests
vendor/bin/phpunit

# Run specific test suites
vendor/bin/phpunit tests/Unit
vendor/bin/phpunit tests/Integration

# Run tests with coverage
vendor/bin/phpunit --coverage-html coverage
```

### Code Quality
```bash
# Run EasyCodingStandard (code style)
vendor/bin/ecs check
vendor/bin/ecs check --fix

# Run PHPStan static analysis
vendor/bin/phpstan analyse
vendor/bin/phpstan analyse -c phpstan.tests.neon  # For tests

# Validate composer.json
composer validate
```

### Development
```bash
# Install dependencies
composer install
```

## Architecture & Structure

This bundle follows Symfony bundle best practices:

### Directory Structure
```
src/
├── Command/           # Console commands for AI operations
├── Platform/          # Provider-specific platform decorators
└── LingodaAiBundle.php # Main bundle class

tests/
├── Unit/             # Unit tests for individual classes
├── Integration/      # Integration tests for service registration
└── Util/             # Test utilities and helpers
```

### Key Components

1. **Provider Platforms**: Type-safe wrappers around AI SDK
   - `OpenAIPlatform` - OpenAI-specific operations
   - `AnthropicPlatform` - Anthropic/Claude-specific operations  
   - `GeminiPlatform` - Google Gemini-specific operations

2. **Console Commands**: CLI tools for AI operations
   - `ai:list-providers` - Show configured providers
   - `ai:list-models` - List available models
   - `ai:test-connection` - Test API connectivity

3. **Bundle Integration**: Automatic service registration based on API key availability

## Coding Standards

- Follow PSR-12 coding style (enforced by ECS)
- Use strict typing (`declare(strict_types=1)`)
- Type declarations for all method parameters and return types
- Constructor-based dependency injection
- Immutable value objects where appropriate
- Unit tests for all public methods
- Integration tests for service registration

## Testing Philosophy

- **Unit Tests**: Test individual classes in isolation using mocks
- **Integration Tests**: Test Symfony service container integration  
- Use `MockHelper` utility for consistent test setup
- Test both success and error scenarios
- Verify proper exception handling and error messages

## Development Workflow

1. **Adding New Features**:
   - Create unit tests first (TDD approach)
   - Implement the feature
   - Add integration tests for DI registration
   - Run all quality checks before committing

2. **Service Registration**:
   - Services auto-register based on API key availability
   - Use conditional service registration in bundle extension
   - Test service registration in `ServiceRegistrationTest`

3. **Platform Integration**:
   - Provider platforms decorate the base Platform class
   - Maintain type safety with provider-specific interfaces
   - Test each platform independently

## Bundle-Specific Guidelines

- **Configuration**: Support both environment variables and YAML config
- **Service Registration**: Conditional registration based on API key availability  
- **Error Handling**: Provide clear error messages for missing configuration
- **Commands**: Follow Symfony command conventions with proper exit codes
- **Testing**: Test bundle registration and service availability
- **Documentation**: Keep README.md synchronized with actual functionality

## Common Development Tasks

### Adding a New AI Provider Platform
1. Create platform class in `src/Platform/`
2. Add unit tests in `tests/Unit/Platform/`
3. Update service registration logic if needed
4. Add integration test in `ServiceRegistrationTest`
5. Update documentation

### Adding a New Console Command  
1. Create command class in `src/Command/`
2. Add unit tests in `tests/Unit/Command/`
3. Register command in bundle if needed

### Configuration Changes
1. Update bundle extension if needed
2. Test configuration loading
3. Update documentation and examples
4. Test both minimal and full configuration scenarios

## Important Notes

- Always test with different Symfony versions (6.4 and 7.x)
- Ensure backward compatibility within supported version ranges
- Bundle should work with minimal configuration (env vars only)
- All public APIs should be well-documented and tested
- Follow semantic versioning for releases
- Use the local `lingoda/ai-sdk` dependency during development