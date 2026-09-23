<?php

declare(strict_types = 1);

namespace Lingoda\AiBundle\Tests\Integration;

use Lingoda\AiBundle\Command\AiTestConnectionCommand;
use Lingoda\AiBundle\LingodaAiBundle;
use Lingoda\AiBundle\Platform\ProviderPlatform;
use Lingoda\AiSdk\Client\Bedrock\BedrockClient;
use Lingoda\AiSdk\Client\Bedrock\BedrockClientFactory;
use Lingoda\AiSdk\Client\TypeSafe\TypeSafeDecisionPlatform;
use Lingoda\AiSdk\Decision\DecisionPlatformInterface;
use Lingoda\AiSdk\Platform;
use Lingoda\AiSdk\PlatformInterface;
use Lingoda\AiSdk\RateLimit\RateLimitedClient;
use Lingoda\AiSdk\Security\DataSanitizer;
use Lingoda\AiSdk\Security\Pattern\PatternRegistry;
use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractExtensionTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\HttpClient;

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

        // Third argument carries sanitization.patterns next to the SDK defaults
        self::assertInstanceOf(Definition::class, $arguments[2]);
        self::assertSame(DataSanitizer::class, $arguments[2]->getClass());
        $registry = $arguments[2]->getArgument('$filter')->getArgument('$patternRegistry');
        self::assertSame(PatternRegistry::class, $registry->getClass());
        self::assertSame(['/test_\d+/', '/sensitive-\w+/'], $registry->getArgument(2));

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

    public function testDefaultModelIsConfiguredWhenRateLimitingIsDisabled(): void
    {
        $config = $this->getFullTestConfiguration();
        $config['rate_limiting']['enabled'] = false;
        $config['providers']['gemini']['default_model'] = 'gemini-2.5-pro';

        $this->load($config);

        $this->assertContainerBuilderHasAlias('lingoda_ai.client.gemini', 'lingoda_ai.client.gemini.base');

        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall(
            'lingoda_ai.platform',
            'configureProviderDefaultModel',
            ['openai', 'gpt-4o-mini']
        );
        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall(
            'lingoda_ai.platform',
            'configureProviderDefaultModel',
            ['anthropic', 'claude-3-5-haiku-20241022']
        );
        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall(
            'lingoda_ai.platform',
            'configureProviderDefaultModel',
            ['gemini', 'gemini-2.5-pro']
        );
    }

    public function testDefaultModelIsConfiguredWhenRateLimitingIsEnabled(): void
    {
        $config = $this->getFullTestConfiguration();
        $config['providers']['gemini']['default_model'] = 'gemini-2.5-pro';

        $this->load($config);

        $this->assertContainerBuilderHasService('lingoda_ai.client.gemini', RateLimitedClient::class);

        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall(
            'lingoda_ai.platform',
            'configureProviderDefaultModel',
            ['gemini', 'gemini-2.5-pro']
        );
    }

    #[Group('bedrock')]
    public function testBedrockIsRegisteredBehindTheRateLimiterWithoutTransportRetries(): void
    {
        $config = $this->getFullTestConfiguration();
        $config['providers']['bedrock'] = ['runtime_client' => 'app.bedrock_runtime', 'default_model' => 'amazon.nova-2-lite-v1:0'];

        $this->load($config);

        $base = $this->container->getDefinition('lingoda_ai.client.bedrock.base');
        self::assertSame(BedrockClient::class, $base->getClass());
        self::assertSame([BedrockClientFactory::class, 'createClient'], $base->getFactory());
        self::assertEquals(new Reference('app.bedrock_runtime'), $base->getArgument('$runtimeClient'));

        $this->assertContainerBuilderHasService('lingoda_ai.client.bedrock', RateLimitedClient::class);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument('lingoda_ai.client.bedrock', '$retryTransportErrors', false);
        $this->assertContainerBuilderHasServiceDefinitionWithArgument('lingoda_ai.client.openai', '$retryTransportErrors', true);

        $this->assertContainerBuilderHasService('bedrockPlatform', ProviderPlatform::class);
        $this->assertContainerBuilderHasAlias(PlatformInterface::class . ' $bedrockPlatform', 'bedrockPlatform');
        self::assertContains('lingoda_ai.client.bedrock', array_map('strval', $this->container->getDefinition('lingoda_ai.platform')->getArgument(0)));
        $this->assertContainerBuilderHasServiceDefinitionWithMethodCall(
            'lingoda_ai.platform',
            'configureProviderDefaultModel',
            ['bedrock', 'amazon.nova-2-lite-v1:0']
        );
    }

    #[Group('bedrock')]
    public function testBedrockClientIsAnAliasWithoutRateLimiting(): void
    {
        $config = $this->getFullTestConfiguration();
        $config['rate_limiting']['enabled'] = false;
        $config['logging']['enabled'] = false;
        $config['providers']['bedrock'] = ['runtime_client' => 'app.bedrock_runtime'];

        $this->load($config);

        $this->assertContainerBuilderHasAlias('lingoda_ai.client.bedrock', 'lingoda_ai.client.bedrock.base');
        self::assertSame(['$runtimeClient'], array_keys($this->container->getDefinition('lingoda_ai.client.bedrock.base')->getArguments()));
    }

    public function testBedrockAndTypeSafeAreNotRegisteredUnlessConfigured(): void
    {
        $this->load($this->getFullTestConfiguration());

        $this->assertContainerBuilderNotHasService('lingoda_ai.client.bedrock');
        $this->assertContainerBuilderNotHasService('bedrockPlatform');
        $this->assertContainerBuilderNotHasService('lingoda_ai.decision_platform.typesafe');
        $this->assertContainerBuilderNotHasService(DecisionPlatformInterface::class);
    }

    public function testTypeSafeIsRegisteredAsADecisionPlatformOnly(): void
    {
        $config = $this->getFullTestConfiguration();
        $config['providers']['typesafe'] = ['api_key' => 'ts-key', 'timeout' => 12];

        $this->load($config);

        $definition = $this->container->getDefinition('lingoda_ai.decision_platform.typesafe');
        self::assertSame(TypeSafeDecisionPlatform::class, $definition->getClass());
        self::assertSame('ts-key', $definition->getArgument('$apiKey'));
        self::assertSame('jev-latest', $definition->getArgument('$defaultModel'));
        self::assertArrayNotHasKey('$baseUrl', $definition->getArguments());
        self::assertEquals(new Reference('logger'), $definition->getArgument('$logger'));

        $httpClient = $definition->getArgument('$httpClient');
        self::assertInstanceOf(Definition::class, $httpClient);
        self::assertSame([HttpClient::class, 'create'], $httpClient->getFactory());
        self::assertSame([['timeout' => 12]], $httpClient->getArguments());

        $this->assertContainerBuilderHasAlias(DecisionPlatformInterface::class, 'lingoda_ai.decision_platform.typesafe');
        $this->assertContainerBuilderNotHasService('lingoda_ai.client.typesafe');
        $this->assertContainerBuilderNotHasService('typesafePlatform');
        self::assertNotContains('lingoda_ai.client.typesafe', array_map('strval', $this->container->getDefinition('lingoda_ai.platform')->getArgument(0)));
    }

    public function testTypeSafeUsesTheConfiguredHttpClientAndModel(): void
    {
        $config = $this->getFullTestConfiguration();
        $config['logging']['enabled'] = false;
        $config['providers']['typesafe'] = ['api_key' => 'ts-key', 'http_client' => 'app.http', 'default_model' => 'jev-1.13.0'];

        $this->load($config);

        $definition = $this->container->getDefinition('lingoda_ai.decision_platform.typesafe');
        self::assertEquals(new Reference('app.http'), $definition->getArgument('$httpClient'));
        self::assertSame('jev-1.13.0', $definition->getArgument('$defaultModel'));
        self::assertArrayNotHasKey('$logger', $definition->getArguments());
    }

    public function testTypeSafeWithoutApiKeyIsNotRegistered(): void
    {
        $config = $this->getFullTestConfiguration();
        $config['providers']['typesafe'] = ['api_key' => ''];

        $this->load($config);

        $this->assertContainerBuilderNotHasService('lingoda_ai.decision_platform.typesafe');
    }

    public function testNoDefaultPlatformAlias(): void
    {
        $this->load($this->getFullTestConfiguration());

        self::assertFalse($this->container->has('lingoda_ai.default_platform'));
    }

    public function testExternalRateLimiterGetsALocatorOfPrivateLimiterFactories(): void
    {
        $config = $this->getFullTestConfiguration();
        $config['rate_limiting']['providers'] = ['openai' => ['requests' => ['limit' => 10, 'rate' => ['interval' => '1 minute', 'amount' => 10]]]];

        $this->load($config);

        $locator = $this->container->getDefinition('lingoda_ai.external_rate_limiter')->getArgument(0);
        self::assertInstanceOf(Reference::class, $locator);
        self::assertSame(['openai' => ['requests' => 'lingoda_ai.rate_limiter.openai_requests']], $this->container->getDefinition('lingoda_ai.external_rate_limiter')->getArgument(1));
        self::assertFalse($this->container->getDefinition('lingoda_ai.rate_limiter.openai_requests')->isPublic());
        self::assertTrue($this->container->getAlias('limiter.openai_requests')->isPublic());
    }

    public function testPlatformBuildsItsDefaultSanitizerWithoutPatterns(): void
    {
        $config = $this->getFullTestConfiguration();
        $config['sanitization']['patterns'] = [];

        $this->load($config);

        self::assertNull($this->container->getDefinition('lingoda_ai.platform')->getArgument(2));
    }
}
