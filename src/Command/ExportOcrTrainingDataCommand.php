<?php

namespace App\Command;

use App\Service\TrainingDatasetService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:export-ocr-training-data',
    description: 'Download approved Cloudinary crops and create a train_synthetic-compatible local manifest.',
)]
final class ExportOcrTrainingDataCommand extends Command
{
    public function __construct(private readonly TrainingDatasetService $datasets) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument(
            'name',
            InputArgument::OPTIONAL,
            'Safe export name under var/ai-datasets (letters, numbers, dash and underscore only).',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $name = (string) ($input->getArgument('name') ?: 'dataset-' . (new \DateTimeImmutable())->format('Ymd-His'));

        try {
            $result = $this->datasets->export($name);
        } catch (\Throwable $e) {
            $io->error('Dataset export failed safely: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $summary = $result['summary'];
        $io->table(['Export', 'Eligible', 'Written', 'Skipped', 'Train', 'Validation', 'Test'], [[
            $summary['name'], $summary['eligible'], $summary['written'], $summary['failed'],
            $summary['train'], $summary['val'], $summary['test'],
        ]]);
        if ($summary['written'] === 0) {
            foreach ($summary['failureExamples'] ?? [] as $failure) { $io->warning($failure); }
            $io->error('No approved Cloudinary crop could be downloaded. Nothing is ready for training.');
            return Command::FAILURE;
        }
        $io->success('Images and manifest.csv were validated and saved to ' . $result['directory']);
        return Command::SUCCESS;
    }
}
