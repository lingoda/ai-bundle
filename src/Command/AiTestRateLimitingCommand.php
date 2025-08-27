<?php

declare(strict_types = 1);

namespace Lingoda\AiBundle\Command;

use Lingoda\AiSdk\ClientInterface;
use Lingoda\AiSdk\Exception\ModelNotFoundException;
use Lingoda\AiSdk\Exception\RateLimitExceededException;
use Lingoda\AiSdk\ModelInterface;
use Lingoda\AiSdk\Platform;
use Lingoda\AiSdk\PlatformInterface;
use Lingoda\AiSdk\Provider\OpenAIProvider;
use Lingoda\AiSdk\ProviderInterface;
use Lingoda\AiSdk\RateLimit\RateLimitedClient;
use Lingoda\AiSdk\RateLimit\TokenEstimatorRegistry;
use Lingoda\AiSdk\Result\ResultInterface;
use Lingoda\AiSdk\Result\TextResult;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

#[AsCommand(
    name: 'ai:test:rate-limiting',
    description: 'Test rate limiting functionality with your actual configuration'
)]
final class AiTestRateLimitingCommand extends Command
{
    public function __construct(
        private readonly ?PlatformInterface $platform = null,
        private readonly ?ParameterBagInterface $parameterBag = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('requests', 'r', InputOption::VALUE_OPTIONAL, 'Number of requests to make', '5')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Override rate limit (mock mode only)', '2')
            ->addOption('delay', 'd', InputOption::VALUE_OPTIONAL, 'Delay between requests in milliseconds', '100')
            ->addOption('use-mock', 'm', InputOption::VALUE_NONE, 'Use mock client instead of real platform')
            ->addOption('no-retry', null, InputOption::VALUE_NONE, 'Disable automatic retry on rate limit')
            ->addOption('client-id', 'c', InputOption::VALUE_OPTIONAL, 'Client identifier for distributed testing', 'cli-test')
            ->addOption('model', null, InputOption::VALUE_OPTIONAL, 'Model to use for testing', null)
            ->addOption('provider', null, InputOption::VALUE_OPTIONAL, 'Provider to test (openai, anthropic, gemini)', null)
            ->setHelp('
This command tests your actual rate limiting configuration by:
1. Using your configured Platform service with real rate limits
2. Making multiple requests to trigger rate limiting
3. Showing exactly when rate limiting occurs and retry behavior

Example usage:
  # Test with actual configuration
  php bin/console ai:test:rate-limiting
  
  # Test with mock client (standalone)
  php bin/console ai:test:rate-limiting --use-mock
  
  # Test without automatic retry (see raw rate limit errors)
  php bin/console ai:test:rate-limiting --no-retry
  
  # Test specific provider
  php bin/console ai:test:rate-limiting --provider=openai
  
  # Distributed testing (run in multiple terminals)
  Terminal 1: php bin/console ai:test:rate-limiting --client-id=client1
  Terminal 2: php bin/console ai:test:rate-limiting --client-id=client2
            ')
        ;
    }

    /**
     * @throws ModelNotFoundException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $requestsOption = $input->getOption('requests');
        $delayOption = $input->getOption('delay');
        $useMock = (bool) $input->getOption('use-mock');
        $noRetry = (bool) $input->getOption('no-retry');
        $clientId = is_string($input->getOption('client-id')) ? $input->getOption('client-id') : null;
        $modelOption = is_string($input->getOption('model')) ? $input->getOption('model') : null;
        $providerOption = is_string($input->getOption('provider')) ? $input->getOption('provider') : null;
        
        $numRequests = is_numeric($requestsOption) ? (int) $requestsOption : 5;
        $delay = is_numeric($delayOption) ? (int) $delayOption : 100;

        $io->title('AI Bundle Rate Limiting Test');
        
        if ($useMock) {
            return $this->executeMockTest($input, $output, $io, $numRequests, $delay);
        }

        // Use actual Bundle configuration
        if ($this->platform === null) {
            $io->error('No Platform service configured. Make sure the Bundle is properly configured with providers.');
            $io->note('Try running with --use-mock to test without actual configuration.');
            return Command::FAILURE;
        }

        $io->section('Configuration');
        $this->displayRealConfiguration($io, $clientId ?? 'unknown');

        // Resolve model
        $model = $this->resolveModel($io, $modelOption, $providerOption);
        if ($model === null) {
            return Command::FAILURE;
        }

        $io->section('Making requests');
        $this->displayTestInfo($io, $numRequests, $delay, $noRetry, $clientId);
        
        $successful = 0;
        $permanentlyRateLimited = 0;
        $hitRateLimit = 0;
        $totalRetryAttempts = 0;
        
        for ($i = 1; $i <= $numRequests; $i++) {
            $clientIdStr = $clientId ?? 'unknown';
            $io->write("Request {$i}/{$numRequests} [Client: {$clientIdStr}]: ");
            
            $maxRetries = $noRetry ? 0 : 3;
            $attempt = 0;
            $requestHitRateLimit = false;
            $requestRetryAttempts = 0;
            
            while ($attempt <= $maxRetries) {
                try {
                    $startTime = microtime(true);
                    $result = $this->platform->ask("Test request #{$i} from client {$clientIdStr} (attempt " . ($attempt + 1) . ")", $model->getId());
                    $endTime = microtime(true);
                    
                    $duration = round(($endTime - $startTime) * 1000, 2);
                    $content = $result->getContent();
                    $contentStr = is_string($content) ? $content : 'Response received';
                    
                    $retryInfo = $requestRetryAttempts > 0 ? " (after {$requestRetryAttempts} rate limit retries)" : '';
                    $rateLimitInfo = $requestHitRateLimit ? " <fg=yellow>[HIT RATE LIMIT]</fg=yellow>" : '';
                    $io->writeln("<fg=green>✓ SUCCESS</fg=green> ({$duration}ms){$retryInfo}{$rateLimitInfo} - " . mb_substr($contentStr, 0, 50) . '...');
                    $successful++;
                    break;
                } catch (RateLimitExceededException $e) {
                    $requestHitRateLimit = true;
                    $retryAfter = $e->getRetryAfter();
                    
                    if ($attempt < $maxRetries) {
                        $requestRetryAttempts++;
                        $totalRetryAttempts++;
                        $io->write("<fg=yellow>⏳ RATE LIMITED</fg=yellow> - Retrying in {$retryAfter}s... ");
                        sleep($retryAfter);
                        $attempt++;
                        continue;
                    }
                    $io->writeln("<fg=red>✗ RATE LIMITED</fg=red> - Max retries reached (retry after {$retryAfter}s)");
                    $permanentlyRateLimited++;
                    break;
                } catch (\Exception $e) {
                    $io->writeln("<fg=yellow>⚠ ERROR</fg=yellow> - " . $e->getMessage());
                    break;
                }
            }
            
            if ($requestHitRateLimit) {
                $hitRateLimit++;
            }
            
            // Add delay between requests if specified
            if ($delay > 0 && $i < $numRequests) {
                usleep($delay * 1000); // Convert ms to microseconds
            }
        }

        $io->section('Results Summary');
        $io->definitionList(
            ['Client ID' => $clientId ?? 'unknown'],
            ['Successful requests' => "<fg=green>{$successful}</fg=green>"],
            ['Requests that hit rate limits' => "<fg=yellow>{$hitRateLimit}</fg=yellow>"],
            ['Permanently rate limited' => "<fg=red>{$permanentlyRateLimited}</fg=red>"],
            ['Total retry attempts' => "<fg=cyan>{$totalRetryAttempts}</fg=cyan>"],
            ['Total requests' => $numRequests],
        );

        if ($hitRateLimit > 0 || $permanentlyRateLimited > 0) {
            $io->success('Rate limiting is working correctly!');
            if ($hitRateLimit > 0) {
                $io->note("Rate limiting activated on {$hitRateLimit} out of {$numRequests} requests.");
            }
            if ($totalRetryAttempts > 0) {
                $io->note("Made {$totalRetryAttempts} retry attempts due to rate limiting.");
            }
            if ($permanentlyRateLimited > 0) {
                $io->note("{$permanentlyRateLimited} requests were permanently blocked after reaching maximum retries.");
            }
        } else {
            $io->warning('No requests were rate limited. The configured limits may be higher than your test load.');
        }

        return Command::SUCCESS;
    }

    /**
     * @throws ModelNotFoundException
     */
    private function executeMockTest(InputInterface $input, OutputInterface $output, SymfonyStyle $io, int $numRequests, int $delay): int
    {
        $limitOption = $input->getOption('limit');
        $rateLimit = is_numeric($limitOption) ? (int) $limitOption : 2;

        $io->section('Mock Mode Configuration');
        $io->definitionList(
            ['Mode' => 'Mock (standalone testing)'],
            ['Requests to make' => $numRequests],
            ['Rate limit' => "{$rateLimit} requests per minute"],
            ['Delay between requests' => "{$delay}ms"],
        );

        // Create mock client with rate limiting
        $io->section('Setting up rate-limited mock client');
        $client = $this->createRateLimitedMockClient($rateLimit, $io);
        $platform = new Platform([$client]);
        $model = $client->getProvider()->getModel('gpt-4o-mini');

        $io->section('Making requests');
        
        $successful = 0;
        $rateLimited = 0;
        
        for ($i = 1; $i <= $numRequests; $i++) {
            $io->write("Request {$i}/{$numRequests}: ");
            
            try {
                $startTime = microtime(true);
                $result = $platform->ask("Test request #{$i}", $model->getId());
                $endTime = microtime(true);
                
                $duration = round(($endTime - $startTime) * 1000, 2);
                $content = $result->getContent();
                $contentStr = is_string($content) ? $content : 'Response received';
                $io->writeln("<fg=green>✓ SUCCESS</fg=green> ({$duration}ms) - " . mb_substr($contentStr, 0, 50) . '...');
                $successful++;
            } catch (RateLimitExceededException $e) {
                $retryAfter = $e->getRetryAfter();
                $io->writeln("<fg=red>✗ RATE LIMITED</fg=red> - Retry after {$retryAfter}s");
                $rateLimited++;
            } catch (\Exception $e) {
                $io->writeln("<fg=yellow>⚠ ERROR</fg=yellow> - " . $e->getMessage());
            }
            
            // Add delay between requests if specified
            if ($delay > 0 && $i < $numRequests) {
                usleep($delay * 1000); // Convert ms to microseconds
            }
        }

        $io->section('Results Summary');
        $io->definitionList(
            ['Successful requests' => "<fg=green>{$successful}</fg=green>"],
            ['Rate limited requests' => "<fg=red>{$rateLimited}</fg=red>"],
            ['Total requests' => $numRequests],
        );

        if ($rateLimited > 0) {
            $io->success('Mock rate limiting is working correctly! Some requests were blocked as expected.');
            $io->note([
                'Rate limiting blocked ' . $rateLimited . ' requests that exceeded the limit.',
                'This is mock behavior. Use without --use-mock to test your actual Bundle configuration.',
                'You can adjust the --limit and --delay options to test different scenarios.',
            ]);
        } else {
            $io->warning('No requests were rate limited. Try increasing --requests or decreasing --limit to see rate limiting in action.');
        }

        return Command::SUCCESS;
    }

    private function displayRealConfiguration(SymfonyStyle $io, string $clientId): void
    {
        $io->definitionList(
            ['Mode' => 'Real configuration (using Bundle settings)'],
            ['Client ID' => $clientId],
            ['Platform service' => $this->platform !== null ? 'Available' : 'Not configured'],
        );

        if ($this->platform !== null) {
            $providers = $this->platform->getAvailableProviders();
            $io->definitionList(['Available providers' => implode(', ', $providers)]);
        }

        if ($this->parameterBag !== null) {
            $rateLimitingEnabled = $this->parameterBag->get('lingoda_ai.rate_limiting.enabled');
            $io->definitionList(['Rate limiting enabled' => $rateLimitingEnabled ? 'Yes' : 'No']);
            
            if ($rateLimitingEnabled) {
                $storage = $this->parameterBag->get('lingoda_ai.rate_limiting.storage');
                $lockFactory = $this->parameterBag->get('lingoda_ai.rate_limiting.lock_factory');
                $enableRetries = $this->parameterBag->get('lingoda_ai.rate_limiting.enable_retries');
                $maxRetries = $this->parameterBag->get('lingoda_ai.rate_limiting.max_retries');
                
                $io->definitionList(
                    ['Rate limit storage' => $storage ?? 'Default'],
                    ['Lock factory' => $lockFactory ?? 'Default'],
                    ['SDK retries enabled' => $enableRetries ? 'Yes' : 'No'],
                    ['Max SDK retries' => $enableRetries ? (string)(is_numeric($maxRetries) ? (int)$maxRetries : 0) : 'N/A'],
                );
            }
        }
    }

    private function displayTestInfo(SymfonyStyle $io, int $numRequests, int $delay, bool $noRetry, ?string $clientId): void
    {
        $io->definitionList(
            ['Requests to make' => $numRequests],
            ['Delay between requests' => "{$delay}ms"],
            ['Retry on rate limit' => $noRetry ? 'No' : 'Yes (max 3 attempts)'],
            ['Client identifier' => $clientId ?? 'unknown'],
        );

        if (!$noRetry) {
            $io->note('Rate limited requests will be automatically retried up to 3 times.');
        }

        $io->writeln('Starting test...');
    }

    /**
     * @throws ModelNotFoundException
     */
    private function resolveModel(SymfonyStyle $io, ?string $modelId, ?string $providerId): ?ModelInterface
    {
        if ($this->platform === null) {
            return null;
        }

        try {
            if ($modelId !== null) {
                // Specific model requested - find which provider supports it
                $providers = $this->platform->getAvailableProviders();
                foreach ($providers as $providerName) {
                    try {
                        $provider = $this->platform->getProvider($providerName);
                        $model = $provider->getModel($modelId);
                        $io->writeln("Using specified model: {$model->getId()} from provider: {$provider->getId()}");
                        return $model;
                    } catch (\Exception) {
                        // This provider doesn't have the model, try next
                        continue;
                    }
                }
                throw new ModelNotFoundException("Model '{$modelId}' not found in any configured provider.");
            }

            if ($providerId !== null) {
                // Specific provider requested, use its default model
                $provider = $this->platform->getProvider($providerId);
                $defaultModel = $provider->getDefaultModel();
                $model = $provider->getModel($defaultModel);
                $io->writeln("Using default model: {$model->getId()} from specified provider: {$provider->getId()}");
                return $model;
            }

            // No specific model/provider requested, let Platform choose default
            $providers = $this->platform->getAvailableProviders();
            if (empty($providers)) {
                $io->error('No providers available.');
                return null;
            }

            $provider = $this->platform->getProvider($providers[0]);
            $defaultModel = $provider->getDefaultModel();
            $model = $provider->getModel($defaultModel);

            $io->writeln("Using default model: {$model->getId()} from provider: {$provider->getId()}");
            
            return $model;
        } catch (\Exception $e) {
            $io->error("Failed to resolve model: " . $e->getMessage());
            $providers = $this->platform->getAvailableProviders();
            $io->note('Available providers: ' . implode(', ', $providers));
            return null;
        }
    }

    private function createRateLimitedMockClient(int $requestsPerMinute, SymfonyStyle $io): ClientInterface
    {
        $io->writeln("Creating mock client with {$requestsPerMinute} requests/minute limit...");
        
        // Create in-memory rate limiter with very low limits
        $storage = new InMemoryStorage();
        $lockFactory = new LockFactory(new InMemoryStore());
        
        $requestLimiterFactory = new RateLimiterFactory([
            'id' => 'test_requests',
            'policy' => 'token_bucket',
            'limit' => $requestsPerMinute,
            'rate' => [
                'interval' => '1 minute',
                'amount' => $requestsPerMinute,
            ],
        ], $storage, $lockFactory);
        
        $tokenLimiterFactory = new RateLimiterFactory([
            'id' => 'test_tokens',
            'policy' => 'token_bucket',
            'limit' => 100000, // High token limit so it doesn't interfere
            'rate' => [
                'interval' => '1 minute',
                'amount' => 100000,
            ],
        ], $storage, $lockFactory);

        // Create rate limiter that uses our factories
        $rateLimiter = new TestRateLimiter($requestLimiterFactory, $tokenLimiterFactory);
        
        // Create mock base client
        $baseClient = new MockClient();
        
        // Create token estimator
        $tokenEstimator = TokenEstimatorRegistry::createDefault();
        
        // Wrap in rate limited client
        $rateLimitedClient = new RateLimitedClient(
            $baseClient,
            $rateLimiter,
            $tokenEstimator,
            new NullLogger()
        );
        
        $io->writeln('<fg=green>✓</fg=green> Rate-limited client created successfully');
        
        return $rateLimitedClient;
    }
}

/**
 * Simple rate limiter implementation for testing
 */
final class TestRateLimiter implements \Lingoda\AiSdk\RateLimit\RateLimiterInterface
{
    public function __construct(
        private readonly RateLimiterFactory $requestLimiterFactory,
        private readonly RateLimiterFactory $tokenLimiterFactory,
    ) {
    }

