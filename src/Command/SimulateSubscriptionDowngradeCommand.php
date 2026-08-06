<?php

namespace App\Command;

use App\Entity\Subscription;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use App\Service\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:subscription:simulate-downgrade',
    description: 'Activate a pending downgrade early for local Stripe test-mode verification.',
)]
final class SimulateSubscriptionDowngradeCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly SubscriptionRepository $subscriptions,
        private readonly SubscriptionService $subscriptionService,
        private readonly EntityManagerInterface $em,
        private readonly string $stripeSecretKey,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Owner email address');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!str_starts_with($this->stripeSecretKey, 'sk_test_')) {
            $io->error('Refused: this command only works with a Stripe test-mode secret key.');
            return Command::FAILURE;
        }

        $email = mb_strtolower(trim((string) $input->getArgument('email')));
        $user = $this->users->findOneBy(['email' => $email]);
        if (!$user || $user->getRole() !== 'owner') {
            $io->error('No owner account was found for that email.');
            return Command::FAILURE;
        }

        $subscription = $this->subscriptions->findOneBy(['owner' => $user]);
        if ($subscription?->getStatus() !== Subscription::STATUS_ACTIVE || !$subscription->isActive()) {
            $io->error('The owner does not have an active paid subscription.');
            return Command::FAILURE;
        }
        if ($subscription->isCancelAtPeriodEnd()) {
            $io->error('Restore automatic renewal before testing a downgrade.');
            return Command::FAILURE;
        }
        if (!$subscription->hasPendingDowngrade()) {
            $io->error('No pending downgrade exists. Schedule one in the owner subscription page first.');
            return Command::FAILURE;
        }

        $fromPlan = $subscription->getPlanLabel();
        $targetPlan = $subscription->getPendingPlan();
        $targetPeriod = $subscription->getPendingBillingPeriod();
        $targetRank = (new Subscription())->setPlan($targetPlan)->getPlanRank();
        if (!in_array($targetPlan, Subscription::PLANS, true)
            || !in_array($targetPeriod, [Subscription::PERIOD_MONTHLY, Subscription::PERIOD_YEARLY], true)
            || $targetRank >= $subscription->getPlanRank()) {
            $io->error('The pending transition is not a valid downgrade. No data was changed.');
            return Command::FAILURE;
        }

        $subscription->setPlan($targetPlan)
            ->setBillingPeriod($targetPeriod)
            ->clearPendingDowngrade();
        $selectionRequired = $this->subscriptionService->refreshLimitEnforcement($user, $targetPlan);
        $this->em->flush();

        $io->success(sprintf(
            'Simulated %s to %s for %s. Menu selection required: %s.',
            $fromPlan,
            $subscription->getPlanLabel(),
            $email,
            $selectionRequired ? 'yes' : 'no',
        ));
        if ($selectionRequired) {
            $io->note('Open /dashboard. Owner features and public QR menus remain paused until the exact menu selection is confirmed.');
        }

        return Command::SUCCESS;
    }
}
