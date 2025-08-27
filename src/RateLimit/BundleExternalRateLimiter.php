<?php

declare(strict_types=1);

namespace Lingoda\AiBundle\RateLimit;

use Lingoda\AiSdk\ModelInterface;
use Lingoda\AiSdk\RateLimit\ExternalRateLimiterInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Bundle implementation of external rate limiter that provides rate limiters
 * configured via Symfony's rate_limiter configuration.
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
    public function getRateLimiter(string $providerId, string $type, ModelInterface $model): RateLimiterFactory
    {
        $serviceId = $this->getServiceId($providerId, $type);
        
        if ($this->container->has($serviceId)) {
            /** @var RateLimiterFactory $factory */
            $factory = $this->container->get($serviceId);

            return $factory;
        }
        
        throw new \RuntimeException(sprintf(
            'Rate limiter service "%s" not found for provider "%s" and type "%s"',
            $serviceId,
            $providerId,
            $type
        ));
    }

    public function hasRateLimiter(string $providerId, string $type): bool
    {
        $serviceId = $this->getServiceId($providerId, $type);

        return $this->container->has($serviceId);
    }

    public function getRateLimiterKey(string $providerId, string $type, ModelInterface $model): string
    {
        // Use provider and model for more granular rate limiting if needed
        return sprintf('%s_%s_%s', $providerId, $type, $model->getId());
    }

    private function getServiceId(string $providerId, string $type): string
    {
        // Check if there's a custom mapping
        return $this->rateLimiterServiceMap[$providerId][$type] ?? sprintf('limiter.%s_%s', $providerId, $type);
    }
}