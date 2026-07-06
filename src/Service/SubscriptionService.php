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

        $sub->setStripeCustomerId($session->customer);
        $sub->setStripeSubscriptionId($session->subscription);
        $sub->setPlan($plan);
        $sub->setBillingPeriod($period);
        $sub->setStatus(Subscription::STATUS_ACTIVE);
        $sub->setCurrentPeriodEnd(\DateTimeImmutable::createFromFormat('U', (string) $stripeSub->current_period_end));
        $sub->setExpiryReminderSent(false);

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

        $sub->setStatus(Subscription::STATUS_ACTIVE);
        $sub->setCurrentPeriodEnd(\DateTimeImmutable::createFromFormat('U', (string) $stripeSub->current_period_end));
        $sub->setExpiryReminderSent(false);
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
     * All other published menus are demoted to draft.
     */
    public function applyDowngrade(User $user, string $newPlan, string $newPeriod, array $keepMenuIds = []): void
    {
        Stripe::setApiKey($this->stripeSecretKey);

        $sub = $this->getSubscription($user)
            ?? throw new \LogicException('No subscription found for user');

        $newPriceId = $this->stripePriceIds[$newPlan][$newPeriod]
            ?? throw new \InvalidArgumentException("No Stripe price for {$newPlan}/{$newPeriod}");

        // Update Stripe subscription to new price
        if ($sub->getStripeSubscriptionId()) {
            $stripeClient    = new StripeClient($this->stripeSecretKey);
            $stripeSub       = $stripeClient->subscriptions->retrieve($sub->getStripeSubscriptionId());
            $stripeClient->subscriptions->update($sub->getStripeSubscriptionId(), [
                'items'       => [['id' => $stripeSub->items->data[0]->id, 'price' => $newPriceId]],
                'proration_behavior' => 'none',
            ]);
        }

        // Demote extra menus to draft
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
}
