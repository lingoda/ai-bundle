<?php
declare(strict_types=1);

namespace Lingoda\AiBundle\Tests\Integration;

use Lingoda\AiBundle\Command\AiListModelsCommand;
use Lingoda\AiBundle\Command\AiListProvidersCommand;
use Lingoda\AiBundle\Command\AiTestConnectionCommand;
use Lingoda\AiBundle\LingodaAiBundle;
use Lingoda\AiBundle\Platform\ProviderPlatform;
use Lingoda\AiBundle\Tests\Config\TestConfiguration;
use Lingoda\AiSdk\Platform;
use Lingoda\AiSdk\PlatformInterface;
use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractExtensionTestCase;

final class BundleRegistrationTest extends AbstractExtensionTestCase
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
    protected function getMinimalConfiguration(): array
    {
        return [];
    }

    public function testCoreServicesAreRegistered(): void
    {
        $this->load(TestConfiguration::getFullConfiguration());
        
        $this->assertContainerBuilderHasService('lingoda_ai.platform');
        $this->assertContainerBuilderHasService(PlatformInterface::class);
        $this->assertContainerBuilderHasAlias(PlatformInterface::class, 'lingoda_ai.platform');
        $this->assertContainerBuilderHasAlias(Platform::class, 'lingoda_ai.platform');
        // HTTP client is now created internally by client factories, not as a separate service
    }

    public function testProviderClientsAreRegistered(): void
    {
        $this->load(TestConfiguration::getFullConfiguration());
        
        $this->assertContainerBuilderHasService('lingoda_ai.client.openai');
        $this->assertContainerBuilderHasService('lingoda_ai.client.anthropic');
        $this->assertContainerBuilderHasService('lingoda_ai.client.gemini');
    }

    public function testProviderPlatformsAreRegistered(): void
    {
        $this->load(TestConfiguration::getFullConfiguration());
        
        $this->assertContainerBuilderHasService('openaiPlatform');
        $this->assertContainerBuilderHasService('anthropicPlatform');
        $this->assertContainerBuilderHasService('geminiPlatform');
    }

    public function testConsoleCommandsAreRegistered(): void
    {
        $this->load(TestConfiguration::getFullConfiguration());
        
        $this->assertContainerBuilderHasService(AiTestConnectionCommand::class);
        $this->assertContainerBuilderHasService(AiListProvidersCommand::class);
        $this->assertContainerBuilderHasService(AiListModelsCommand::class);

        $this->assertContainerBuilderHasServiceDefinitionWithTag(
            AiTestConnectionCommand::class,
            'console.command'
        );
        $this->assertContainerBuilderHasServiceDefinitionWithTag(
            AiListProvidersCommand::class,
            'console.command'
        );
        $this->assertContainerBuilderHasServiceDefinitionWithTag(
            AiListModelsCommand::class,
            'console.command'
        );
    }

    public function testConfigurationParametersAreLoaded(): void
    {
        $this->load(TestConfiguration::getFullConfiguration());
        
        $this->assertContainerBuilderHasParameter('lingoda_ai.config');
        
        $config = $this->container->getParameter('lingoda_ai.config');
        self::assertIsArray($config);
        self::assertArrayHasKey('default_provider', $config);
        self::assertArrayHasKey('providers', $config);
        self::assertArrayHasKey('sanitization', $config);
        self::assertArrayHasKey('logging', $config);
    }

    public function testProvidersWithoutApiKeyAreNotRegistered(): void
    {
        // Load configuration where anthropic has empty key and gemini has no key
        $config = [
            'providers' => [
                'openai' => [
                    'api_key' => 'test_openai_key',
                ],
                'anthropic' => [
                    'api_key' => '', // Empty key - should be disabled
                ],
                'gemini' => [
                    // Empty api_key - should be disabled
                    'api_key' => '',
                    'default_model' => 'gemini-2.5-flash-002',
                ],
            ],
        ];
        
        $this->load($config);
        
        // Only OpenAI should be registered since it has a valid API key
        $this->assertContainerBuilderHasService('lingoda_ai.client.openai');
        $this->assertContainerBuilderNotHasService('lingoda_ai.client.anthropic');
        $this->assertContainerBuilderNotHasService('lingoda_ai.client.gemini');
        
        // Platform services should also not be registered for disabled providers
        $this->assertContainerBuilderHasService('openaiPlatform');
        $this->assertContainerBuilderNotHasService('anthropicPlatform');
        $this->assertContainerBuilderNotHasService('geminiPlatform');
    }

    public function testServiceTagsAreApplied(): void
    {
        $this->load(TestConfiguration::getFullConfiguration());

        $this->assertContainerBuilderHasServiceDefinitionWithTag(
            'lingoda_ai.client.openai',
            'ai.client',
            ['provider' => 'openai', 'rate_limited' => true]
        );
        
        $this->assertContainerBuilderHasServiceDefinitionWithTag(
            'openaiPlatform',
            'ai.platform',
            ['provider' => 'openai']
        );
        
        $this->assertContainerBuilderHasServiceDefinitionWithTag(
            'lingoda_ai.platform',
            'ai.platform',
            ['provider' => 'main', 'multi_provider' => true]
        );
    }

    public function testDefaultProviderValidation(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $this->expectExceptionMessage('Invalid default provider "invalid_provider"');
        
        $this->load([
            'default_provider' => 'invalid_provider',
        ]);
    }

    public function testConfigurationWithEnvironmentVariables(): void
    {
        $this->load(TestConfiguration::getEnvironmentConfiguration());
        
        $this->assertContainerBuilderHasService('lingoda_ai.client.openai');
    }
}