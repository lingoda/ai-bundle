<?php

declare(strict_types = 1);

namespace Lingoda\AiBundle\Tests\Integration;

use Lingoda\AiBundle\LingodaAiBundle;
use Lingoda\AiSdk\PlatformInterface;
use Nyholm\BundleTest\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Regression test for providers.<name>.default_model being ignored when rate limiting is disabled.
 *
 * With rate limiting disabled the provider client is registered as an alias of the base client,
 * with rate limiting enabled it is a RateLimitedClient definition. The configured default model
 * must be applied to the platform in both cases.
 */
final class DefaultModelConfigurationTest extends KernelTestCase
{
    private const string CONFIGURED_DEFAULT_MODEL = 'gemini-3.1-flash-lite';

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): TestKernel
    {
        $kernel = parent::createKernel($options);
        \assert($kernel instanceof TestKernel);

        $kernel->addTestBundle(LingodaAiBundle::class);
        $kernel->handleOptions($options);

        return $kernel;
    }

    public function testConfiguredDefaultModelIsAppliedWhenRateLimitingIsDisabled(): void
    {
        $platform = $this->bootPlatform(__DIR__ . '/config/default_model_test.yaml');

        self::assertSame(self::CONFIGURED_DEFAULT_MODEL, $platform->resolveModel(null)->getId());
    }

    public function testConfiguredDefaultModelIsAppliedWhenRateLimitingIsEnabled(): void
    {
        $platform = $this->bootPlatform(__DIR__ . '/config/default_model_rate_limited_test.yaml');

        self::assertSame(self::CONFIGURED_DEFAULT_MODEL, $platform->resolveModel(null)->getId());
    }

    private function bootPlatform(string $configFile): PlatformInterface
    {
        self::bootKernel([
            'config' => static function (TestKernel $kernel) use ($configFile): void {
                $kernel->addTestConfig($configFile);
            },
        ]);

        $platform = self::getContainer()->get('lingoda_ai.platform');
        self::assertInstanceOf(PlatformInterface::class, $platform);

        return $platform;
    }
}
