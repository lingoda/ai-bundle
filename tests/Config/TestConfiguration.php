<?php

declare(strict_types=1);

namespace Lingoda\AiBundle\Tests\Config;

/**
 * Test configuration constants and helpers for the AI Bundle test suite
 */
final class TestConfiguration
{
    public const string DEFAULT_OPENAI_KEY = 'sk-test-openai-key-1234567890abcdef';
    public const string DEFAULT_ANTHROPIC_KEY = 'sk-ant-test-anthropic-key-1234567890';
    public const string DEFAULT_GEMINI_KEY = 'AIzaSy-test-gemini-key-1234567890';
    public const string DEFAULT_ORGANIZATION = 'org-test-organization-123';

    /**
     * Get the complete test configuration for all providers
     *
     * @return array<string, mixed>
     */
    public static function getFullConfiguration(): array
    {
        return [
            'default_provider' => 'openai',
            'providers' => [
                'openai' => [
                    'api_key' => self::DEFAULT_OPENAI_KEY,
                    'organization' => self::DEFAULT_ORGANIZATION,
                    'default_model' => 'gpt-4o-mini',
                ],
                'anthropic' => [
                    'api_key' => self::DEFAULT_ANTHROPIC_KEY,
                    'default_model' => 'claude-3-5-haiku-20241022',
                ],
                'gemini' => [
                    'api_key' => self::DEFAULT_GEMINI_KEY,
                    'default_model' => 'gemini-2.5-flash-002',
                ],
            ],
            'sanitization' => [
                'enabled' => true,
                'patterns' => [
                    '/test_\d+/',
                    '/sensitive-\w+/',
                    '/api[-_]key[-_]\w+/i',
                ],
            ],
            'logging' => [
                'enabled' => true,
                'service' => 'logger',
            ],
        ];
    }

    /**
     * Get minimal configuration with only OpenAI
     *
     * @return array<string, mixed>
     */
    public static function getMinimalConfiguration(): array
    {
        return [
            'providers' => [
                'openai' => [
                    'api_key' => self::DEFAULT_OPENAI_KEY,
                ],
            ],
        ];
    }

    /**
     * Get configuration with environment variables
     *
     * @return array<string, mixed>
     */
    public static function getEnvironmentConfiguration(): array
    {
        return [
            'providers' => [
                'openai' => [
                    'api_key' => '%env(OPENAI_API_KEY)%',
                    'organization' => '%env(OPENAI_ORGANIZATION)%',
                ],
                'anthropic' => [
                    'api_key' => '%env(ANTHROPIC_API_KEY)%',
                ],
                'gemini' => [
                    'api_key' => '%env(GEMINI_API_KEY)%',
                ],
            ],
        ];
    }

    /**
     * Get configuration with some providers disabled
     *
     * @return array<string, mixed>
     */
    public static function getPartialConfiguration(): array
    {
        return [
            'providers' => [
                'openai' => [
                    'api_key' => self::DEFAULT_OPENAI_KEY,
                ],
                'anthropic' => [
                    'api_key' => '', // Empty key - should be disabled
                ],
                'gemini' => [
                    // No api_key provided - should be disabled
                    'default_model' => 'gemini-2.5-flash-002',
                ],
            ],
        ];
    }

    /**
     * Get configuration with disabled sanitization
     *
     * @return array<string, mixed>
     */
    public static function getNoSanitizationConfiguration(): array
    {
        return [
            'providers' => [
                'openai' => [
                    'api_key' => self::DEFAULT_OPENAI_KEY,
                ],
            ],
            'sanitization' => [
                'enabled' => false,
            ],
        ];
    }

    /**
     * Get configuration with custom logging service
     *
     * @return array<string, mixed>
     */
    public static function getCustomLoggingConfiguration(): array
    {
        return [
            'providers' => [
                'openai' => [
                    'api_key' => self::DEFAULT_OPENAI_KEY,
                ],
            ],
            'logging' => [
                'enabled' => true,
                'service' => 'custom_logger',
            ],
        ];
    }

    /**
     * Get configuration with disabled logging
     *
     * @return array<string, mixed>
     */
    public static function getNoLoggingConfiguration(): array
    {
        return [
            'providers' => [
                'openai' => [
                    'api_key' => self::DEFAULT_OPENAI_KEY,
                ],
            ],
            'logging' => [
                'enabled' => false,
            ],
        ];
    }

    /**
     * Get the required kernel parameters for testing
     *
     * @return array<string, mixed>
     */
    public static function getKernelParameters(): array
    {
        return [
            'kernel.environment' => 'test',
            'kernel.debug' => true,
            'kernel.build_dir' => sys_get_temp_dir(),
            'kernel.cache_dir' => sys_get_temp_dir(),
            'kernel.project_dir' => dirname(__DIR__, 2),
        ];
    }

    /**
     * Get test environment variables
     *
     * @return array<string, string>
     */
    public static function getTestEnvironmentVariables(): array
    {
        return [
            'APP_ENV' => 'test',
            'APP_DEBUG' => 'true',
            'OPENAI_API_KEY' => self::DEFAULT_OPENAI_KEY,
            'ANTHROPIC_API_KEY' => self::DEFAULT_ANTHROPIC_KEY,
            'GEMINI_API_KEY' => self::DEFAULT_GEMINI_KEY,
            'OPENAI_ORGANIZATION' => self::DEFAULT_ORGANIZATION,
        ];
    }
}