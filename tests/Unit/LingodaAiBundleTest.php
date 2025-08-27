<?php

declare(strict_types=1);

namespace Lingoda\AiBundle\Tests\Unit;

use Lingoda\AiBundle\LingodaAiBundle;
use Lingoda\AiSdk\Enum\AIProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

final class LingodaAiBundleTest extends TestCase
{
    private LingodaAiBundle $bundle;

    protected function setUp(): void
    {
        $this->bundle = new LingodaAiBundle();
    }

    public function testGetContainerExtension(): void
    {
        $extension = $this->bundle->getContainerExtension();
        
        self::assertNotNull($extension);
        self::assertSame('lingoda_ai', $extension->getAlias());
    }

    public function testDefaultConfiguration(): void
    {
        $processor = new Processor();
        /** @var Extension $extension */
        $extension = $this->bundle->getContainerExtension();
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        
        $config = $processor->processConfiguration($configuration, []);
        
        self::assertSame(AIProvider::OPENAI->value, $config['default_provider']);
        self::assertTrue($config['sanitization']['enabled']);
        self::assertEmpty($config['sanitization']['patterns']);
        self::assertTrue($config['logging']['enabled']);
        self::assertSame('logger', $config['logging']['service']);
    }

    public function testProviderConfiguration(): void
    {
        $processor = new Processor();
        /** @var Extension $extension */
        $extension = $this->bundle->getContainerExtension();
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        
        $configs = [
            [
                'providers' => [
                    'openai' => [
                        'api_key' => 'test_key',
                        'organization' => 'test_org',
                        'default_model' => 'gpt-4o',
                    ],
                ],
            ],
        ];
        
        $config = $processor->processConfiguration($configuration, $configs);
        
        self::assertArrayHasKey('openai', $config['providers']);
        self::assertSame('test_key', $config['providers']['openai']['api_key']);
        self::assertSame('test_org', $config['providers']['openai']['organization']);
        self::assertSame('gpt-4o', $config['providers']['openai']['default_model']);
    }

    public function testInvalidDefaultProvider(): void
    {
        $processor = new Processor();
        /** @var Extension $extension */
        $extension = $this->bundle->getContainerExtension();
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        
        $configs = [
            ['default_provider' => 'invalid_provider'],
        ];
        
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Invalid default provider "invalid_provider"');
        
        $processor->processConfiguration($configuration, $configs);
    }

    public function testValidDefaultProviders(): void
    {
        $processor = new Processor();
        /** @var Extension $extension */
        $extension = $this->bundle->getContainerExtension();
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        
        $validProviders = ['openai', 'anthropic', 'gemini'];
        
        foreach ($validProviders as $provider) {
            $configs = [['default_provider' => $provider]];
            $config = $processor->processConfiguration($configuration, $configs);
            
            self::assertSame($provider, $config['default_provider']);
        }
    }

    public function testSanitizationConfiguration(): void
    {
        $processor = new Processor();
        /** @var Extension $extension */
        $extension = $this->bundle->getContainerExtension();
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        
        $configs = [
            [
                'sanitization' => [
                    'enabled' => false,
                    'patterns' => ['/test_\d+/', '/sensitive-\w+/'],
                ],
            ],
        ];
        
        $config = $processor->processConfiguration($configuration, $configs);
        
        self::assertFalse($config['sanitization']['enabled']);
        self::assertSame(['/test_\d+/', '/sensitive-\w+/'], $config['sanitization']['patterns']);
    }

    public function testLoggingConfiguration(): void
    {
        $processor = new Processor();
        /** @var Extension $extension */
        $extension = $this->bundle->getContainerExtension();
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        
        $configs = [
            [
                'logging' => [
                    'enabled' => false,
                    'service' => 'custom_logger',
                ],
            ],
        ];
        
        $config = $processor->processConfiguration($configuration, $configs);
        
        self::assertFalse($config['logging']['enabled']);
        self::assertSame('custom_logger', $config['logging']['service']);
    }

    public function testAllProvidersConfiguration(): void
    {
        $processor = new Processor();
        /** @var Extension $extension */
        $extension = $this->bundle->getContainerExtension();
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        
        $configs = [
            [
                'providers' => [
                    'openai' => [
                        'api_key' => 'openai_key',
                        'organization' => 'test_org',
                    ],
                    'anthropic' => [
                        'api_key' => 'anthropic_key',
                    ],
                    'gemini' => [
                        'api_key' => 'gemini_key',
                    ],
                ],
            ],
        ];
        
        $config = $processor->processConfiguration($configuration, $configs);
        
        self::assertArrayHasKey('openai', $config['providers']);
        self::assertArrayHasKey('anthropic', $config['providers']);
        self::assertArrayHasKey('gemini', $config['providers']);
        
        self::assertSame('openai_key', $config['providers']['openai']['api_key']);
        self::assertSame('anthropic_key', $config['providers']['anthropic']['api_key']);
        self::assertSame('gemini_key', $config['providers']['gemini']['api_key']);
    }