    public function consume(ModelInterface $model, int $estimatedTokens = 1): void
    {
        // Check request rate limit
        $requestLimiter = $this->requestLimiterFactory->create('test');
        $requestLimit = $requestLimiter->consume();
        
        if (!$requestLimit->isAccepted()) {
            $retryAfter = $requestLimit->getRetryAfter()->getTimestamp() - time();
            throw new RateLimitExceededException($retryAfter, 'Request rate limit exceeded');
        }

        // Check token rate limit
        $tokenLimiter = $this->tokenLimiterFactory->create('test');
        $tokenLimit = $tokenLimiter->consume($estimatedTokens);
        
        if (!$tokenLimit->isAccepted()) {
            $retryAfter = $tokenLimit->getRetryAfter()->getTimestamp() - time();
            throw new RateLimitExceededException($retryAfter, 'Token rate limit exceeded');
        }
    }

    public function isAllowed(ModelInterface $model, int $estimatedTokens = 1): bool
    {
        try {
            $this->consume($model, $estimatedTokens);
            return true;
        } catch (RateLimitExceededException) {
            return false;
        }
    }

    public function getRetryAfter(ModelInterface $model): ?int
    {
        $requestLimiter = $this->requestLimiterFactory->create('test');
        $tokenLimiter = $this->tokenLimiterFactory->create('test');
        
        $requestReservation = $requestLimiter->reserve(1);
        $tokenReservation = $tokenLimiter->reserve(1);
        
        $requestRetryAfter = $requestReservation->getWaitDuration();
        $tokenRetryAfter = $tokenReservation->getWaitDuration();
        
        $maxRetryAfter = max($requestRetryAfter, $tokenRetryAfter);
        
        return $maxRetryAfter > 0 ? (int)$maxRetryAfter : null;
    }
}

/**
 * Mock client that simulates AI responses without making real API calls
 * Mock client that simulates AI responses without making real API calls
 */
final class MockClient implements ClientInterface
{
    private readonly ProviderInterface $provider;

    public function __construct()
    {
        $this->provider = new OpenAIProvider();
    }

    public function supports(ModelInterface $model): bool
    {
        return $model->getProvider()->getId() === 'openai';
    }

    public function request(ModelInterface $model, array|string $payload, array $options = []): ResultInterface
    {
        // Simulate processing delay
        usleep(50000); // 50ms delay to simulate API call
        
        // Return mock response
        return new TextResult("Mock response for: " . (is_string($payload) ? $payload : json_encode($payload)));
    }

    public function getProvider(): ProviderInterface
    {
        return $this->provider;
    }
}
