<?php

declare(strict_types=1);

namespace Lingoda\AiBundle\Tests\Integration;

use Lingoda\AiBundle\LingodaAiBundle;
use Lingoda\AiSdk\Platform;
use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractExtensionTestCase;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Lingoda\AiBundle\Command\AiTestConnectionCommand;

final class ServiceRegistrationTest extends AbstractExtensionTestCase
{
    protected function getContainerExtensions(): array
    {
        $bundle = new LingodaAiBundle();

        return [$bundle->getContainerExtension()];
    }

    protected function setUp(): void
    {
        parent::setUp();
        
        // Set required kernel parameters for AbstractBundle
        $this->container->setParameter('kernel.environment', 'test');
        $this->container->setParameter('kernel.debug', true);
        $this->container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $this->container->setParameter('kernel.cache_dir', sys_get_temp_dir());
        $this->container->setParameter('kernel.project_dir', dirname(__DIR__, 2));
    }

    /**
     * @return array<string, mixed>
     */
    private function getFullTestConfiguration(): array
    {
        return [
            'default_provider' => 'openai',
            'providers' => [
                'openai' => [
                    'api_key' => 'test_openai_key',
                    'organization' => 'test_org',
                    'default_model' => 'gpt-4o-mini',
                    'timeout' => 30,
                ],
                'anthropic' => [
                    'api_key' => 'test_anthropic_key',
                    'default_model' => 'claude-3-5-haiku-20241022',
                    'timeout' => 30,
                ],
                'gemini' => [
                    'api_key' => 'test_gemini_key',
                    'default_model' => 'gemini-2.5-flash-002',
                    'timeout' => 30,
                ],
            ],
            'sanitization' => [
                'enabled' => true,
                'patterns' => ['/test_\d+/', '/sensitive-\w+/'],
            ],
            'logging' => [
                'enabled' => true,
                'service' => 'logger',
            ],
            'rate_limiting' => [
                'enabled' => true, // Bundle enables rate limiting by default
                'storage' => 'cache.rate_limiter',
                'lock_factory' => 'lock.factory',
                'enable_retries' => true,
                'max_retries' => 10,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getPartialConfiguration(): array
    {
        return [
            'providers' => [
                'openai' => [
                    'api_key' => 'test_openai_key',
                ],
                'anthropic' => [
                    'api_key' => '', // Empty - should be disabled
                ],
                'gemini' => [
                    'api_key' => '', // Empty - should be disabled
                    'default_model' => 'gemini-2.5-flash-002',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getEnvironmentConfiguration(): array
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

    // HTTP client is now created internally by client factories, no longer registered as a service

    public function testPlatformServiceConfiguration(): void
    {
        $this->load($this->getFullTestConfiguration());

        $platformDefinition = $this->container->getDefinition('lingoda_ai.platform');
        
        // Check that the Platform service has the correct arguments
        $arguments = $platformDefinition->getArguments();
        
        self::assertCount(5, $arguments);
        
        // First argument should be array of client references
        self::assertIsArray($arguments[0]);
        self::assertNotEmpty($arguments[0]);
        
        // Second argument should be sanitization enabled (true)
        self::assertTrue($arguments[1]);
        
        // Third argument should be null (DataSanitizer created internally)
        self::assertNull($arguments[2]);
        
        // Fourth argument should be logger reference
        self::assertInstanceOf(Reference::class, $arguments[3]);
        
        // Fifth argument should be default provider
        self::assertSame('openai', $arguments[4]);
    }

    public function testPlatformServiceWithDisabledSanitization(): void
    {
        $config = $this->getFullTestConfiguration();
        $config['sanitization']['enabled'] = false;
        
        $this->load($config);

        $platformDefinition = $this->container->getDefinition('lingoda_ai.platform');
        $arguments = $platformDefinition->getArguments();
        
        // Second argument should be sanitization enabled (false)
        self::assertFalse($arguments[1]);
    }

    public function testPlatformServiceWithDisabledLogging(): void
    {
        $config = $this->getFullTestConfiguration();
        $config['logging']['enabled'] = false;
        
        $this->load($config);

        $platformDefinition = $this->container->getDefinition('lingoda_ai.platform');
        $arguments = $platformDefinition->getArguments();
        
        // Fourth argument should be null when logging is disabled
        self::assertNull($arguments[3]);
    }

    public function testServiceTagging(): void
    {
        $this->load($this->getFullTestConfiguration());

        // Check client tags (rate limited when enabled by default)
        $this->assertContainerBuilderHasServiceDefinitionWithTag(
            'lingoda_ai.client.openai',
            'ai.client',
            ['provider' => 'openai', 'rate_limited' => true]
        );
        
        $this->assertContainerBuilderHasServiceDefinitionWithTag(
            'lingoda_ai.client.anthropic',
            'ai.client',
            ['provider' => 'anthropic', 'rate_limited' => true]
        );
        
        $this->assertContainerBuilderHasServiceDefinitionWithTag(
            'lingoda_ai.client.gemini',
            'ai.client',
            ['provider' => 'gemini', 'rate_limited' => true]
        );

        // Check platform tags
        $this->assertContainerBuilderHasServiceDefinitionWithTag(
            'openaiPlatform',
            'ai.platform',
            ['provider' => 'openai']
        );
        
        $this->assertContainerBuilderHasServiceDefinitionWithTag(
            'anthropicPlatform',
            'ai.platform',
            ['provider' => 'anthropic']
        );
        
        $this->assertContainerBuilderHasServiceDefinitionWithTag(
            'geminiPlatform',
            'ai.platform',
            ['provider' => 'gemini']
        );

        // Check main platform tag
        $this->assertContainerBuilderHasServiceDefinitionWithTag(
            Platform::class,
            'ai.platform',
            ['provider' => 'main', 'multi_provider' => true]
        );
    }

    public function testClientServiceArguments(): void
    {
        $this->load($this->getFullTestConfiguration());

        // When rate limiting is enabled (default), clients are wrapped in RateLimitedClient
        // Test OpenAI rate-limited client arguments: uses named arguments
        $openaiDefinition = $this->container->findDefinition('lingoda_ai.client.openai');
        $openaiArguments = $openaiDefinition->getArguments();
        
        // Should have named arguments: $client, $rateLimiter, $estimatorRegistry, $enableRetries, $maxRetries, $logger
        self::assertArrayHasKey('$client', $openaiArguments);
        self::assertArrayHasKey('$rateLimiter', $openaiArguments);
        self::assertArrayHasKey('$estimatorRegistry', $openaiArguments);
        self::assertArrayHasKey('$enableRetries', $openaiArguments);
        self::assertArrayHasKey('$maxRetries', $openaiArguments);
        self::assertArrayHasKey('$logger', $openaiArguments);
        
        self::assertInstanceOf(Reference::class, $openaiArguments['$client']); // base client reference
        self::assertInstanceOf(Reference::class, $openaiArguments['$rateLimiter']); // rate limiter reference
        self::assertInstanceOf(Reference::class, $openaiArguments['$estimatorRegistry']); // token estimator reference
        self::assertInstanceOf(Reference::class, $openaiArguments['$logger']); // logger reference
        self::assertTrue($openaiArguments['$enableRetries']); // enable retries
        self::assertSame(10, $openaiArguments['$maxRetries']); // max retries

        // Test that the base client has the correct API key arguments
        $baseOpenaiDefinition = $this->container->findDefinition('lingoda_ai.client.openai.base');
        $baseOpenaiArguments = $baseOpenaiDefinition->getArguments();
        
        self::assertSame('test_openai_key', $baseOpenaiArguments['$apiKey']);
        self::assertSame('test_org', $baseOpenaiArguments['$organization']);
        self::assertSame(30, $baseOpenaiArguments['$timeout']);
        self::assertInstanceOf(Reference::class, $baseOpenaiArguments['$logger']);

        // Test Anthropic rate-limited client arguments
        $anthropicDefinition = $this->container->findDefinition('lingoda_ai.client.anthropic');
        $anthropicArguments = $anthropicDefinition->getArguments();
        
        self::assertArrayHasKey('$client', $anthropicArguments);
        self::assertInstanceOf(Reference::class, $anthropicArguments['$client']); // base client reference
        
        // Test that the base client has the correct API key arguments
        $baseAnthropicDefinition = $this->container->findDefinition('lingoda_ai.client.anthropic.base');
        $baseAnthropicArguments = $baseAnthropicDefinition->getArguments();
        
        self::assertSame('test_anthropic_key', $baseAnthropicArguments['$apiKey']);
        self::assertSame(30, $baseAnthropicArguments['$timeout']);
        self::assertInstanceOf(Reference::class, $baseAnthropicArguments['$logger']);

        // Test Gemini rate-limited client arguments
        $geminiDefinition = $this->container->findDefinition('lingoda_ai.client.gemini');
        $geminiArguments = $geminiDefinition->getArguments();
        
        self::assertArrayHasKey('$client', $geminiArguments);
        self::assertInstanceOf(Reference::class, $geminiArguments['$client']); // base client reference
        
        // Test that the base client has the correct API key arguments
        $baseGeminiDefinition = $this->container->findDefinition('lingoda_ai.client.gemini.base');
        $baseGeminiArguments = $baseGeminiDefinition->getArguments();
        
        self::assertSame('test_gemini_key', $baseGeminiArguments['$apiKey']);
        self::assertSame(30, $baseGeminiArguments['$timeout']);
        self::assertInstanceOf(Reference::class, $baseGeminiArguments['$logger']);
    }

    public function testOpenAIClientWithoutOrganization(): void
    {
        $config = $this->getFullTestConfiguration();
        $config['providers']['openai']['organization'] = '';
        
        $this->load($config);

        // With rate limiting enabled, check the base client arguments
        $baseOpenaiDefinition = $this->container->findDefinition('lingoda_ai.client.openai.base');
        $baseOpenaiArguments = $baseOpenaiDefinition->getArguments();
        
        // Should have 3 arguments when organization is not provided: $apiKey, $timeout, $logger
        self::assertCount(3, $baseOpenaiArguments);
        self::assertSame('test_openai_key', $baseOpenaiArguments['$apiKey']);
        self::assertSame(30, $baseOpenaiArguments['$timeout']);
        self::assertInstanceOf(Reference::class, $baseOpenaiArguments['$logger']);
    }

    public function testPartialProviderRegistration(): void
    {
        $this->load($this->getPartialConfiguration());

        // Only OpenAI should be registered
        $this->assertContainerBuilderHasService('lingoda_ai.client.openai');
        $this->assertContainerBuilderNotHasService('lingoda_ai.client.anthropic');
        $this->assertContainerBuilderNotHasService('lingoda_ai.client.gemini');

        // Platform should still be created with available clients
        $this->assertContainerBuilderHasService(Platform::class);
        
        $platformDefinition = $this->container->getDefinition('lingoda_ai.platform');
        $clientsArgument = $platformDefinition->getArguments()[0];
        
        // Should have only one client reference
        self::assertIsArray($clientsArgument);
        self::assertCount(1, $clientsArgument);
    }

    public function testNoProvidersConfiguration(): void
    {
        $this->load(['providers' => []]);

        // No clients should be registered
        $this->assertContainerBuilderNotHasService('lingoda_ai.client.openai');
        $this->assertContainerBuilderNotHasService('lingoda_ai.client.anthropic');
        $this->assertContainerBuilderNotHasService('lingoda_ai.client.gemini');

        // Platform and commands should not be registered when no clients exist
        $this->assertContainerBuilderNotHasService(Platform::class);
        $this->assertContainerBuilderNotHasService(AiTestConnectionCommand::class);
    }

    public function testEnvironmentVariableConfiguration(): void
    {
        $this->load($this->getEnvironmentConfiguration());

        // Check the base client arguments since rate limiting is enabled by default
        $baseOpenaiDefinition = $this->container->findDefinition('lingoda_ai.client.openai.base');
        $baseOpenaiArguments = $baseOpenaiDefinition->getArguments();
        
        // Environment variables should be passed as-is to the base client
        self::assertSame('%env(OPENAI_API_KEY)%', $baseOpenaiArguments['$apiKey']);
        self::assertSame('%env(OPENAI_ORGANIZATION)%', $baseOpenaiArguments['$organization']);
    }

    public function testRateLimitingRetryConfiguration(): void
    {
        $config = $this->getFullTestConfiguration();
        $config['rate_limiting']['enable_retries'] = false;
        $config['rate_limiting']['max_retries'] = 5;
        
        $this->load($config);

        // Test that the base client has the correct retry arguments
        $baseOpenaiDefinition = $this->container->findDefinition('lingoda_ai.client.openai.base');
        $baseOpenaiArguments = $baseOpenaiDefinition->getArguments();
        
        // The rate-limited client wrapper should have the retry parameters
        $openaiDefinition = $this->container->findDefinition('lingoda_ai.client.openai');
        $openaiArguments = $openaiDefinition->getArguments();
        
        // Should have named arguments with custom retry configuration
        self::assertArrayHasKey('$enableRetries', $openaiArguments);
        self::assertArrayHasKey('$maxRetries', $openaiArguments);
        self::assertFalse($openaiArguments['$enableRetries']); // enable_retries
        self::assertSame(5, $openaiArguments['$maxRetries']); // max_retries
    }

    public function testRateLimitingDisabledConfiguration(): void
    {
        $config = $this->getFullTestConfiguration();
        $config['rate_limiting']['enabled'] = false;
        
        $this->load($config);

        // When rate limiting is disabled, clients should not be wrapped in RateLimitedClient
        $openaiDefinition = $this->container->findDefinition('lingoda_ai.client.openai');
        
        // Should be the base client class, not RateLimitedClient
        self::assertStringContainsString('OpenAIClient', $openaiDefinition->getClass());
        
        // Should have base client arguments only: $apiKey, $organization, $timeout, $logger
        $openaiArguments = $openaiDefinition->getArguments();
        self::assertCount(4, $openaiArguments);
        self::assertSame('test_openai_key', $openaiArguments['$apiKey']);
        self::assertSame('test_org', $openaiArguments['$organization']);
        self::assertSame(30, $openaiArguments['$timeout']);
        self::assertInstanceOf(Reference::class, $openaiArguments['$logger']);
    }

    public function testConfigurationParameterStorage(): void
    {
        $config = $this->getFullTestConfiguration();
        $this->load($config);

        $this->assertContainerBuilderHasParameter('lingoda_ai.config');
        
        $storedConfig = $this->container->getParameter('lingoda_ai.config');
        
        // Check essential configuration values are preserved (configuration gets normalized)
        self::assertSame($config['default_provider'], $storedConfig['default_provider']);
        self::assertSame($config['sanitization'], $storedConfig['sanitization']);
        self::assertSame($config['logging'], $storedConfig['logging']);
        
        // Check providers are preserved with their essential values
        self::assertArrayHasKey('openai', $storedConfig['providers']);
        self::assertSame('test_openai_key', $storedConfig['providers']['openai']['api_key']);
        self::assertSame('test_org', $storedConfig['providers']['openai']['organization']);
        self::assertSame('gpt-4o-mini', $storedConfig['providers']['openai']['default_model']);
        
        self::assertArrayHasKey('anthropic', $storedConfig['providers']);
        self::assertSame('test_anthropic_key', $storedConfig['providers']['anthropic']['api_key']);
        
        // Check rate limiting configuration is preserved
        self::assertSame($config['rate_limiting']['enabled'], $storedConfig['rate_limiting']['enabled']);
        self::assertSame($config['rate_limiting']['storage'], $storedConfig['rate_limiting']['storage']);
        self::assertSame($config['rate_limiting']['enable_retries'], $storedConfig['rate_limiting']['enable_retries']);
        self::assertSame($config['rate_limiting']['max_retries'], $storedConfig['rate_limiting']['max_retries']);
    }
}