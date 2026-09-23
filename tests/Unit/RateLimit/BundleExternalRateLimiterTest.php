<?php

declare(strict_types = 1);

namespace Lingoda\AiBundle\Tests\Unit\RateLimit;

use Lingoda\AiBundle\RateLimit\BundleExternalRateLimiter;
use Lingoda\AiSdk\ModelInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class BundleExternalRateLimiterTest extends TestCase
{
    /** @var ContainerInterface&MockObject */
    private MockObject $container;
    private BundleExternalRateLimiter $rateLimiter;

    protected function setUp(): void
    {
        $this->container = $this->createMock(ContainerInterface::class);
        $this->rateLimiter = new BundleExternalRateLimiter($this->container);
    }

    public function testGetRateLimiterWithCustomServiceMapping(): void
    {
        /** @var RateLimiterFactory&MockObject $factory */
        $factory = $this->createMock(RateLimiterFactory::class);
        /** @var ModelInterface&MockObject $model */
        $model = $this->createMock(ModelInterface::class);

        $serviceMap = [
            'anthropic' => [
                'tokens' => 'custom.anthropic.token.limiter'
            ]
        ];

        $rateLimiter = new BundleExternalRateLimiter($this->container, $serviceMap);

        $this->container
            ->expects(self::once())
            ->method('has')
            ->with('custom.anthropic.token.limiter')
            ->willReturn(true)
        ;

        $this->container
            ->expects(self::once())
            ->method('get')
            ->with('custom.anthropic.token.limiter')
            ->willReturn($factory)
        ;

        $result = $rateLimiter->getRateLimiter('anthropic', 'tokens', $model);

        self::assertSame($factory, $result);
    }

    public function testGetRateLimiterThrowsForAnUnmappedProvider(): void
    {
        /** @var ModelInterface&MockObject $model */
        $model = $this->createMock(ModelInterface::class);

        // An unmapped provider never reaches the container, whatever it holds
        $this->container->expects(self::never())->method('has');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No rate limiter configured for provider "openai" and type "requests"');

        $this->rateLimiter->getRateLimiter('openai', 'requests', $model);
    }

    public function testGetRateLimiterThrowsWhenTheMappedServiceIsMissing(): void
    {
        /** @var ModelInterface&MockObject $model */
        $model = $this->createMock(ModelInterface::class);
        $rateLimiter = new BundleExternalRateLimiter($this->container, ['openai' => ['requests' => 'lingoda_ai.rate_limiter.openai_requests']]);

        $this->container->expects(self::once())->method('has')->with('lingoda_ai.rate_limiter.openai_requests')->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No rate limiter configured for provider "openai" and type "requests"');

        $rateLimiter->getRateLimiter('openai', 'requests', $model);
    }

    public function testHasRateLimiterIsFalseForAnUnmappedProvider(): void
    {
        $this->container->expects(self::never())->method('has');

        self::assertFalse($this->rateLimiter->hasRateLimiter('openai', 'requests'));
    }

    public function testHasRateLimiterIsFalseWhenTheMappedServiceIsMissing(): void
    {
        $rateLimiter = new BundleExternalRateLimiter($this->container, ['openai' => ['requests' => 'lingoda_ai.rate_limiter.openai_requests']]);

        $this->container->expects(self::once())->method('has')->with('lingoda_ai.rate_limiter.openai_requests')->willReturn(false);

        self::assertFalse($rateLimiter->hasRateLimiter('openai', 'requests'));
    }

    public function testHasRateLimiterWithCustomServiceMapping(): void
    {
        $serviceMap = [
            'gemini' => [
                'requests' => 'custom.gemini.request.limiter'
            ]
        ];

        $rateLimiter = new BundleExternalRateLimiter($this->container, $serviceMap);

        $this->container
            ->expects(self::once())
            ->method('has')
            ->with('custom.gemini.request.limiter')
            ->willReturn(true)
        ;

        $result = $rateLimiter->hasRateLimiter('gemini', 'requests');

        self::assertTrue($result);
    }

    public function testGetRateLimiterKeyIncludesProviderTypeAndModel(): void
    {
        /** @var ModelInterface&MockObject $model */
        $model = $this->createMock(ModelInterface::class);
        $model->expects(self::once())->method('getId')->willReturn('gpt-4o-mini');

        $result = $this->rateLimiter->getRateLimiterKey('openai', 'requests', $model);

        self::assertSame('openai_requests_gpt-4o-mini', $result);
    }

    public function testGetRateLimiterKeyWithDifferentProviderAndModel(): void
    {
        /** @var ModelInterface&MockObject $model */
        $model = $this->createMock(ModelInterface::class);
        $model->expects(self::once())->method('getId')->willReturn('claude-3-5-sonnet-20241022');

        $result = $this->rateLimiter->getRateLimiterKey('anthropic', 'tokens', $model);

        self::assertSame('anthropic_tokens_claude-3-5-sonnet-20241022', $result);
    }
}
