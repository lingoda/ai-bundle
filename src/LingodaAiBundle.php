<?php

declare(strict_types = 1);

namespace Lingoda\AiBundle;

use AsyncAws\BedrockRuntime\BedrockRuntimeClient;
use Lingoda\AiBundle\Command\AiListModelsCommand;
use Lingoda\AiBundle\Command\AiListProvidersCommand;
use Lingoda\AiBundle\Command\AiTestConnectionCommand;
use Lingoda\AiBundle\Command\AiTestRateLimitingCommand;
use Lingoda\AiBundle\Platform\ProviderPlatform;
use Lingoda\AiBundle\RateLimit\BundleExternalRateLimiter;
use Lingoda\AiSdk\Client\Anthropic\AnthropicClient;
use Lingoda\AiSdk\Client\Anthropic\AnthropicClientFactory;
use Lingoda\AiSdk\Client\Bedrock\BedrockClient;
use Lingoda\AiSdk\Client\Bedrock\BedrockClientFactory;
use Lingoda\AiSdk\Client\Gemini\GeminiClient;
use Lingoda\AiSdk\Client\Gemini\GeminiClientFactory;
use Lingoda\AiSdk\Client\OpenAI\OpenAIClient;
use Lingoda\AiSdk\Client\OpenAI\OpenAIClientFactory;
use Lingoda\AiSdk\Client\TypeSafe\TypeSafeDecisionPlatform;
use Lingoda\AiSdk\Decision\DecisionPlatformInterface;
use Lingoda\AiSdk\Enum\AIProvider;
use Lingoda\AiSdk\Enum\Anthropic\ChatModel as AnthropicChatModel;
use Lingoda\AiSdk\Enum\Gemini\ChatModel as GeminiChatModel;
use Lingoda\AiSdk\Enum\OpenAI\ChatModel as OpenAIChatModel;
use Lingoda\AiSdk\Enum\TypeSafe\DecisionModel;
use Lingoda\AiSdk\Platform;
use Lingoda\AiSdk\PlatformInterface;
use Lingoda\AiSdk\RateLimit\RateLimitedClient;
use Lingoda\AiSdk\RateLimit\RateLimitedDecisionPlatform;
use Lingoda\AiSdk\RateLimit\SymfonyRateLimiter;
use Lingoda\AiSdk\RateLimit\TokenEstimatorRegistry;
use Lingoda\AiSdk\Security\AttributeSanitizer;
use Lingoda\AiSdk\Security\DataSanitizer;
use Lingoda\AiSdk\Security\Pattern\DefaultPatterns;
use Lingoda\AiSdk\Security\Pattern\PatternRegistry;
use Lingoda\AiSdk\Security\SensitiveContentFilter;
use Symfony\AI\Platform\Bridge\Bedrock\Factory as BedrockPlatformFactory;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Webmozart\Assert\Assert;

