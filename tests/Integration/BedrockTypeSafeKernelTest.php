<?php

declare(strict_types = 1);

namespace Lingoda\AiBundle\Tests\Integration;

use Lingoda\AiBundle\LingodaAiBundle;
use Lingoda\AiBundle\RateLimit\BundleExternalRateLimiter;
use Lingoda\AiSdk\Client\TypeSafe\TypeSafeDecisionPlatform;
use Lingoda\AiSdk\Enum\AIProvider;
use Lingoda\AiSdk\Enum\Bedrock\ChatModel;
use Lingoda\AiSdk\Platform;
use Lingoda\AiSdk\PlatformInterface;
use Lingoda\AiSdk\RateLimit\RateLimitedClient;
use Lingoda\AiSdk\Security\DataSanitizer;
use Nyholm\BundleTest\TestKernel;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Boots a real kernel with Bedrock and TypeSafe configured. No request leaves the process.
 */
#[Group('bedrock')]
final class BedrockTypeSafeKernelTest extends KernelTestCase
{
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
        $kernel->addTestConfig(__DIR__ . '/config/bedrock_typesafe_test.yaml');
        $kernel->handleOptions($options);

        return $kernel;
    }

    public function testBedrockIsTheDefaultProviderWithItsConfiguredModel(): void
    {
        self::bootKernel();

        $platform = self::getContainer()->get(PlatformInterface::class);
        self::assertInstanceOf(PlatformInterface::class, $platform);

        $model = $platform->resolveModel(null);
        self::assertSame(ChatModel::CLAUDE_HAIKU_45->value, $model->getId());
        self::assertTrue($model->getProvider()->is(AIProvider::BEDROCK));
        self::assertInstanceOf(RateLimitedClient::class, self::getContainer()->get('lingoda_ai.client.bedrock'));
    }

    public function testTypeSafeDecisionPlatformIsBuilt(): void
    {
        self::bootKernel();

        $decisions = self::getContainer()->get('lingoda_ai.decision_platform.typesafe');

        self::assertInstanceOf(TypeSafeDecisionPlatform::class, $decisions);
        self::assertTrue($decisions->getProvider()->is(AIProvider::TYPESAFE));
    }

    public function testExternalRateLimiterResolvesConfiguredLimitersThroughTheLocator(): void
    {
        self::bootKernel();

        $external = self::getContainer()->get('lingoda_ai.external_rate_limiter');
        self::assertInstanceOf(BundleExternalRateLimiter::class, $external);
        $model = self::getContainer()->get(PlatformInterface::class)->resolveModel(null);

        self::assertTrue($external->hasRateLimiter('bedrock', 'tokens'));
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $external->getRateLimiter('bedrock', 'tokens', $model));
        self::assertFalse($external->hasRateLimiter('openai', 'tokens'));
    }

    public function testConfiguredSanitizationPatternIsRedactedNextToTheDefaults(): void
    {
        self::bootKernel();

        $platform = self::getContainer()->get('lingoda_ai.platform');
        self::assertInstanceOf(Platform::class, $platform);
        $sanitizer = (new \ReflectionProperty(Platform::class, 'sanitizer'))->getValue($platform);
        self::assertInstanceOf(DataSanitizer::class, $sanitizer);

        self::assertSame(
            'Code [REDACTED] for [REDACTED_EMAIL]',
            $sanitizer->sanitize('Code voucher-4711 for jane@example.com')
        );
    }
}
