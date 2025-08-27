# Rate Limiting

The Lingoda AI Bundle provides enhanced rate limiting capabilities that integrate with Symfony's rate limiter component. This allows for more sophisticated rate limiting with shared state across multiple application instances.

## Overview

### SDK Built-in Rate Limiting (Default)

By default, the AI SDK includes built-in rate limiting with:
- In-memory storage
- Provider-specific default limits
- Automatic retry with exponential backoff
- Token estimation for accurate rate limiting

### Bundle Enhanced Rate Limiting (Optional)

When enabled, the Bundle provides:
- Storage for shared state
- YAML configuration for easy customization
- Environment-specific rate limits
- Integration with Symfony's caching and locking systems
- Monitoring via Symfony profiler

## Configuration

### Basic Setup (SDK rate limiting only)

```yaml
lingoda_ai:
    providers:
        openai:
            api_key: '%env(OPENAI_API_KEY)%'
    # rate_limiting is disabled by default
```

### Enhanced Rate Limiting

```yaml
framework:
    cache:
        pools:
            rate_limiter_pool:
                adapter: cache.adapter.redis

lingoda_ai:
    providers:
        openai:
            api_key: '%env(OPENAI_API_KEY)%'
    
    rate_limiting:
        enabled: true
        storage: 'rate_limiter_pool'  # Use Redis for shared state
        
        # Retry configuration
        enable_retries: true    # Enable automatic retries on rate limit (default: true)
        max_retries: 10        # Maximum retry attempts (default: 10)
        
        providers:
            openai:
                requests:
                    limit: 180  # Requests per minute
                    rate:
                        interval: '1 minute'
                        amount: 180
                tokens:
                    limit: 450000  # Tokens per minute
                    rate:
                        interval: '1 minute'  
                        amount: 450000
```

## Rate Limiting Policies

### Token Bucket (Recommended)

```yaml
rate_limiting:
    providers:
        openai:
            requests:
                policy: 'token_bucket'  # Allow bursts up to limit
                limit: 180
                rate:
                    interval: '1 minute'
                    amount: 180
```

### Fixed Window

```yaml
rate_limiting:
    providers:
        openai:
            requests:
                policy: 'fixed_window'  # Strict window boundaries
                limit: 180
                rate:
                    interval: '1 minute'
                    amount: 180
```

### Sliding Window

```yaml
rate_limiting:
    providers:
        openai:
            requests:
                policy: 'sliding_window'  # Smooth rate limiting
                limit: 180
                rate:
                    interval: '1 minute'
                    amount: 180
```

## Provider-Specific Limits

### OpenAI Tier-Based Limits

```yaml
# Tier 1 (Free)
openai:
    requests: { limit: 180, rate: { interval: '1 minute', amount: 180 } }
    tokens: { limit: 450000, rate: { interval: '1 minute', amount: 450000 } }

# Tier 2 (Pay-as-you-go)  
openai:
    requests: { limit: 3500, rate: { interval: '1 minute', amount: 3500 } }
    tokens: { limit: 4000000, rate: { interval: '1 minute', amount: 4000000 } }
```

### Anthropic Tier-Based Limits

```yaml
# Pro Tier
anthropic:
    requests: { limit: 1000, rate: { interval: '1 minute', amount: 1000 } }
    tokens: { limit: 1000000, rate: { interval: '1 minute', amount: 1000000 } }

# Team Tier
anthropic:
    requests: { limit: 2500, rate: { interval: '1 minute', amount: 2500 } }
    tokens: { limit: 2500000, rate: { interval: '1 minute', amount: 2500000 } }
```

### Gemini Limits

```yaml
# Free Tier (very generous)
gemini:
    requests: { limit: 1000, rate: { interval: '1 minute', amount: 1000 } }
    tokens: { limit: 1000000, rate: { interval: '1 minute', amount: 1000000 } }
```

## Retry Behavior Configuration

### Automatic Retries (Default)

By default, the SDK automatically retries requests that hit rate limits:

```yaml
rate_limiting:
    enabled: true
    enable_retries: true    # Default: automatically retry on rate limit
    max_retries: 10        # Default: up to 10 retry attempts
```

**Behavior:**
- When a rate limit is hit, the request automatically waits and retries
- Uses the `retry-after` value from the rate limiter
- Exponential backoff for non-rate-limit errors
- Transparent to your application code
- Best for production environments

### Disabled Retries (Testing Mode)

For testing and debugging, you can disable automatic retries:

```yaml
rate_limiting:
    enabled: true
    enable_retries: false   # Disable retries - throw exceptions immediately
```

**Behavior:**
- Rate limit exceptions are thrown immediately
- No automatic waiting or retrying
- Allows you to see raw rate limiting behavior
- Useful for testing rate limiting configuration
- Use with the test command: `php bin/console ai:test:rate-limiting`

### Custom Retry Configuration

Fine-tune retry behavior for your needs:

```yaml
rate_limiting:
    enabled: true
    enable_retries: true
    max_retries: 3         # Reduce retries for faster failures
```

**Use Cases:**
- **High retries (10+)**: Production with strict SLA requirements
- **Medium retries (3-5)**: Balanced performance and reliability  
- **No retries (0)**: Testing, debugging, or when handling retries manually

### Testing Rate Limiting

Use the built-in test command to verify your configuration:

```bash
# Test with automatic retries (production mode)
php bin/console ai:test:rate-limiting --requests=5

# Test without retries (see raw rate limiting)
# First, set enable_retries: false in your config, then:
php bin/console ai:test:rate-limiting --requests=5 --no-retry
```

