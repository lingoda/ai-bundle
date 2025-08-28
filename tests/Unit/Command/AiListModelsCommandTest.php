<?php

declare(strict_types = 1);
namespace Lingoda\AiBundle\Tests\Unit\Command;

use Lingoda\AiBundle\Command\AiListModelsCommand;
use Lingoda\AiSdk\PlatformInterface;
use Lingoda\AiSdk\Provider\ProviderCollection;
use Lingoda\AiSdk\ProviderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AiListModelsCommandTest extends TestCase
{
    private MockObject&PlatformInterface $platform;
    private AiListModelsCommand $command;
    private CommandTester $commandTester;
    protected function setUp(): void
    {
        $this->platform = $this->createMock(PlatformInterface::class);
        $this->command = new AiListModelsCommand($this->platform);
        $this->commandTester = new CommandTester($this->command);
    }
    public function testCommandInstantiation(): void
    {
        self::assertSame('ai:list:models', $this->command->getName());
        self::assertSame('List all available models for each provider', $this->command->getDescription());
    }
    public function testExecuteWithNoProviders(): void
    {
        $this->platform
            ->expects($this->once())
            ->method('getAvailableProviders')
            ->willReturn(new ProviderCollection([]))
        ;
        $exitCode = $this->commandTester->execute([]);
        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('No AI providers configured', $this->commandTester->getDisplay());
    }
    public function testExecuteWithSingleProvider(): void
    {
        $mockProvider = $this->createMock(ProviderInterface::class);
        $mockProvider
            ->expects($this->once())
            ->method('getAvailableModels')
            ->willReturn(['gpt-4o-mini', 'gpt-4o', 'gpt-3.5-turbo'])
        ;

        $mockProvider
            ->expects($this->once())
            ->method('getDefaultModel')
            ->willReturn('gpt-4o-mini')
        ;

        $mockProvider
            ->method('getId')
            ->willReturn('openai')
        ;

        $this->platform
            ->expects($this->once())
            ->method('getAvailableProviders')
            ->willReturn(new ProviderCollection([$mockProvider]))
        ;
        $this->platform
            ->expects($this->once())
            ->method('getProvider')
            ->with('openai')
            ->willReturn($mockProvider)
        ;
        $exitCode = $this->commandTester->execute([]);
        self::assertSame(Command::SUCCESS, $exitCode);

        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('Available AI Models', $display);
        self::assertStringContainsString('openai', $display);
        self::assertStringContainsString('gpt-4o-mini', $display);
        self::assertStringContainsString('gpt-4o', $display);
        self::assertStringContainsString('gpt-3.5-turbo', $display);
        self::assertStringContainsString('(default)', $display);
    }
    public function testExecuteWithMultipleProviders(): void
    {
        $openaiProvider = $this->createMock(ProviderInterface::class);
        $openaiProvider
            ->expects($this->once())
            ->method('getAvailableModels')
            ->willReturn(['gpt-4o-mini', 'gpt-4o'])
        ;
        $openaiProvider
            ->expects($this->once())
            ->method('getDefaultModel')
            ->willReturn('gpt-4o-mini')
        ;
        $openaiProvider
            ->method('getId')
            ->willReturn('openai')
        ;

        $anthropicProvider = $this->createMock(ProviderInterface::class);
        $anthropicProvider
            ->expects($this->once())
            ->method('getAvailableModels')
            ->willReturn(['claude-3-5-haiku-20241022', 'claude-3-5-sonnet-20241022'])
        ;
        $anthropicProvider
            ->expects($this->once())
            ->method('getDefaultModel')
            ->willReturn('claude-3-5-haiku-20241022')
        ;
        $anthropicProvider
            ->method('getId')
            ->willReturn('anthropic')
        ;

        $this->platform
            ->expects($this->once())
            ->method('getAvailableProviders')
            ->willReturn(new ProviderCollection([$openaiProvider, $anthropicProvider]))
        ;
        $this->platform
            ->expects($this->exactly(2))
            ->method('getProvider')
            ->willReturnMap([
                ['openai', $openaiProvider],
                ['anthropic', $anthropicProvider],
            ])
        ;
        $exitCode = $this->commandTester->execute([]);
        self::assertSame(Command::SUCCESS, $exitCode);

        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('openai', $display);
        self::assertStringContainsString('anthropic', $display);
        self::assertStringContainsString('gpt-4o-mini', $display);
        self::assertStringContainsString('claude-3-5-haiku-20241022', $display);
    }
    public function testExecuteWithSpecificProvider(): void
    {
        $anthropicProvider = $this->createMock(ProviderInterface::class);
        $anthropicProvider
            ->expects($this->once())
            ->method('getAvailableModels')
            ->willReturn(['claude-3-5-haiku-20241022', 'claude-3-5-sonnet-20241022'])
        ;
        $anthropicProvider
            ->expects($this->once())
            ->method('getDefaultModel')
            ->willReturn('claude-3-5-haiku-20241022')
        ;
        $anthropicProvider
            ->method('getId')
            ->willReturn('anthropic')
        ;

        $this->platform
            ->expects($this->once())
            ->method('getAvailableProviders')
            ->willReturn(new ProviderCollection([$anthropicProvider]))
        ;
        $this->platform
            ->expects($this->once())
            ->method('hasProvider')
            ->with('anthropic')
            ->willReturn(true)
        ;
        $this->platform
            ->expects($this->once())
            ->method('getProvider')
            ->with('anthropic')
            ->willReturn($anthropicProvider)
        ;
        $exitCode = $this->commandTester->execute(['--provider' => 'anthropic']);
        self::assertSame(Command::SUCCESS, $exitCode);

        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('anthropic', $display);
        self::assertStringContainsString('claude-3-5-haiku-20241022', $display);
        self::assertStringContainsString('claude-3-5-sonnet-20241022', $display);
        self::assertStringNotContainsString('openai', $display);
    }
    public function testExecuteWithInvalidProvider(): void
    {
        $openaiProvider = $this->createMock(ProviderInterface::class);
        $openaiProvider->method('getId')->willReturn('openai');

        $anthropicProvider = $this->createMock(ProviderInterface::class);
        $anthropicProvider->method('getId')->willReturn('anthropic');

        $this->platform
            ->expects($this->once())
            ->method('hasProvider')
            ->with('invalid_provider')
            ->willReturn(false)
        ;
        $this->platform
            ->expects($this->once())
            ->method('getAvailableProviders')
            ->willReturn(new ProviderCollection([$openaiProvider, $anthropicProvider]))
        ;
        $exitCode = $this->commandTester->execute(['--provider' => 'invalid_provider']);
        self::assertSame(Command::FAILURE, $exitCode);

        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('Provider \'invalid_provider\' is not configured', $display);
        self::assertStringContainsString('Available providers: openai, anthropic', $display);
    }
    public function testExecuteWithProviderException(): void
    {
        $mockProvider = $this->createMock(ProviderInterface::class);
        $mockProvider
            ->expects($this->once())
            ->method('getAvailableModels')
            ->willThrowException(new \Exception('API error'))
        ;
        $mockProvider
            ->method('getId')
            ->willReturn('openai')
        ;

        $this->platform
            ->expects($this->once())
            ->method('getAvailableProviders')
            ->willReturn(new ProviderCollection([$mockProvider]))
        ;
        $this->platform
            ->expects($this->once())
            ->method('getProvider')
            ->with('openai')
            ->willReturn($mockProvider)
        ;
        $exitCode = $this->commandTester->execute([]);
        self::assertSame(Command::SUCCESS, $exitCode);

        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('Failed to load models for openai', $display);
        self::assertStringContainsString('API error', $display);
    }
    public function testExecuteWithEmptyModelsList(): void
    {
        $mockProvider = $this->createMock(ProviderInterface::class);
        $mockProvider
            ->expects($this->once())
            ->method('getAvailableModels')
            ->willReturn([])
        ;
        $mockProvider
            ->expects($this->once())
            ->method('getDefaultModel')
            ->willReturn('unknown')
        ;
        $mockProvider
            ->method('getId')
            ->willReturn('test_provider')
        ;

        $this->platform
            ->expects($this->once())
            ->method('getAvailableProviders')
            ->willReturn(new ProviderCollection([$mockProvider]))
        ;
        $this->platform
            ->expects($this->once())
            ->method('getProvider')
            ->with('test_provider')
            ->willReturn($mockProvider)
        ;
        $exitCode = $this->commandTester->execute([]);
        self::assertSame(Command::SUCCESS, $exitCode);

        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('test_provider', $display);
        self::assertStringContainsString('No models available', $display);
    }
    public function testCommandInputDefinition(): void
    {
        $definition = $this->command->getDefinition();

        self::assertTrue($definition->hasOption('provider'));

        $providerOption = $definition->getOption('provider');
        self::assertTrue($providerOption->isValueRequired());
        self::assertFalse($providerOption->isValueOptional());
        self::assertSame('p', $providerOption->getShortcut());
        self::assertStringContainsString('Filter by specific provider', $providerOption->getDescription());
    }
    public function testExecuteWithVerboseOutput(): void
    {
        $mockProvider = $this->createMock(ProviderInterface::class);
        $mockProvider
            ->expects($this->once())
            ->method('getAvailableModels')
            ->willReturn(['gpt-4o-mini', 'gpt-4o'])
        ;
        $mockProvider
            ->expects($this->once())
            ->method('getDefaultModel')
            ->willReturn('gpt-4o-mini')
        ;
        $mockProvider
            ->method('getId')
            ->willReturn('openai')
        ;

        $this->platform
            ->expects($this->once())
            ->method('getAvailableProviders')
            ->willReturn(new ProviderCollection([$mockProvider]))
        ;
        $this->platform
            ->expects($this->once())
            ->method('getProvider')
            ->with('openai')
            ->willReturn($mockProvider)
        ;
        $exitCode = $this->commandTester->execute([], [
            'verbosity' => \Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE
        ]);
        self::assertSame(Command::SUCCESS, $exitCode);

        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('Total models: 2', $display);
    }
    public function testExecuteWithDetailedOutput(): void
    {
        $mockProvider = $this->createMock(ProviderInterface::class);
        $mockProvider
            ->expects($this->once())
            ->method('getAvailableModels')
            ->willReturn(['gpt-4o-mini', 'gpt-4o'])
        ;
        $mockProvider
            ->expects($this->once())
            ->method('getDefaultModel')
            ->willReturn('gpt-4o-mini')
        ;
        $mockProvider
            ->expects($this->exactly(2))
            ->method('getModel')
            ->willReturnCallback(function ($modelId) {
                if ($modelId === 'gpt-4o') {
                    throw new \Exception('Model access error');
                }
                return $this->createMock(\Lingoda\AiSdk\ModelInterface::class);
            })
        ;
        $mockProvider
            ->method('getId')
            ->willReturn('openai')
        ;

        $this->platform
            ->expects($this->once())
            ->method('getAvailableProviders')
            ->willReturn(new ProviderCollection([$mockProvider]))
        ;
        $this->platform
            ->expects($this->once())
            ->method('getProvider')
            ->with('openai')
            ->willReturn($mockProvider)
        ;
        $exitCode = $this->commandTester->execute(['--detailed' => true]);
        self::assertSame(Command::SUCCESS, $exitCode);

        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('Model', $display); // Table header
        self::assertStringContainsString('Status', $display); // Table header
        self::assertStringContainsString('Default', $display); // Table header
        self::assertStringContainsString('gpt-4o-mini', $display);
        self::assertStringContainsString('gpt-4o', $display);
        self::assertStringContainsString('✓ Available', $display); // For accessible model
        self::assertStringContainsString('✗ Error', $display); // For inaccessible model
        self::assertStringContainsString('✓', $display); // Default model marker
    }
    public function testExecuteWithDetailedOutputAndProvider(): void
    {
        $mockProvider = $this->createMock(ProviderInterface::class);
        $mockProvider
            ->expects($this->once())
            ->method('getAvailableModels')
            ->willReturn(['claude-3-5-haiku-20241022'])
        ;
        $mockProvider
            ->expects($this->once())
            ->method('getDefaultModel')
            ->willReturn('claude-3-5-haiku-20241022')
        ;
        $mockProvider
            ->expects($this->once())
            ->method('getModel')
            ->with('claude-3-5-haiku-20241022')
            ->willReturn($this->createMock(\Lingoda\AiSdk\ModelInterface::class))
        ;
        $mockProvider
            ->method('getId')
            ->willReturn('anthropic')
        ;

        $this->platform
            ->expects($this->once())
            ->method('getAvailableProviders')
            ->willReturn(new ProviderCollection([$mockProvider]))
        ;
        $this->platform
            ->expects($this->once())
            ->method('hasProvider')
            ->with('anthropic')
            ->willReturn(true)
        ;
        $this->platform
            ->expects($this->once())
            ->method('getProvider')
            ->with('anthropic')
            ->willReturn($mockProvider)
        ;
        $exitCode = $this->commandTester->execute([
            '--provider' => 'anthropic',
            '--detailed' => true
        ]);
        self::assertSame(Command::SUCCESS, $exitCode);

        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('anthropic', $display);
        self::assertStringContainsString('claude-3-5-haiku-20241022', $display);
        self::assertStringContainsString('✓ Available', $display);
        // Should NOT contain the note about using --provider since it was used
        self::assertStringNotContainsString('Use --provider to filter', $display);
    }
}
