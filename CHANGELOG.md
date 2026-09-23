# Changelog

## 2.0.0

### Added
- AWS Bedrock provider (`providers.bedrock`): Amazon Nova and Claude through an async-aws `BedrockRuntimeClient` service (`runtime_client`), registered only when configured. `bedrockPlatform` service and autowiring alias. Needs `symfony/ai-bedrock-platform` ~0.13.0 and `async-aws/bedrock-runtime`; configuring it without them fails at container build with the `composer require` command.
- TypeSafe Jev (`providers.typesafe`): `lingoda_ai.decision_platform.typesafe`, aliased as `Lingoda\AiSdk\Decision\DecisionPlatformInterface`, registered only with an `api_key`. Default model `jev-latest` (set `jev-1.13.0` to pin). Never part of the main platform.
- Default rate limits for Bedrock (60 requests, 100,000 tokens per minute). TypeSafe has defaults in the table (1,080 requests, 13,500,000 tokens per minute), but `decide()` is not rate-limited yet, so they are not enforced.
- Rate-limited clients use the SDK's per-provider token estimators (`TokenEstimatorRegistry::createDefault()`: OpenAI, Anthropic, Gemini; the generic estimator for the rest) instead of the generic one for every provider. Token estimates, and with them when token limits kick in, change for OpenAI, Anthropic and Gemini.
- Attachments (PDF, DOCX, images, text formats) through ai-sdk 2.0 `Conversation::withAttachments()`.

### Changed
- Requires `lingoda/ai-sdk` ^2.0, PHP ^8.4 and Symfony ^7.4|^8.0.
- The rate-limited Bedrock client does not retry transport errors itself: async-aws already retries 429, 5xx and throttling.
- `BundleExternalRateLimiter` reads the configured limiter factories from a service locator instead of the whole container; `getRateLimiter()` returns `RateLimiterFactoryInterface`.

### Upgrading from 1.x
- `sanitization.patterns` is now applied: each pattern is redacted as `[REDACTED]` in prompt text, on top of the SDK defaults. In 1.x it was accepted but ignored, so review existing patterns before upgrading. An invalid regular expression now fails validation.
- The `lingoda_ai.default_platform` alias is removed (it pointed at a missing service when the default provider was not registered). Inject `PlatformInterface`, or a provider platform such as `PlatformInterface $openaiPlatform`.
- `lingoda_ai.rate_limiter.<provider>_<type>` factories are private. Use the public `limiter.<provider>_<type>` aliases.
- Only limiters configured under `rate_limiting.providers` are used. Before, any container service named `limiter.<provider>_<type>` (for example a `framework.rate_limiter` entry called `openai_requests`) was picked up implicitly; configure the limit under `lingoda_ai.rate_limiting.providers` instead.
- A rate limiter definition that cannot be registered now fails the container build instead of being skipped silently.
- `default_provider: typesafe` is rejected: Jev answers `decide()` only.
- Unknown keys under `providers` (for example a misspelled `openia`) now fail validation instead of being ignored.