    public function testGetProviderDefaults(): void
    {
        $reflection = new \ReflectionClass($this->bundle);
        $method = $reflection->getMethod('getProviderDefaults');

        // Test OpenAI defaults
        $openaiDefaults = $method->invoke($this->bundle, 'openai');
        self::assertIsArray($openaiDefaults);
        self::assertArrayHasKey('api_key', $openaiDefaults);
        self::assertArrayHasKey('organization', $openaiDefaults);
        self::assertArrayHasKey('default_model', $openaiDefaults);
        self::assertSame('%env(OPENAI_API_KEY)%', $openaiDefaults['api_key']);
        self::assertSame('%env(OPENAI_ORGANIZATION)%', $openaiDefaults['organization']); 
        self::assertSame('gpt-4o-mini', $openaiDefaults['default_model']);

        // Test Anthropic defaults
        $anthropicDefaults = $method->invoke($this->bundle, 'anthropic');
        self::assertIsArray($anthropicDefaults);
        self::assertArrayHasKey('api_key', $anthropicDefaults);
        self::assertArrayHasKey('default_model', $anthropicDefaults);
        self::assertSame('%env(ANTHROPIC_API_KEY)%', $anthropicDefaults['api_key']);
        self::assertSame('claude-sonnet-4-20250514', $anthropicDefaults['default_model']);

        // Test Gemini defaults
        $geminiDefaults = $method->invoke($this->bundle, 'gemini');
        self::assertIsArray($geminiDefaults);
        self::assertArrayHasKey('api_key', $geminiDefaults);
        self::assertArrayHasKey('default_model', $geminiDefaults);
        self::assertSame('%env(GEMINI_API_KEY)%', $geminiDefaults['api_key']);
        self::assertSame('gemini-2.5-flash', $geminiDefaults['default_model']);

        // Test unknown provider defaults
        $unknownDefaults = $method->invoke($this->bundle, 'unknown_provider');
        self::assertSame(['api_key' => '', 'default_model' => ''], $unknownDefaults);
    }

    public function testGetRateLimitDefaults(): void
    {
        $reflection = new \ReflectionClass($this->bundle);
        $method = $reflection->getMethod('getRateLimitDefaults');

        // Test OpenAI requests rate limiting defaults
        $openaiRequestDefaults = $method->invoke($this->bundle, 'openai', 'requests');
        self::assertIsArray($openaiRequestDefaults);
        self::assertArrayHasKey('policy', $openaiRequestDefaults);
        self::assertArrayHasKey('limit', $openaiRequestDefaults);
        self::assertArrayHasKey('rate', $openaiRequestDefaults);
        self::assertSame('token_bucket', $openaiRequestDefaults['policy']);
        self::assertSame(180, $openaiRequestDefaults['limit']); // Tier 1 default
        self::assertIsArray($openaiRequestDefaults['rate']);
        self::assertSame('1 minute', $openaiRequestDefaults['rate']['interval']);
        self::assertSame(180, $openaiRequestDefaults['rate']['amount']);

        // Test OpenAI tokens rate limiting defaults
        $openaiTokenDefaults = $method->invoke($this->bundle, 'openai', 'tokens');
        self::assertIsArray($openaiTokenDefaults);
        self::assertSame('token_bucket', $openaiTokenDefaults['policy']);
        self::assertSame(450000, $openaiTokenDefaults['limit']); // Tier 1 default
        self::assertSame('1 minute', $openaiTokenDefaults['rate']['interval']);
        self::assertSame(450000, $openaiTokenDefaults['rate']['amount']);

        // Test Anthropic requests rate limiting defaults
        $anthropicRequestDefaults = $method->invoke($this->bundle, 'anthropic', 'requests');
        self::assertIsArray($anthropicRequestDefaults);
        self::assertSame('token_bucket', $anthropicRequestDefaults['policy']);
        self::assertSame(100, $anthropicRequestDefaults['limit']); // Free tier
        self::assertSame('1 minute', $anthropicRequestDefaults['rate']['interval']);

        // Test Anthropic tokens rate limiting defaults
        $anthropicTokenDefaults = $method->invoke($this->bundle, 'anthropic', 'tokens');
        self::assertSame(100000, $anthropicTokenDefaults['limit']); // Free tier

        // Test Gemini rate limiting defaults
        $geminiRequestDefaults = $method->invoke($this->bundle, 'gemini', 'requests');
        self::assertSame(1000, $geminiRequestDefaults['limit']); // Free tier

        $geminiTokenDefaults = $method->invoke($this->bundle, 'gemini', 'tokens');
        self::assertSame(1000000, $geminiTokenDefaults['limit']); // Free tier

        // Test unknown provider/type defaults
        $unknownDefaults = $method->invoke($this->bundle, 'unknown', 'requests');
        self::assertIsArray($unknownDefaults);
        self::assertSame('token_bucket', $unknownDefaults['policy']);
        self::assertSame(60, $unknownDefaults['limit']);
    }

