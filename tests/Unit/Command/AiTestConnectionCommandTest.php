<?php

declare(strict_types=1);

namespace Lingoda\AiBundle\Tests\Unit\Command;

use Lingoda\AiBundle\Command\AiTestConnectionCommand;
use Lingoda\AiSdk\PlatformInterface;
use Lingoda\AiSdk\ProviderInterface;
use Lingoda\AiSdk\Result\ResultInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class AiTestConnectionCommandTest extends TestCase
{
    private MockObject&PlatformInterface $platform;
    private AiTestConnectionCommand $command;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->platform = $this->createMock(PlatformInterface::class);
        $this->command = new AiTestConnectionCommand($this->platform);
        $this->commandTester = new CommandTester($this->command);
    }

    public function testCommandInstantiation(): void
    {
        self::assertSame('ai:test:connection', $this->command->getName());
        self::assertSame('Test connections to all configured AI providers', $this->command->getDescription());
    }

    public function testExecuteWithNoProviders(): void
    {
        $this->platform
            ->expects($this->once())
            ->method('getAvailableProviders')
            ->willReturn([]);

        $exitCode = $this->commandTester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('No AI providers configured', $this->commandTester->getDisplay());
    }

    public function testExecuteWithSuccessfulProviders(): void
    {
        $mockProvider = $this->createMock(ProviderInterface::class);
        $mockProvider
            ->expects($this->once())
            ->method('getDefaultModel')
            ->willReturn('gpt-4o-mini');

        $mockResult = $this->createMock(ResultInterface::class);
        $mockResult
            ->expects($this->once())
            ->method('getContent')
            ->willReturn('Hello! How can I assist you today?');

        $this->platform
            ->expects($this->once())
            ->method('getAvailableProviders')
            ->willReturn(['openai']);

        $this->platform
            ->expects($this->once())
            ->method('getProvider')
            ->with('openai')
            ->willReturn($mockProvider);

        $this->platform
            ->expects($this->once())
            ->method('ask')
            ->with('Hello', 'gpt-4o-mini')
            ->willReturn($mockResult);

        $exitCode = $this->commandTester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        
        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('Testing openai', $display);
        self::assertStringContainsString('✓ openai connection successful', $display);
        self::assertStringContainsString('Default model: gpt-4o-mini', $display);
        self::assertStringContainsString('Response length: 34 characters', $display);
    }

    public function testExecuteWithFailedProvider(): void
    {
        $mockProvider = $this->createMock(ProviderInterface::class);
        $mockProvider
            ->expects($this->once())
            ->method('getDefaultModel')
            ->willReturn('gpt-4o-mini');

        $this->platform
            ->expects($this->once())
            ->method('getAvailableProviders')
            ->willReturn(['openai']);

        $this->platform
            ->expects($this->once())
            ->method('getProvider')
            ->with('openai')
            ->willReturn($mockProvider);

        $this->platform
            ->expects($this->once())
            ->method('ask')
            ->with('Hello', 'gpt-4o-mini')
            ->willThrowException(new \Exception('API connection failed'));

        $exitCode = $this->commandTester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        
        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('Testing openai', $display);
        self::assertStringContainsString('✗ openai connection failed', $display);
        self::assertStringContainsString('API connection failed', $display);
    }

    public function testExecuteWithMultipleProvidersPartialFailure(): void
    {
        $openaiProvider = $this->createMock(ProviderInterface::class);
        $openaiProvider
            ->expects($this->once())
            ->method('getDefaultModel')
            ->willReturn('gpt-4o-mini');

        $anthropicProvider = $this->createMock(ProviderInterface::class);
        $anthropicProvider
            ->expects($this->once())
            ->method('getDefaultModel')
            ->willReturn('claude-3-5-haiku-20241022');

        $mockResult = $this->createMock(ResultInterface::class);
        $mockResult
            ->expects($this->once())
            ->method('getContent')
            ->willReturn('Hello!');

        $this->platform
            ->expects($this->once())
            ->method('getAvailableProviders')
            ->willReturn(['openai', 'anthropic']);

        $this->platform
            ->expects($this->exactly(2))
            ->method('getProvider')
            ->willReturnMap([
                ['openai', $openaiProvider],
                ['anthropic', $anthropicProvider],
            ]);

        $this->platform
            ->expects($this->exactly(2))
            ->method('ask')
            ->willReturnOnConsecutiveCalls(
                $mockResult,
                $this->throwException(new \Exception('Anthropic API error'))
            );

        $exitCode = $this->commandTester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        
        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('✓ openai connection successful', $display);
        self::assertStringContainsString('✗ anthropic connection failed', $display);
        self::assertStringContainsString('Some provider connections failed', $display);
    }

    public function testExecuteWithAllProvidersSuccessful(): void
    {
        $providers = ['openai', 'anthropic', 'gemini'];
        $models = ['gpt-4o-mini', 'claude-3-5-haiku-20241022', 'gemini-2.5-flash-002'];
        
        $mockProviders = [];
        $mockResults = [];
        
        foreach ($models as $i => $model) {
            $provider = $this->createMock(ProviderInterface::class);
            $provider->expects($this->once())
                ->method('getDefaultModel')
                ->willReturn($model);
            $mockProviders[] = $provider;
            
            $result = $this->createMock(ResultInterface::class);
            $result->expects($this->once())
                ->method('getContent')
                ->willReturn("Response from {$providers[$i]}");
            $mockResults[] = $result;
        }

        $this->platform
            ->expects($this->once())
            ->method('getAvailableProviders')
            ->willReturn($providers);

        $this->platform
            ->expects($this->exactly(3))
            ->method('getProvider')
            ->willReturnCallback(function (string $provider) use ($providers, $mockProviders) {
                $index = array_search($provider, $providers, true);
                return $mockProviders[$index];
            });

        $this->platform
            ->expects($this->exactly(3))
            ->method('ask')
            ->willReturnOnConsecutiveCalls(...$mockResults);

        $exitCode = $this->commandTester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        
        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('✓ openai connection successful', $display);
        self::assertStringContainsString('✓ anthropic connection successful', $display);
        self::assertStringContainsString('✓ gemini connection successful', $display);
        self::assertStringContainsString('All provider connections successful!', $display);
    }

    public function testExecuteWithVerboseOutputOnError(): void
    {
        $exception = new \RuntimeException('Connection timeout', 0, new \Exception('Network error'));
        
        $this->platform
            ->expects($this->once())
            ->method('getAvailableProviders')
            ->willReturn(['test_provider']);

        $this->platform
            ->expects($this->once())
            ->method('getProvider')
            ->with('test_provider')
            ->willThrowException($exception);

        // Execute with verbose flag
        $exitCode = $this->commandTester->execute([], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

        self::assertSame(Command::FAILURE, $exitCode);
        
        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('✗ test_provider connection failed', $display);
        self::assertStringContainsString('Connection timeout', $display);
        self::assertStringContainsString('Exception: RuntimeException', $display);
        self::assertStringContainsString('File:', $display);
        self::assertStringContainsString('Some provider connections failed', $display);
    }

    public function testConstructorSetsPropertiesCorrectly(): void
    {
        // Test that constructor properly initializes the command
        self::assertSame('ai:test:connection', $this->command->getName());
        self::assertSame('Test connections to all configured AI providers', $this->command->getDescription());
    }
}