The test command will show:
- Whether SDK retries are enabled
- How many requests hit rate limits
- Retry attempt counts
- Actual timing of rate limit enforcement

## Storage Options

### Development (In-Memory)

```yaml
rate_limiting:
    enabled: true
    # Uses default in-memory storage - state lost on restart
```

### Production (Redis)

```yaml
framework:
    cache:
        pools:
            rate_limiter_redis:
                adapter: cache.adapter.redis
                provider: 'redis://localhost:6379'

rate_limiting:
    enabled: true
    storage: 'rate_limiter_redis'
```

### Production (Database)

```yaml
framework:
    cache:
        pools:
            rate_limiter_db:
                adapter: cache.adapter.pdo
                provider: 'doctrine.dbal.default_connection'

rate_limiting:
    enabled: true
    storage: 'rate_limiter_db'
```

## Environment-Specific Configuration

### Development Environment

```yaml
# config/packages/dev/lingoda_ai.yaml
lingoda_ai:
    rate_limiting:
        enabled: true
        providers:
            openai:
                requests: { limit: 50 }    # Lower limits for dev
                tokens: { limit: 10000 }
```

### Production Environment

```yaml
# config/packages/prod/lingoda_ai.yaml  
lingoda_ai:
    rate_limiting:
        enabled: true
        storage: 'cache.rate_limiter.redis'
        providers:
            openai:
                requests: { limit: 3500 }  # Full production limits
                tokens: { limit: 4000000 }
```

## Monitoring and Debugging

### Symfony Profiler Integration

When rate limiting is enabled, rate limit information appears in the Symfony profiler:

- Rate limit consumption
- Remaining quota
- Reset times
- Failed rate limit checks

### Logging

Enable detailed rate limiting logs:

```yaml
monolog:
    handlers:
        rate_limiting:
            type: stream
            path: '%kernel.logs_dir%/rate_limiting.log'
            level: debug
            channels: ['lingoda_ai']
```

### Metrics Collection

```php
// Custom metrics collection
use Symfony\Component\RateLimiter\RateLimiterFactory;

class RateLimitingMetrics 
{
    public function __construct(
        private RateLimiterFactory $openaiRequestsLimiter
    ) {}
    
    public function getOpenAiQuotaUsage(): array
    {
        $limiter = $this->openaiRequestsLimiter->create('openai_requests');
        $reservation = $limiter->reserve(0); // Check without consuming
        
        return [
            'available' => $reservation->getRemainingTokens(),
            'reset_time' => $reservation->getRetryAfter(),
        ];
    }
}
```

## Migration from SDK-Only Rate Limiting

### Step 1: Current SDK Usage

```php
// Before: SDK handles rate limiting internally
$platform = new Platform($clients);
$result = $platform->request($model, $prompt); // Built-in rate limiting
```

### Step 2: Enable Bundle Rate Limiting

```yaml
# Add to config/packages/lingoda_ai.yaml
lingoda_ai:
    rate_limiting:
        enabled: true  # This is the only change needed!
```

### Step 3: Customize as Needed

```yaml
# Optionally customize limits
lingoda_ai:
    rate_limiting:
        enabled: true
        providers:
            openai:
                requests: { limit: 500 }  # Custom limit
```

## Best Practices

### Conservative Limits

Always set limits to 90% of your actual quota:

```yaml
# If your OpenAI quota is 200 RPM, set limit to 180
openai:
    requests: { limit: 180 }
```

### Burst Handling

Use token bucket policy for handling traffic spikes:

```yaml
openai:
    requests:
        policy: 'token_bucket'  # Allows temporary bursts
        limit: 180
```

### Multi-Instance Deployments

Use Redis storage for shared rate limiting:

```yaml
rate_limiting:
    storage: 'cache.redis'  # Shared across all app instances
```

### Environment Variables

Make limits configurable:

```yaml
openai:
    requests: 
        limit: '%env(int:OPENAI_RATE_LIMIT_RPM)%'
    tokens:
        limit: '%env(int:OPENAI_RATE_LIMIT_TPM)%'
```

## Troubleshooting

### Rate Limits Too Strict

Symptoms: Frequent rate limit exceptions
Solution: Increase limits or check actual API quotas

```yaml
# Increase limits
openai:
    requests: { limit: 300 }  # Increased from 180
```

### Rate Limits Too Permissive  

Symptoms: API rejections from provider
Solution: Decrease limits closer to actual quotas

```yaml
# Be more conservative
openai:
    requests: { limit: 150 }  # Decreased from 180
```

### Memory Issues with In-Memory Storage

Symptoms: Memory usage growing over time
Solution: Use Redis or database storage

```yaml
rate_limiting:
    storage: 'cache.redis'  # Instead of in-memory
```

### Rate Limiting Not Working

Check configuration:

```bash
# Debug container services
bin/console debug:container lingoda_ai.external_rate_limiter

# Check rate limiter services
bin/console debug:container limiter.openai_requests
```

## Advanced Usage

### Custom Rate Limiter Keys

```php
// Custom rate limiting per user
class UserAwareRateLimiter implements ExternalRateLimiterInterface
{
    public function getRateLimiterKey(string $providerId, string $type, ModelInterface $model): string
    {
        $userId = $this->security->getUser()->getId();
        return sprintf('%s_%s_%s', $providerId, $type, $userId);
    }
}
```

### Dynamic Rate Limits

```php
// Adjust limits based on subscription tier
class TierBasedRateLimiting
{
    public function createLimiterConfig(User $user): array
    {
        return match ($user->getSubscriptionTier()) {
            'premium' => ['limit' => 1000],
            'basic' => ['limit' => 100], 
            default => ['limit' => 50],
        };
    }
}
```