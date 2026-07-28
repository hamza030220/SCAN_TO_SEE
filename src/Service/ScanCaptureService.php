<?php

namespace App\Service;

use App\Entity\Menu;
use App\Entity\ScanCapture;
use App\Entity\ScanRegion;
use App\Entity\User;
use App\Repository\ScanCaptureRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Captures immutable OCR output and later attaches owner-reviewed labels.
 *
 * Live Menu/Category/Item persistence remains in OwnerMenuController so the
 * existing publishing workflow is not changed by data collection.
 */
class ScanCaptureService
{
    public function __construct(
        private readonly MenuScannerClient $scannerClient,
        private readonly ScanCaptureRepository $scanRepository,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * @return array{scan: ScanCapture, response: array}
     */
    public function capture(
        UploadedFile $image,
        User $owner,
        ?Menu $menu,
        string $currency,
    ): array {
        $response = $this->scannerClient->scanMenu($image, $currency);
        $uuid = (string) ($response['scan_uuid'] ?? '');
        if ($uuid === '') {
            throw new \RuntimeException('The scan service returned an unsupported response.');
        }

        $originalAsset = is_array($response['original_asset'] ?? null)
            ? $response['original_asset']
            : [];

        $scan = (new ScanCapture())
            ->setScanUuid($uuid)
            ->setOwner($owner)
            ->setMenu($menu)
            ->setBusiness($menu?->getBusiness())
            ->setOriginalImageUrl($response['original_image_url'] ?? null)
            ->setOriginalPublicId($originalAsset['public_id'] ?? null)
            ->setModelVersion((string) ($response['model_version'] ?? 'unknown'))
            ->setInferenceManifest(
                is_array($response['inference_manifest'] ?? null)
                    ? $response['inference_manifest']
                    : null
            )
            ->setQualityMetrics(
                is_array($response['quality_metrics'] ?? null)
                    ? $response['quality_metrics']
                    : null
            )
            ->setRawResponse($response);

        foreach (($response['regions'] ?? []) as $regionData) {
            if (!is_array($regionData) || !isset($regionData['box_id'])) {
                continue;
            }
            $asset = is_array($regionData['crop_asset'] ?? null)
                ? $regionData['crop_asset']
                : [];
            $geometry = [
                'box' => $regionData['box'] ?? null,
                'x' => $regionData['x'] ?? null,
                'y' => $regionData['y'] ?? null,
            ];
            $region = (new ScanRegion())
                ->setBoxId((int) $regionData['box_id'])
                ->setRole((string) ($regionData['role'] ?? 'unresolved'))
                ->setPairBoxId(isset($regionData['pair_box_id']) ? (int) $regionData['pair_box_id'] : null)
                ->setGroupBoxId(isset($regionData['group_box_id']) ? (int) $regionData['group_box_id'] : null)
                ->setGeometry($geometry)
                ->setCropUrl($regionData['crop_url'] ?? null)
                ->setCropPublicId($asset['public_id'] ?? null)
                ->setCropAssetId($asset['asset_id'] ?? null)
                ->setRawText((string) ($regionData['raw_text'] ?? ''))
                ->setConfidence((float) ($regionData['confidence'] ?? 0.0))
                ->setRawJson($regionData);
            $scan->addRegion($region);
        }

        $this->em->persist($scan);
        $this->em->flush();

        $response['scan_id'] = $scan->getId();
        return ['scan' => $scan, 'response' => $response];
    }

    /**
     * Store corrected crop labels while preserving the immutable raw response.
     */
    public function recordReview(int $scanId, User $owner, Menu $menu, array $categories): void
    {
        $scan = $this->scanRepository->findOneBy(['id' => $scanId, 'owner' => $owner]);
        if (!$scan) {
            throw new \RuntimeException('Scan not found or access denied.');
        }
        if ($scan->getMenu() && $scan->getMenu()?->getId() !== $menu->getId()) {
            throw new \RuntimeException('Scan does not belong to this menu.');
        }

        $now = new \DateTimeImmutable();
        $regionsByBox = [];
        foreach ($scan->getRegions() as $region) {
            $regionsByBox[$region->getBoxId()] = $region;
            $region
                ->setCorrectedText(null)
                ->setReviewOutcome('deleted')
                ->setCorrectedAt($now);
        }

        foreach ($categories as $category) {
            if (!is_array($category)) {
                continue;
            }
            $this->labelRegion(
                $regionsByBox,
                $category['box_id'] ?? null,
                trim((string) ($category['name'] ?? '')),
                $now,
            );
            foreach (($category['items'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $this->labelRegion(
                    $regionsByBox,
                    $item['name_box_id'] ?? null,
                    trim((string) ($item['name'] ?? '')),
                    $now,
                );
                $this->labelRegion(
                    $regionsByBox,
                    $item['price_box_id'] ?? null,
                    trim((string) ($item['price'] ?? '')),
                    $now,
                );
            }
        }

        $scan
            ->setCorrectedResponse(['categories' => $categories])
            ->setStatus('reviewed')
            ->setReviewedAt($now);
    }

    private function labelRegion(
        array $regionsByBox,
        mixed $boxId,
        string $correctedText,
        \DateTimeImmutable $correctedAt,
    ): void {
        if ($boxId === null || $boxId === '' || !isset($regionsByBox[(int) $boxId])) {
            return;
        }
        /** @var ScanRegion $region */
        $region = $regionsByBox[(int) $boxId];
        $region
            ->setCorrectedText($correctedText)
            ->setReviewOutcome(
                $this->normalize($region->getRawText()) === $this->normalize($correctedText)
                    ? 'accepted'
                    : 'modified'
            )
            ->setCorrectedAt($correctedAt);
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }
}
