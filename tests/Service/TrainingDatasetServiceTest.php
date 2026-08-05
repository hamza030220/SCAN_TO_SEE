<?php

namespace App\Tests\Service;

use App\Entity\ScanCapture;
use App\Entity\ScanRegion;
use App\Repository\ScanRegionRepository;
use App\Service\TrainingDatasetService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TrainingDatasetServiceTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/s2s_dataset_' . bin2hex(random_bytes(4));
        mkdir($this->projectDir, 0775, true);
    }

    protected function tearDown(): void { $this->removeDirectory($this->projectDir); }

    public function testExportDownloadsCloudinaryCropAndWritesCompatibleCsv(): void
    {
        $region = $this->region('7df65264-763e-450b-b233-cf75632a4341', 'Coffee corrected', 'https://res.cloudinary.com/demo/image/upload/crop.jpg');
        $repository = $this->createMock(ScanRegionRepository::class);
        $repository->method('findTrainingEligible')->willReturn([$region]);
        $service = new TrainingDatasetService($repository, new MockHttpClient(new MockResponse('jpeg-bytes')), $this->projectDir);

        $result = $service->export('test-export');

        self::assertSame(1, $result['summary']['written']);
        self::assertSame(0, $result['summary']['failed']);
        self::assertFileExists($result['directory'] . '/crops/7df65264-763e-450b-b233-cf75632a4341_0001.jpg');
        $manifest = (string) file_get_contents($result['manifest']);
        self::assertStringContainsString('crop_path,text,split,field_type,model_version', $manifest);
        self::assertStringContainsString('Coffee corrected', $manifest);
    }

    public function testExportRefusesNonCloudinaryCropHost(): void
    {
        $region = $this->region('7df65264-763e-450b-b233-cf75632a4341', 'Coffee', 'https://attacker.example/crop.jpg');
        $repository = $this->createMock(ScanRegionRepository::class);
        $repository->method('findTrainingEligible')->willReturn([$region]);
        $service = new TrainingDatasetService($repository, new MockHttpClient(), $this->projectDir);

        $result = $service->export('blocked-export');

        self::assertSame(0, $result['summary']['written']);
        self::assertSame(1, $result['summary']['failed']);
    }

    public function testSmallDatasetKeepsScansSeparateAndFillsEverySplit(): void
    {
        $regions = [
            $this->region('10000000-0000-4000-8000-000000000001', 'One', 'https://res.cloudinary.com/demo/image/upload/one.jpg'),
            $this->region('20000000-0000-4000-8000-000000000002', 'Two', 'https://res.cloudinary.com/demo/image/upload/two.jpg'),
            $this->region('30000000-0000-4000-8000-000000000003', 'Three', 'https://res.cloudinary.com/demo/image/upload/three.jpg'),
        ];
        $repository = $this->createMock(ScanRegionRepository::class);
        $repository->method('findTrainingEligible')->willReturn($regions);
        $service = new TrainingDatasetService(
            $repository,
            new MockHttpClient(static fn(): MockResponse => new MockResponse('jpeg-bytes')),
            $this->projectDir,
        );

        $result = $service->export('three-scans');

        self::assertSame(1, $result['summary']['train']);
        self::assertSame(1, $result['summary']['val']);
        self::assertSame(1, $result['summary']['test']);
    }

    private function region(string $uuid, string $label, string $url): ScanRegion
    {
        $scan = (new ScanCapture())->setScanUuid($uuid)->setStatus('reviewed')->setModelVersion('test-model');
        $region = (new ScanRegion())->setBoxId(1)->setRole('item_name')->setRawText('Cofee')
            ->setCorrectedText($label)->setReviewOutcome('modified')->setConfidence(.7)->setCropUrl($url);
        $scan->addRegion($region);
        return $region;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) { return; }
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $name) {
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
