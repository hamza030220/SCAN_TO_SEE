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
     * NOTE: This method only checks whether the transition is possible.
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
        if (!$sub?->isActive()) {
            return false;
        }

        if (!in_array($newStatus, ['draft', 'published'], true)) {
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
     * Compatibility wrapper for older callers. It never mutates another menu.
     *
     * Returns whether the transition is allowed and an explanatory message.
     */
    public function autoSwapMenuStatus(User $user, ?int $excludeMenuId, ?string $currentStatus, string $newStatus): array
    {
        if ($this->canSetMenuStatus($user, $currentStatus, $newStatus)) {
            return [
                'allowed' => true,
                'swapped_menu' => null,
                'message' => null,
            ];
        }

        $sub = $this->getSubscription($user);
        if (!$sub?->isActive()) {
            return [
                'allowed' => false,
                'swapped_menu' => null,
                'message' => 'No active subscription found.',
            ];
        }

        return [
            'allowed' => false,
            'swapped_menu' => null,
            'message' => sprintf(
                'Your %s plan has no free %s menu slot. Archive or change another menu first, or upgrade your plan.',
                $sub->getPlanLabel(),
                $newStatus,
            ),
        ];
    }

    /**
     * Can the owner create a brand-new menu (used to gate the "New menu" button).
     * Both a draft and a published slot must be potentially available.
     */
    public function canCreateMenu(User $user): bool
    {
        $sub = $this->getSubscription($user);
        if (!$sub?->isActive()) {
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

    public function canCreateBusiness(User $user, int $currentBusinessCount): bool
    {
        $sub = $this->getSubscription($user);
        if (!$sub?->isActive()) {
            return false;
        }

        $limit = $sub->getBusinessLimit();
        return $limit === null || $currentBusinessCount < $limit;
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

        $existing = $this->getSubscription($user);
        if ($existing?->isActive()) {
            throw new \LogicException('Use the plan-change flow for an active subscription.');
        }
        if ($existing?->getStatus() === Subscription::STATUS_PENDING && $existing->getStripeSubscriptionId()) {
            throw new \LogicException('A subscription payment is already awaiting confirmation.');
        }

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
            'checkout.session.async_payment_succeeded' => $this->onCheckoutCompleted($event->data->object),
            'invoice.paid'                    => $this->onInvoicePaid($event->data->object),
            'customer.subscription.updated'   => $this->onSubscriptionUpdated($event->data->object),
            'customer.subscription.deleted'   => $this->onSubscriptionDeleted($event->data->object),
            'invoice.payment_failed'          => $this->onPaymentFailed($event->data->object),
            default                           => null,
        };
    }

    private function onCheckoutCompleted(\Stripe\Checkout\Session $session): void
    {
        $userId = (int) $session->client_reference_id;
        $user   = $this->em->find(User::class, $userId);
        $stripeSubscriptionId = $this->extractStripeId($session->subscription);
        if (!$user
            || !$stripeSubscriptionId
            || $session->status !== 'complete'
            || !in_array($session->payment_status, ['paid', 'no_payment_required'], true)
        ) {
            return;
        }

        $stripeClient = new StripeClient($this->stripeSecretKey);
        $stripeSub    = $stripeClient->subscriptions->retrieve($stripeSubscriptionId);

        $sub = $this->subscriptionRepo->findOneBy(['owner' => $user])
            ?? (new Subscription())->setOwner($user);

        $wasEntitled = $sub->isActive();
        $oldRank = $sub->getPlanRank();

        $sub->setStripeCustomerId($this->extractStripeId($session->customer));
        $sub->setStripeSubscriptionId($stripeSubscriptionId);
        $this->synchronizeFromStripe($sub, $stripeSub);
        $newRank = $sub->getPlanRank();

        // Check if enforcement is needed (downgrade or new subscription with existing menus)
        if (!$wasEntitled || $newRank < $oldRank) {
            $this->checkAndSetEnforcement($user, $sub->getPlan());
        } else {
            // Upgrade: clear enforcement
            $user->setEnforcementRequired(false);
        }

        $this->em->persist($sub);
        $this->em->flush();
    }

    private function onInvoicePaid(\Stripe\Invoice $invoice): void
    {
        $stripeSubscriptionId = $this->getInvoiceSubscriptionId($invoice);
        if (!$stripeSubscriptionId) return;

        $sub = $this->subscriptionRepo->findOneBy(['stripeSubscriptionId' => $stripeSubscriptionId]);
        if (!$sub) return;

        $stripeClient = new StripeClient($this->stripeSecretKey);
        $stripeSub    = $stripeClient->subscriptions->retrieve($stripeSubscriptionId);

        $oldPlan = $sub->getPlan();
        $this->synchronizeFromStripe($sub, $stripeSub);
        
        // Check if plan changed (renewal might include plan change)
        $newPlan = $sub->getPlan();
        if ($oldPlan !== $newPlan) {
            $this->checkAndSetEnforcement($sub->getOwner(), $newPlan);
        }
        
        $this->em->flush();
    }

    private function onSubscriptionUpdated(\Stripe\Subscription $stripeSub): void
    {
        $sub = $this->subscriptionRepo->findOneBy(['stripeSubscriptionId' => $stripeSub->id]);
        if (!$sub) {
            return;
        }

        $oldRank = $sub->getPlanRank();
        $this->synchronizeFromStripe($sub, $stripeSub);

        if ($sub->getPlanRank() < $oldRank) {
            $this->checkAndSetEnforcement($sub->getOwner(), $sub->getPlan());
        } elseif ($sub->getPlanRank() > $oldRank) {
            $sub->getOwner()->setEnforcementRequired(false);
        }

        $this->em->flush();
    }

    private function onSubscriptionDeleted(\Stripe\Subscription $stripeSub): void
    {
        $sub = $this->subscriptionRepo->findOneBy(['stripeSubscriptionId' => $stripeSub->id]);
        if (!$sub) return;

        $sub->setStatus(Subscription::STATUS_CANCELLED);
        $this->em->flush();
    }

    private function onPaymentFailed(\Stripe\Invoice $invoice): void
    {
        $stripeSubscriptionId = $this->getInvoiceSubscriptionId($invoice);
        if (!$stripeSubscriptionId) {
            return;
        }

        $sub = $this->subscriptionRepo->findOneBy(['stripeSubscriptionId' => $stripeSubscriptionId]);
        if (!$sub) {
            return;
        }

        $sub->setStatus(Subscription::STATUS_PAST_DUE);
        $this->em->flush();
    }

    /**
     * Stripe API 2025-03-31 moved an invoice's subscription reference under
     * parent.subscription_details. Keep the legacy fallback for older accounts.
     */
    private function getInvoiceSubscriptionId(\Stripe\Invoice $invoice): ?string
    {
        $data = $invoice->jsonSerialize();
        $value = $data['parent']['subscription_details']['subscription']
            ?? $data['subscription']
            ?? null;

        return $this->extractStripeId($value);
    }

    private function extractStripeId(mixed $value): ?string
    {
        if (is_string($value) && $value !== '') {
            return $value;
        }
        if (is_array($value) && isset($value['id']) && is_string($value['id'])) {
            return $value['id'];
        }
        if (is_object($value) && isset($value->id) && is_string($value->id)) {
            return $value->id;
        }

        return null;
    }

    /**
     * Synchronize the local record from Stripe's subscription object.
     * Supports old Stripe API versions and item-level billing periods.
     */
    public function synchronizeFromStripe(Subscription $sub, \Stripe\Subscription $stripeSub): void
    {
        $data = $stripeSub->jsonSerialize();
        $stripeStatus = (string) ($data['status'] ?? '');

        $sub->setStatus(match ($stripeStatus) {
            'active' => Subscription::STATUS_ACTIVE,
            'canceled' => Subscription::STATUS_CANCELLED,
            'past_due', 'unpaid' => Subscription::STATUS_PAST_DUE,
            'incomplete_expired' => Subscription::STATUS_EXPIRED,
            default => Subscription::STATUS_PENDING,
        });

        $periodEnd = $data['items']['data'][0]['current_period_end']
            ?? $data['current_period_end']
            ?? null;
        $date = is_numeric($periodEnd)
            ? \DateTimeImmutable::createFromFormat('U', (string) $periodEnd)
            : false;
        $sub->setCurrentPeriodEnd($date instanceof \DateTimeImmutable ? $date : null);

        $priceId = $data['items']['data'][0]['price']['id'] ?? null;
        $planPeriod = $this->findPlanPeriodForPriceId(is_string($priceId) ? $priceId : null);
        if ($planPeriod !== null) {
            $sub->setPlan($planPeriod['plan']);
            $sub->setBillingPeriod($planPeriod['period']);
        }

        $sub->setExpiryReminderSent(false);
    }

    private function findPlanPeriodForPriceId(?string $priceId): ?array
    {
        if ($priceId === null) {
            return null;
        }

        foreach ($this->stripePriceIds as $plan => $periods) {
            foreach ($periods as $period => $configuredPriceId) {
                if (hash_equals((string) $configuredPriceId, $priceId)) {
                    return ['plan' => $plan, 'period' => $period];
                }
            }
        }

        return null;
    }

    // ── Downgrade ─────────────────────────────────────────────────────────────

    public function changeActiveSubscription(
        User $user,
        string $newPlan,
        string $newPeriod,
        string $prorationBehavior = 'create_prorations',
    ): void {
        if (!in_array($newPlan, Subscription::PLANS, true)) {
            throw new \InvalidArgumentException('Invalid subscription plan.');
        }
        if (!in_array($newPeriod, [Subscription::PERIOD_MONTHLY, Subscription::PERIOD_YEARLY], true)) {
            throw new \InvalidArgumentException('Invalid billing period.');
        }

        $sub = $this->getSubscription($user)
            ?? throw new \LogicException('No subscription found for user.');
        if (!$sub->isActive() || !$sub->getStripeSubscriptionId()) {
            throw new \LogicException('An active Stripe subscription is required.');
        }

        $oldRank = $sub->getPlanRank();
        $newRank = (new Subscription())->setPlan($newPlan)->getPlanRank();
        $newPriceId = $this->stripePriceIds[$newPlan][$newPeriod]
            ?? throw new \InvalidArgumentException("No Stripe price for {$newPlan}/{$newPeriod}");

        $stripeClient = new StripeClient($this->stripeSecretKey);
        $stripeSub = $stripeClient->subscriptions->retrieve($sub->getStripeSubscriptionId());
        $itemId = $stripeSub->items->data[0]->id ?? null;
        if (!$itemId) {
            throw new \RuntimeException('Stripe subscription has no billable item.');
        }

        $updatedStripeSub = $stripeClient->subscriptions->update($sub->getStripeSubscriptionId(), [
            'items' => [['id' => $itemId, 'price' => $newPriceId]],
            'proration_behavior' => $prorationBehavior,
        ]);

        $this->synchronizeFromStripe($sub, $updatedStripeSub);
        // Keep the selected values even when an older Stripe API response does
        // not expand the price object.
        $sub->setPlan($newPlan);
        $sub->setBillingPeriod($newPeriod);

        if ($newRank < $oldRank) {
            $this->checkAndSetEnforcement($user, $newPlan);
        } elseif ($newRank > $oldRank) {
            $user->setEnforcementRequired(false);
        }

        $this->em->flush();
    }

    public function applyDowngrade(User $user, string $newPlan, string $newPeriod, array $keepMenuIds = []): void
    {
        $sub = $this->getSubscription($user)
            ?? throw new \LogicException('No subscription found for user.');
        $newRank = (new Subscription())->setPlan($newPlan)->getPlanRank();
        if ($newRank >= $sub->getPlanRank()) {
            throw new \LogicException('Selected plan is not a downgrade.');
        }

        $this->changeActiveSubscription($user, $newPlan, $newPeriod, 'none');
    }

    // ── Expire menus when subscription lapses ─────────────────────────────────

    public function expireOwnerMenus(User $user): void
    {
        // Menu statuses are intentionally preserved. Public access checks the
        // owner's active subscription, so renewal restores visibility without
        // losing which menus were published.
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
