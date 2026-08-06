<?php

namespace App\Command;

use App\Repository\SubscriptionRepository;
use App\Service\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\Stripe;
use Stripe\StripeClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sync-stripe',
    description: 'Sync subscription data from Stripe to fix pending status',
)]
class SyncStripeSubscriptionCommand extends Command
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptionRepo,
        private readonly EntityManagerInterface $em,
        private readonly SubscriptionService $subscriptionService,
        private readonly string $stripeSecretKey,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        Stripe::setApiKey($this->stripeSecretKey);
        $client = new StripeClient($this->stripeSecretKey);

        $subscriptions = $this->subscriptionRepo->findAll();

        foreach ($subscriptions as $sub) {
            if (!$sub->getStripeSubscriptionId()) {
                $io->warning(sprintf(
                    'Subscription #%d has no Stripe subscription ID',
                    $sub->getId()
                ));
                continue;
            }

            try {
                $stripeSub = $client->subscriptions->retrieve($sub->getStripeSubscriptionId());
                $oldPlan = $sub->getPlan();
                $this->subscriptionService->synchronizeFromStripe($sub, $stripeSub);
                if ($oldPlan !== $sub->getPlan()) {
                    $this->subscriptionService->refreshLimitEnforcement($sub->getOwner(), $sub->getPlan());
                }
                $periodEnd = $sub->getCurrentPeriodEnd();

                $io->success(sprintf(
                    'Synced subscription #%d (%s) - Status: %s, Expires: %s',
                    $sub->getId(),
                    $sub->getPlan(),
                    $sub->getStatus(),
                    $periodEnd ? $periodEnd->format('Y-m-d H:i:s') : 'N/A'
                ));
            } catch (\Exception $e) {
                $io->error(sprintf(
                    'Failed to sync subscription #%d: %s',
                    $sub->getId(),
                    $e->getMessage()
                ));
            }
        }

        $this->em->flush();
        $io->success('All subscriptions synced!');

        return Command::SUCCESS;
    }
}
