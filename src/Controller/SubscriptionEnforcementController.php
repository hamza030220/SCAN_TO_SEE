<?php

namespace App\Controller;

use App\Entity\Subscription;
use App\Repository\MenuRepository;
use App\Repository\SubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_OWNER')]
class SubscriptionEnforcementController extends AbstractController
{
    #[Route('/owner/subscription/enforce-limits', name: 'app_owner_subscription_enforce_limits', methods: ['GET'])]
    public function show(
        SubscriptionRepository $subRepo,
        MenuRepository $menuRepo,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        // If enforcement is not required, redirect to dashboard
        if (!$user->isEnforcementRequired()) {
            return $this->redirectToRoute('app_owner');
        }

        $subscription = $subRepo->findOneBy(['owner' => $user]);
        if (!$subscription) {
            $this->addFlash('error', 'No subscription found.');
            return $this->redirectToRoute('app_owner_subscription');
        }

        $plan = $subscription->getPlan();
        $publishedLimit = Subscription::LIMITS[$plan]['published'];
        $draftLimit     = Subscription::LIMITS[$plan]['draft'];

        // Get all menus grouped by status
        $publishedMenus = $menuRepo->createQueryBuilder('m')
            ->join('m.business', 'b')
            ->where('b.owner = :owner')
            ->andWhere('m.status = :status')
            ->setParameter('owner', $user)
            ->setParameter('status', 'published')
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $draftMenus = $menuRepo->createQueryBuilder('m')
            ->join('m.business', 'b')
            ->where('b.owner = :owner')
            ->andWhere('m.status = :status')
            ->setParameter('owner', $user)
            ->setParameter('status', 'draft')
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('owner/subscription/enforce_limits.html.twig', [
            'subscription'     => $subscription,
            'plan'             => $plan,
            'planLabel'        => Subscription::LABELS[$plan],
            'publishedLimit'   => $publishedLimit,
            'draftLimit'       => $draftLimit,
            'publishedMenus'   => $publishedMenus,
            'draftMenus'       => $draftMenus,
            'publishedCount'   => count($publishedMenus),
            'draftCount'       => count($draftMenus),
        ]);
    }

    #[Route('/owner/subscription/enforce-limits', name: 'app_owner_subscription_enforce_limits_post', methods: ['POST'])]
    public function process(
        Request $request,
        SubscriptionRepository $subRepo,
        MenuRepository $menuRepo,
        EntityManagerInterface $em,
        LoggerInterface $logger,
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        // Verify CSRF token
        if (!$this->isCsrfTokenValid('enforce-limits', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        // If enforcement is not required, redirect to dashboard
        if (!$user->isEnforcementRequired()) {
            return $this->redirectToRoute('app_owner');
        }

        $subscription = $subRepo->findOneBy(['owner' => $user]);
        if (!$subscription) {
            $this->addFlash('error', 'No subscription found.');
            return $this->redirectToRoute('app_owner_subscription');
        }

        $plan = $subscription->getPlan();
        $publishedLimit = Subscription::LIMITS[$plan]['published'];
        $draftLimit     = Subscription::LIMITS[$plan]['draft'];

        // Get selected menu IDs
        $selectedPublished = array_map('intval', $request->request->all('keep_published') ?: []);
        $selectedDraft     = array_map('intval', $request->request->all('keep_draft') ?: []);

        // Fetch current menus
        $allPublished = $menuRepo->createQueryBuilder('m')
            ->join('m.business', 'b')
            ->where('b.owner = :owner')
            ->andWhere('m.status = :status')
            ->setParameter('owner', $user)
            ->setParameter('status', 'published')
            ->getQuery()
            ->getResult();

        $allDraft = $menuRepo->createQueryBuilder('m')
            ->join('m.business', 'b')
            ->where('b.owner = :owner')
            ->andWhere('m.status = :status')
            ->setParameter('owner', $user)
            ->setParameter('status', 'draft')
            ->getQuery()
            ->getResult();

        // Validation: Check exact count match
        $errors = [];

        if ($publishedLimit !== null) {
            if (count($selectedPublished) !== min($publishedLimit, count($allPublished))) {
                $required = min($publishedLimit, count($allPublished));
                $errors[] = sprintf(
                    'Please select exactly %d published menu%s to keep.',
                    $required,
                    $required !== 1 ? 's' : ''
                );
            }
        }

        if ($draftLimit !== null) {
            if (count($selectedDraft) !== min($draftLimit, count($allDraft))) {
                $required = min($draftLimit, count($allDraft));
                $errors[] = sprintf(
                    'Please select exactly %d draft menu%s to keep.',
                    $required,
                    $required !== 1 ? 's' : ''
                );
            }
        }

        // Validation: Ensure selected IDs belong to user's menus
        $publishedIds = array_map(fn($m) => $m->getId(), $allPublished);
        $draftIds     = array_map(fn($m) => $m->getId(), $allDraft);

        foreach ($selectedPublished as $id) {
            if (!in_array($id, $publishedIds, true)) {
                $this->addFlash('error', 'Invalid menu selection detected.');
                return $this->redirectToRoute('app_owner_subscription_enforce_limits');
            }
        }

        foreach ($selectedDraft as $id) {
            if (!in_array($id, $draftIds, true)) {
                $this->addFlash('error', 'Invalid menu selection detected.');
                return $this->redirectToRoute('app_owner_subscription_enforce_limits');
            }
        }

        if (!empty($errors)) {
            foreach ($errors as $error) {
                $this->addFlash('error', $error);
            }
            return $this->redirectToRoute('app_owner_subscription_enforce_limits');
        }

        // Begin atomic deletion
        $em->beginTransaction();

        try {
            $deletedCount = 0;
            $deletedMenus = [];

            // Delete unselected published menus
            foreach ($allPublished as $menu) {
                if (!in_array($menu->getId(), $selectedPublished, true)) {
                    $deletedMenus[] = sprintf('%s (published)', $menu->getName());
                    $em->remove($menu);
                    $deletedCount++;
                }
            }

            // Delete unselected draft menus
            foreach ($allDraft as $menu) {
                if (!in_array($menu->getId(), $selectedDraft, true)) {
                    $deletedMenus[] = sprintf('%s (draft)', $menu->getName());
                    $em->remove($menu);
                    $deletedCount++;
                }
            }

            // Clear enforcement flag
            $user->setEnforcementRequired(false);

            // Flush changes
            $em->flush();
            $em->commit();

            // Audit log
            $logger->info('Subscription limit enforcement completed', [
                'user_id'         => $user->getId(),
                'user_email'      => $user->getEmail(),
                'plan'            => $plan,
                'deleted_count'   => $deletedCount,
                'deleted_menus'   => $deletedMenus,
                'kept_published'  => count($selectedPublished),
                'kept_draft'      => count($selectedDraft),
            ]);

            // Success message
            $this->addFlash('success', sprintf(
                '✅ Your account is now compliant with the %s plan. %d menu%s removed.',
                Subscription::LABELS[$plan],
                $deletedCount,
                $deletedCount !== 1 ? 's were' : ' was'
            ));

            return $this->redirectToRoute('app_owner');

        } catch (\Throwable $e) {
            $em->rollback();

            $logger->error('Subscription limit enforcement failed', [
                'user_id'   => $user->getId(),
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            $this->addFlash('error', 'An error occurred while processing your selection. Please try again or contact support.');
            return $this->redirectToRoute('app_owner_subscription_enforce_limits');
        }
    }
}
