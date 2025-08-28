<?php

declare(strict_types = 1);

namespace Lingoda\AiBundle\Command;

use Lingoda\AiSdk\PlatformInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webmozart\Assert\Assert;

#[AsCommand(
    name: 'ai:test:connection',
    description: 'Test connections to all configured AI providers'
)]
final class AiTestConnectionCommand extends Command
{
    public function __construct(
        private readonly PlatformInterface $platform
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Testing AI Provider Connections');

        $providers = $this->platform->getAvailableProviders();
        if ($providers->isEmpty()) {
            $io->warning('No AI providers configured');
            return Command::FAILURE;
        }

        $allSuccessful = true;

        foreach ($providers as $provider) {
            $providerName = $provider->getName();
            $io->section("Testing {$providerName}");

            try {
                // Debug the platform instance
                $io->text("  Platform class: " . get_class($this->platform));

                $defaultModel = $provider->getDefaultModel();
                $io->text("  Provider class: " . get_class($provider));
                $io->text("  Default model: {$defaultModel}");

                // Test with a simple prompt using the provider's default model
                $io->text("  Testing with model: {$defaultModel}");
                $result = $this->platform->ask('Hello', $defaultModel);

                $io->success("✓ {$providerName} connection successful");
                $io->text("  Default model: {$defaultModel}");

                $content = $result->getContent();
                Assert::string($content);

                $io->text("  Response length: " . mb_strlen($content) . ' characters');
            } catch (\Throwable $e) {
                $allSuccessful = false;
                $io->error("✗ {$providerName} connection failed");
                $io->text("  Error: " . $e->getMessage());

                if ($output->isVerbose()) {
                    $io->text("  Exception: " . get_class($e));
                    $io->text("  File: " . $e->getFile() . ':' . $e->getLine());
                }
            }
        }

        if ($allSuccessful) {
            $io->success('All provider connections successful!');

            return Command::SUCCESS;
        }

        $io->warning('Some provider connections failed');

        return Command::FAILURE;
    }
}
