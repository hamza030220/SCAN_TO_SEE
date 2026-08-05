<?php

namespace App\Controller;

use App\Entity\ScanRegion;
use App\Entity\TrainingJob;
use App\Entity\User;
use App\Entity\AdminAuditLog;
use App\Message\RunTrainingJob;
use App\Repository\ScanRegionRepository;
use App\Repository\TrainingJobRepository;
use App\Service\TrainingDatasetService;
use App\Service\ModelDeploymentService;
use App\Service\AdminAuditService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/ai')]
final class AdminAiController extends AbstractController
{
    #[Route('', name: 'app_admin_ai', methods: ['GET'])]
    public function index(TrainingDatasetService $datasets, TrainingJobRepository $jobs): Response
    {
        return $this->render('admin/ai/index.html.twig', [
            'statistics' => $datasets->statistics(),
            'activeJob' => $jobs->findActive(),
            'recentJobs' => $jobs->findBy([], ['createdAt' => 'DESC'], 5),
            'cloudinaryUrl' => 'https://console.cloudinary.com/console/media_library',
        ]);
    }

    #[Route('/dataset', name: 'app_admin_ai_dataset', methods: ['GET'])]
    public function dataset(Request $request, TrainingDatasetService $datasets, ScanRegionRepository $regions): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        return $this->render('admin/ai/dataset.html.twig', [
            'regions' => $datasets->findReviewPage($page), 'statistics' => $datasets->statistics(),
            'exports' => $datasets->listExports(), 'page' => $page,
            'pageCount' => max(1, (int) ceil($regions->count([]) / 40)),
        ]);
    }

    #[Route('/dataset/{id}/review', name: 'app_admin_ai_dataset_review', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function review(ScanRegion $region, Request $request, EntityManagerInterface $em, AdminAuditService $audit): Response
    {
        if (!$this->isCsrfTokenValid('dataset-region-' . $region->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'The review form expired. No dataset change was made.');
            return $this->redirectToRoute('app_admin_ai_dataset');
        }
        $action = (string) $request->request->get('action');
        /** @var User $admin */ $admin = $this->getUser();
        $before = ['label' => $region->getCorrectedText(), 'excluded' => $region->isExcludedFromTraining(), 'reason' => $region->getExclusionReason()];
        if ($action === 'exclude') {
            $reason = trim((string) $request->request->get('reason'));
            if ($reason === '') { $this->addFlash('error', 'Explain why this sample should be excluded.'); return $this->redirectToRoute('app_admin_ai_dataset'); }
            $region->setExcludedFromTraining(true)->setExclusionReason(mb_substr($reason, 0, 500));
            $message = 'The sample was excluded. Its source record remains available for audit and can be restored.';
        } elseif ($action === 'restore') {
            $region->setExcludedFromTraining(false)->setExclusionReason(null);
            $message = 'The sample is eligible for future dataset exports again.';
        } elseif ($action === 'correct') {
            $label = trim((string) $request->request->get('corrected_text'));
            if ($label === '') { $this->addFlash('error', 'A training label cannot be empty. Exclude the sample instead.'); return $this->redirectToRoute('app_admin_ai_dataset'); }
            $region->setCorrectedText(mb_substr($label, 0, 1000))->setReviewOutcome('modified')->setCorrectedAt(new \DateTimeImmutable());
            $message = 'The corrected label was saved and will be used in the next export.';
        } else {
            $this->addFlash('error', 'Unknown dataset action.'); return $this->redirectToRoute('app_admin_ai_dataset');
        }
        $log = $audit->start($admin, 'ai.dataset.' . $action, 'scan_region', $region->getId(), 'OCR region #' . $region->getId(), $region->getExclusionReason(), $request, $before);
        $em->flush();
        $audit->finish($log, AdminAuditLog::OUTCOME_SUCCESS, ['label' => $region->getCorrectedText(), 'excluded' => $region->isExcludedFromTraining(), 'reason' => $region->getExclusionReason()]);
        $this->addFlash('success', $message);
        return $this->redirectToRoute('app_admin_ai_dataset');
    }

    #[Route('/dataset/export', name: 'app_admin_ai_dataset_export', methods: ['POST'])]
    public function export(Request $request, TrainingDatasetService $datasets, AdminAuditService $audit): Response
    {
        if (!$this->isCsrfTokenValid('dataset-export', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'The export form expired. Please try again.'); return $this->redirectToRoute('app_admin_ai_dataset');
        }
        $name = 'dataset-' . (new \DateTimeImmutable())->format('Ymd-His');
        /** @var User $admin */ $admin = $this->getUser();
        $log = $audit->start($admin, 'ai.dataset.export', 'training_dataset', null, $name, null, $request);
        try {
            $result = $datasets->export($name); $s = $result['summary'];
            if ($s['written'] === 0) { $this->addFlash('error', 'No Cloudinary crops could be downloaded. Check asset access before training.'); }
            else { $this->addFlash('success', sprintf('Dataset %s saved locally: %d rows written, %d skipped. CSV validation is ready below.', $name, $s['written'], $s['failed'])); }
            $audit->finish($log, $s['written'] > 0 ? AdminAuditLog::OUTCOME_SUCCESS : AdminAuditLog::OUTCOME_FAILED, $s);
        } catch (\Throwable $e) {
            $audit->finish($log, AdminAuditLog::OUTCOME_FAILED, null, $e->getMessage());
            $this->addFlash('error', 'Dataset export failed: ' . $e->getMessage());
        }
        return $this->redirectToRoute('app_admin_ai_dataset');
    }

    #[Route('/dataset/{name}/manifest', name: 'app_admin_ai_dataset_manifest', methods: ['GET'], requirements: ['name' => '[a-zA-Z0-9_-]+'])]
    public function manifest(string $name, TrainingDatasetService $datasets): Response
    {
        $path = $datasets->manifestPath($name);
        if (!is_file($path)) { throw $this->createNotFoundException('Dataset manifest not found.'); }
        return (new BinaryFileResponse($path))->setContentDisposition('attachment', $name . '-manifest.csv');
    }

    #[Route('/training/start', name: 'app_admin_ai_training_start', methods: ['POST'])]
    public function startTraining(
        Request $request, TrainingJobRepository $jobs, TrainingDatasetService $datasets,
        EntityManagerInterface $em, MessageBusInterface $bus, AdminAuditService $audit,
    ): Response {
        if (!$this->isCsrfTokenValid('training-start', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'The training request expired. Please review the settings and try again.');
            return $this->redirectToRoute('app_admin_ai');
        }
        if ($jobs->findActive()) {
            $this->addFlash('error', 'A training or comparison job is already active. Only one GPU job can run at a time.');
            return $this->redirectToRoute('app_admin_ai');
        }
        if ($datasets->eligibleCount() < 3) {
            $this->addFlash('error', 'At least three approved samples are required before a proof-of-concept job can start.');
            return $this->redirectToRoute('app_admin_ai_dataset');
        }
        $epochs = max(1, min(5, $request->request->getInt('epochs', 1)));
        /** @var User $admin */ $admin = $this->getUser();
        $job = (new TrainingJob())->setRequestedBy($admin)->setParameters([
            'epochs' => $epochs, 'batchSize' => 1, 'gradientAccumulation' => 4, 'patience' => min(2, $epochs),
        ]);
        $em->persist($job); $em->flush();
        $log = $audit->start($admin, 'ai.training.start', 'training_job', $job->getId(), 'Training job #' . $job->getId(), null, $request, ['parameters' => $job->getParameters()]);
        try {
            $bus->dispatch(new RunTrainingJob((int) $job->getId()));
            $audit->finish($log, AdminAuditLog::OUTCOME_SUCCESS, ['status' => $job->getStatus()]);
        } catch (\Throwable $e) {
            $job->setStatus(TrainingJob::STATUS_FAILED)->setPhase('The training worker could not be reached')
                ->setErrorMessage('The job could not be queued. AI scanning remains available.')
                ->setFinishedAt(new \DateTimeImmutable());
            $em->flush();
            $audit->finish($log, AdminAuditLog::OUTCOME_FAILED, ['status' => $job->getStatus()], $e->getMessage());
            $this->addFlash('error', 'Training could not be queued. AI scanning remains available; check the worker configuration and try again.');
            return $this->redirectToRoute('app_admin_ai');
        }
        $this->addFlash('success', 'Training job queued. AI scanning is now in maintenance mode until dataset validation and model comparison finish.');
        return $this->redirectToRoute('app_admin_ai_training_job', ['id' => $job->getId()]);
    }

    #[Route('/training/{id}', name: 'app_admin_ai_training_job', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function trainingJob(TrainingJob $job): Response
    {
        return $this->render('admin/ai/training_job.html.twig', ['job' => $job]);
    }

    #[Route('/training/{id}/status', name: 'app_admin_ai_training_status', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function trainingStatus(TrainingJob $job): Response
    {
        return $this->json([
            'status' => $job->getStatus(), 'phase' => $job->getPhase(), 'progress' => $job->getProgress(),
            'stopRequested' => $job->isStopRequested(), 'baseline' => $job->getBaselineMetrics(),
            'candidate' => $job->getCandidateMetrics(), 'recommendation' => $job->getRecommendation(),
            'error' => $job->getErrorMessage(), 'log' => $job->getLogExcerpt(), 'active' => $job->isActive(),
        ], headers: ['Cache-Control' => 'no-store']);
    }

    #[Route('/training/{id}/stop', name: 'app_admin_ai_training_stop', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function stopTraining(TrainingJob $job, Request $request, ModelDeploymentService $models, EntityManagerInterface $em, AdminAuditService $audit): Response
    {
        if (!$this->isCsrfTokenValid('training-stop-' . $job->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'The stop request expired. No training process was changed.');
        } elseif (!$job->isActive()) {
            $this->addFlash('error', 'This job has already finished and cannot be stopped.');
        } elseif ($job->isStopRequested()) {
            $this->addFlash('error', 'A safe stop is already pending. The current step must finish before comparison begins.');
        } elseif ($job->getStatus() === TrainingJob::STATUS_QUEUED) {
            /** @var User $admin */ $admin = $this->getUser();
            $log = $audit->start($admin, 'ai.training.stop', 'training_job', $job->getId(), 'Training job #' . $job->getId(), 'Cancelled before execution', $request, ['status' => $job->getStatus()]);
            $job->setStatus(TrainingJob::STATUS_STOPPED)->setPhase('Cancelled before training started')
                ->setProgress(100)->setStopRequested(true)->setFinishedAt(new \DateTimeImmutable());
            $em->flush();
            $audit->finish($log, AdminAuditLog::OUTCOME_SUCCESS, ['status' => $job->getStatus()]);
            $this->addFlash('success', 'The queued job was cancelled. AI scanning is available again.');
        } else {
            try {
                /** @var User $admin */ $admin = $this->getUser();
                $log = $audit->start($admin, 'ai.training.stop', 'training_job', $job->getId(), 'Training job #' . $job->getId(), 'Safe stop requested', $request, ['status' => $job->getStatus()]);
                $models->requestStop($job); $job->setStopRequested(true)->setPhase('Safe stop requested; finishing the current step'); $em->flush();
                $audit->finish($log, AdminAuditLog::OUTCOME_SUCCESS, ['status' => $job->getStatus(), 'stopRequested' => true]);
                $this->addFlash('success', 'Safe stop requested. The latest checkpoint will be evaluated before the production model is changed.');
            } catch (\Throwable $e) {
                if (isset($log)) { $audit->finish($log, AdminAuditLog::OUTCOME_FAILED, null, $e->getMessage()); }
                $this->addFlash('error', 'The safe-stop signal could not be created. Training continues unchanged; please try again.');
            }
        }
        return $this->redirectToRoute('app_admin_ai_training_job', ['id' => $job->getId()]);
    }
}
