<?php

declare(strict_types = 1);

namespace Lingoda\AiBundle\RateLimit;

use Lingoda\AiSdk\ModelInterface;
use Lingoda\AiSdk\RateLimit\ExternalRateLimiterInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Webmozart\Assert\Assert;

/**
 * Provides the rate limiter factories configured under lingoda_ai.rate_limiting.providers,
 * from a service locator that holds only those factories.
 */
final readonly class BundleExternalRateLimiter implements ExternalRateLimiterInterface
{
    /**
     * @param array<string, array<string, string>> $rateLimiterServiceMap
     */
    public function __construct(
        private ContainerInterface $container,
        private array $rateLimiterServiceMap = [],
    ) {
    }

    /**
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    public function getRateLimiter(string $providerId, string $type, ModelInterface $model): RateLimiterFactoryInterface
    {
        $serviceId = $this->getServiceId($providerId, $type);

        if ($serviceId !== null && $this->container->has($serviceId)) {
            $factory = $this->container->get($serviceId);
            Assert::isInstanceOf($factory, RateLimiterFactory::class);

            return $factory;
        }

        throw new \RuntimeException(sprintf(
            'No rate limiter configured for provider "%s" and type "%s"',
            $providerId,
            $type
        ));
    }

    public function hasRateLimiter(string $providerId, string $type): bool
    {
        $serviceId = $this->getServiceId($providerId, $type);

        return $serviceId !== null && $this->container->has($serviceId);
    }

    public function getRateLimiterKey(string $providerId, string $type, ModelInterface $model): string
    {
        // Use provider and model for more granular rate limiting if needed
        return sprintf('%s_%s_%s', $providerId, $type, $model->getId());
    }

    /**
     * Only limiters configured under lingoda_ai.rate_limiting.providers are mapped.
     */
    private function getServiceId(string $providerId, string $type): ?string
    {
        return $this->rateLimiterServiceMap[$providerId][$type] ?? null;
    }
}
