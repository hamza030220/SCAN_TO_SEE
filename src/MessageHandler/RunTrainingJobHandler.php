<?php

namespace App\MessageHandler;

use App\Entity\TrainingJob;
use App\Message\RunTrainingJob;
use App\Repository\TrainingJobRepository;
use App\Service\ModelDeploymentService;
use App\Service\TrainingDatasetService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Process\Process;

#[AsMessageHandler]
final class RunTrainingJobHandler
{
    public function __construct(
        private readonly TrainingJobRepository $jobs,
        private readonly TrainingDatasetService $datasets,
        private readonly ModelDeploymentService $models,
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(RunTrainingJob $message): void
    {
        $job = $this->jobs->find($message->jobId);
        if (!$job || $job->getStatus() !== TrainingJob::STATUS_QUEUED) { return; }

        try {
            $job->setStatus(TrainingJob::STATUS_EXPORTING)->setPhase('Validating Cloudinary assets and CSV')->setProgress(1)->setStartedAt(new \DateTimeImmutable());
            $this->em->flush();
            $export = $this->datasets->export('training-job-' . $job->getId());
            $summary = $export['summary'];
            $job->setDatasetPath($export['directory'])->setDatasetSummary($summary)->setProgress(3);
            if ($summary['written'] < 3 || $summary['train'] < 1 || $summary['val'] < 1 || $summary['test'] < 1) {
                throw new \RuntimeException(sprintf(
                    'A safe comparison needs train, validation, and test examples. Current split: %d train, %d validation, %d test. Collect or approve more independent scans.',
                    $summary['train'], $summary['val'], $summary['test'],
                ));
            }

            $python = $this->findTrainingPython();
            $jobDir = $this->models->jobDirectory((int) $job->getId());
            if (!is_dir($jobDir) && !mkdir($jobDir, 0775, true) && !is_dir($jobDir)) {
                throw new \RuntimeException('The candidate model directory could not be created.');
            }
            $candidate = $jobDir . '/candidate';
            $progressFile = $jobDir . '/progress.json';
            $resultFile = $jobDir . '/result.json';
            $stopFile = $jobDir . '/stop-requested.flag';
            $parameters = $job->getParameters();
            $command = [
                $python, '-u', $this->models->scannerRoot() . '/training/train_synthetic.py',
                '--dataset_dir', $export['directory'], '--output_model_dir', $candidate,
                '--base_checkpoint', $this->models->currentCheckpoint(),
                '--max_epochs', (string) ($parameters['epochs'] ?? 1),
                '--batch_size', (string) ($parameters['batchSize'] ?? 1),
                '--gradient_accumulation_steps', (string) ($parameters['gradientAccumulation'] ?? 4),
                '--early_stopping_patience', (string) ($parameters['patience'] ?? 2),
                '--eval_test', '--progress_file', $progressFile, '--stop_file', $stopFile, '--result_file', $resultFile,
            ];
            $job->setStatus(TrainingJob::STATUS_TRAINING)->setPhase('Measuring the production model')->setCandidatePath($candidate);
            $this->em->flush();

            $process = new Process($command, $this->models->scannerRoot(), ['PYTHONUNBUFFERED' => '1']);
            $process->setTimeout(21600);
            $log = '';
            $lastFlushAt = 0.0;
            $process->run(function (string $type, string $buffer) use ($job, &$log, $progressFile, &$lastFlushAt): void {
                $log .= $buffer;
                $log = mb_substr(preg_replace('/\x1B(?:[@-Z\\-_]|\[[0-?]*[ -\/]*[@-~])/', '', $log) ?? $log, -12000);
                $job->setLogExcerpt($log);
                if (is_file($progressFile)) {
                    try {
                        $progress = json_decode((string) file_get_contents($progressFile), true, 512, JSON_THROW_ON_ERROR);
                        $job->setProgress((int) ($progress['progress'] ?? $job->getProgress()))->setPhase((string) ($progress['phase'] ?? $job->getPhase()));
                    } catch (\Throwable) {}
                }
                if (microtime(true) - $lastFlushAt >= 0.75) {
                    $this->em->flush();
                    $lastFlushAt = microtime(true);
                }
            });
            $job->setLogExcerpt($log);
            $this->em->flush();
            if (!$process->isSuccessful() || !is_file($resultFile)) {
                throw new \RuntimeException('Training did not produce a valid comparison result. Review the job log for the technical details.');
            }

            $result = json_decode((string) file_get_contents($resultFile), true, 512, JSON_THROW_ON_ERROR);
            $job->setStatus(TrainingJob::STATUS_EVALUATING)->setPhase('Protecting the best model')->setProgress(98)
                ->setBaselineMetrics($result['baseline'] ?? null)->setCandidateMetrics($result['candidate'] ?? null)
                ->setRecommendation((string) ($result['recommendation'] ?? 'keep-production'));
            $this->em->flush();

            if ($job->getRecommendation() === 'promote') {
                $this->models->promote($candidate, (int) $job->getId());
                $phase = 'Candidate performed better and is now active';
            } else {
                $phase = 'Production model kept because the candidate was not better';
            }
            $job->setStatus(($result['stopped'] ?? false) ? TrainingJob::STATUS_STOPPED : TrainingJob::STATUS_COMPLETED)
                ->setPhase($phase)->setProgress(100)->setFinishedAt(new \DateTimeImmutable());
            $this->em->flush();
        } catch (\Throwable $e) {
            $job->setStatus(TrainingJob::STATUS_FAILED)->setPhase('Training stopped safely')->setErrorMessage($e->getMessage())
                ->setFinishedAt(new \DateTimeImmutable());
            $this->em->flush();
        }
    }

    private function findTrainingPython(): string
    {
        $userProfile = $_SERVER['USERPROFILE'] ?? getenv('USERPROFILE') ?: '';
        $configured = $_ENV['OCR_TRAINING_PYTHON'] ?? null;
        $candidates = array_filter([
            $configured,
            $userProfile . '/anaconda3/envs/training/python.exe',
            $userProfile . '/miniconda3/envs/training/python.exe',
            $userProfile . '/.conda/envs/training/python.exe',
            'C:/ProgramData/anaconda3/envs/training/python.exe',
        ]);
        foreach ($candidates as $candidate) { if (is_file($candidate)) { return $candidate; } }
        throw new \RuntimeException('The Conda “training” environment was not found. Configure OCR_TRAINING_PYTHON before starting a job.');
    }
}
