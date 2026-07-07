<?php

namespace App\Controller;

use App\Entity\Subscription;
use App\Repository\MenuRepository;
use App\Repository\SubscriptionRepository;
use App\Service\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
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

        $session = $service->createCheckoutSession($user, $plan, $period, $successUrl, $cancelUrl);

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

        // Plan/period from URL (safe: session_id is cryptographically random, only Stripe knows it)
        $plan   = $request->query->get('plan', Subscription::PLAN_BASIC);
        $period = $request->query->get('period', Subscription::PERIOD_MONTHLY);

        if (!in_array($plan, Subscription::PLANS, true)) {
            $plan = Subscription::PLAN_BASIC;
        }
        if (!in_array($period, [Subscription::PERIOD_MONTHLY, Subscription::PERIOD_YEARLY], true)) {
            $period = Subscription::PERIOD_MONTHLY;
        }

        // Create or update subscription as PENDING immediately
        // Webhook will later confirm and set to ACTIVE with correct period dates
        $sub = $subRepo->findOneBy(['owner' => $user]) ?? (new Subscription())->setOwner($user);
        $sub->setPlan($plan);
        $sub->setBillingPeriod($period);
        $sub->setStatus(Subscription::STATUS_PENDING);

        // Try to enrich with Stripe data (subscription ID / customer ID)
        $stripeSession = $service->retrieveStripeSession($sessionId);
        if ($stripeSession !== null) {
            if ($stripeSession->customer) {
                $sub->setStripeCustomerId($stripeSession->customer);
            }
            if ($stripeSession->subscription) {
                $sub->setStripeSubscriptionId($stripeSession->subscription);
            }
        }

        $em->persist($sub);
        $em->flush();

        $this->addFlash('success', '🎉 Payment confirmed! Your subscription is now active.');
        return $this->redirectToRoute('app_owner');
    }

    // ── Stripe webhook (raw POST, no CSRF) ────────────────────────────────────

    #[Route('/webhook', name: 'app_stripe_webhook', methods: ['POST'])]
    public function webhook(Request $request, SubscriptionService $service): Response
    {
        $payload   = $request->getContent();
        $sigHeader = $request->headers->get('Stripe-Signature', '');

        try {
            $service->handleWebhookPayload($payload, $sigHeader);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            return new Response('Invalid signature', 400);
        } catch (\Throwable $e) {
            return new Response('Webhook error: ' . $e->getMessage(), 400);
        }

        return new Response('OK', 200);
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

        // Downgrade must be to a lower plan
        $newRank = (new Subscription())->setPlan($plan)->getPlanRank();
        if ($newRank >= $currentSub->getPlanRank()) {
            // It's actually an upgrade — go through Stripe checkout
            return $this->redirectToRoute('app_owner_subscription_checkout', ['plan' => $plan, 'period' => $period]);
        }

        // For downgrades, use the new enforcement flow
        // Apply the downgrade and let the enforcement subscriber handle menu selection
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('downgrade', $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            // Apply downgrade - this will trigger enforcement check
            $service->applyDowngrade($user, $plan, $period, []);
            
            // Check if enforcement is now required
            if ($user->isEnforcementRequired()) {
                $this->addFlash('info', sprintf(
                    '✅ Plan changed to %s. Please select which menus to keep.',
                    Subscription::LABELS[$plan]
                ));
                return $this->redirectToRoute('app_owner_subscription_enforce_limits');
            }
            
            $this->addFlash('success', sprintf(
                '✅ Successfully downgraded to %s plan.',
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
            throw $this->createAccessDeniedException();
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $sub  = $subRepo->findOneBy(['owner' => $user]);

        if ($sub && $sub->isActive()) {
            $service->cancelStripeSubscription($sub);
            $sub->setStatus(Subscription::STATUS_CANCELLED);
            $service->expireOwnerMenus($user);
            $em->flush();
            $this->addFlash('success', 'Subscription cancelled. Your menus have been set to draft.');
        }

        return $this->redirectToRoute('app_owner_subscription');
    }
}
