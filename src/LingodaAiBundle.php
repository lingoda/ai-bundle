<?php

declare(strict_types = 1);

namespace Lingoda\AiBundle;

use Lingoda\AiBundle\Command\AiListModelsCommand;
use Lingoda\AiBundle\Command\AiListProvidersCommand;
use Lingoda\AiBundle\Command\AiTestConnectionCommand;
use Lingoda\AiBundle\Command\TestRateLimitingCommand;
use Lingoda\AiBundle\Platform\ProviderPlatform;
use Lingoda\AiBundle\RateLimit\BundleExternalRateLimiter;
use Lingoda\AiSdk\Client\Anthropic\AnthropicClient;
use Lingoda\AiSdk\Client\Anthropic\AnthropicClientFactory;
use Lingoda\AiSdk\Client\Gemini\GeminiClient;
use Lingoda\AiSdk\Client\Gemini\GeminiClientFactory;
use Lingoda\AiSdk\Client\OpenAI\OpenAIClient;
use Lingoda\AiSdk\Client\OpenAI\OpenAIClientFactory;
use Lingoda\AiSdk\Enum\AIProvider;
use Lingoda\AiSdk\Enum\Anthropic\ChatModel as AnthropicChatModel;
use Lingoda\AiSdk\Enum\Gemini\ChatModel as GeminiChatModel;
use Lingoda\AiSdk\Enum\OpenAI\ChatModel as OpenAIChatModel;
use Lingoda\AiSdk\Platform;
use Lingoda\AiSdk\PlatformInterface;
use Lingoda\AiSdk\RateLimit\RateLimitedClient;
use Lingoda\AiSdk\RateLimit\SymfonyRateLimiter;
use Lingoda\AiSdk\RateLimit\TokenEstimatorRegistry;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

