<?php

namespace App\Service;

use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use Doctrine\DBAL\Connection;

class EntitlementService
{
    public const TRIAL_DAYS = 5;
    public const TRIAL_AI_LIMIT = 3;

    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
        private readonly Connection $connection,
    ) {}

    public function paidSubscription(User $user): ?Subscription
    {
        $subscription = $this->subscriptions->findOneBy(['owner' => $user]);
        return $subscription?->isActive() ? $subscription : null;
    }

    public function hasPaidAccess(User $user): bool
    {
        return $this->paidSubscription($user) !== null;
    }

    public function hasAccess(User $user): bool
    {
        return $this->hasPaidAccess($user) || $user->isTrialActive();
    }

    public function accessPlan(User $user): ?string
    {
        return $this->paidSubscription($user)?->getPlan()
            ?? ($user->isTrialActive() ? Subscription::PLAN_BASIC : null);
    }

    public function isTrialAccess(User $user): bool
    {
        return !$this->hasPaidAccess($user) && $user->isTrialActive();
    }

    public function startTrial(User $user): void
    {
        $user->setTrialEndsAt(new \DateTimeImmutable(sprintf('+%d days', self::TRIAL_DAYS)));
        $user->setTrialAiUses(0);
    }

    public function reserveTrialAiUse(User $user): bool
    {
        if (!$this->isTrialAccess($user) || $user->getId() === null) {
            return $this->hasPaidAccess($user);
        }

        $affected = $this->connection->executeStatement(
            'UPDATE `user` SET trial_ai_uses = trial_ai_uses + 1 WHERE id = :id AND trial_ai_uses < :limit AND trial_ends_at > :now',
            [
                'id' => $user->getId(),
                'limit' => self::TRIAL_AI_LIMIT,
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );
        if ($affected === 1) {
            $user->setTrialAiUses($user->getTrialAiUses() + 1);
            return true;
        }
        return false;
    }

    public function releaseTrialAiUse(User $user): void
    {
        if ($user->getId() === null || $this->hasPaidAccess($user)) {
            return;
        }
        $this->connection->executeStatement(
            'UPDATE `user` SET trial_ai_uses = GREATEST(0, trial_ai_uses - 1) WHERE id = :id',
            ['id' => $user->getId()],
        );
        $user->setTrialAiUses(max(0, $user->getTrialAiUses() - 1));
    }

    public function remainingTrialAiUses(User $user): ?int
    {
        return $this->isTrialAccess($user)
            ? max(0, self::TRIAL_AI_LIMIT - $user->getTrialAiUses())
            : null;
    }
}
