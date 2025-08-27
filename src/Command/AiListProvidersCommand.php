<?php

declare(strict_types = 1);

namespace Lingoda\AiBundle\Command;

use Lingoda\AiSdk\PlatformInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AsCommand(
    name: 'ai:list:providers',
    description: 'List all configured AI providers and their status'
)]
final class AiListProvidersCommand extends Command
{
    public function __construct(
        private readonly PlatformInterface $platform,
        private readonly ParameterBagInterface $parameterBag
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $io->title('Configured AI Providers');
        
        $providers = $this->platform->getAvailableProviders();
        
        if (empty($providers)) {
            $io->warning('No AI providers configured');
            $io->note('Configure providers by setting API keys in your environment variables:');
            $io->listing([
                'OPENAI_API_KEY=your-openai-key',
                'ANTHROPIC_API_KEY=your-anthropic-key',
                'GEMINI_API_KEY=your-gemini-key'
            ]);
            return Command::SUCCESS;
        }
        
        // Get configuration if available
        /** @var array<string, mixed> $config */
        $config = $this->parameterBag->has('lingoda_ai.config')
            ? $this->parameterBag->get('lingoda_ai.config')
            : [];
        
        $table = new Table($output);
        $table->setHeaders(['Provider', 'Status', 'Default Model', 'Available Models']);
        
        foreach ($providers as $providerName) {
            try {
                $provider = $this->platform->getProvider($providerName);
                $defaultModel = $provider->getDefaultModel();
                $availableModels = $provider->getAvailableModels();
                
                $status = '✓ Available';
                
                $modelCount = count($availableModels);
                if ($modelCount <= 3 && $modelCount > 0) {
                    $modelsText = implode(', ', $availableModels);
                } elseif ($modelCount > 3) {
                    $modelsText = implode(', ', array_slice($availableModels, 0, 3)) . ' (+' . ($modelCount - 3) . ' more)';
                } else {
                    $modelsText = '0 models';
                }
            } catch (\Throwable $e) {
                $status = '✗ Error';
                $defaultModel = 'N/A';
                $modelsText = $e->getMessage();
            }
            
            $table->addRow([
                $providerName,
                $status,
                $defaultModel,
                $modelsText
            ]);
        }
        
        $table->render();
        
        // Show default provider if configured
        if (isset($config['default_provider']) && is_string($config['default_provider'])) {
            $io->note("Default provider: {$config['default_provider']}");
        }
        
        // Show logging status
        if (isset($config['logging']) && is_array($config['logging'])) {
            $loggingStatus = ($config['logging']['enabled'] ?? false) ? 'enabled' : 'disabled';
            $io->note("Logging: {$loggingStatus}");
        }
        
        // Show sanitization status
        if (isset($config['sanitization']) && is_array($config['sanitization'])) {
            $sanitizationStatus = ($config['sanitization']['enabled'] ?? false) ? 'enabled' : 'disabled';
            $io->note("Data sanitization: {$sanitizationStatus}");
        }
        
        return Command::SUCCESS;
    }
}
