# Changelog

## 2.0.0 (unreleased)

### Added
- AWS Bedrock provider (`providers.bedrock`): Amazon Nova and Claude through an async-aws `BedrockRuntimeClient` service (`runtime_client`), registered only when configured. `bedrockPlatform` service and autowiring alias. Needs `symfony/ai-bedrock-platform` ~0.13.0 and `async-aws/bedrock-runtime`; configuring it without them fails at container build with the `composer require` command.
- TypeSafe Jev (`providers.typesafe`): `lingoda_ai.decision_platform.typesafe`, aliased as `Lingoda\AiSdk\Decision\DecisionPlatformInterface`, registered only with an `api_key`. New `base_url` option. Never part of the main platform.
- Attachments (PDF, DOCX, images, text formats) through ai-sdk 2.0 `Conversation::withAttachments()`.

### Changed
- Requires `lingoda/ai-sdk` ^2.0, PHP ^8.4 and Symfony ^7.4|^8.0.
- Rate-limit defaults per provider come from `AIProvider::getDefaultRateLimits()` in ai-sdk (OpenAI, Anthropic and Gemini values unchanged; Bedrock and TypeSafe get their own instead of the 60 requests / 60,000 tokens fallback).
- The rate-limited Bedrock client does not retry transport errors itself: async-aws already retries 429, 5xx and throttling.
- `BundleExternalRateLimiter` reads the configured limiter factories from a service locator instead of the whole container; `getRateLimiter()` returns `RateLimiterFactoryInterface`.

### Upgrading from 1.x
- `sanitization.patterns` is removed (it was never applied). Delete it from your config: it now fails validation.
- The `lingoda_ai.default_platform` alias is removed (it pointed at a missing service when the default provider was not registered). Inject `PlatformInterface`, or a provider platform such as `PlatformInterface $openaiPlatform`.
- `lingoda_ai.rate_limiter.<provider>_<type>` factories are private. Use the public `limiter.<provider>_<type>` aliases.
- Only limiters configured under `rate_limiting.providers` are used. Before, any container service named `limiter.<provider>_<type>` (for example a `framework.rate_limiter` entry called `openai_requests`) was picked up implicitly; configure the limit under `lingoda_ai.rate_limiting.providers` instead.
- A rate limiter definition that cannot be registered now fails the container build instead of being skipped silently.
- `default_provider: typesafe` is rejected: Jev answers `decide()` only.
