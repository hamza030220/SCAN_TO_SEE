<?php

namespace App\Service;

use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\MenuRepository;
use App\Repository\SubscriptionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Checkout\Session as StripeSession;
use Stripe\Stripe;
use Stripe\StripeClient;

class SubscriptionService
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptionRepo,
        private readonly MenuRepository         $menuRepo,
        private readonly EntityManagerInterface $em,
        private readonly string                 $stripeSecretKey,
        private readonly string                 $stripeWebhookSecret,
        private readonly array                  $stripePriceIds, // map plan+period → price ID
    ) {}

    // ── Getters ───────────────────────────────────────────────────────────────

    public function getSubscription(User $user): ?Subscription
    {
        return $this->subscriptionRepo->findOneBy(['owner' => $user]);
    }

    public function hasActiveSubscription(User $user): bool
    {
        $sub = $this->getSubscription($user);
        return $sub !== null && $sub->isActive();
    }

    // ── Published menu count for this owner ───────────────────────────────────

    public function countPublishedMenus(User $user): int
    {
        return (int) $this->menuRepo->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->join('m.business', 'b')
            ->where('b.owner = :owner')
            ->andWhere('m.status = :status')
            ->setParameter('owner', $user)
            ->setParameter('status', 'published')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countAllMenus(User $user): int
    {
        return (int) $this->menuRepo->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->join('m.business', 'b')
            ->where('b.owner = :owner')
            ->setParameter('owner', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countDraftMenus(User $user): int
    {
        return (int) $this->menuRepo->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->join('m.business', 'b')
            ->where('b.owner = :owner')
            ->andWhere('m.status = :status')
            ->setParameter('owner', $user)
            ->setParameter('status', 'draft')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The single source of truth for all menu slot checks.
     *
     * Pass the menu's CURRENT status (or null for a new menu) and the DESIRED new status.
     * Returns true if the transition is allowed by the plan limits.
     *
     * NOTE: This method only CHECKS if the transition is possible. 
     * Use autoSwapMenuStatus() to automatically make room when limits are reached.
     *
     * Examples:
     *   canSetMenuStatus($user, null,        'draft')      → new draft
     *   canSetMenuStatus($user, null,        'published')  → new published
     *   canSetMenuStatus($user, 'draft',     'published')  → promote draft to published
     *   canSetMenuStatus($user, 'published', 'draft')      → demote published to draft
     *   canSetMenuStatus($user, 'published', 'published')  → keep published (always ok)
     */
    public function canSetMenuStatus(User $user, ?string $currentStatus, string $newStatus): bool
    {
        $sub = $this->getSubscription($user);
        if (!$sub || (!$sub->isActive() && $sub->getStatus() !== Subscription::STATUS_PENDING)) {
            return false;
        }

        $plan           = $sub->getPlan();
        $publishedLimit = Subscription::LIMITS[$plan]['published'] ?? null;
        $draftLimit     = Subscription::LIMITS[$plan]['draft'] ?? null;

        // No change in status → always allowed
        if ($currentStatus === $newStatus) {
            return true;
        }

        $publishedCount = $this->countPublishedMenus($user);
        $draftCount     = $this->countDraftMenus($user);

        if ($newStatus === 'published') {
            // Gaining a published slot; losing a draft slot only if demoting from draft
            $newPublished = $publishedCount + 1;
            if ($publishedLimit !== null && $newPublished > $publishedLimit) {
                return false;
            }
        }

        if ($newStatus === 'draft') {
            // Gaining a draft slot; losing a published slot only if demoting from published
            $newDraft = $draftCount + 1;
            if ($draftLimit !== null && $newDraft > $draftLimit) {
                return false;
            }
        }

        return true;
    }

    /**
     * Automatically swap menu statuses to make room for a new menu or status change.
     * 
     * When a user wants to create/change a menu but would exceed limits, this method
     * automatically demotes the oldest menu of the target status to make room.
     * 
     * Returns array with:
     *   'allowed' => bool (whether the transition can proceed)
     *   'swapped_menu' => Menu|null (menu that was auto-swapped, if any)
     *   'message' => string (user-friendly message explaining what happened)
     */
    public function autoSwapMenuStatus(User $user, ?int $excludeMenuId, ?string $currentStatus, string $newStatus): array
    {
        // If already allowed, no swap needed
        if ($this->canSetMenuStatus($user, $currentStatus, $newStatus)) {
            return [
                'allowed' => true,
                'swapped_menu' => null,
                'message' => null,
            ];
        }

        $sub = $this->getSubscription($user);
        if (!$sub || (!$sub->isActive() && $sub->getStatus() !== Subscription::STATUS_PENDING)) {
            return [
                'allowed' => false,
                'swapped_menu' => null,
                'message' => 'No active subscription found.',
            ];
        }

        $plan = $sub->getPlan();
        $publishedLimit = Subscription::LIMITS[$plan]['published'] ?? null;
        $draftLimit     = Subscription::LIMITS[$plan]['draft'] ?? null;

        // Pro plan: unlimited, should never need swapping
        if ($publishedLimit === null && $draftLimit === null) {
            return [
                'allowed' => true,
                'swapped_menu' => null,
                'message' => null,
            ];
        }

        $swappedMenu = null;
        $message = null;

        // Scenario 1: Want to create/change to published, but published limit reached
        if ($newStatus === 'published' && $publishedLimit !== null) {
            $publishedCount = $this->countPublishedMenus($user);
            if ($publishedCount >= $publishedLimit) {
                // Find oldest published menu (excluding the one being edited)
                $qb = $this->menuRepo->createQueryBuilder('m')
                    ->join('m.business', 'b')
                    ->where('b.owner = :owner')
                    ->andWhere('m.status = :status')
                    ->setParameter('owner', $user)
                    ->setParameter('status', 'published')
                    ->orderBy('m.createdAt', 'ASC')
                    ->setMaxResults(1);

                if ($excludeMenuId) {
                    $qb->andWhere('m.id != :exclude')
                       ->setParameter('exclude', $excludeMenuId);
                }

                $oldestPublished = $qb->getQuery()->getOneOrNullResult();

                if ($oldestPublished) {
                    $oldestPublished->setStatus('draft');
                    $oldestPublished->setUpdatedAt(new \DateTimeImmutable());
                    $this->em->flush();
                    
                    $swappedMenu = $oldestPublished;
                    $message = sprintf(
                        '✨ "%s" was automatically set to draft to make room for your published menu.',
                        $oldestPublished->getName()
                    );
                }
            }
        }

        // Scenario 2: Want to create/change to draft, but draft limit reached
        if ($newStatus === 'draft' && $draftLimit !== null) {
            $draftCount = $this->countDraftMenus($user);
            if ($draftCount >= $draftLimit) {
                // Find oldest draft menu (excluding the one being edited)
                $qb = $this->menuRepo->createQueryBuilder('m')
                    ->join('m.business', 'b')
                    ->where('b.owner = :owner')
                    ->andWhere('m.status = :status')
                    ->setParameter('owner', $user)
                    ->setParameter('status', 'draft')
                    ->orderBy('m.createdAt', 'ASC')
                    ->setMaxResults(1);

                if ($excludeMenuId) {
                    $qb->andWhere('m.id != :exclude')
                       ->setParameter('exclude', $excludeMenuId);
                }

                $oldestDraft = $qb->getQuery()->getOneOrNullResult();

                if ($oldestDraft) {
                    $oldestDraft->setStatus('published');
                    $oldestDraft->setUpdatedAt(new \DateTimeImmutable());
                    $this->em->flush();
                    
                    $swappedMenu = $oldestDraft;
                    $message = sprintf(
                        '✨ "%s" was automatically published to make room for your draft menu.',
                        $oldestDraft->getName()
                    );
                }
            }
        }

        return [
            'allowed' => true,
            'swapped_menu' => $swappedMenu,
            'message' => $message,
        ];
    }

    /**
     * Can the owner create a brand-new menu (used to gate the "New menu" button).
     * Both a draft and a published slot must be potentially available.
     */
    public function canCreateMenu(User $user): bool
    {
        $sub = $this->getSubscription($user);
        if (!$sub || (!$sub->isActive() && $sub->getStatus() !== Subscription::STATUS_PENDING)) {
            return false;
        }

        $plan           = $sub->getPlan();
        $publishedLimit = Subscription::LIMITS[$plan]['published'] ?? null;
        $draftLimit     = Subscription::LIMITS[$plan]['draft'] ?? null;

        if ($publishedLimit === null && $draftLimit === null) {
            return true; // Pro: unlimited
        }

        $publishedCount = $this->countPublishedMenus($user);
        $draftCount     = $this->countDraftMenus($user);

        // There must be at least one free slot (either published or draft) to create a new menu
        $publishedFree = $publishedLimit === null || $publishedCount < $publishedLimit;
        $draftFree     = $draftLimit === null || $draftCount < $draftLimit;

        return $publishedFree || $draftFree;
    }

    /** @deprecated Use canSetMenuStatus() instead */
    public function canPublishMenu(User $user): bool
    {
        return $this->canSetMenuStatus($user, 'draft', 'published');
    }

    // ── Stripe checkout session ───────────────────────────────────────────────

    public function createCheckoutSession(
        User   $user,
        string $plan,
        string $period,
        string $successUrl,
        string $cancelUrl,
    ): StripeSession {
        Stripe::setApiKey($this->stripeSecretKey);

        $priceId = $this->stripePriceIds[$plan][$period]
            ?? throw new \InvalidArgumentException("No Stripe price for {$plan}/{$period}");

        $params = [
            'mode'               => 'subscription',
            'line_items'         => [['price' => $priceId, 'quantity' => 1]],
            'success_url'        => $successUrl,
            'cancel_url'         => $cancelUrl,
            'client_reference_id' => (string) $user->getId(),
            'metadata'           => ['plan' => $plan, 'period' => $period],
        ];

        // Re-use existing customer if available
        $sub = $this->getSubscription($user);
        if ($sub?->getStripeCustomerId()) {
            $params['customer'] = $sub->getStripeCustomerId();
        } else {
            $params['customer_email'] = $user->getEmail();
        }

        return StripeSession::create($params);
    }

    // ── Retrieve a Stripe Checkout Session ───────────────────────────────────

    public function retrieveStripeSession(string $sessionId): ?\Stripe\Checkout\Session
    {
        Stripe::setApiKey($this->stripeSecretKey);

        try {
            return StripeSession::retrieve($sessionId);
        } catch (\Throwable) {
            return null;
        }
    }

    // ── Webhook processing ────────────────────────────────────────────────────

    public function handleWebhookPayload(string $payload, string $sigHeader): void
    {
        Stripe::setApiKey($this->stripeSecretKey);

        $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $this->stripeWebhookSecret);

        match ($event->type) {
            'checkout.session.completed'      => $this->onCheckoutCompleted($event->data->object),
            'invoice.paid'                    => $this->onInvoicePaid($event->data->object),
            'customer.subscription.deleted'   => $this->onSubscriptionDeleted($event->data->object),
            'invoice.payment_failed'          => $this->onPaymentFailed($event->data->object),
            default                           => null,
        };
    }

    private function onCheckoutCompleted(\Stripe\Checkout\Session $session): void
    {
        $userId = (int) $session->client_reference_id;
        $user   = $this->em->find(User::class, $userId);
        if (!$user) return;

        $plan   = $session->metadata->plan ?? Subscription::PLAN_BASIC;
        $period = $session->metadata->period ?? Subscription::PERIOD_MONTHLY;

        $stripeClient = new StripeClient($this->stripeSecretKey);
        $stripeSub    = $stripeClient->subscriptions->retrieve($session->subscription);

        $sub = $this->subscriptionRepo->findOneBy(['owner' => $user])
            ?? (new Subscription())->setOwner($user);

        $oldPlan = $sub->getPlan();
        $oldRank = $sub->getPlanRank();
        $newRank = (new Subscription())->setPlan($plan)->getPlanRank();

        $sub->setStripeCustomerId($session->customer);
        $sub->setStripeSubscriptionId($session->subscription);
        $sub->setPlan($plan);
        $sub->setBillingPeriod($period);
        $sub->setStatus(Subscription::STATUS_ACTIVE);
        $sub->setCurrentPeriodEnd(\DateTimeImmutable::createFromFormat('U', (string) $stripeSub->current_period_end));
        $sub->setExpiryReminderSent(false);

        // Check if enforcement is needed (downgrade or new subscription with existing menus)
        if ($newRank < $oldRank || $oldPlan === null) {
            $this->checkAndSetEnforcement($user, $plan);
        } else {
            // Upgrade: clear enforcement
            $user->setEnforcementRequired(false);
        }

        $this->em->persist($sub);
        $this->em->flush();
    }

    private function onInvoicePaid(\Stripe\Invoice $invoice): void
    {
        if (!$invoice->subscription) return;

        $sub = $this->subscriptionRepo->findOneBy(['stripeSubscriptionId' => $invoice->subscription]);
        if (!$sub) return;

        $stripeClient = new StripeClient($this->stripeSecretKey);
        $stripeSub    = $stripeClient->subscriptions->retrieve($invoice->subscription);

        $oldPlan = $sub->getPlan();
        
        $sub->setStatus(Subscription::STATUS_ACTIVE);
        $sub->setCurrentPeriodEnd(\DateTimeImmutable::createFromFormat('U', (string) $stripeSub->current_period_end));
        $sub->setExpiryReminderSent(false);
        
        // Check if plan changed (renewal might include plan change)
        $newPlan = $sub->getPlan();
        if ($oldPlan !== $newPlan) {
            $this->checkAndSetEnforcement($sub->getOwner(), $newPlan);
        }
        
        $this->em->flush();
    }

    private function onSubscriptionDeleted(\Stripe\Subscription $stripeSub): void
    {
        $sub = $this->subscriptionRepo->findOneBy(['stripeSubscriptionId' => $stripeSub->id]);
        if (!$sub) return;

        $sub->setStatus(Subscription::STATUS_CANCELLED);
        $this->expireOwnerMenus($sub->getOwner());
        $this->em->flush();
    }

    private function onPaymentFailed(\Stripe\Invoice $invoice): void
    {
        // Keep subscription active for now — Stripe will retry
        // Mark it so the UI can show a warning
    }

    // ── Downgrade ─────────────────────────────────────────────────────────────

    /**
     * Downgrade to a lower plan. $keepMenuIds = IDs of menus to keep published.
     * DEPRECATED: This method only handles published menus. 
     * New enforcement flow handles both published and draft limits.
     */
    public function applyDowngrade(User $user, string $newPlan, string $newPeriod, array $keepMenuIds = []): void
    {
        Stripe::setApiKey($this->stripeSecretKey);

        $sub = $this->getSubscription($user)
            ?? throw new \LogicException('No subscription found for user');

        $newPriceId = $this->stripePriceIds[$newPlan][$newPeriod]
            ?? throw new \InvalidArgumentException("No Stripe price for {$newPlan}/{$newPeriod}");

        $oldRank = $sub->getPlanRank();
        $newRank = (new Subscription())->setPlan($newPlan)->getPlanRank();

        // Update Stripe subscription to new price
        if ($sub->getStripeSubscriptionId()) {
            $stripeClient    = new StripeClient($this->stripeSecretKey);
            $stripeSub       = $stripeClient->subscriptions->retrieve($sub->getStripeSubscriptionId());
            $stripeClient->subscriptions->update($sub->getStripeSubscriptionId(), [
                'items'       => [['id' => $stripeSub->items->data[0]->id, 'price' => $newPriceId]],
                'proration_behavior' => 'none',
            ]);
        }

        // Demote extra menus to draft (legacy behavior)
        $limit = Subscription::LIMITS[$newPlan]['published'] ?? null;
        if ($limit !== null) {
            $menus = $this->menuRepo->createQueryBuilder('m')
                ->join('m.business', 'b')
                ->where('b.owner = :owner')
                ->andWhere('m.status = :status')
                ->setParameter('owner', $user)
                ->setParameter('status', 'published')
                ->getQuery()
                ->getResult();

            foreach ($menus as $menu) {
                if (!in_array($menu->getId(), $keepMenuIds, true)) {
                    $menu->setStatus('draft');
                }
            }
        }

        $sub->setPlan($newPlan);
        $sub->setBillingPeriod($newPeriod);
        
        // Check if enforcement is needed (downgrade might create draft overflow)
        if ($newRank < $oldRank) {
            $this->checkAndSetEnforcement($user, $newPlan);
        }
        
        $this->em->flush();
    }

    // ── Expire menus when subscription lapses ─────────────────────────────────

    public function expireOwnerMenus(User $user): void
    {
        $menus = $this->menuRepo->createQueryBuilder('m')
            ->join('m.business', 'b')
            ->where('b.owner = :owner')
            ->andWhere('m.status = :status')
            ->setParameter('owner', $user)
            ->setParameter('status', 'published')
            ->getQuery()
            ->getResult();

        foreach ($menus as $menu) {
            $menu->setStatus('draft');
        }
    }

    // ── Cancel Stripe subscription ────────────────────────────────────────────

    public function cancelStripeSubscription(Subscription $sub): void
    {
        if (!$sub->getStripeSubscriptionId()) return;
        $client = new StripeClient($this->stripeSecretKey);
        $client->subscriptions->cancel($sub->getStripeSubscriptionId());
    }

    // ── Enforcement check ─────────────────────────────────────────────────────

    /**
     * Check if the user exceeds the new plan's limits and set enforcement flag if needed.
     */
    private function checkAndSetEnforcement(User $user, string $newPlan): void
    {
        $publishedLimit = Subscription::LIMITS[$newPlan]['published'] ?? null;
        $draftLimit     = Subscription::LIMITS[$newPlan]['draft'] ?? null;

        // Pro plan: unlimited, no enforcement needed
        if ($publishedLimit === null && $draftLimit === null) {
            $user->setEnforcementRequired(false);
            return;
        }

        $publishedCount = $this->countPublishedMenus($user);
        $draftCount     = $this->countDraftMenus($user);

        $exceededPublished = $publishedLimit !== null && $publishedCount > $publishedLimit;
        $exceededDraft     = $draftLimit !== null && $draftCount > $draftLimit;

        if ($exceededPublished || $exceededDraft) {
            $user->setEnforcementRequired(true);
        } else {
            $user->setEnforcementRequired(false);
        }
    }
}
