<?php

namespace App\Service;

use App\Entity\ScanRegion;
use App\Repository\ScanRegionRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class TrainingDatasetService
{
    private string $storageRoot;

    public function __construct(
        private readonly ScanRegionRepository $regions,
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%kernel.project_dir%')] string $projectDir,
    ) {
        $this->storageRoot = $projectDir . '/var/ai-datasets';
    }

    public function statistics(): array
    {
        $row = $this->regions->createQueryBuilder('region')
            ->select('COUNT(region.id) total')
            ->addSelect('SUM(CASE WHEN region.reviewOutcome = :accepted THEN 1 ELSE 0 END) accepted')
            ->addSelect('SUM(CASE WHEN region.reviewOutcome = :modified THEN 1 ELSE 0 END) modified')
            ->addSelect('SUM(CASE WHEN region.reviewOutcome = :deleted THEN 1 ELSE 0 END) deleted')
            ->addSelect('SUM(CASE WHEN region.excludedFromTraining = true THEN 1 ELSE 0 END) excluded')
            ->addSelect('AVG(region.confidence) averageConfidence')
            ->setParameter('accepted', 'accepted')->setParameter('modified', 'modified')->setParameter('deleted', 'deleted')
            ->getQuery()->getSingleResult();

        return [
            'total' => (int) ($row['total'] ?? 0),
            'accepted' => (int) ($row['accepted'] ?? 0),
            'modified' => (int) ($row['modified'] ?? 0),
            'deleted' => (int) ($row['deleted'] ?? 0),
            'excluded' => (int) ($row['excluded'] ?? 0),
            'averageConfidence' => round(((float) ($row['averageConfidence'] ?? 0)) * 100, 1),
            'eligible' => $this->eligibleCount(),
        ];
    }

    public function findReviewPage(int $page, int $perPage = 40): array
    {
        return $this->regions->createQueryBuilder('region')
            ->addSelect('scan')->join('region.scan', 'scan')
            ->orderBy('region.correctedAt', 'DESC')->addOrderBy('region.id', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)
            ->getQuery()->getResult();
    }

    public function eligibleCount(): int
    {
        return (int) $this->regions->createQueryBuilder('region')
            ->select('COUNT(region.id)')->join('region.scan', 'scan')
            ->andWhere('scan.status = :reviewed')
            ->andWhere('region.reviewOutcome IN (:outcomes)')
            ->andWhere('region.correctedText IS NOT NULL')->andWhere('region.correctedText != :empty')
            ->andWhere('region.cropUrl IS NOT NULL')->andWhere('region.excludedFromTraining = false')
            ->setParameter('reviewed', 'reviewed')->setParameter('outcomes', ['accepted', 'modified'])->setParameter('empty', '')
            ->getQuery()->getSingleScalarResult();
    }

    public function export(string $name): array
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,60}$/D', $name)) {
            throw new \InvalidArgumentException('Invalid dataset export name.');
        }
        $directory = $this->storageRoot . '/' . $name;
        if (file_exists($directory)) {
            throw new \RuntimeException('That dataset export already exists.');
        }
        if (!mkdir($directory . '/crops', 0775, true) && !is_dir($directory . '/crops')) {
            throw new \RuntimeException('The local dataset directory could not be created.');
        }

        $rows = $this->eligibleRegions();
        $manifestPath = $directory . '/manifest.csv';
        $manifest = fopen($manifestPath, 'wb');
        if ($manifest === false) {
            throw new \RuntimeException('The dataset manifest could not be created.');
        }
        fputcsv($manifest, ['crop_path', 'text', 'split', 'field_type', 'model_version', 'scan_uuid', 'box_id', 'review_outcome']);

        $summary = ['name' => $name, 'eligible' => count($rows), 'written' => 0, 'failed' => 0, 'train' => 0, 'val' => 0, 'test' => 0];
        foreach ($rows as $region) {
            $scan = $region->getScan();
            if (!$scan) { continue; }
            $filename = sprintf('%s_%04d.jpg', $scan->getScanUuid(), $region->getBoxId());
            try {
                $image = $this->downloadCloudinaryImage((string) $region->getCropUrl());
                if (file_put_contents($directory . '/crops/' . $filename, $image) === false) {
                    throw new \RuntimeException('Local image write failed.');
                }
            } catch (\Throwable) {
                ++$summary['failed'];
                continue;
            }
            $split = $this->splitForScan($scan->getScanUuid());
            fputcsv($manifest, ['crops/' . $filename, $region->getCorrectedText(), $split, $region->getRole(), $scan->getModelVersion(), $scan->getScanUuid(), $region->getBoxId(), $region->getReviewOutcome()]);
            ++$summary['written']; ++$summary[$split];
        }
        fclose($manifest);
        file_put_contents($directory . '/summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return ['directory' => $directory, 'manifest' => $manifestPath, 'summary' => $summary];
    }

    public function listExports(): array
    {
        if (!is_dir($this->storageRoot)) { return []; }
        $exports = [];
        foreach (glob($this->storageRoot . '/*/summary.json') ?: [] as $file) {
            try {
                $summary = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
                $summary['createdAt'] = (new \DateTimeImmutable())->setTimestamp((int) filemtime($file));
                $exports[] = $summary;
            } catch (\Throwable) {}
        }
        usort($exports, static fn(array $a, array $b) => $b['createdAt'] <=> $a['createdAt']);
        return $exports;
    }

    public function manifestPath(string $name): string
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,60}$/D', $name)) { throw new \InvalidArgumentException(); }
        return $this->storageRoot . '/' . $name . '/manifest.csv';
    }

    private function eligibleRegions(): array
    {
        return $this->regions->createQueryBuilder('region')->addSelect('scan')->join('region.scan', 'scan')
            ->andWhere('scan.status = :reviewed')->andWhere('region.reviewOutcome IN (:outcomes)')
            ->andWhere('region.correctedText IS NOT NULL')->andWhere('region.correctedText != :empty')
            ->andWhere('region.cropUrl IS NOT NULL')->andWhere('region.excludedFromTraining = false')
            ->setParameter('reviewed', 'reviewed')->setParameter('outcomes', ['accepted', 'modified'])->setParameter('empty', '')
            ->orderBy('scan.createdAt', 'ASC')->addOrderBy('region.boxId', 'ASC')->getQuery()->getResult();
    }

    private function downloadCloudinaryImage(string $url): string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || strtolower((string) ($parts['host'] ?? '')) !== 'res.cloudinary.com') {
            throw new \RuntimeException('The crop URL is not an approved Cloudinary asset.');
        }
        return $this->httpClient->request('GET', $url, ['timeout' => 30, 'max_redirects' => 0])->getContent();
    }

    private function splitForScan(string $uuid): string
    {
        $bucket = hexdec(substr(hash('sha256', $uuid), 0, 8)) % 100;
        return $bucket < 80 ? 'train' : ($bucket < 90 ? 'val' : 'test');
    }
}
