<?php
declare(strict_types=1);

namespace Lingoda\AiBundle\Tests\Unit\Command;

use Lingoda\AiBundle\Command\AiListProvidersCommand;
use Lingoda\AiSdk\PlatformInterface;
use Lingoda\AiSdk\ProviderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

final class AiListProvidersCommandTest extends TestCase
{
    private MockObject&PlatformInterface $platform;
    private MockObject&ParameterBagInterface $parameterBag;
    private AiListProvidersCommand $command;
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $this->platform = $this->createMock(PlatformInterface::class);
        $this->parameterBag = $this->createMock(ParameterBagInterface::class);
        $this->command = new AiListProvidersCommand($this->platform, $this->parameterBag);
        $this->commandTester = new CommandTester($this->command);
    }

    public function testCommandInstantiation(): void
    {
        self::assertSame('ai:list:providers', $this->command->getName());
        self::assertSame('List all configured AI providers and their status', $this->command->getDescription());
    }

    public function testExecuteWithNoProviders(): void
    {
        $this->platform
            ->expects($this->once())
            ->method('getAvailableProviders')
            ->willReturn([]);

        $exitCode = $this->commandTester->execute([]);
        
        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No AI providers configured', $this->commandTester->getDisplay());
    }

    public function testExecuteWithSingleProvider(): void
    {
        $mockProvider = $this->createMock(ProviderInterface::class);
        $mockProvider
            ->expects($this->once())
            ->method('getDefaultModel')
            ->willReturn('gpt-4o-mini');
        $mockProvider
            ->expects($this->once())
            ->method('getAvailableModels')
            ->willReturn(['gpt-4o-mini', 'gpt-4o', 'gpt-3.5-turbo']);

        $this->platform
            ->expects($this->once())
            ->method('getAvailableProviders')
            ->willReturn(['openai']);

        $this->platform
            ->expects($this->once())
            ->method('getProvider')
            ->with('openai')
            ->willReturn($mockProvider);

        $this->parameterBag
            ->expects($this->once())
            ->method('has')
            ->with('lingoda_ai.config')
            ->willReturn(true);
            
        $this->parameterBag
            ->expects($this->once())
            ->method('get')
            ->with('lingoda_ai.config')
            ->willReturn([
                'default_provider' => 'openai',
                'providers' => [
                    'openai' => [
                        'api_key' => 'sk-***',
                        'organization' => 'org-123',
                        'default_model' => 'gpt-4o-mini',
                    ],
                ],
            ]);

        $exitCode = $this->commandTester->execute([]);
        
        self::assertSame(Command::SUCCESS, $exitCode);
        
        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('Configured AI Providers', $display);
        self::assertStringContainsString('openai', $display);
        self::assertStringContainsString('✓ Available', $display);
        self::assertStringContainsString('gpt-4o-mini', $display);
        self::assertStringContainsString('Default provider: openai', $display);
    }

    public function testExecuteWithMultipleProviders(): void
    {
        $openaiProvider = $this->createMock(ProviderInterface::class);
        $openaiProvider
            ->expects($this->once())
            ->method('getDefaultModel')
            ->willReturn('gpt-4o-mini');
        $openaiProvider
            ->expects($this->once())
            ->method('getAvailableModels')
            ->willReturn(['gpt-4o-mini', 'gpt-4o', 'gpt-3.5-turbo']);

        $anthropicProvider = $this->createMock(ProviderInterface::class);
        $anthropicProvider
            ->expects($this->once())
            ->method('getDefaultModel')
            ->willReturn('claude-3-5-haiku-20241022');
        $anthropicProvider
            ->expects($this->once())
            ->method('getAvailableModels')
            ->willReturn(['claude-3-5-haiku-20241022', 'claude-3-5-sonnet-20241022']);

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

        $this->parameterBag
            ->expects($this->once())
            ->method('has')
            ->with('lingoda_ai.config')
            ->willReturn(true);
            
        $this->parameterBag
            ->expects($this->once())
            ->method('get')
            ->with('lingoda_ai.config')
            ->willReturn([
                'default_provider' => 'anthropic',
                'providers' => [
                    'openai' => [
                        'api_key' => 'sk-***',
                        'default_model' => 'gpt-4o-mini',
                    ],
                    'anthropic' => [
                        'api_key' => 'sk-ant-***',
                        'default_model' => 'claude-3-5-haiku-20241022',
                    ],
                ],
            ]);

        $exitCode = $this->commandTester->execute([]);
        
        self::assertSame(Command::SUCCESS, $exitCode);
        
        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('openai', $display);
        self::assertStringContainsString('anthropic', $display);
        self::assertStringContainsString('Default provider: anthropic', $display);
    }

    public function testExecuteHandlesExceptionGracefully(): void
    {
        $this->platform
            ->expects($this->once())
            ->method('getAvailableProviders')
            ->willReturn(['openai']);
            
        $this->platform
            ->expects($this->once())
            ->method('getProvider')
            ->with('openai')
            ->willThrowException(new \Exception('Connection failed'));
            
        $this->parameterBag
            ->expects($this->once())
            ->method('has')
            ->with('lingoda_ai.config')
            ->willReturn(false);
            
        $exitCode = $this->commandTester->execute([]);
        
        self::assertSame(Command::SUCCESS, $exitCode);
        
        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('✗ Error', $display);
        self::assertStringContainsString('Connection failed', $display);
    }

    public function testExecuteWithProviderHavingNoModels(): void
    {
        $mockProvider = $this->createMock(ProviderInterface::class);
        $mockProvider
            ->expects($this->once())
            ->method('getDefaultModel')
            ->willReturn('test-model');
        $mockProvider
            ->expects($this->once())
            ->method('getAvailableModels')
            ->willReturn([]); // No models available

        $this->platform
            ->expects($this->once())
            ->method('getAvailableProviders')
            ->willReturn(['test_provider']);

        $this->platform
            ->expects($this->once())
            ->method('getProvider')
            ->with('test_provider')
            ->willReturn($mockProvider);

        $this->parameterBag
            ->expects($this->once())
            ->method('has')
            ->with('lingoda_ai.config')
            ->willReturn(true);
            
        $this->parameterBag
            ->expects($this->once())
            ->method('get')
            ->with('lingoda_ai.config')
            ->willReturn(['providers' => ['test_provider' => []]]);
            
        $exitCode = $this->commandTester->execute([]);
        
        self::assertSame(Command::SUCCESS, $exitCode);
        
        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('0 models', $display);
    }

    public function testExecuteWithProviderHavingManyModels(): void
    {
        $mockProvider = $this->createMock(ProviderInterface::class);
        $mockProvider
            ->expects($this->once())
            ->method('getDefaultModel')
            ->willReturn('model1');
        $mockProvider
            ->expects($this->once())
            ->method('getAvailableModels')
            ->willReturn(['model1', 'model2', 'model3', 'model4', 'model5']); // More than 3 models

        $this->platform
            ->expects($this->once())
            ->method('getAvailableProviders')
            ->willReturn(['test_provider']);

        $this->platform
            ->expects($this->once())
            ->method('getProvider')
            ->with('test_provider')
            ->willReturn($mockProvider);

        $this->parameterBag
            ->expects($this->once())
            ->method('has')
            ->with('lingoda_ai.config')
            ->willReturn(true);
            
        $this->parameterBag
            ->expects($this->once())
            ->method('get')
            ->with('lingoda_ai.config')
            ->willReturn(['providers' => ['test_provider' => []]]);
            
        $exitCode = $this->commandTester->execute([]);
        
        self::assertSame(Command::SUCCESS, $exitCode);
        
        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('(+2 more)', $display); // Should show truncation
    }

    public function testConstructorSetsPropertiesCorrectly(): void
    {
        // Test that constructor properly initializes the command
        self::assertSame('ai:list:providers', $this->command->getName());
        self::assertSame('List all configured AI providers and their status', $this->command->getDescription());
    }

    public function testExecuteWithCompleteConfiguration(): void
    {
        $mockProvider = $this->createMock(ProviderInterface::class);
        $mockProvider
            ->expects($this->once())
            ->method('getDefaultModel')
            ->willReturn('gpt-4o-mini');
        $mockProvider
            ->expects($this->once())
            ->method('getAvailableModels')
            ->willReturn(['gpt-4o-mini']);

        $this->platform
            ->expects($this->once())
            ->method('getAvailableProviders')
            ->willReturn(['openai']);

        $this->platform
            ->expects($this->once())
            ->method('getProvider')
            ->with('openai')
            ->willReturn($mockProvider);

        $this->parameterBag
            ->expects($this->once())
            ->method('has')
            ->with('lingoda_ai.config')
            ->willReturn(true);
            
        $this->parameterBag
            ->expects($this->once())
            ->method('get')
            ->with('lingoda_ai.config')
            ->willReturn([
                'default_provider' => 'openai',
                'logging' => [
                    'enabled' => true,
                    'service' => 'logger'
                ],
                'sanitization' => [
                    'enabled' => false,
                    'patterns' => []
                ]
            ]);

        $exitCode = $this->commandTester->execute([]);
        
        self::assertSame(Command::SUCCESS, $exitCode);
        
        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('Default provider: openai', $display);
        self::assertStringContainsString('Logging: enabled', $display);
        self::assertStringContainsString('Data sanitization: disabled', $display);
    }

    public function testExecuteWithPartialConfiguration(): void
    {
        $mockProvider = $this->createMock(ProviderInterface::class);
        $mockProvider
            ->expects($this->once())
            ->method('getDefaultModel')
            ->willReturn('gpt-4o-mini');
        $mockProvider
            ->expects($this->once())
            ->method('getAvailableModels')
            ->willReturn(['gpt-4o-mini']);

        $this->platform
            ->expects($this->once())
            ->method('getAvailableProviders')
            ->willReturn(['openai']);

        $this->platform
            ->expects($this->once())
            ->method('getProvider')
            ->with('openai')
            ->willReturn($mockProvider);

        $this->parameterBag
            ->expects($this->once())
            ->method('has')
            ->with('lingoda_ai.config')
            ->willReturn(true);
            
        $this->parameterBag
            ->expects($this->once())
            ->method('get')
            ->with('lingoda_ai.config')
            ->willReturn([
                'logging' => [
                    'enabled' => false
                ]
                // No default_provider or sanitization config
            ]);

        $exitCode = $this->commandTester->execute([]);
        
        self::assertSame(Command::SUCCESS, $exitCode);
        
        $display = $this->commandTester->getDisplay();
        self::assertStringNotContainsString('Default provider:', $display);
        self::assertStringContainsString('Logging: disabled', $display);
        self::assertStringNotContainsString('Data sanitization:', $display);
    }
}