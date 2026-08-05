<?php

namespace App\Service;

use App\Entity\TrainingJob;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ModelDeploymentService
{
    private string $scannerRoot;

    public function __construct(
        #[Autowire('%kernel.project_dir%')] string $projectDir,
        private readonly MenuScannerClient $scannerClient,
    ) { $this->scannerRoot = dirname($projectDir) . '/handwritten-menu-scanner'; }

    public function scannerRoot(): string { return $this->scannerRoot; }

    public function currentCheckpoint(): string
    {
        $pointer = $this->scannerRoot . '/models/active_model.json';
        if (is_file($pointer)) {
            try {
                $value = json_decode((string) file_get_contents($pointer), true, 512, JSON_THROW_ON_ERROR)['checkpoint'] ?? null;
                $candidate = is_string($value) ? realpath($value) : false;
                $modelsRoot = realpath($this->scannerRoot . '/models');
                if ($candidate !== false && $modelsRoot !== false && is_dir($candidate)
                    && str_starts_with($candidate, rtrim($modelsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
                    return $candidate;
                }
            } catch (\Throwable) {}
        }
        return $this->scannerRoot . '/models/trocr_menu_v1_digits_v3/checkpoints/checkpoint-765';
    }

    public function jobDirectory(int $jobId): string
    {
        return $this->scannerRoot . '/models/admin-training/job-' . $jobId;
    }

    public function requestStop(TrainingJob $job): void
    {
        $dir = $this->jobDirectory((int) $job->getId());
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('The training stop signal could not be created.');
        }
        file_put_contents($dir . '/stop-requested.flag', (new \DateTimeImmutable())->format(DATE_ATOM));
    }

    public function promote(string $candidatePath, int $jobId): array
    {
        $candidate = realpath($candidatePath);
        $allowedRoot = realpath($this->scannerRoot . '/models/admin-training');
        if ($candidate === false || $allowedRoot === false || !is_dir($candidate)
            || !str_starts_with($candidate, rtrim($allowedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
            || !is_file($candidate . '/config.json')) {
            throw new \RuntimeException('Candidate model files are incomplete or outside managed storage.');
        }
        $pointer = $this->scannerRoot . '/models/active_model.json';
        $previous = is_file($pointer) ? file_get_contents($pointer) : null;
        $payload = json_encode(['checkpoint' => $candidate, 'training_job' => $jobId, 'promoted_at' => (new \DateTimeImmutable())->format(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $temporary = $pointer . '.tmp';
        if (file_put_contents($temporary, $payload) === false || !rename($temporary, $pointer)) {
            @unlink($temporary);
            throw new \RuntimeException('The active-model pointer could not be updated safely.');
        }
        try {
            return $this->scannerClient->reloadPromotedModel();
        } catch (\Throwable $e) {
            if ($previous === false || $previous === null) { @unlink($pointer); }
            else { file_put_contents($pointer, $previous); }
            try { $this->scannerClient->reloadPromotedModel(); } catch (\Throwable) {}
            throw $e;
        }
    }
}
