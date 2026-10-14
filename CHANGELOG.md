# Changelog

## 2.1.0

### Changed
- The OpenAI default model is `gpt-6-luna`, was `gpt-4o-mini`. OpenAI removes `gpt-4o`, `gpt-4o-mini`, `gpt-4.1` and `gpt-4.1-mini` from the API on 14 January 2027 and names `gpt-6-luna` as the replacement. An application that sets `providers.openai.default_model` is unaffected; one that relies on the bundle default would have stopped working on that date.
- Requires `lingoda/ai-sdk` ^2.4, which is where the GPT-6 models are defined. It also sends `max_completion_tokens` rather than `max_tokens` for them, and keeps `reasoning_effort` at `none` by default so `temperature` stays usable.
- `ai:test-rate-limiting` builds its mock client on `gpt-6-luna` rather than `gpt-4o-mini`. It makes no real request; the id just stops naming a retiring model.

### Fixed
- `AiTestRateLimitingCommand::executeMockTest()` took an `$output` it never used, writing through `SymfonyStyle` instead. PHPStan 2.3 reports it; 2.2 did not, and CI resolves PHPStan fresh because this repo keeps no lockfile, so main was already failing on it.

## 2.0.0

### Added
- AWS Bedrock provider (`providers.bedrock`): Amazon Nova and Claude through an async-aws `BedrockRuntimeClient` service (`runtime_client`), registered only when configured. `bedrockPlatform` service and autowiring alias. Needs `symfony/ai-bedrock-platform` ~0.13.0 and `async-aws/bedrock-runtime`; configuring it without them fails at container build with the `composer require` command.
- TypeSafe Jev (`providers.typesafe`): `lingoda_ai.decision_platform.typesafe`, aliased as `Lingoda\AiSdk\Decision\DecisionPlatformInterface`, registered only with an `api_key`. Default model `jev-latest` (set `jev-1.13.0` to pin). Never part of the main platform.
- Default rate limits for Bedrock (60 requests, 100,000 tokens per minute) and TypeSafe (1,080 requests, 13,500,000 tokens per minute, 90% of its published account limits).
- With rate limiting enabled, TypeSafe is wrapped in ai-sdk's `RateLimitedDecisionPlatform` (`lingoda_ai.decision_platform.typesafe`, the raw platform is `lingoda_ai.decision_platform.typesafe.base`): each `decide()` goes through the limiter and is retried with backoff on 429, 529, 502, 503 and 504.
- Rate-limited clients use the SDK's per-provider token estimators (`TokenEstimatorRegistry::createDefault()`: OpenAI, Anthropic, Gemini; the generic estimator for the rest) instead of the generic one for every provider. Token estimates, and with them when token limits kick in, change for OpenAI, Anthropic and Gemini.
- Attachments (PDF, DOCX, images, text formats) through ai-sdk 2.0 `Conversation::withAttachments()`.

### Changed
- Requires `lingoda/ai-sdk` ^2.1, PHP ^8.4 and Symfony ^7.4|^8.0.
- The rate-limited Bedrock client does not retry transport errors itself: async-aws already retries 429, 5xx and throttling.
- `BundleExternalRateLimiter` reads the configured limiter factories from a service locator instead of the whole container; `getRateLimiter()` returns `RateLimiterFactoryInterface`.

### Fixed
- Provider platforms (`openaiPlatform`, `bedrockPlatform`, ...) now use the same sanitization (`enabled`, `patterns`), logger and `default_model` as the main platform. Before, they always ran the default sanitizer, and got their default model only if the main platform had been built first. `ProviderPlatform` takes the optional `$enableSanitization`, `$sanitizer` and `$logger` arguments of `Platform`.

### Upgrading from 1.x
- `sanitization.patterns` is now applied: each pattern is redacted as `[REDACTED]` in prompt text, on top of the SDK defaults. In 1.x it was accepted but ignored, so review existing patterns before upgrading. An invalid regular expression now fails validation.
- The `lingoda_ai.default_platform` alias is removed (it pointed at a missing service when the default provider was not registered). Inject `PlatformInterface`, or a provider platform such as `PlatformInterface $openaiPlatform`.
- `lingoda_ai.rate_limiter.<provider>_<type>` factories are private. Use the public `limiter.<provider>_<type>` aliases.
- Only limiters configured under `rate_limiting.providers` are used. Before, any container service named `limiter.<provider>_<type>` (for example a `framework.rate_limiter` entry called `openai_requests`) was picked up implicitly; configure the limit under `lingoda_ai.rate_limiting.providers` instead.
- A rate limiter definition that cannot be registered now fails the container build instead of being skipped silently.
- `default_provider: typesafe` is rejected: Jev answers `decide()` only.
- Unknown keys under `providers` (for example a misspelled `openia`) now fail validation instead of being ignored.
