<?php

namespace App\MessageHandler;

use App\Entity\Subscription;
use App\Message\SubscriptionDailyCheck;
use App\Repository\SubscriptionRepository;
use App\Service\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

#[AsMessageHandler]
class SubscriptionDailyCheckHandler
{
    public function __construct(
        private readonly SubscriptionRepository $subRepo,
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface        $mailer,
        private readonly SubscriptionService    $subscriptionService,
        private readonly string                 $mailerFrom,
        private readonly string                 $mailerBaseUrl,
    ) {}

    public function __invoke(SubscriptionDailyCheck $message): void
    {
        $this->sendExpiryReminders();
        $this->expireOverdueSubscriptions();
    }

    private function sendExpiryReminders(): void
    {
        foreach ($this->subRepo->findExpiringIn(2) as $sub) {
            $user    = $sub->getOwner();
            $name    = $user->getFullName() ?: 'there';
            $plan    = $sub->getPlanLabel();
            $expiry  = $sub->getCurrentPeriodEnd()?->format('d M Y') ?? 'soon';
            $renewUrl = rtrim($this->mailerBaseUrl, '/') . '/owner/subscription';

            $mail = (new Email())
                ->from(new Address($this->mailerFrom, 'Scan to See'))
                ->to($user->getEmail())
                ->subject('⚠️ Your Scan to See subscription expires in 2 days')
                ->html($this->buildEmail($name, $plan, $expiry, $renewUrl, $user->getEmail()));

            $this->mailer->send($mail);
            $sub->setExpiryReminderSent(true);
        }

        $this->em->flush();
    }

    private function expireOverdueSubscriptions(): void
    {
        foreach ($this->subRepo->findExpiredActive() as $sub) {
            $sub->setStatus(Subscription::STATUS_EXPIRED);
            $this->subscriptionService->expireOwnerMenus($sub->getOwner());
        }

        $this->em->flush();
    }

    private function buildEmail(string $name, string $plan, string $expiry, string $renewUrl, string $email): string
    {
        return <<<HTML
        <!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"></head>
        <body style="margin:0;padding:0;background:#0f0a07;font-family:'DM Sans',Arial,sans-serif;color:#f5f0e8;">
          <div style="max-width:560px;margin:40px auto;background:#1a1208;border:1px solid rgba(255,255,255,.08);border-radius:16px;overflow:hidden;">
            <div style="background:#18120a;padding:28px 40px 20px;border-bottom:1px solid rgba(255,255,255,.06);text-align:center;">
              <span style="font-size:1.5rem">⏰</span>
              <span style="font-size:1rem;font-weight:700;color:#fff;margin-left:8px;">Scan to See</span>
            </div>
            <div style="padding:32px 40px;">
              <h1 style="font-size:1.25rem;font-weight:700;color:#fff;margin:0 0 12px;">Hi {$name}, your subscription expires in 2 days</h1>
              <p style="font-size:.93rem;color:rgba(245,240,232,.65);line-height:1.7;margin:0 0 20px;">
                Your <strong>{$plan}</strong> plan expires on <strong>{$expiry}</strong>.
                When it expires, all your published menus will go offline automatically.
              </p>
              <div style="text-align:center;margin:28px 0;">
                <a href="{$renewUrl}" style="display:inline-block;background:#e84040;color:#fff;font-size:.93rem;font-weight:700;text-decoration:none;padding:14px 32px;border-radius:8px;">Renew my subscription</a>
              </div>
              <p style="font-size:.82rem;color:rgba(245,240,232,.4);">If you've already renewed, you can safely ignore this email.</p>
            </div>
            <div style="background:#18120a;padding:16px 40px;border-top:1px solid rgba(255,255,255,.06);text-align:center;font-size:.75rem;color:rgba(245,240,232,.3);">
              © Scan to See · {$email}
            </div>
          </div>
        </body></html>
        HTML;
    }
}
