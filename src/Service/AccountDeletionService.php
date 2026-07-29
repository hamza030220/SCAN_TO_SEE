<?php

namespace App\Service;

use App\Entity\Item;
use App\Entity\Menu;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\BusinessRepository;
use App\Repository\ScanCaptureRepository;
use App\Repository\SubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;

class AccountDeletionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BusinessRepository $businesses,
        private readonly ScanCaptureRepository $scans,
        private readonly SubscriptionRepository $subscriptions,
        private readonly SubscriptionService $subscriptionService,
        private readonly EmailBlockService $emailBlocks,
        private readonly ImageUploadService $imageUpload,
        private readonly MenuScannerClient $scannerClient,
    ) {}

    /**
     * Deletes owner-linked application data while retaining only reviewed,
     * de-linked OCR training captures. Stripe cancellation happens first so
     * an external failure cannot leave a deleted account being charged.
     */
    public function delete(User $user): \DateTimeImmutable
    {
        $subscription = $this->subscriptions->findOneBy(['owner' => $user]);
        if ($subscription && $subscription->getStripeSubscriptionId()
            && in_array($subscription->getStatus(), [Subscription::STATUS_ACTIVE, Subscription::STATUS_PENDING, Subscription::STATUS_PAST_DUE], true)) {
            $this->subscriptionService->cancelStripeSubscription($subscription);
        }

        $localImagePaths = [];
        $businesses = $this->businesses->findBy(['owner' => $user]);
        foreach ($businesses as $business) {
            $localImagePaths[] = $business->getLogoPath();
            foreach ($this->em->getRepository(Menu::class)->findBy(['business' => $business]) as $menu) {
                $theme = $menu->getThemeConfig();
                $localImagePaths[] = $theme['bgImagePath'] ?? null;
                foreach ($menu->getCategories() as $category) {
                    foreach ($category->getItems() as $item) {
                        \assert($item instanceof Item);
                        $localImagePaths[] = $item->getImagePath();
                    }
                }
            }
        }

        $unreviewedScanUuids = [];
        $this->em->beginTransaction();
        try {
            foreach ($this->scans->findBy(['owner' => $user]) as $scan) {
                if ($scan->getStatus() === 'reviewed' && $scan->getCorrectedResponse() !== null) {
                    $scan->setOwner(null)->setBusiness(null)->setMenu(null);
                } else {
                    $unreviewedScanUuids[] = $scan->getScanUuid();
                    $this->em->remove($scan);
                }
            }

            $block = $this->emailBlocks->block((string) $user->getEmail());
            $blockedUntil = $block->getBlockedUntil();
            $this->em->remove($user);
            $this->em->flush();
            $this->em->commit();

            foreach ($localImagePaths as $localImagePath) {
                $this->imageUpload->delete($localImagePath);
            }
            foreach ($unreviewedScanUuids as $scanUuid) {
                $this->scannerClient->deleteTrainingAssets($scanUuid);
            }

            return $blockedUntil;
        } catch (\Throwable $e) {
            $this->em->rollback();
            throw $e;
        }
    }
}
