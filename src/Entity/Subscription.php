<?php

namespace App\Entity;

use App\Repository\SubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
class Subscription
{
    // ── Plan constants ────────────────────────────────────────────────────────
    public const PLAN_BASIC   = 'basic';
    public const PLAN_PREMIUM = 'premium';
    public const PLAN_PRO     = 'pro';

    public const PLANS = [self::PLAN_BASIC, self::PLAN_PREMIUM, self::PLAN_PRO];

    // ── Status constants ──────────────────────────────────────────────────────
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED   = 'expired';
    public const STATUS_PENDING   = 'pending'; // awaiting Stripe webhook confirmation
    public const STATUS_PAST_DUE  = 'past_due';

    // ── Billing period constants ──────────────────────────────────────────────
    public const PERIOD_MONTHLY = 'monthly';
    public const PERIOD_YEARLY  = 'yearly';

    // ── Plan limits ───────────────────────────────────────────────────────────
    // null means unlimited
    public const LIMITS = [
        self::PLAN_BASIC   => ['published' => 1,    'draft' => 1],
        self::PLAN_PREMIUM => ['published' => 3,    'draft' => 3],
        self::PLAN_PRO     => ['published' => null, 'draft' => null],
    ];

    public const BUSINESS_LIMITS = [
        self::PLAN_BASIC => 1,
        self::PLAN_PREMIUM => null,
        self::PLAN_PRO => null,
    ];

    // ── Prices in EUR cents ───────────────────────────────────────────────────
    public const PRICES = [
        self::PLAN_BASIC   => [self::PERIOD_MONTHLY => 500,  self::PERIOD_YEARLY => 5000],
        self::PLAN_PREMIUM => [self::PERIOD_MONTHLY => 1200, self::PERIOD_YEARLY => 12000],
        self::PLAN_PRO     => [self::PERIOD_MONTHLY => 2500, self::PERIOD_YEARLY => 25000],
    ];

    // ── Plan labels ───────────────────────────────────────────────────────────
    public const LABELS = [
        self::PLAN_BASIC   => 'Basic',
        self::PLAN_PREMIUM => 'Premium',
        self::PLAN_PRO     => 'Pro',
    ];

    // ── ORM fields ───────────────────────────────────────────────────────────

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(length: 20)]
    private string $plan = self::PLAN_BASIC;

    #[ORM\Column(length: 10)]
    private string $billingPeriod = self::PERIOD_MONTHLY;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeCustomerId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeSubscriptionId = null;

    /** When the current paid period ends — null until first payment confirmed */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $currentPeriodEnd = null;

    /** Set to true after the 2-day reminder has been sent (reset each renewal) */
    #[ORM\Column]
    private bool $expiryReminderSent = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // ── Status helpers ────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->currentPeriodEnd !== null
            && $this->currentPeriodEnd > new \DateTimeImmutable();
    }

    public function isExpiredOrCancelled(): bool
    {
        return !$this->isActive();
    }

    public function getDaysUntilExpiry(): ?int
    {
        if ($this->currentPeriodEnd === null) {
            return null;
        }

        $diff = (new \DateTimeImmutable())->diff($this->currentPeriodEnd);
        return $diff->invert ? -$diff->days : $diff->days;
    }

    // ── Plan helpers ──────────────────────────────────────────────────────────

    public function getPublishedMenuLimit(): ?int
    {
        return self::LIMITS[$this->plan]['published'] ?? null;
    }

    public function getDraftMenuLimit(): ?int
    {
        return self::LIMITS[$this->plan]['draft'] ?? null;
    }

    public function getBusinessLimit(): ?int
    {
        return self::BUSINESS_LIMITS[$this->plan] ?? null;
    }

    public function getPlanLabel(): string
    {
        return self::LABELS[$this->plan] ?? ucfirst($this->plan);
    }

    public function getPlanRank(): int
    {
        return match ($this->plan) {
            self::PLAN_BASIC   => 1,
            self::PLAN_PREMIUM => 2,
            self::PLAN_PRO     => 3,
            default            => 0,
        };
    }

    // ── Getters / setters ─────────────────────────────────────────────────────

    public function getId(): ?int { return $this->id; }

    public function getOwner(): ?User { return $this->owner; }
    public function setOwner(User $owner): static { $this->owner = $owner; return $this; }

    public function getPlan(): string { return $this->plan; }
    public function setPlan(string $plan): static { $this->plan = $plan; return $this; }

    public function getBillingPeriod(): string { return $this->billingPeriod; }
    public function setBillingPeriod(string $period): static { $this->billingPeriod = $period; return $this; }

    public function getStatus(): string { return $this->status; }
    public function setStatus(string $status): static { $this->status = $status; return $this; }

    public function getStripeCustomerId(): ?string { return $this->stripeCustomerId; }
    public function setStripeCustomerId(?string $id): static { $this->stripeCustomerId = $id; return $this; }

    public function getStripeSubscriptionId(): ?string { return $this->stripeSubscriptionId; }
    public function setStripeSubscriptionId(?string $id): static { $this->stripeSubscriptionId = $id; return $this; }

    public function getCurrentPeriodEnd(): ?\DateTimeImmutable { return $this->currentPeriodEnd; }
    public function setCurrentPeriodEnd(?\DateTimeImmutable $dt): static { $this->currentPeriodEnd = $dt; return $this; }

    public function isExpiryReminderSent(): bool { return $this->expiryReminderSent; }
    public function setExpiryReminderSent(bool $sent): static { $this->expiryReminderSent = $sent; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
