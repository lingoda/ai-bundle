<?php

declare(strict_types = 1);

namespace Lingoda\AiBundle\Command;

use Lingoda\AiSdk\PlatformInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webmozart\Assert\Assert;

#[AsCommand(
    name: 'ai:list:models',
    description: 'List all available models for each provider'
)]
final class AiListModelsCommand extends Command
{
    public function __construct(
        private readonly PlatformInterface $platform
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'provider',
            'p',
            InputOption::VALUE_REQUIRED,
            'Filter by specific provider (openai, anthropic, gemini)'
        );

        $this->addOption(
            'detailed',
            'd',
            InputOption::VALUE_NONE,
            'Show detailed model information'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $providerFilter = $input->getOption('provider');
        $detailed = $input->getOption('detailed');

        $availableProviders = $this->platform->getAvailableProviders();
        if ($availableProviders->isEmpty()) {
            $io->warning('No AI providers configured');
            return Command::FAILURE;
        }

        $providers = $availableProviders->getIds();
        // Filter providers if specified
        if ($providerFilter !== null) {
            Assert::stringNotEmpty($providerFilter);
            if (!$this->platform->hasProvider($providerFilter)) {
                $io->error("Provider '{$providerFilter}' is not configured");
                $io->note('Available providers: ' . $availableProviders);
                return Command::FAILURE;
            }
            $providers = [$providerFilter];
        }

        $io->title('Available AI Models');

        foreach ($providers as $providerName) {
            Assert::stringNotEmpty($providerName);
            $io->section($providerName);

            try {
                $provider = $this->platform->getProvider($providerName);
                $defaultModel = $provider->getDefaultModel();
                $availableModels = $provider->getAvailableModels();

                if (empty($availableModels)) {
                    $io->text('No models available');
                    continue;
                }

                if ($detailed) {
                    $table = new Table($output);
                    $table->setHeaders(['Model', 'Status', 'Default']);

                    foreach ($availableModels as $modelId) {
                        $isDefault = $modelId === $defaultModel ? '✓' : '';
                        $status = '✓ Available';

                        try {
                            $provider->getModel($modelId); // Test if model is accessible
                        } catch (\Throwable $e) {
                            $status = '✗ Error';
                        }

                        $table->addRow([$modelId, $status, $isDefault]);
                    }

                    $table->render();
                } else {
                    // Simple list format
                    $modelsList = [];
                    foreach ($availableModels as $modelId) {
                        if ($modelId === $defaultModel) {
                            $modelsList[] = "<info>{$modelId}</info> (default)";
                        } else {
                            $modelsList[] = $modelId;
                        }
                    }

                    $io->listing($modelsList);
                }

                $io->text("Total models: " . count($availableModels));
            } catch (\Throwable $e) {
                $io->error("Failed to load models for {$providerName}: " . $e->getMessage());
            }
        }

        if (!$providerFilter && !$detailed) {
            $io->note('Use --provider to filter by specific provider, or --detailed for more information');
        }

        return Command::SUCCESS;
    }
}
