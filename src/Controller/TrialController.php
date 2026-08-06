<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Service\EntitlementService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class TrialController extends AbstractController
{
    #[Route('/trial-expired', name: 'app_trial_expired', methods: ['GET'])]
    #[IsGranted('ROLE_OWNER')]
    public function expired(EntitlementService $entitlements, SubscriptionRepository $subscriptions): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user->isEmailVerified()) {
            return $this->redirectToRoute('app_verify_email_pending');
        }
        if ($entitlements->hasAccess($user)) {
            return $this->redirectToRoute('app_dashboard');
        }
        return $this->render('owner/trial_expired.html.twig', [
            'user' => $user,
            'subscription' => $subscriptions->findOneBy(['owner' => $user]),
        ]);
    }
}
