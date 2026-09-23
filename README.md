# Lingoda AI Bundle

Symfony bundle for the [Lingoda AI SDK](https://github.com/lingoda/ai-sdk), providing seamless integration with Symfony's dependency injection container and configuration system.

## Features

- **🎯 Dual Platform Architecture**: Multi-provider Platform for flexibility + Single-provider platforms for simplicity
- **🔌 Smart Autowiring**: Named parameter injection (`Platform $openaiPlatform`)
- **⚡ Simple ask() Method**: `$platform->ask('question')` for minimal code
- **🛡️ Data Sanitization**: Built-in protection for sensitive information (inherited from AI SDK)
- **🚦 Enhanced Rate Limiting**: Built-in Symfony-managed rate limiting (enabled by default)
- **🎭 Full AI SDK Power**: Complete access to all AI SDK capabilities and models
- **📦 Simple Setup**: Straightforward configuration with environment variables
- **🔧 Full Symfony Integration**: DI container, configuration, logging, console commands

## Installation

```bash
composer require lingoda/ai-bundle
```

## Quick Start

### 1. Installation

```bash
composer require lingoda/ai-bundle
```

### 2. Configuration

Create `config/packages/lingoda_ai.yaml` and add your API keys to `.env`:

**Step 1: Add API keys to `.env`**

```env
OPENAI_API_KEY=sk-your-openai-key
ANTHROPIC_API_KEY=sk-ant-your-anthropic-key
GEMINI_API_KEY=your-gemini-key
```

**Step 2: Create configuration file**

Create `config/packages/lingoda_ai.yaml`:

```yaml
lingoda_ai:
    default_provider: openai # Used when ask() called without model parameter
    providers:
        openai:
            api_key: '%env(OPENAI_API_KEY)%'
            default_model: 'gpt-4o-2024-11-20' # Override default model
            # Optional: Custom HTTP client with retry logic, timeouts, etc.
            # http_client: 'openai.http_client'
            # timeout: 30 # Request timeout (only used if no custom http_client)
        anthropic:
            api_key: '%env(ANTHROPIC_API_KEY)%'
            default_model: 'claude-3-5-sonnet-20241022'
            # Optional: Custom HTTP client for Anthropic requests
            # http_client: 'anthropic.http_client'
            # timeout: 30
        gemini:
            api_key: '%env(GEMINI_API_KEY)%'
            default_model: 'gemini-2.0-pro'
            # Optional: Custom HTTP client for Gemini requests
            # http_client: 'gemini.http_client'
            # timeout: 30
    sanitization:
        enabled: true # Auto-sanitize sensitive data in prompt text (attachments are sent as provided)
        patterns: [] # Extra regexes, each replaced with [REDACTED], on top of the SDK defaults
    logging:
        enabled: true
        service: 'monolog.logger' # Logger service ID
```

### AWS Bedrock (Nova and Claude)

Bedrock needs `symfony/ai-bedrock-platform` (~0.13.0) and `async-aws/bedrock-runtime`. It has no API key: region and credentials come from an async-aws `BedrockRuntimeClient` service, for example the one the async-aws bundle registers.

```bash
composer require symfony/ai-bedrock-platform:~0.13.0 async-aws/bedrock-runtime
```

```yaml
# config/packages/async_aws.yaml
async_aws:
    clients:
        bedrock_runtime: ~ # region eu-west-1 (or another eu-/us- region), async-aws retries on

# config/packages/lingoda_ai.yaml
lingoda_ai:
    providers:
        bedrock: # opt-in: absent means not registered
            runtime_client: 'async_aws.client.bedrock_runtime'
            default_model: 'amazon.nova-2-lite-v1:0'
```

- Model ids are the Bedrock base ids (`amazon.nova-2-lite-v1:0`, `anthropic.claude-haiku-4-5-20251001-v1:0`, ...); the SDK prefixes the region's inference profile (`eu.`/`us.`) and refuses any other region.
- `http_client`, `api_key` and `organization` are rejected: an injected HTTP client would drop the async-aws retries (429, 5xx, throttling).
- Retries happen once, in async-aws: the rate-limited Bedrock client does not retry transport errors itself, only rate-limit waits.
- Configuring `bedrock` without the two packages fails at container build with the `composer require` command.
- Attachments (PDF, DOCX on Nova, images, text formats) work through `Conversation::withAttachments()`; see the [AI SDK README](https://github.com/lingoda/ai-sdk#documents-and-images) for the per-model format matrix.

### TypeSafe Jev (decisions)

Jev answers structured questions about a text state. It is registered as `Lingoda\AiSdk\Decision\DecisionPlatformInterface`, never on the main platform, so `ask()` cannot route to it and `default_provider: typesafe` is rejected.

```yaml
lingoda_ai:
    providers:
        typesafe: # registered only when api_key is set
            api_key: '%env(TYPESAFE_API_KEY)%'
            default_model: 'jev-latest' # default; pin 'jev-1.13.0' to keep decisions stable across TypeSafe releases
            # http_client: 'typesafe.http_client' # or timeout: 30
```

```php
use Lingoda\AiSdk\Decision\DecisionPlatformInterface;
use Lingoda\AiSdk\Decision\Question;

public function __construct(private DecisionPlatformInterface $decisions) {}

$result = $this->decisions->decide('Teacher log text', [
    'on_topic' => Question::noul('Is the log about the lesson?'),
    'mood' => Question::choice('Learner mood?', ['happy' => 'Positive', 'neutral' => 'Neutral', 'upset' => 'Negative']),
]);
$result->getAnswer('on_topic')->isTrue();
```

### 3. Usage

The bundle provides two usage patterns:

## Usage Patterns

### Pattern 1: Multi-Provider Platform (Flexible)

Use when you need access to multiple AI providers in the same service:

```php
use Lingoda\AiSdk\Platform;
use Lingoda\AiSdk\PlatformInterface;

class MyService
{
    public function __construct(
        private Platform $platform,  // AI SDK Platform with ALL configured providers
        // OR use the interface:
        // private PlatformInterface $platform
    ) {}

    public function compareModels(string $prompt): array
    {
        return [
            'openai' => $this->platform->ask($prompt, 'gpt-4o-mini')->getContent(),
            'anthropic' => $this->platform->ask($prompt, 'claude-3-5-haiku-20241022')->getContent(),
            'gemini' => $this->platform->ask($prompt, 'gemini-2.5-flash-002')->getContent(),
        ];
    }

    public function useDefaultProvider(string $prompt): string
    {
        // Uses your configured default_provider
        return $this->platform->ask($prompt)->getContent();
    }
}
```

### Pattern 2: Single-Provider Platforms (Simple)

Use when you want dedicated platforms for specific providers:

```php
use Lingoda\AiBundle\Platform\ProviderPlatform;
use Lingoda\AiSdk\PlatformInterface;

class MyService
{
    public function __construct(
        // Named parameter autowiring - the bundle automatically wires the right provider:
        private ProviderPlatform $openaiPlatform,      // Only OpenAI models
        private ProviderPlatform $anthropicPlatform,   // Only Anthropic models
        private PlatformInterface $geminiPlatform,     // Interface alias also works
    ) {}

    public function generateWithOpenAI(string $prompt): string
    {
        // Only has access to OpenAI models
        return $this->openaiPlatform->ask($prompt)->getContent();
    }

    public function generateWithAnthropic(string $prompt): string
    {
        // Only has access to Anthropic models
        return $this->anthropicPlatform->ask($prompt)->getContent();
    }

    public function generateWithGemini(string $prompt): string
    {
        // Only has access to Gemini models
        return $this->geminiPlatform->ask($prompt)->getContent();
    }
}
```

### Advanced AI SDK Features

All [AI SDK features](https://github.com/lingoda/ai-sdk#-usage-patterns) work seamlessly:

```php
use Lingoda\AiSdk\Prompt\UserPrompt;
use Lingoda\AiSdk\Prompt\Conversation;
use Lingoda\AiSdk\Prompt\SystemPrompt;

class AdvancedService
{
    public function __construct(
        private Platform $platform
    ) {}

    public function parameterizedPrompts(): string
    {
        $template = UserPrompt::create('Hello {{name}}, explain {{topic}} in simple terms');
        $prompt = $template->withParameters([
            'name' => 'Alice',
            'topic' => 'machine learning'
        ]);

        return $this->platform->ask($prompt)->getContent();
    }

    public function conversations(): string
    {
        $conversation = Conversation::withSystem(
            SystemPrompt::create('You are a helpful coding assistant'),
            UserPrompt::create('How do I implement dependency injection?')
        );

        return $this->platform->ask($conversation)->getContent();
    }

    public function audioFeatures(): void
    {
        // Text-to-Speech (requires OpenAI)
        $audioResult = $this->platform->textToSpeech('Hello world');
        file_put_contents('speech.mp3', $audioResult->getContent());

        // Speech-to-Text (requires OpenAI)
        $transcription = $this->platform->transcribeAudio('audio.mp3');
        echo $transcription->getContent();
    }
}
```

## Rate Limiting

The bundle provides enhanced rate limiting that integrates with Symfony's rate limiter component (enabled by default):

### Enhanced Rate Limiting (Enabled by Default)

The Bundle enables enhanced rate limiting by default with sensible provider defaults:

```yaml
lingoda_ai:
    providers:
        openai:
            api_key: '%env(OPENAI_API_KEY)%'
    # Enhanced rate limiting is enabled by default
    rate_limiting:
        enabled: true  # Default - can be disabled if needed
```

Limits resolve in this order: your `rate_limiting.providers.<provider>` values, then the bundle's defaults for any key you leave out, then the SDK's `AIProvider` defaults for a provider you do not list. Only the first two are shared through `storage` across processes; a provider you do not list gets an in-memory limit per process, so N workers allow N times that limit.

### Custom Rate Limits (Optional)

Configure custom limits per provider when needed:

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
        enabled: true  # Enable enhanced rate limiting
        storage: 'rate_limiter_pool'  # Use Redis for e.g. for shared state

        providers:
            openai:
                requests:
                    limit: 180    # Requests per minute
                    rate:
                        interval: '1 minute'
                        amount: 180
                tokens:
                    limit: 450000  # Tokens per minute
                    rate:
                        interval: '1 minute'
                        amount: 450000
```

**Benefits of Enhanced Rate Limiting (Default):**
- **Shared State**: Multiple app instances share rate limits via Redis
- **Provider Defaults**: Sensible rate limits for each provider out of the box
- **Environment-Specific**: Different limits for dev/staging/prod
- **Monitoring**: Integration with Symfony profiler for debugging
- **Custom Limits**: Override with provider-specific limits based on your API quotas

**When to Keep Enabled (Default):**
- ✅ Most production applications (recommended default)
- ✅ Multi-instance deployments (load balanced apps)
- ✅ Applications needing predictable rate limiting behavior

**When to Disable:**
- ❌ Local development with unlimited API quotas
- ❌ Testing environments where you want to bypass limits

See [Rate Limiting Documentation](docs/rate-limiting.md) for complete configuration options.

## Advanced Configuration

### Custom HTTP Clients

You can configure custom HTTP clients per provider for advanced retry logic, timeouts, and request customization:

```yaml
# config/packages/framework.yaml
framework:
    http_client:
        scoped_clients:
            openai.http_client:
                base_uri: 'https://api.openai.com'
                timeout: 60
                retry_failed:
                    max_retries: 3
                    delay: 1000
                    multiplier: 2
                    http_codes:
                        0: ['GET', 'POST'] # Network errors
                        429: true # Rate limits
                        500: ['GET', 'POST'] # Server errors
                headers:
                    'User-Agent': 'MyApp/1.0'

            anthropic.http_client:
                base_uri: 'https://api.anthropic.com'
                timeout: 45
                retry_failed:
                    max_retries: 2
                    delay: 500
```

```yaml
# config/packages/lingoda_ai.yaml
lingoda_ai:
    providers:
        openai:
            api_key: '%env(OPENAI_API_KEY)%'
            http_client: 'openai.http_client' # Use custom client
        anthropic:
            api_key: '%env(ANTHROPIC_API_KEY)%'
            http_client: 'anthropic.http_client' # Use custom client
```

This gives you full control over:
- **Retry strategies** for handling rate limits and network errors
- **Custom timeouts** per provider based on your needs
- **Request/response headers** for debugging and user-agent identification
- **Base URIs** if using proxy servers or custom endpoints

## Console Commands

The bundle provides helpful console commands:

```bash
# List all configured AI providers and their status
php bin/console ai:list:providers

# List available models for each provider
php bin/console ai:list:models

# List models for a specific provider
php bin/console ai:list:models --provider=openai

# Detailed model information with availability status
php bin/console ai:list:models --detailed

# Test connections to all configured providers
php bin/console ai:test:connection

# Test rate limiting configuration (verify limits are enforced)
php bin/console ai:test:rate-limiting

# Test rate limiting with custom request count
php bin/console ai:test:rate-limiting --requests=10
```

## Development

### Code Quality

```bash
# Install dependencies
composer install

# Run code style check
vendor/bin/ecs check

# Fix code style
vendor/bin/ecs check --fix

# Run static analysis
vendor/bin/phpstan analyse

# Run tests
vendor/bin/phpunit
```

### Requirements

- PHP ^8.4
- Symfony ^7.4|^8.0
- lingoda/ai-sdk ^2.0
- For Bedrock: symfony/ai-bedrock-platform ~0.13.0 and async-aws/bedrock-runtime

## Available Services

The bundle automatically registers these services based on your configured API keys:

### Multi-Provider Services
- `lingoda_ai.platform` - Main Platform service with all configured providers
- `Lingoda\AiSdk\Platform` - Alias to the main Platform service
- `Lingoda\AiSdk\PlatformInterface` - Interface alias to the main Platform service

### Single-Provider Services
- `openaiPlatform` - OpenAI-only platform (if `OPENAI_API_KEY` configured)
- `anthropicPlatform` - Anthropic-only platform (if `ANTHROPIC_API_KEY` configured)
- `geminiPlatform` - Gemini-only platform (if `GEMINI_API_KEY` configured)
- `bedrockPlatform` - Bedrock-only platform (if `providers.bedrock` configured)

### Decision Services
- `lingoda_ai.decision_platform.typesafe` - TypeSafe Jev (if `providers.typesafe.api_key` configured)
- `Lingoda\AiSdk\Decision\DecisionPlatformInterface` - alias to it

### Autowiring Support
```php
// These all work automatically:
private Platform $platform;                          // Multi-provider
private PlatformInterface $platform;                 // Multi-provider (interface)
private ProviderPlatform $openaiPlatform;            // OpenAI only
private PlatformInterface $anthropicPlatform;        // Anthropic only (interface)
private ProviderPlatform $geminiPlatform;            // Gemini only
```

## Architecture Benefits

- **🎯 Dual Architecture**: Choose multi-provider flexibility OR single-provider simplicity
- **📦 Simple Setup**: Straightforward configuration with environment variables
- **🔌 Smart Autowiring**: Named parameter injection automatically wires correct providers
- **⚡ Full AI SDK Power**: Complete access to all AI SDK features and capabilities
- **🛡️ Built-in Security**: Automatic data sanitization inherited from AI SDK
- **🎭 Provider Flexibility**: Register only the providers you need

## Supported Models & Features

The bundle supports all models and features from the [Lingoda AI SDK](https://github.com/lingoda/ai-sdk#-supported-models):

**OpenAI Models**: GPT-5, GPT-4.1, GPT-4o series, Audio models (Whisper, TTS)
**Anthropic Models**: Claude 4.1, Claude 4.0, Claude 3.7, Claude 3.5 series
**Google Models**: Gemini 2.5 Pro and Flash with 1M context
**AWS Bedrock (EU/US inference profiles)**: Amazon Nova Micro, Lite, Pro, 2 Lite; Claude Haiku 4.5 up to Opus 5.5
**TypeSafe Jev**: `jev-1.13.0`, `jev-latest`, `jev-preview` (decisions, via `DecisionPlatformInterface`)

**AI Capabilities**: Text generation, conversations, audio synthesis/transcription, parameterized prompts, streaming, vision, tools, reasoning, and more.

See the [AI SDK documentation](https://github.com/lingoda/ai-sdk/tree/main/docs) for complete feature documentation.

## Getting Help

- 📖 **AI SDK Docs**: [Complete documentation](https://github.com/lingoda/ai-bundle/tree/main/docs)
- 🐛 **Issues**: [Report bugs or request features](https://github.com/lingoda/ai-bundle/issues)
- 💬 **Discussions**: [Community discussions](https://github.com/lingoda/ai-bundle/discussions)

## License

MIT License. See [LICENSE](LICENSE) for details.