    public function testGetProviderFactoryConfig(): void
    {
        $reflection = new \ReflectionClass($this->bundle);
        $method = $reflection->getMethod('getProviderFactoryConfig');
        
        $factoryConfig = $method->invoke($this->bundle);
        
        self::assertIsArray($factoryConfig);
        
        // Test OpenAI configuration
        self::assertArrayHasKey('openai', $factoryConfig);
        self::assertArrayHasKey('factory', $factoryConfig['openai']);
        self::assertArrayHasKey('client', $factoryConfig['openai']);
        self::assertStringContainsString('OpenAI', $factoryConfig['openai']['factory']);
        self::assertStringContainsString('OpenAI', $factoryConfig['openai']['client']);
        
        // Test Anthropic configuration
        self::assertArrayHasKey('anthropic', $factoryConfig);
        self::assertStringContainsString('Anthropic', $factoryConfig['anthropic']['factory']);
        self::assertStringContainsString('Anthropic', $factoryConfig['anthropic']['client']);
        
        // Test Gemini configuration
        self::assertArrayHasKey('gemini', $factoryConfig);
        self::assertStringContainsString('Gemini', $factoryConfig['gemini']['factory']);
        self::assertStringContainsString('Gemini', $factoryConfig['gemini']['client']);
    }

    public function testRateLimitingConfiguration(): void
    {
        $processor = new Processor();
        /** @var Extension $extension */
        $extension = $this->bundle->getContainerExtension();
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        
        $configs = [
            [
                'rate_limiting' => [
                    'enabled' => true,
                    'storage' => 'custom.rate_limiter',
                    'lock_factory' => 'custom.lock.factory',
                    'enable_retries' => false,
                    'max_retries' => 5,
                    'providers' => [
                        'openai' => [
                            'requests' => [
                                'policy' => 'sliding_window',
                                'limit' => 200,
                                'rate' => [
                                    'interval' => '2 minutes',
                                    'amount' => 200
                                ]
                            ]
                        ]
                    ]
                ],
            ],
        ];
        
        $config = $processor->processConfiguration($configuration, $configs);
        
        self::assertTrue($config['rate_limiting']['enabled']);
        self::assertSame('custom.rate_limiter', $config['rate_limiting']['storage']);
        self::assertSame('custom.lock.factory', $config['rate_limiting']['lock_factory']);
        self::assertFalse($config['rate_limiting']['enable_retries']);
        self::assertSame(5, $config['rate_limiting']['max_retries']);
        
        // Test that provider rate limiting config is preserved
        self::assertArrayHasKey('openai', $config['rate_limiting']['providers']);
        self::assertSame('sliding_window', $config['rate_limiting']['providers']['openai']['requests']['policy']);
        self::assertSame(200, $config['rate_limiting']['providers']['openai']['requests']['limit']);
    }

    public function testNormalizationAppliesToProviders(): void
    {
        $processor = new Processor();
        /** @var Extension $extension */
        $extension = $this->bundle->getContainerExtension();
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        
        // Test that providing minimal config gets normalized with defaults
        $configs = [
            [
                'providers' => [
                    'openai' => [
                        'api_key' => 'test_key',
                        // No organization, default_model, or timeout - should get defaults
                    ],
                ],
            ],
        ];
        
        $config = $processor->processConfiguration($configuration, $configs);
        
        // Should include defaults from getProviderDefaults()
        self::assertArrayHasKey('organization', $config['providers']['openai']);
        self::assertArrayHasKey('default_model', $config['providers']['openai']);
        self::assertArrayHasKey('timeout', $config['providers']['openai']);
        self::assertSame('test_key', $config['providers']['openai']['api_key']);
        self::assertSame('%env(OPENAI_ORGANIZATION)%', $config['providers']['openai']['organization']); // Default env var
        self::assertSame('gpt-4o-mini', $config['providers']['openai']['default_model']);
    }

    public function testNormalizationAppliesToRateLimitProviders(): void
    {
        $processor = new Processor();
        /** @var Extension $extension */
        $extension = $this->bundle->getContainerExtension();
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        
        // Test that providing minimal rate limiting config gets normalized with defaults
        $configs = [
            [
                'rate_limiting' => [
                    'enabled' => true,
                    'providers' => [
                        'openai' => [
                            'requests' => [
                                'limit' => 500, // Override limit but keep other defaults
                            ]
                        ]
                    ]
                ],
            ],
        ];
        
        $config = $processor->processConfiguration($configuration, $configs);
        
        // Should include defaults from getRateLimitDefaults() but with our override
        self::assertSame(500, $config['rate_limiting']['providers']['openai']['requests']['limit']);
        self::assertSame('token_bucket', $config['rate_limiting']['providers']['openai']['requests']['policy']); // Default
        self::assertIsArray($config['rate_limiting']['providers']['openai']['requests']['rate']);
        self::assertSame('1 minute', $config['rate_limiting']['providers']['openai']['requests']['rate']['interval']); // Default
    }
}