final class LingodaAiBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $definition->rootNode();

        $supportedProviders = array_map(static fn (AIProvider $provider) => $provider->value, AIProvider::cases());

        $rootNode
            ->children()
                ->scalarNode('default_provider')
                    ->defaultValue(AIProvider::OPENAI->value)
                    ->validate()
                        ->ifNotInArray($supportedProviders)
                        ->thenInvalid('Invalid default provider %s. Must be one of: ' . implode(', ', $supportedProviders))
                    ->end()
                ->end()
                ->arrayNode('providers')
                    ->normalizeKeys(false)
                    ->prototype('array')
                        ->children()
                            ->scalarNode('api_key')->end()
                            ->scalarNode('organization')->end() // OpenAI-specific, optional for others
                            ->scalarNode('default_model')->end()
                            ->scalarNode('http_client')
                                ->info('Custom HTTP client service ID for this provider')
                            ->end()
                            ->integerNode('timeout')
                                ->defaultValue(30)
                                ->info('Request timeout in seconds (only used if no custom http_client is provided)')
                            ->end()
                        ->end()
                    ->end()
                    ->beforeNormalization()
                        ->always(function ($providers) {
                            if (!is_array($providers)) {
                                return $providers;
                            }
                            foreach ($providers as $providerName => $config) {
                                if (!is_array($config)) {
                                    $config = [];
                                }
                                $defaults = $this->getProviderDefaults((string) $providerName);
                                $providers[$providerName] = array_merge($defaults, $config);
                            }
                            return $providers;
                        })
                    ->end()
                ->end()
                ->arrayNode('sanitization')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                        ->end()
                        ->arrayNode('patterns')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('logging')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                        ->end()
                        ->scalarNode('service')
                            ->defaultValue('logger')
                        ->end()
                    ->end()
                ->end()
            ->arrayNode('rate_limiting')
                ->addDefaultsIfNotSet()
                ->children()
                    ->booleanNode('enabled')
                        ->defaultTrue()
                        ->info('Enable Bundle-managed rate limiting with Symfony rate limiter configuration')
                    ->end()
                    ->scalarNode('storage')
                        ->defaultValue('cache.rate_limiter')
                        ->info('Storage service ID for rate limiter state (defaults to cache.rate_limiter)')
                    ->end()
                    ->scalarNode('lock_factory')
                        ->defaultValue('lock.factory')
                        ->info('Lock factory service ID for coordination (defaults to lock.factory)')
                    ->end()
                    ->booleanNode('enable_retries')
                        ->defaultTrue()
                        ->info('Enable automatic retries on rate limit exceptions (set to false for testing)')
                    ->end()
                    ->integerNode('max_retries')
                        ->defaultValue(10)
                        ->info('Maximum number of retry attempts on rate limit exceptions')
                    ->end()
                    ->arrayNode('providers')
                        ->normalizeKeys(false)
                        ->prototype('array')
                            ->prototype('array')
                                ->children()
                                    ->scalarNode('policy')->end()
                                    ->integerNode('limit')->end()
                                    ->arrayNode('rate')
                                        ->children()
                                            ->scalarNode('interval')->end()
                                            ->integerNode('amount')->end()
                                        ->end()
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                        ->beforeNormalization()
                            ->always(function ($providers) {
                                if (!is_array($providers)) {
                                    return $providers;
                                }
                                $result = [];
                                foreach ($providers as $providerName => $config) {
                                    if (!is_array($config) || !is_string($providerName)) {
                                        $result[$providerName] = $config;
                                        continue;
                                    }
                                    $result[$providerName] = [];
                                    foreach ($config as $type => $rateLimitConfig) {
                                        if (!is_string($type) || !is_array($rateLimitConfig)) {
                                            $result[$providerName][$type] = $rateLimitConfig;
                                            continue;
                                        }
                                        $defaults = $this->getRateLimitDefaults($providerName, $type);
                                        $result[$providerName][$type] = array_merge($defaults, $rateLimitConfig);
                                    }
                                }
                                return $result;
                            })
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $clients = [];
        
        // Register logger reference if logging is enabled
        $loggerRef = null;
        if (isset($config['logging']) && is_array($config['logging']) && ($config['logging']['enabled'] ?? false)) {
            $loggerService = !empty($config['logging']['service']) && is_string($config['logging']['service']) 
                ? $config['logging']['service'] 
                : 'logger'; // Default Symfony logger service
            $loggerRef = new Reference($loggerService);
        }
        
        // Register rate limiting services if enabled
        $externalRateLimiterRef = null;
        if (isset($config['rate_limiting']) && is_array($config['rate_limiting']) && ($config['rate_limiting']['enabled'] ?? false)) {
            $externalRateLimiterRef = $this->registerRateLimiting($config['rate_limiting'], $builder);
        }
        
        // Get rate limiting configuration for passing to providers
        $rateLimitingConfig = isset($config['rate_limiting']) && is_array($config['rate_limiting']) ? $config['rate_limiting'] : [];
        
        // Register provider clients and platforms
        $this->registerProvider(AIProvider::OPENAI->value, OpenAIClient::class, $config, $builder, $clients, $loggerRef, $externalRateLimiterRef, $rateLimitingConfig);
        $this->registerProvider(AIProvider::ANTHROPIC->value, AnthropicClient::class, $config, $builder, $clients, $loggerRef, $externalRateLimiterRef, $rateLimitingConfig);
        $this->registerProvider(AIProvider::GEMINI->value, GeminiClient::class, $config, $builder, $clients, $loggerRef, $externalRateLimiterRef, $rateLimitingConfig);
        
        // Main Platform service
        if (!empty($clients)) {
            $sanitizationEnabled = isset($config['sanitization']) && is_array($config['sanitization'])
                ? ($config['sanitization']['enabled'] ?? true)
                : true;
                
            $platformDef = new Definition(Platform::class, [
                $clients,
                $sanitizationEnabled,
                null, // DataSanitizer will be created internally if enabled
                $loggerRef,
                $config['default_provider'] ?? null
            ]);
            $platformDef->addTag('ai.platform', ['provider' => 'main', 'multi_provider' => true]);
            $platformDef->setPublic(true); // Make service public for testing
            
            // Configure default models after platform creation
            $this->addDefaultModelConfiguration($platformDef, $config, $builder);
            
            $builder->setDefinition('lingoda_ai.platform', $platformDef);
            
            // Set up main platform aliases and autowiring
            $builder->setAlias(Platform::class, 'lingoda_ai.platform');
            $builder->setAlias(PlatformInterface::class, 'lingoda_ai.platform');
            
            // If there's a default provider, also alias it
            if (!empty($config['default_provider']) && is_string($config['default_provider'])) {
                $defaultProviderPlatformId = $config['default_provider'] . 'Platform';
                $builder->setAlias('lingoda_ai.default_platform', $defaultProviderPlatformId);
            }
        }
        
        // Store config as parameters for potential console commands
        $builder->setParameter('lingoda_ai.config', $config);
        
        // Store rate limiting specific parameters for easier access
        if (isset($config['rate_limiting']) && is_array($config['rate_limiting'])) {
            $rateLimitingConfig = $config['rate_limiting'];
            $builder->setParameter('lingoda_ai.rate_limiting.enabled', (bool) ($rateLimitingConfig['enabled'] ?? true));
            $builder->setParameter('lingoda_ai.rate_limiting.storage', (string) ($rateLimitingConfig['storage'] ?? 'cache.rate_limiter'));
            $builder->setParameter('lingoda_ai.rate_limiting.lock_factory', (string) ($rateLimitingConfig['lock_factory'] ?? 'lock.factory'));
            $builder->setParameter('lingoda_ai.rate_limiting.enable_retries', (bool) ($rateLimitingConfig['enable_retries'] ?? true));
            $builder->setParameter('lingoda_ai.rate_limiting.max_retries', is_numeric($rateLimitingConfig['max_retries'] ?? 10) ? (int) ($rateLimitingConfig['max_retries'] ?? 10) : 10);
        }
        
        // Register console commands
        if (!empty($clients)) {
            $testCommandDef = new Definition(AiTestConnectionCommand::class, [
                new Reference(PlatformInterface::class)
            ]);
            $testCommandDef->addTag('console.command');
            $builder->setDefinition(AiTestConnectionCommand::class, $testCommandDef);
            
            $listProvidersCommandDef = new Definition(AiListProvidersCommand::class, [
                new Reference(PlatformInterface::class),
                new Reference('parameter_bag')
            ]);
            $listProvidersCommandDef->addTag('console.command');
            $builder->setDefinition(AiListProvidersCommand::class, $listProvidersCommandDef);
            
            $listModelsCommandDef = new Definition(AiListModelsCommand::class, [
                new Reference(PlatformInterface::class)
            ]);
            $listModelsCommandDef->addTag('console.command');
            $builder->setDefinition(AiListModelsCommand::class, $listModelsCommandDef);
            
            // Register rate limiting test command with Platform dependency
            $testRateLimitCommandDef = new Definition(TestRateLimitingCommand::class, [
                new Reference(PlatformInterface::class),
                new Reference('parameter_bag'),
            ]);
            $testRateLimitCommandDef->addTag('console.command');
            $builder->setDefinition(TestRateLimitingCommand::class, $testRateLimitCommandDef);
        }
    }

    /**
     * Returns mapping of provider names to their client factory and client classes.
     *
     * @return array<value-of<AIProvider>, array{factory: class-string, client: class-string}>
     */
    private function getProviderFactoryConfig(): array
    {
        return [
            AIProvider::OPENAI->value => [
                'factory' => OpenAIClientFactory::class,
                'client' => OpenAIClient::class,
            ],
            AIProvider::ANTHROPIC->value => [
                'factory' => AnthropicClientFactory::class,
                'client' => AnthropicClient::class,
            ],
            AIProvider::GEMINI->value => [
                'factory' => GeminiClientFactory::class,
                'client' => GeminiClient::class,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param array<Reference> $clients
     * @param array<string, mixed> $rateLimitingConfig
     */
    private function registerProvider(
        string $providerName,
        string $clientClass,
        array $config,
        ContainerBuilder $container,
        array &$clients,
        ?Reference $loggerRef = null,
        ?Reference $externalRateLimiterRef = null,
        array $rateLimitingConfig = []
    ): void {
        if (!is_array($config['providers']) || !isset($config['providers'][$providerName]) || !is_array($config['providers'][$providerName])) {
            return;
        }
        
        $providerConfig = $config['providers'][$providerName];
        if (empty($providerConfig['api_key']) || !is_string($providerConfig['api_key'])) {
            return;
        }
        
        $factoryConfig = $this->getProviderFactoryConfig();
        if (!isset($factoryConfig[$providerName])) {
            return; // Unsupported provider
        }
        
        $factoryClass = $factoryConfig[$providerName]['factory'];
        
        // Build named arguments for factory method (Symfony DI requires $ prefix)
        $factoryArgs = ['$apiKey' => $providerConfig['api_key']];
        
        // Add provider-specific arguments
        if ($providerName === AIProvider::OPENAI->value && !empty($providerConfig['organization'])) {
            $factoryArgs['$organization'] = $providerConfig['organization'];
        }
        
        // Add timeout if specified
        $factoryArgs['$timeout'] = $providerConfig['timeout'];
        
        // Add custom HTTP client if specified
        if (!empty($providerConfig['http_client'])) {
            $factoryArgs['$httpClient'] = new Reference($providerConfig['http_client']);
        }
        
        // Add logger if configured
        if ($loggerRef !== null) {
            $factoryArgs['$logger'] = $loggerRef;
        }
        
        // Register base client using factory with named arguments
        $baseClientDef = new Definition($clientClass);
        $baseClientDef->setFactory([$factoryClass, 'createClient']);
        $baseClientDef->setArguments($factoryArgs);
        $baseClientDef->addTag('ai.client', ['provider' => $providerName]);
        $baseClientDef->setPublic(true); // Make public for testing
        
        $baseClientServiceId = "lingoda_ai.client.{$providerName}.base";
        $container->setDefinition($baseClientServiceId, $baseClientDef);
        
        // If external rate limiter is available, wrap the client with RateLimitedClient
        $clientServiceId = "lingoda_ai.client.{$providerName}";
        if ($externalRateLimiterRef !== null) {
            $rateLimiterArgs = ['$externalRateLimiter' => $externalRateLimiterRef];
            if ($loggerRef !== null) {
                $rateLimiterArgs['$logger'] = $loggerRef;
            }
            // lockFactory is null by default, so no need to specify it
            
            $rateLimiterDef = new Definition(SymfonyRateLimiter::class, $rateLimiterArgs);
            $rateLimiterServiceId = "lingoda_ai.rate_limiter.{$providerName}";
            $rateLimiterDef->setPublic(true); // Make public for testing
            $container->setDefinition($rateLimiterServiceId, $rateLimiterDef);
            
            $estimatorRegistryDef = new Definition(TokenEstimatorRegistry::class);
            $estimatorRegistryServiceId = "lingoda_ai.token_estimator_registry.{$providerName}";
            $estimatorRegistryDef->setPublic(true); // Make public for testing
            $container->setDefinition($estimatorRegistryServiceId, $estimatorRegistryDef);
            
            // Get retry configuration
            $enableRetries = (bool) ($rateLimitingConfig['enable_retries'] ?? true);
            $maxRetries = is_numeric($rateLimitingConfig['max_retries'] ?? 10) ? (int) ($rateLimitingConfig['max_retries'] ?? 10) : 10;
            
            $rateLimitedClientArgs = [
                '$client' => new Reference($baseClientServiceId),
                '$rateLimiter' => new Reference($rateLimiterServiceId),
                '$estimatorRegistry' => new Reference($estimatorRegistryServiceId),
                '$enableRetries' => $enableRetries,
                '$maxRetries' => $maxRetries,
            ];
            if ($loggerRef !== null) {
                $rateLimitedClientArgs['$logger'] = $loggerRef;
            }
            // DelayInterface is null by default, so no need to specify it
            
            $rateLimitedClientDef = new Definition(RateLimitedClient::class, $rateLimitedClientArgs);
            $rateLimitedClientDef->addTag('ai.client', ['provider' => $providerName, 'rate_limited' => true]);
            $rateLimitedClientDef->setPublic(true); // Make public for testing
            $container->setDefinition($clientServiceId, $rateLimitedClientDef);
        } else {
            // No rate limiting, use base client directly
            $container->setAlias($clientServiceId, $baseClientServiceId);
            $container->getAlias($clientServiceId)->setPublic(true);
        }
        
        $clients[] = new Reference($clientServiceId);
        
        
        // Register provider-specific platform (single provider)
        $providerPlatformDef = new Definition(ProviderPlatform::class, [new Reference($clientServiceId)]);
        $providerPlatformDef->addTag('ai.platform', ['provider' => $providerName]);
        $providerPlatformServiceId = $providerName . 'Platform';
        $container->setDefinition($providerPlatformServiceId, $providerPlatformDef);
        
        // Set up autowiring for provider-specific platforms
        $container->setAlias(ProviderPlatform::class . ' $' . $providerPlatformServiceId, $providerPlatformServiceId);
        $container->setAlias(PlatformInterface::class . ' $' . $providerPlatformServiceId, $providerPlatformServiceId);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function addDefaultModelConfiguration(Definition $platformDef, array $config, ContainerBuilder $builder): void
    {
        // Add method calls to configure default models for each provider
        if (!isset($config['providers']) || !is_array($config['providers'])) {
            return;
        }

        foreach ($config['providers'] as $providerName => $providerConfig) {
            if (is_array($providerConfig) && !empty($providerConfig['default_model']) && is_string($providerConfig['default_model'])) {
                $clientServiceId = "lingoda_ai.client.{$providerName}";
                if ($builder->hasDefinition($clientServiceId)) {
                    // Create a method call that will configure the provider's default model
                    $platformDef->addMethodCall('configureProviderDefaultModel', [
                        $providerName,
                        $providerConfig['default_model']
                    ]);
                }
            }
        }
    }

    /**
     * Register rate limiting services based on configuration
     * 
     * @param array<string, mixed> $rateLimitingConfig
     */
    private function registerRateLimiting(array $rateLimitingConfig, ContainerBuilder $builder): Reference
    {
        // Register rate limiter factories for each provider and type
        $rateLimiterServiceMap = [];
        
        
        if (isset($rateLimitingConfig['providers']) && is_array($rateLimitingConfig['providers'])) {
            foreach ($rateLimitingConfig['providers'] as $providerId => $providerLimits) {
                if (!is_array($providerLimits)) {
                    continue;
                }
                
                foreach (['requests', 'tokens'] as $type) {
                    if (!isset($providerLimits[$type]) || !is_array($providerLimits[$type])) {
                        continue;
                    }
                    
                    $limitConfig = $providerLimits[$type];
                    $serviceId = sprintf('lingoda_ai.rate_limiter.%s_%s', $providerId, $type);
                    
                    try {
                        // Create storage adapter for rate limiter
                        $storageServiceId = is_string($rateLimitingConfig['storage'] ?? null) ? $rateLimitingConfig['storage'] : 'cache.rate_limiter';
                        $storageAdapterServiceId = sprintf('lingoda_ai.rate_limiter_storage.%s_%s', $providerId, $type);
                        
                        $storageAdapterDef = new Definition(CacheStorage::class, [
                            new Reference($storageServiceId),
                        ]);
                        $builder->setDefinition($storageAdapterServiceId, $storageAdapterDef);
                        
                        // Register the rate limiter factory
                        $rateLimiterDef = new Definition(RateLimiterFactory::class, [
                            [
                                'id' => sprintf('%s_%s', $providerId, $type),
                                'policy' => $limitConfig['policy'] ?? 'token_bucket',
                                'limit' => $limitConfig['limit'] ?? 60,
                                'rate' => $limitConfig['rate'] ?? ['interval' => '1 minute', 'amount' => 60],
                            ],
                            new Reference($storageAdapterServiceId),
                            new Reference(is_string($rateLimitingConfig['lock_factory'] ?? null) ? $rateLimitingConfig['lock_factory'] : 'lock.factory'),
                        ]);
                        
                        // Make service public for testing
                        $rateLimiterDef->setPublic(true);
                        $builder->setDefinition($serviceId, $rateLimiterDef);
                        $rateLimiterServiceMap[$providerId][$type] = $serviceId;
                        
                        // Also register with the standard Symfony naming convention for manual access
                        $aliasId = sprintf('limiter.%s_%s', $providerId, $type);
                        $builder->setAlias($aliasId, $serviceId);
                        $builder->getAlias($aliasId)->setPublic(true);
                        
                    } catch (\Exception $e) {
                        // Silently continue on registration failure
                    }
                }
            }
        }
        
        // Register the external rate limiter service
        $externalRateLimiterDef = new Definition(BundleExternalRateLimiter::class, [
            new Reference('service_container'),
            $rateLimiterServiceMap,
        ]);
        $externalRateLimiterDef->setPublic(true);
        
        $builder->setDefinition('lingoda_ai.external_rate_limiter', $externalRateLimiterDef);
        
        return new Reference('lingoda_ai.external_rate_limiter');
    }

    /**
     * @return array<string, mixed>
     */
    private function getProviderDefaults(string $provider): array
    {
        return match ($provider) {
            AIProvider::OPENAI->value => [
                'api_key' => '%env(OPENAI_API_KEY)%',
                'default_model' => OpenAIChatModel::GPT_4O_MINI->value,
                'organization' => '%env(OPENAI_ORGANIZATION)%', // OpenAI-specific field
            ],
            AIProvider::ANTHROPIC->value => [
                'api_key' => '%env(ANTHROPIC_API_KEY)%',
                'default_model' => AnthropicChatModel::CLAUDE_SONNET_4->value,
            ],
            AIProvider::GEMINI->value => [
                'api_key' => '%env(GEMINI_API_KEY)%',
                'default_model' => GeminiChatModel::GEMINI_2_5_FLASH->value,
            ],
            default => [
                'api_key' => '',
                'default_model' => '',
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function getRateLimitDefaults(string $provider, string $type): array
    {
        $defaults = [
            AIProvider::OPENAI->value => [
                'requests' => ['limit' => 180, 'amount' => 180],
                'tokens' => ['limit' => 450000, 'amount' => 450000],
            ],
            AIProvider::ANTHROPIC->value => [
                'requests' => ['limit' => 100, 'amount' => 100],
                'tokens' => ['limit' => 100000, 'amount' => 100000],
            ],
            AIProvider::GEMINI->value => [
                'requests' => ['limit' => 1000, 'amount' => 1000],
                'tokens' => ['limit' => 1000000, 'amount' => 1000000],
            ],
        ];

        $providerDefaults = $defaults[$provider] ?? [
            'requests' => [
                'limit' => 60,
                'amount' => 60,
            ],
            'tokens' => [
                'limit' => 60000,
                'amount' => 60000,
            ]
        ];
        $typeDefaults = $providerDefaults[$type] ?? ['limit' => 60, 'amount' => 60];

        return [
            'policy' => 'token_bucket',
            'limit' => $typeDefaults['limit'],
            'rate' => [
                'interval' => '1 minute',
                'amount' => $typeDefaults['amount'],
            ],
        ];
    }
}
