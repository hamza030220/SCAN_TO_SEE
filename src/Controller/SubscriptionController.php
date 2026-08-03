<?php

namespace App\Controller;

use App\Entity\Subscription;
use App\Repository\BusinessRepository;
use App\Repository\MenuRepository;
use App\Repository\SubscriptionRepository;
use App\Service\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[Route('/owner/subscription')]
class SubscriptionController extends AbstractController
{
    // ── Subscription dashboard ────────────────────────────────────────────────

    #[Route('', name: 'app_owner_subscription', methods: ['GET'])]
    public function index(SubscriptionRepository $subRepo): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $sub  = $subRepo->findOneBy(['owner' => $user]);

        return $this->render('owner/subscription/index.html.twig', [
            'sub'    => $sub,
            'plans'  => Subscription::PLANS,
            'limits' => Subscription::LIMITS,
            'prices' => Subscription::PRICES,
            'labels' => Subscription::LABELS,
        ]);
    }

    // ── Start Stripe Checkout ─────────────────────────────────────────────────

    #[Route('/checkout/{plan}/{period}', name: 'app_owner_subscription_checkout', methods: ['GET'])]
    public function checkout(
        string $plan,
        string $period,
        SubscriptionService $service,
    ): Response {
        if (!in_array($plan, Subscription::PLANS, true)) {
            throw $this->createNotFoundException('Invalid plan.');
        }
        if (!in_array($period, [Subscription::PERIOD_MONTHLY, Subscription::PERIOD_YEARLY], true)) {
            throw $this->createNotFoundException('Invalid period.');
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        // Generate success URL WITHOUT the session_id placeholder (to avoid Symfony URL-encoding the braces)
        // Then append it manually so Stripe can replace {CHECKOUT_SESSION_ID} literally
        $successBase = $this->generateUrl(
            'app_owner_subscription_success',
            ['plan' => $plan, 'period' => $period],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
        $successUrl = $successBase . '&session_id={CHECKOUT_SESSION_ID}';
        $cancelUrl  = $this->generateUrl('app_owner_subscription', [], UrlGeneratorInterface::ABSOLUTE_URL);

        try {
            $session = $service->createCheckoutSession($user, $plan, $period, $successUrl, $cancelUrl);
        } catch (\LogicException $e) {
            $this->addFlash('warning', $e->getMessage());
            return $this->redirectToRoute('app_owner_subscription');
        } catch (\Throwable) {
            $this->addFlash('error', 'Stripe checkout is temporarily unavailable. Please try again.');
            return $this->redirectToRoute('app_owner_subscription');
        }

        return $this->redirect($session->url);
    }

    // ── Stripe success redirect ───────────────────────────────────────────────

    #[Route('/success', name: 'app_owner_subscription_success', methods: ['GET'])]
    public function success(
        Request $request,
        SubscriptionService $service,
        SubscriptionRepository $subRepo,
        EntityManagerInterface $em,
    ): Response {
        $sessionId = $request->query->get('session_id');

        // Stripe session IDs always start with cs_test_ or cs_live_
        if (!$sessionId || !str_starts_with($sessionId, 'cs_')) {
            return $this->redirectToRoute('app_owner_subscription');
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $stripeSession = $service->retrieveStripeSession($sessionId);
        $paymentAccepted = $stripeSession !== null
            && (int) $stripeSession->client_reference_id === $user->getId()
            && $stripeSession->mode === 'subscription'
            && $stripeSession->status === 'complete'
            && in_array($stripeSession->payment_status, ['paid', 'no_payment_required'], true)
            && !empty($stripeSession->subscription);

        if (!$paymentAccepted) {
            $this->addFlash('error', 'Stripe could not verify this checkout session.');
            return $this->redirectToRoute('app_owner_subscription');
        }

        $sub = $subRepo->findOneBy(['owner' => $user]);
        if (!$sub?->isActive()) {
            $plan = (string) ($stripeSession->metadata->plan ?? '');
            $period = (string) ($stripeSession->metadata->period ?? '');
            if (!in_array($plan, Subscription::PLANS, true)
                || !in_array($period, [Subscription::PERIOD_MONTHLY, Subscription::PERIOD_YEARLY], true)) {
                $this->addFlash('error', 'Stripe returned invalid subscription metadata.');
                return $this->redirectToRoute('app_owner_subscription');
            }

            $sub ??= (new Subscription())->setOwner($user);
            $sub->setPlan($plan);
            $sub->setBillingPeriod($period);
            $sub->setStatus(Subscription::STATUS_PENDING);
            $sub->setStripeCustomerId($stripeSession->customer ?: null);
            $sub->setStripeSubscriptionId($stripeSession->subscription);
            $em->persist($sub);
            $em->flush();
        }

        $this->addFlash('success', 'Payment received. Stripe is confirming your subscription.');
        return $this->redirectToRoute('app_owner_subscription');
    }

    // ── Stripe webhook (raw POST, no CSRF) ────────────────────────────────────

    #[Route('/webhook', name: 'app_stripe_webhook', methods: ['POST'])]
    public function webhook(
        Request $request,
        SubscriptionService $service,
        LoggerInterface $logger,
    ): Response
    {
        $payload   = $request->getContent();
        $sigHeader = $request->headers->get('Stripe-Signature', '');

        try {
            $service->handleWebhookPayload($payload, $sigHeader);
        } catch (\UnexpectedValueException $e) {
            return new Response('Invalid payload', 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            return new Response('Invalid signature', 400);
        } catch (\Throwable $e) {
            $logger->error('Stripe webhook processing failed', [
                'exception' => $e,
            ]);
            return new Response('Webhook processing failed', 500);
        }

        return new Response('OK', 200);
    }

    #[Route('/change/{plan}/{period}', name: 'app_owner_subscription_change', methods: ['POST'])]
    public function change(
        string $plan,
        string $period,
        Request $request,
        SubscriptionService $service,
    ): Response {
        if (!$this->isCsrfTokenValid('change-subscription', $request->request->get('_token'))) {
            $this->addFlash('error', 'Your security session expired. Refresh the page before changing plans.');
            return $this->redirectToRoute('app_owner_subscription');
        }
        if (!in_array($plan, Subscription::PLANS, true)
            || !in_array($period, [Subscription::PERIOD_MONTHLY, Subscription::PERIOD_YEARLY], true)) {
            throw $this->createNotFoundException();
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $current = $service->getSubscription($user);
        if (!$current?->isActive()) {
            $this->addFlash('warning', 'An active subscription is required to change plans.');
            return $this->redirectToRoute('app_owner_subscription');
        }

        if ($current->getPlan() === $plan && $current->getBillingPeriod() === $period) {
            $this->addFlash('info', 'This is already your current plan and billing period.');
            return $this->redirectToRoute('app_owner_subscription');
        }

        $newRank = (new Subscription())->setPlan($plan)->getPlanRank();
        if ($newRank < $current->getPlanRank()) {
            return $this->redirectToRoute('app_owner_subscription_downgrade', [
                'plan' => $plan,
                'period' => $period,
            ]);
        }

        try {
            $service->changeActiveSubscription($user, $plan, $period);
            $this->addFlash('success', 'Your subscription has been updated.');
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Stripe could not update the subscription. No local plan change was applied.');
        }

        return $this->redirectToRoute('app_owner_subscription');
    }

    // ── Downgrade: plan selection ─────────────────────────────────────────────

    #[Route('/downgrade/{plan}/{period}', name: 'app_owner_subscription_downgrade', methods: ['GET', 'POST'])]
    public function downgrade(
        string $plan,
        string $period,
        Request $request,
        SubscriptionService $service,
        SubscriptionRepository $subRepo,
        MenuRepository $menuRepo,
        BusinessRepository $businessRepo,
    ): Response {
        /** @var \App\Entity\User $user */
        $user      = $this->getUser();
        $currentSub = $subRepo->findOneBy(['owner' => $user]);

        if (!$currentSub || !$currentSub->isActive()) {
            $this->addFlash('info', 'Please select a subscription plan to continue.');
            return $this->redirectToRoute('app_owner_subscription');
        }

        if (!in_array($plan, Subscription::PLANS, true)) {
            throw $this->createNotFoundException();
        }
        if (!in_array($period, [Subscription::PERIOD_MONTHLY, Subscription::PERIOD_YEARLY], true)) {
            throw $this->createNotFoundException();
        }

        // Downgrade must be to a lower plan
        $newRank = (new Subscription())->setPlan($plan)->getPlanRank();
        if ($newRank >= $currentSub->getPlanRank()) {
            $this->addFlash('info', 'Select that plan from the subscription page.');
            return $this->redirectToRoute('app_owner_subscription');
        }

        $newBusinessLimit = Subscription::BUSINESS_LIMITS[$plan] ?? null;
        $businessCount = $businessRepo->count(['owner' => $user]);
        $businessLimitExceeded = $newBusinessLimit !== null && $businessCount > $newBusinessLimit;

        // For downgrades, use the new enforcement flow
        // Apply the downgrade and let the enforcement subscriber handle menu selection
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('downgrade', $request->request->get('_token'))) {
                $this->addFlash('error', 'Your security session expired. Refresh the page before changing plans.');
                return $this->redirectToRoute('app_owner_subscription');
            }
            if ($businessLimitExceeded) {
                $this->addFlash('error', sprintf(
                    'Reduce your businesses to %d before selecting the %s plan.',
                    $newBusinessLimit,
                    Subscription::LABELS[$plan],
                ));
                return $this->redirectToRoute('app_owner_businesses');
            }

            // Apply downgrade - this will trigger enforcement check
            try {
                $service->applyDowngrade($user, $plan, $period, []);
            } catch (\Throwable $e) {
                $this->addFlash('error', 'Stripe could not apply the downgrade. Your current plan is unchanged.');
                return $this->redirectToRoute('app_owner_subscription');
            }
            
            // Check if enforcement is now required
            if ($user->isEnforcementRequired()) {
                $this->addFlash('info', sprintf(
                    'Plan changed to %s. Please select which menus to keep.',
                    Subscription::LABELS[$plan]
                ));
                return $this->redirectToRoute('app_owner_subscription_enforce_limits');
            }
            
            $this->addFlash('success', sprintf(
                'Successfully downgraded to %s plan.',
                Subscription::LABELS[$plan]
            ));
            return $this->redirectToRoute('app_owner_subscription');
        }

        // Show confirmation page
        $newPublishedLimit = Subscription::LIMITS[$plan]['published'];
        $newDraftLimit     = Subscription::LIMITS[$plan]['draft'];
        
        $publishedCount = $service->countPublishedMenus($user);
        $draftCount     = $service->countDraftMenus($user);
        
        $willNeedEnforcement = 
            ($newPublishedLimit !== null && $publishedCount > $newPublishedLimit) ||
            ($newDraftLimit !== null && $draftCount > $newDraftLimit);

        return $this->render('owner/subscription/downgrade_confirm.html.twig', [
            'currentSub'          => $currentSub,
            'newPlan'             => $plan,
            'newPlanLabel'        => Subscription::LABELS[$plan],
            'newPeriod'           => $period,
            'newPublishedLimit'   => $newPublishedLimit,
            'newDraftLimit'       => $newDraftLimit,
            'publishedCount'      => $publishedCount,
            'draftCount'          => $draftCount,
            'willNeedEnforcement' => $willNeedEnforcement,
            'newBusinessLimit'    => $newBusinessLimit,
            'businessCount'       => $businessCount,
            'businessLimitExceeded' => $businessLimitExceeded,
        ]);
    }

    // ── Cancel subscription ───────────────────────────────────────────────────

    #[Route('/cancel', name: 'app_owner_subscription_cancel', methods: ['POST'])]
    public function cancel(
        Request $request,
        SubscriptionService $service,
        SubscriptionRepository $subRepo,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('cancel-subscription', $request->request->get('_token'))) {
            $this->addFlash('error', 'Your security session expired. Refresh the page before cancelling the subscription.');
            return $this->redirectToRoute('app_owner_subscription');
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $sub  = $subRepo->findOneBy(['owner' => $user]);

        if ($sub && $sub->isActive()) {
            $service->cancelStripeSubscription($sub);
            $sub->setStatus(Subscription::STATUS_CANCELLED);
            $em->flush();
            $this->addFlash('success', 'Subscription cancelled. Your menu data has been preserved.');
        }

        return $this->redirectToRoute('app_owner_subscription');
    }
}
