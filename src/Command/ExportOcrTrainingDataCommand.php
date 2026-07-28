<?php

namespace App\Command;

use App\Entity\ScanRegion;
use App\Repository\ScanRegionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:export-ocr-training-data',
    description: 'Download reviewed OCR crops and create a train_synthetic-compatible manifest.',
)]
class ExportOcrTrainingDataCommand extends Command
{
    public function __construct(
        private readonly ScanRegionRepository $regions,
        private readonly HttpClientInterface $httpClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'output-dir',
            InputArgument::REQUIRED,
            'New or empty directory that will receive manifest.csv and crops/',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $outputDir = rtrim((string) $input->getArgument('output-dir'), '/\\');
        $cropsDir = $outputDir . DIRECTORY_SEPARATOR . 'crops';

        if (is_file($outputDir)) {
            $io->error('Output path is a file.');
            return Command::FAILURE;
        }
        if (!is_dir($cropsDir) && !mkdir($cropsDir, 0775, true) && !is_dir($cropsDir)) {
            $io->error('Could not create output directory.');
            return Command::FAILURE;
        }

        /** @var ScanRegion[] $rows */
        $rows = $this->regions->createQueryBuilder('r')
            ->join('r.scan', 's')
            ->andWhere('s.status = :status')
            ->andWhere('r.reviewOutcome IN (:outcomes)')
            ->andWhere('r.correctedText IS NOT NULL')
            ->andWhere('r.cropUrl IS NOT NULL')
            ->setParameter('status', 'reviewed')
            ->setParameter('outcomes', ['accepted', 'modified'])
            ->orderBy('s.createdAt', 'ASC')
            ->addOrderBy('r.boxId', 'ASC')
            ->getQuery()
            ->getResult();

        $manifestPath = $outputDir . DIRECTORY_SEPARATOR . 'manifest.csv';
        $manifest = fopen($manifestPath, 'wb');
        if ($manifest === false) {
            $io->error('Could not create manifest.csv.');
            return Command::FAILURE;
        }
        fputcsv($manifest, [
            'crop_path', 'text', 'split', 'field_type', 'model_version',
            'scan_uuid', 'box_id', 'review_outcome',
        ]);

        $written = 0;
        $failed = 0;
        foreach ($rows as $region) {
            $scan = $region->getScan();
            if (!$scan || $region->getCorrectedText() === '') {
                continue;
            }
            $filename = sprintf('%s_%04d.jpg', $scan->getScanUuid(), $region->getBoxId());
            $relativePath = 'crops/' . $filename;
            try {
                $content = $this->httpClient->request('GET', (string) $region->getCropUrl(), [
                    'timeout' => 30,
                ])->getContent();
                if (file_put_contents($cropsDir . DIRECTORY_SEPARATOR . $filename, $content) === false) {
                    throw new \RuntimeException('Could not write crop');
                }
            } catch (\Throwable $e) {
                ++$failed;
                $io->warning(sprintf(
                    'Skipped scan %s box %d: %s',
                    $scan->getScanUuid(),
                    $region->getBoxId(),
                    $e->getMessage(),
                ));
                continue;
            }

            fputcsv($manifest, [
                $relativePath,
                $region->getCorrectedText(),
                $this->splitForScan($scan->getScanUuid()),
                $region->getRole(),
                $scan->getModelVersion(),
                $scan->getScanUuid(),
                $region->getBoxId(),
                $region->getReviewOutcome(),
            ]);
            ++$written;
        }
        fclose($manifest);

        $io->success(sprintf(
            'Exported %d reviewed crops to %s (%d downloads skipped).',
            $written,
            $outputDir,
            $failed,
        ));
        return Command::SUCCESS;
    }

    private function splitForScan(string $scanUuid): string
    {
        // All regions from one menu stay in one split, preventing image/style
        // leakage between training and evaluation.
        $bucket = hexdec(substr(hash('sha256', $scanUuid), 0, 8)) % 100;
        return $bucket < 80 ? 'train' : ($bucket < 90 ? 'val' : 'test');
    }
}
