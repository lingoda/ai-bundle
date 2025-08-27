<?php
declare(strict_types=1);

namespace Lingoda\AiBundle\Tests\Unit\Platform;

use Lingoda\AiBundle\Platform\ProviderPlatform;
use Lingoda\AiSdk\Audio\AudioOptionsInterface;
use Lingoda\AiSdk\ClientInterface;
use Lingoda\AiSdk\Platform;
use Lingoda\AiSdk\Prompt\Conversation;
use Lingoda\AiSdk\Prompt\UserPrompt;
use Lingoda\AiSdk\ProviderInterface;
use Lingoda\AiSdk\Result\BinaryResult;
use Lingoda\AiSdk\Result\ResultInterface;
use Lingoda\AiSdk\Result\StreamResult;
use Lingoda\AiSdk\Result\TextResult;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ProviderPlatformTest extends TestCase
{
    /** @var Platform&MockObject */
    private MockObject $mockPlatform;
    /** @var ProviderInterface&MockObject */
    private MockObject $mockProvider;
    /** @var ResultInterface&MockObject */
    private MockObject $mockResult;
    /** @var BinaryResult&MockObject */
    private MockObject $mockBinaryResult;
    /** @var StreamResult&MockObject */
    private MockObject $mockStreamResult;
    /** @var TextResult&MockObject */
    private MockObject $mockTextResult;
    /** @var AudioOptionsInterface&MockObject */
    private MockObject $mockAudioOptions;
    private ProviderPlatform $providerPlatform;

    protected function setUp(): void
    {
        $mockClient = $this->createMock(ClientInterface::class);
        $this->mockPlatform = $this->createMock(Platform::class);
        $this->mockProvider = $this->createMock(ProviderInterface::class);
        $this->mockResult = $this->createMock(ResultInterface::class);
        $this->mockBinaryResult = $this->createMock(BinaryResult::class);
        $this->mockStreamResult = $this->createMock(StreamResult::class);
        $this->mockTextResult = $this->createMock(TextResult::class);
        $this->mockAudioOptions = $this->createMock(AudioOptionsInterface::class);
        
        // Create a ProviderPlatform with injected mock platform using reflection
        $this->providerPlatform = new ProviderPlatform($mockClient);
        $this->injectMockPlatform($this->providerPlatform, $this->mockPlatform);
    }

    public function testGetAvailableProvidersDelegatesToPlatform(): void
    {
        $expectedProviders = ['openai', 'anthropic'];
        
        $this->mockPlatform
            ->expects(self::once())
            ->method('getAvailableProviders')
            ->willReturn($expectedProviders);
        
        $result = $this->providerPlatform->getAvailableProviders();
        
        self::assertSame($expectedProviders, $result);
    }

    public function testHasProviderDelegatesToPlatform(): void
    {
        $this->mockPlatform
            ->expects(self::once())
            ->method('hasProvider')
            ->with('openai')
            ->willReturn(true);
        
        $result = $this->providerPlatform->hasProvider('openai');
        
        self::assertTrue($result);
    }

    public function testHasProviderReturnsFalseWhenNotFound(): void
    {
        $this->mockPlatform
            ->expects(self::once())
            ->method('hasProvider')
            ->with('nonexistent')
            ->willReturn(false);
        
        $result = $this->providerPlatform->hasProvider('nonexistent');
        
        self::assertFalse($result);
    }

    public function testGetProviderDelegatesToPlatform(): void
    {
        $this->mockPlatform
            ->expects(self::once())
            ->method('getProvider')
            ->with('openai')
            ->willReturn($this->mockProvider);
        
        $result = $this->providerPlatform->getProvider('openai');
        
        self::assertSame($this->mockProvider, $result);
    }

    public function testConfigureProviderDefaultModelDelegatesToPlatform(): void
    {
        $this->mockPlatform
            ->expects(self::once())
            ->method('configureProviderDefaultModel')
            ->with('openai', 'gpt-4o-mini');
        
        $this->providerPlatform->configureProviderDefaultModel('openai', 'gpt-4o-mini');
    }

    public function testAskWithStringPromptDelegatesToPlatform(): void
    {
        $this->mockPlatform
            ->expects(self::once())
            ->method('ask')
            ->with('test prompt', null, [])
            ->willReturn($this->mockResult);
        
        $result = $this->providerPlatform->ask('test prompt');
        
        self::assertSame($this->mockResult, $result);
    }

    public function testAskWithPromptObjectDelegatesToPlatform(): void
    {
        $prompt = UserPrompt::create('test prompt content');
        
        $this->mockPlatform
            ->expects(self::once())
            ->method('ask')
            ->with($prompt, null, [])
            ->willReturn($this->mockResult);
        
        $result = $this->providerPlatform->ask($prompt);
        
        self::assertSame($this->mockResult, $result);
    }

    public function testAskWithConversationObjectDelegatesToPlatform(): void
    {
        $userPrompt = UserPrompt::create('test conversation message');
        $conversation = new Conversation($userPrompt);
        
        $this->mockPlatform
            ->expects(self::once())
            ->method('ask')
            ->with($conversation, null, [])
            ->willReturn($this->mockResult);
        
        $result = $this->providerPlatform->ask($conversation);
        
        self::assertSame($this->mockResult, $result);
    }

    public function testAskWithModelAndOptionsDelegatesToPlatform(): void
    {
        $options = ['temperature' => 0.7];
        
        $this->mockPlatform
            ->expects(self::once())
            ->method('ask')
            ->with('test prompt', 'gpt-4o-mini', $options)
            ->willReturn($this->mockResult);
        
        $result = $this->providerPlatform->ask('test prompt', 'gpt-4o-mini', $options);
        
        self::assertSame($this->mockResult, $result);
    }

    public function testTextToSpeechDelegatesToPlatform(): void
    {
        $this->mockPlatform
            ->expects(self::once())
            ->method('textToSpeech')
            ->with('Hello world', $this->mockAudioOptions)
            ->willReturn($this->mockBinaryResult);
        
        $result = $this->providerPlatform->textToSpeech('Hello world', $this->mockAudioOptions);
        
        self::assertSame($this->mockBinaryResult, $result);
    }

    public function testTextToSpeechStreamDelegatesToPlatform(): void
    {
        $this->mockPlatform
            ->expects(self::once())
            ->method('textToSpeechStream')
            ->with('Hello world', $this->mockAudioOptions)
            ->willReturn($this->mockStreamResult);
        
        $result = $this->providerPlatform->textToSpeechStream('Hello world', $this->mockAudioOptions);
        
        self::assertSame($this->mockStreamResult, $result);
    }

    public function testTranscribeAudioDelegatesToPlatform(): void
    {
        $audioPath = '/path/to/audio.mp3';
        
        $this->mockPlatform
            ->expects(self::once())
            ->method('transcribeAudio')
            ->with($audioPath, $this->mockAudioOptions)
            ->willReturn($this->mockTextResult);
        
        $result = $this->providerPlatform->transcribeAudio($audioPath, $this->mockAudioOptions);
        
        self::assertSame($this->mockTextResult, $result);
    }

    public function testTranslateAudioDelegatesToPlatform(): void
    {
        $audioPath = '/path/to/audio.mp3';
        
        $this->mockPlatform
            ->expects(self::once())
            ->method('translateAudio')
            ->with($audioPath, $this->mockAudioOptions)
            ->willReturn($this->mockTextResult);
        
        $result = $this->providerPlatform->translateAudio($audioPath, $this->mockAudioOptions);
        
        self::assertSame($this->mockTextResult, $result);
    }

    private function injectMockPlatform(ProviderPlatform $providerPlatform, Platform $mockPlatform): void
    {
        $reflection = new ReflectionClass($providerPlatform);
        $platformProperty = $reflection->getProperty('platform');
        $platformProperty->setAccessible(true);
        $platformProperty->setValue($providerPlatform, $mockPlatform);
    }
}