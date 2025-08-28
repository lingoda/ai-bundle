<?php

declare(strict_types = 1);

namespace Lingoda\AiBundle\Platform;

use Lingoda\AiSdk\Audio\AudioOptionsInterface;
use Lingoda\AiSdk\ClientInterface;
use Lingoda\AiSdk\Exception\ClientException;
use Lingoda\AiSdk\Exception\InvalidArgumentException;
use Lingoda\AiSdk\Exception\ModelNotFoundException;
use Lingoda\AiSdk\Exception\RuntimeException;
use Lingoda\AiSdk\Platform;
use Lingoda\AiSdk\PlatformInterface;
use Lingoda\AiSdk\Prompt\Conversation;
use Lingoda\AiSdk\Prompt\Prompt;
use Lingoda\AiSdk\Provider\ProviderCollection;
use Lingoda\AiSdk\ProviderInterface;
use Lingoda\AiSdk\Result\BinaryResult;
use Lingoda\AiSdk\Result\ResultInterface;
use Lingoda\AiSdk\Result\StreamResult;
use Lingoda\AiSdk\Result\TextResult;

/**
 * Single-provider platform that wraps the AI SDK Platform with one client.
 * Provides access to a specific AI provider (OpenAI, Anthropic, Gemini, etc.).
 */
final readonly class ProviderPlatform implements PlatformInterface
{
    private PlatformInterface $platform;

    public function __construct(ClientInterface $client)
    {
        $this->platform = new Platform([$client]);
    }

    /**
     * @throws ClientException|InvalidArgumentException|ModelNotFoundException|RuntimeException
     */
    public function ask(string|Prompt|Conversation $input, ?string $model = null, array $options = []): ResultInterface
    {
        return $this->platform->ask($input, $model, $options);
    }

    /**
     * @throws InvalidArgumentException|RuntimeException|ClientException
     */
    public function textToSpeech(string $input, AudioOptionsInterface $options): BinaryResult
    {
        return $this->platform->textToSpeech($input, $options);
    }

    /**
     * @throws InvalidArgumentException|RuntimeException|ClientException
     */
    public function textToSpeechStream(string $input, AudioOptionsInterface $options): StreamResult
    {
        return $this->platform->textToSpeechStream($input, $options);
    }

    /**
     * @throws InvalidArgumentException|RuntimeException|ClientException
     */
    public function transcribeAudio(string $audioFilePath, AudioOptionsInterface $options): TextResult
    {
        return $this->platform->transcribeAudio($audioFilePath, $options);
    }

    /**
     * @throws InvalidArgumentException|RuntimeException|ClientException
     */
    public function translateAudio(string $audioFilePath, AudioOptionsInterface $options): TextResult
    {
        return $this->platform->translateAudio($audioFilePath, $options);
    }

    public function getProvider(string $name): ProviderInterface
    {
        return $this->platform->getProvider($name);
    }

    public function getAvailableProviders(): ProviderCollection
    {
        return $this->platform->getAvailableProviders();
    }

    public function hasProvider(string $name): bool
    {
        return $this->platform->hasProvider($name);
    }

    public function configureProviderDefaultModel(string $providerName, string $defaultModel): void
    {
        $this->platform->configureProviderDefaultModel($providerName, $defaultModel);
    }
}
