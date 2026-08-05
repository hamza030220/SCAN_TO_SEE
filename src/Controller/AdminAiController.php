<?php

namespace App\Controller;

use App\Entity\ScanRegion;
use App\Repository\ScanRegionRepository;
use App\Repository\TrainingJobRepository;
use App\Service\TrainingDatasetService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
    public function review(ScanRegion $region, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('dataset-region-' . $region->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'The review form expired. No dataset change was made.');
            return $this->redirectToRoute('app_admin_ai_dataset');
        }
        $action = (string) $request->request->get('action');
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
        $em->flush(); $this->addFlash('success', $message);
        return $this->redirectToRoute('app_admin_ai_dataset');
    }

    #[Route('/dataset/export', name: 'app_admin_ai_dataset_export', methods: ['POST'])]
    public function export(Request $request, TrainingDatasetService $datasets): Response
    {
        if (!$this->isCsrfTokenValid('dataset-export', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'The export form expired. Please try again.'); return $this->redirectToRoute('app_admin_ai_dataset');
        }
        $name = 'dataset-' . (new \DateTimeImmutable())->format('Ymd-His');
        try {
            $result = $datasets->export($name); $s = $result['summary'];
            if ($s['written'] === 0) { $this->addFlash('error', 'No Cloudinary crops could be downloaded. Check asset access before training.'); }
            else { $this->addFlash('success', sprintf('Dataset %s saved locally: %d rows written, %d skipped. CSV validation is ready below.', $name, $s['written'], $s['failed'])); }
        } catch (\Throwable $e) { $this->addFlash('error', 'Dataset export failed: ' . $e->getMessage()); }
        return $this->redirectToRoute('app_admin_ai_dataset');
    }

    #[Route('/dataset/{name}/manifest', name: 'app_admin_ai_dataset_manifest', methods: ['GET'], requirements: ['name' => '[a-zA-Z0-9_-]+'])]
    public function manifest(string $name, TrainingDatasetService $datasets): Response
    {
        $path = $datasets->manifestPath($name);
        if (!is_file($path)) { throw $this->createNotFoundException('Dataset manifest not found.'); }
        return (new BinaryFileResponse($path))->setContentDisposition('attachment', $name . '-manifest.csv');
    }
}
