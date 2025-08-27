<?php

declare(strict_types=1);

namespace Lingoda\AiBundle\Tests\Integration;

use Lingoda\AiBundle\Tests\Config\TestConfiguration;
use Lingoda\AiSdk\PlatformInterface;
use Lingoda\AiSdk\RateLimit\RateLimitedClient;
use Nyholm\BundleTest\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Webmozart\Assert\Assert;

final class RateLimitingIntegrationTest extends KernelTestCase
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

        $kernel->addTestBundle(\Lingoda\AiBundle\LingodaAiBundle::class);
        $kernel->addTestConfig(__DIR__ . '/config/rate_limiting_test.yaml');

        return $kernel;
    }

    public function testRateLimitingDisabledByDefault(): void
    {
        $kernel = self::createKernel();
        $kernel->handleOptions(['config' => static function (TestKernel $kernel): void {
            $kernel->addTestConfig(__DIR__ . '/config/basic_test.yaml');
        }]);
        
        $kernel->boot();
        $container = $kernel->getContainer();

        // Should not have external rate limiter service
        $this->assertFalse($container->has('lingoda_ai.external_rate_limiter'));
        
        // Platform should still work without rate limiting (if providers are configured)
        if ($container->has(PlatformInterface::class)) {
            $platform = $container->get(PlatformInterface::class);
            $this->assertInstanceOf(PlatformInterface::class, $platform);
        } else {
            // If no providers are configured, skip platform test
            $this->assertTrue(true, 'No providers configured, platform not available');
        }
    }

    public function testRateLimitingEnabledWithConfiguration(): void
    {
        $kernel = self::createKernel();
        $kernel->boot();
        $container = $kernel->getContainer();

        // Should have external rate limiter service
        $this->assertTrue($container->has('lingoda_ai.external_rate_limiter'));
        
        // Should have rate limiter factories for configured providers
        $this->assertTrue($container->has('limiter.openai_requests'));
        $this->assertTrue($container->has('limiter.openai_tokens'));
        $this->assertTrue($container->has('limiter.anthropic_requests'));
        $this->assertTrue($container->has('limiter.anthropic_tokens'));
        
        // Platform should still work with rate limiting (if providers are configured)
        if ($container->has(PlatformInterface::class)) {
            $platform = $container->get(PlatformInterface::class);
            $this->assertInstanceOf(PlatformInterface::class, $platform);
        } else {
            // If no providers are configured, skip platform test
            $this->assertTrue(true, 'No providers configured, platform not available');
        }
    }

    public function testClientWrappedWithRateLimitingWhenEnabled(): void
    {
        $kernel = self::createKernel();
        $kernel->boot();
        /** @var Container $container */
        $container = $kernel->getContainer();

        // Check if external rate limiter service exists
        $this->assertTrue($container->has('lingoda_ai.external_rate_limiter'), 
            'External rate limiter service should exist when rate limiting is enabled');

        // Check for provider-level rate limiter service and token estimator registry
        $this->assertTrue($container->has('lingoda_ai.rate_limiter.openai'), 
            'Provider rate limiter should exist for OpenAI');
        $this->assertTrue($container->has('lingoda_ai.token_estimator_registry.openai'), 
            'Token estimator registry should exist for OpenAI');

        // Debug: Find OpenAI client service
        $allServices = array_filter($container->getServiceIds(), fn($id) => str_contains($id, 'openai') || str_contains($id, 'client'));
        $openaiClientService = null;
        foreach ($allServices as $service) {
            if (str_contains($service, 'openai') && str_contains($service, 'client')) {
                $openaiClientService = $service;
                break;
            }
        }
        
        $this->assertNotNull($openaiClientService, 'OpenAI client service should exist. Available services: ' . implode(', ', $allServices));
        
        $client = $container->get($openaiClientService);
        Assert::object($client);
        $this->assertInstanceOf(RateLimitedClient::class, $client, 'Client should be RateLimitedClient but is: ' . $client::class);
    }

    public function testRateLimiterConfigurationIsApplied(): void
    {
        $kernel = self::createKernel();
        $kernel->boot();
        $container = $kernel->getContainer();

        // Rate limiter factories should exist based on rate_limiting_test.yaml configuration
        $this->assertTrue($container->has('limiter.openai_requests'), 'OpenAI requests rate limiter should be configured');
        $this->assertTrue($container->has('limiter.openai_tokens'), 'OpenAI tokens rate limiter should be configured');
        
        $requestsLimiterFactory = $container->get('limiter.openai_requests');
        $this->assertInstanceOf(RateLimiterFactory::class, $requestsLimiterFactory);
        
        $tokensLimiterFactory = $container->get('limiter.openai_tokens');
        $this->assertInstanceOf(RateLimiterFactory::class, $tokensLimiterFactory);
    }
}