final class LingodaAiBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        /** @var ArrayNodeDefinition $rootNode */
        $rootNode = $definition->rootNode();

        // TypeSafe answers decide() only, so it can never be the provider ask() falls back to
        $supportedProviders = array_values(array_map(
            static fn (AIProvider $provider) => $provider->value,
            array_filter(AIProvider::cases(), static fn (AIProvider $provider) => $provider !== AIProvider::TYPESAFE)
        ));

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
                            ->scalarNode('runtime_client')
                                ->info('Bedrock only: service id of an AsyncAws\\BedrockRuntime\\BedrockRuntimeClient (region and credentials come from it)')
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
                    ->validate()
                        ->always(static function (array $providers): array {
                            foreach ($providers as $providerName => $providerConfig) {
                                self::validateProviderConfig((string) $providerName, $providerConfig);
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
                            ->info('Extra regular expressions redacted as [REDACTED] in prompt text, on top of the SDK defaults')
                            ->scalarPrototype()
                                ->validate()
                                    ->ifTrue(static fn (mixed $pattern): bool => !is_string($pattern) || @preg_match($pattern, '') === false)
                                    ->thenInvalid('Invalid sanitization pattern %s: not a valid regular expression.')
                                ->end()
                            ->end()
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
                                    $limits = [];
                                    foreach ($config as $type => $rateLimitConfig) {
                                        $limits[$type] = is_string($type) && is_array($rateLimitConfig)
                                            ? array_merge($this->getRateLimitDefaults($providerName, $type), $rateLimitConfig)
                                            : $rateLimitConfig;
                                    }
                                    $result[$providerName] = $limits;
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
        $this->registerBedrock($config, $builder, $clients, $loggerRef, $externalRateLimiterRef, $rateLimitingConfig);
        $this->registerDecisionPlatform($config, $builder, $loggerRef, $externalRateLimiterRef, $rateLimitingConfig);

        // Main Platform service
        if (!empty($clients)) {
            $sanitizationEnabled = isset($config['sanitization']) && is_array($config['sanitization'])
                ? ($config['sanitization']['enabled'] ?? true)
                : true;

            // One sanitizer for every platform when patterns are configured; null lets Platform build the default one
            $sanitizerDef = $this->createSanitizerDefinition($config, $loggerRef);
            $sanitizerRef = null;
            if ($sanitizerDef !== null) {
                $builder->setDefinition('lingoda_ai.data_sanitizer', $sanitizerDef);
                $sanitizerRef = new Reference('lingoda_ai.data_sanitizer');
            }

            $platformDef = new Definition(Platform::class, [
                array_values($clients),
                $sanitizationEnabled,
                $sanitizerRef,
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

            foreach ($clients as $providerName => $clientRef) {
                $this->registerProviderPlatform($providerName, $clientRef, (bool) $sanitizationEnabled, $sanitizerRef, $loggerRef, $config, $builder);
            }
        }

        // Store config as parameters for potential console commands
        $builder->setParameter('lingoda_ai.config', $config);

        // Store rate limiting specific parameters for easier access
        if (isset($config['rate_limiting']) && is_array($config['rate_limiting'])) {
            $rateLimitingConfig = $config['rate_limiting'];
            $builder->setParameter('lingoda_ai.rate_limiting.enabled', (bool) ($rateLimitingConfig['enabled'] ?? true));
            $storage = $rateLimitingConfig['storage'] ?? 'cache.rate_limiter';
            Assert::string($storage);
            $builder->setParameter('lingoda_ai.rate_limiting.storage', $storage);
            $lockFactory = $rateLimitingConfig['lock_factory'] ?? 'lock.factory';
            Assert::string($lockFactory);
            $builder->setParameter('lingoda_ai.rate_limiting.lock_factory', $lockFactory);
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
            $testRateLimitCommandDef = new Definition(AiTestRateLimitingCommand::class, [
                new Reference(PlatformInterface::class),
                new Reference('parameter_bag'),
            ]);
            $testRateLimitCommandDef->addTag('console.command');
            $builder->setDefinition(AiTestRateLimitingCommand::class, $testRateLimitCommandDef);
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
     * @param array<string, Reference> $clients
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
            Assert::string($providerConfig['http_client']);
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

        $this->registerClient($providerName, $baseClientDef, $container, $clients, $loggerRef, $externalRateLimiterRef, $rateLimitingConfig);
    }

    /**
     * Registers the Bedrock client when providers.bedrock is configured. Region and credentials come from the
     * configured async-aws runtime client, whose own retries replace the rate limiter's transport retries.
     *
     * @param array<string, mixed> $config
     * @param array<string, Reference> $clients
     * @param array<string, mixed> $rateLimitingConfig
     */
    private function registerBedrock(
        array $config,
        ContainerBuilder $container,
        array &$clients,
        ?Reference $loggerRef,
        ?Reference $externalRateLimiterRef,
        array $rateLimitingConfig
    ): void {
        $providerConfig = is_array($config['providers'] ?? null) ? ($config['providers'][AIProvider::BEDROCK->value] ?? null) : null;
        if (!is_array($providerConfig)) {
            return;
        }

        // Only reachable without the optional packages installed
        // @codeCoverageIgnoreStart
        if (!class_exists(BedrockPlatformFactory::class) || !class_exists(BedrockRuntimeClient::class)) {
            throw new \LogicException('The bedrock provider requires symfony/ai-bedrock-platform and async-aws/bedrock-runtime. Run "composer require symfony/ai-bedrock-platform:~0.13.0 async-aws/bedrock-runtime".');
        }
        // @codeCoverageIgnoreEnd

        Assert::string($providerConfig['runtime_client']);
        $factoryArgs = ['$runtimeClient' => new Reference($providerConfig['runtime_client'])];
        if ($loggerRef !== null) {
            $factoryArgs['$logger'] = $loggerRef;
        }

        $baseClientDef = new Definition(BedrockClient::class);
        $baseClientDef->setFactory([BedrockClientFactory::class, 'createClient']);
        $baseClientDef->setArguments($factoryArgs);

        $this->registerClient(AIProvider::BEDROCK->value, $baseClientDef, $container, $clients, $loggerRef, $externalRateLimiterRef, $rateLimitingConfig, retryTransportErrors: false);
    }

    /**
     * Registers TypeSafe Jev as a DecisionPlatformInterface when providers.typesafe has an api_key, behind
     * RateLimitedDecisionPlatform when rate limiting is enabled. It is never added to the main platform:
     * ask() cannot route to it.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $rateLimitingConfig
     */
    private function registerDecisionPlatform(
        array $config,
        ContainerBuilder $container,
        ?Reference $loggerRef,
        ?Reference $externalRateLimiterRef,
        array $rateLimitingConfig
    ): void {
        $providerConfig = is_array($config['providers'] ?? null) ? ($config['providers'][AIProvider::TYPESAFE->value] ?? null) : null;
        if (!is_array($providerConfig) || empty($providerConfig['api_key']) || !is_string($providerConfig['api_key'])) {
            return;
        }

        if (!empty($providerConfig['http_client'])) {
            Assert::string($providerConfig['http_client']);
            $httpClient = new Reference($providerConfig['http_client']);
        } else {
            $httpClient = (new Definition(HttpClientInterface::class))
                ->setFactory([HttpClient::class, 'create'])
                ->setArguments([['timeout' => $providerConfig['timeout']]])
            ;
        }

        $args = [
            '$httpClient' => $httpClient,
            '$apiKey' => $providerConfig['api_key'],
            '$defaultModel' => !empty($providerConfig['default_model']) ? $providerConfig['default_model'] : null,
        ];
        if ($loggerRef !== null) {
            $args['$logger'] = $loggerRef;
        }

        $provider = AIProvider::TYPESAFE->value;
        $serviceId = "lingoda_ai.decision_platform.{$provider}";
        $baseServiceId = "{$serviceId}.base";

        $definition = new Definition(TypeSafeDecisionPlatform::class, $args);
        $definition->addTag('ai.decision_platform', ['provider' => $provider]);
        $definition->setPublic(true); // Make public for testing
        $container->setDefinition($baseServiceId, $definition);

        if ($externalRateLimiterRef !== null) {
            $rateLimitedDef = new Definition(RateLimitedDecisionPlatform::class, [
                '$platform' => new Reference($baseServiceId),
                ...$this->registerRateLimiterServices($provider, $container, $externalRateLimiterRef, $loggerRef, $rateLimitingConfig),
            ]);
            $rateLimitedDef->addTag('ai.decision_platform', ['provider' => $provider, 'rate_limited' => true]);
            $rateLimitedDef->setPublic(true); // Make public for testing
            $container->setDefinition($serviceId, $rateLimitedDef);
        } else {
            $container->setAlias($serviceId, $baseServiceId);
            $container->getAlias($serviceId)->setPublic(true);
        }

        $container->setAlias(DecisionPlatformInterface::class, $serviceId);
    }

    /**
     * Registers the base client, its optional rate-limited wrapper and the provider-specific platform.
     *
     * @param array<string, Reference> $clients
     * @param array<string, mixed> $rateLimitingConfig
     */
    private function registerClient(
        string $providerName,
        Definition $baseClientDef,
        ContainerBuilder $container,
        array &$clients,
        ?Reference $loggerRef,
        ?Reference $externalRateLimiterRef,
        array $rateLimitingConfig,
        bool $retryTransportErrors = true
    ): void {
        $baseClientDef->addTag('ai.client', ['provider' => $providerName]);
        $baseClientDef->setPublic(true); // Make public for testing

        $baseClientServiceId = "lingoda_ai.client.{$providerName}.base";
        $container->setDefinition($baseClientServiceId, $baseClientDef);

        // If external rate limiter is available, wrap the client with RateLimitedClient
        $clientServiceId = "lingoda_ai.client.{$providerName}";
        if ($externalRateLimiterRef !== null) {
            $rateLimitedClientArgs = [
                '$client' => new Reference($baseClientServiceId),
                ...$this->registerRateLimiterServices($providerName, $container, $externalRateLimiterRef, $loggerRef, $rateLimitingConfig),
                '$retryTransportErrors' => $retryTransportErrors,
            ];
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

        $clients[$providerName] = new Reference($clientServiceId);
    }

    /**
     * Registers the single-provider platform with the main platform's sanitization, logger and default model,
     * so injecting e.g. PlatformInterface $openaiPlatform behaves like asking the main platform for that provider.
     *
     * @param array<string, mixed> $config
     */
    private function registerProviderPlatform(
        string $providerName,
        Reference $clientRef,
        bool $sanitizationEnabled,
        ?Reference $sanitizerRef,
        ?Reference $loggerRef,
        array $config,
        ContainerBuilder $container
    ): void {
        $providerPlatformDef = new Definition(ProviderPlatform::class, [$clientRef, $sanitizationEnabled, $sanitizerRef, $loggerRef]);
        $providerPlatformDef->addTag('ai.platform', ['provider' => $providerName]);

        $providers = is_array($config['providers'] ?? null) ? $config['providers'] : [];
        $providerConfig = is_array($providers[$providerName] ?? null) ? $providers[$providerName] : [];
        $defaultModel = $providerConfig['default_model'] ?? null;
        if (is_string($defaultModel) && $defaultModel !== '') {
            $providerPlatformDef->addMethodCall('configureProviderDefaultModel', [$providerName, $defaultModel]);
        }

        $providerPlatformServiceId = $providerName . 'Platform';
        $container->setDefinition($providerPlatformServiceId, $providerPlatformDef);

        // Set up autowiring for provider-specific platforms
        $container->setAlias(ProviderPlatform::class . ' $' . $providerPlatformServiceId, $providerPlatformServiceId);
        $container->setAlias(PlatformInterface::class . ' $' . $providerPlatformServiceId, $providerPlatformServiceId);
    }

    /**
     * Registers the provider's rate limiter and token estimator registry, shared by chat clients and decision platforms.
     *
     * @param array<string, mixed> $rateLimitingConfig
     *
     * @return array<string, mixed> Named constructor arguments for RateLimitedClient or RateLimitedDecisionPlatform
     */
    private function registerRateLimiterServices(
        string $providerName,
        ContainerBuilder $container,
        Reference $externalRateLimiterRef,
        ?Reference $loggerRef,
        array $rateLimitingConfig
    ): array {
        $logger = $loggerRef !== null ? ['$logger' => $loggerRef] : [];

        // lockFactory is null by default, so no need to specify it
        $rateLimiterDef = new Definition(SymfonyRateLimiter::class, ['$externalRateLimiter' => $externalRateLimiterRef, ...$logger]);
        $rateLimiterServiceId = "lingoda_ai.rate_limiter.{$providerName}";
        $rateLimiterDef->setPublic(true); // Make public for testing
        $container->setDefinition($rateLimiterServiceId, $rateLimiterDef);

        // SDK estimators per provider, generic estimator for the rest
        $estimatorRegistryDef = (new Definition(TokenEstimatorRegistry::class))
            ->setFactory([TokenEstimatorRegistry::class, 'createDefault'])
        ;
        $estimatorRegistryServiceId = "lingoda_ai.token_estimator_registry.{$providerName}";
        $estimatorRegistryDef->setPublic(true); // Make public for testing
        $container->setDefinition($estimatorRegistryServiceId, $estimatorRegistryDef);

        return [
            '$rateLimiter' => new Reference($rateLimiterServiceId),
            '$estimatorRegistry' => new Reference($estimatorRegistryServiceId),
            '$enableRetries' => (bool) ($rateLimitingConfig['enable_retries'] ?? true),
            '$maxRetries' => is_numeric($rateLimitingConfig['max_retries'] ?? 10) ? (int) ($rateLimitingConfig['max_retries'] ?? 10) : 10,
            ...$logger,
        ];
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
                if ($builder->has($clientServiceId)) {
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
        $locatorServices = [];

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
                    $builder->setDefinition($serviceId, $rateLimiterDef);
                    $rateLimiterServiceMap[$providerId][$type] = $serviceId;
                    $locatorServices[$serviceId] = new Reference($serviceId);

                    // Also register with the standard Symfony naming convention for manual access
                    $aliasId = sprintf('limiter.%s_%s', $providerId, $type);
                    $builder->setAlias($aliasId, $serviceId);
                    $builder->getAlias($aliasId)->setPublic(true);
                }
            }
        }

        // Register the external rate limiter service
        $externalRateLimiterDef = new Definition(BundleExternalRateLimiter::class, [
            ServiceLocatorTagPass::register($builder, $locatorServices),
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
            // No api_key: region and credentials come from the async-aws runtime client
            AIProvider::BEDROCK->value => [],
            AIProvider::TYPESAFE->value => [
                'api_key' => '%env(TYPESAFE_API_KEY)%',
                'default_model' => DecisionModel::JEV_LATEST->value,
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
            AIProvider::BEDROCK->value => [
                'requests' => ['limit' => 60, 'amount' => 60],
                'tokens' => ['limit' => 100000, 'amount' => 100000],
            ],
            AIProvider::TYPESAFE->value => [
                'requests' => ['limit' => 1080, 'amount' => 1080], // 90% of 1,200 RPM
                'tokens' => ['limit' => 13500000, 'amount' => 13500000], // 90% of 250K tokens per second
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

    /**
     * A DataSanitizer carrying sanitization.patterns next to the SDK defaults, or null when there are none.
     *
     * @param array<string, mixed> $config
     */
    private function createSanitizerDefinition(array $config, ?Reference $loggerRef): ?Definition
    {
        $patterns = is_array($config['sanitization'] ?? null) ? ($config['sanitization']['patterns'] ?? []) : [];
        if (!is_array($patterns) || $patterns === []) {
            return null;
        }

        $logger = $loggerRef !== null ? ['$logger' => $loggerRef] : [];
        $filter = new Definition(SensitiveContentFilter::class, [
            '$patternRegistry' => new Definition(PatternRegistry::class, [new Definition(DefaultPatterns::class), [], array_values($patterns)]),
            ...$logger,
        ]);

        // Same settings as DataSanitizer::createDefault(), with the extra patterns
        return new Definition(DataSanitizer::class, [
            '$filter' => $filter,
            '$enabled' => true,
            '$auditLog' => true,
            ...$logger,
            '$attributeSanitizer' => (new Definition(AttributeSanitizer::class))
                ->setFactory([AttributeSanitizer::class, 'createDefault'])
                ->setArguments(['$fallbackFilter' => $filter, ...$logger]),
        ]);
    }

    /**
     * @throws \InvalidArgumentException
     */
    private static function validateProviderConfig(string $providerName, mixed $providerConfig): void
    {
        if (AIProvider::tryFrom($providerName) === null) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown provider "%s". Supported providers: %s.',
                $providerName,
                implode(', ', array_map(static fn (AIProvider $provider): string => $provider->value, AIProvider::cases()))
            ));
        }

        if (!is_array($providerConfig)) {
            return;
        }

        if ($providerName === AIProvider::BEDROCK->value) {
            if (empty($providerConfig['runtime_client'])) {
                throw new \InvalidArgumentException('providers.bedrock.runtime_client is required: the service id of an AsyncAws\\BedrockRuntime\\BedrockRuntimeClient.');
            }
            if (isset($providerConfig['http_client'])) {
                throw new \InvalidArgumentException('providers.bedrock.http_client is not supported: configure the HTTP client on the runtime client, an injected one drops the async-aws retries.');
            }
            foreach (['api_key', 'organization'] as $key) {
                if (isset($providerConfig[$key])) {
                    throw new \InvalidArgumentException(sprintf('providers.bedrock.%s is not supported: credentials come from the runtime client.', $key));
                }
            }
        } elseif (isset($providerConfig['runtime_client'])) {
            throw new \InvalidArgumentException(sprintf('providers.%s.runtime_client is only supported for bedrock.', $providerName));
        }
    }
}
