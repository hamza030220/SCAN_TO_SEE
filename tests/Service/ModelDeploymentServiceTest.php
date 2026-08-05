<?php

namespace App\Tests\Service;

use App\Service\MenuScannerClient;
use App\Service\ModelDeploymentService;
use PHPUnit\Framework\TestCase;

final class ModelDeploymentServiceTest extends TestCase
{
    private string $root;
    private string $webRoot;
    private string $modelsRoot;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/s2s_models_' . bin2hex(random_bytes(4));
        $this->webRoot = $this->root . '/web';
        $this->modelsRoot = $this->root . '/handwritten-menu-scanner/models';
        mkdir($this->webRoot, 0775, true);
        mkdir($this->modelsRoot . '/admin-training/job-7/candidate', 0775, true);
        file_put_contents($this->modelsRoot . '/admin-training/job-7/candidate/config.json', '{}');
    }

    protected function tearDown(): void { $this->removeDirectory($this->root); }

    public function testValidManagedCandidateIsPromotedAndReloaded(): void
    {
        $client = $this->createMock(MenuScannerClient::class);
        $client->expects(self::once())->method('reloadPromotedModel')->willReturn(['status' => 'ready']);
        $service = new ModelDeploymentService($this->webRoot, $client);

        $result = $service->promote($this->modelsRoot . '/admin-training/job-7/candidate', 7);
        $pointer = json_decode((string) file_get_contents($this->modelsRoot . '/active_model.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('ready', $result['status']);
        self::assertSame(7, $pointer['training_job']);
    }

    public function testCandidateOutsideManagedStorageIsRejected(): void
    {
        $outside = $this->root . '/outside';
        mkdir($outside, 0775, true);
        file_put_contents($outside . '/config.json', '{}');
        $client = $this->createMock(MenuScannerClient::class);
        $client->expects(self::never())->method('reloadPromotedModel');

        $this->expectException(\RuntimeException::class);
        (new ModelDeploymentService($this->webRoot, $client))->promote($outside, 7);
    }

    public function testPointerRollsBackWhenFastApiCannotReload(): void
    {
        $previous = ['checkpoint' => $this->modelsRoot . '/production'];
        file_put_contents($this->modelsRoot . '/active_model.json', json_encode($previous, JSON_THROW_ON_ERROR));
        $client = $this->createMock(MenuScannerClient::class);
        $client->expects(self::exactly(2))->method('reloadPromotedModel')->willThrowException(new \RuntimeException('offline'));
        $service = new ModelDeploymentService($this->webRoot, $client);

        try {
            $service->promote($this->modelsRoot . '/admin-training/job-7/candidate', 7);
            self::fail('Promotion should fail when FastAPI cannot reload.');
        } catch (\RuntimeException) {
            self::assertSame(
                $previous,
                json_decode((string) file_get_contents($this->modelsRoot . '/active_model.json'), true, 512, JSON_THROW_ON_ERROR),
            );
        }
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
