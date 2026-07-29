<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

class EmailVerificationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface $mailer,
        private readonly RouterInterface $router,
        private readonly EntitlementService $entitlements,
        private readonly string $mailerFrom,
    ) {}

    public function send(User $user): void
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $user
            ->setEmailVerificationTokenHash(hash('sha256', $token))
            ->setEmailVerificationExpiresAt(new \DateTimeImmutable('+1 hour'));
        $this->em->flush();

        $url = $this->router->generate(
            'app_verify_email',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
        $message = (new Email())
            ->from($this->mailerFrom)
            ->to((string) $user->getEmail())
            ->subject('Verify your Scan to See email')
            ->html(sprintf(
                '<h1>Verify your email</h1><p>Confirm this email address to activate your five-day trial.</p><p><a href="%s">Verify my email</a></p><p>This link expires in one hour. If you did not create this account, ignore this message.</p>',
                htmlspecialchars($url, ENT_QUOTES),
            ));
        $this->mailer->send($message);
    }

    public function verify(string $token): ?User
    {
        $user = $this->em->getRepository(User::class)->findOneBy([
            'emailVerificationTokenHash' => hash('sha256', $token),
        ]);
        if (!$user || $user->getEmailVerificationExpiresAt() <= new \DateTimeImmutable()) {
            return null;
        }
        $user
            ->setEmailVerifiedAt(new \DateTimeImmutable())
            ->setEmailVerificationTokenHash(null)
            ->setEmailVerificationExpiresAt(null);
        if ($user->getTrialEndsAt() === null) {
            $this->entitlements->startTrial($user);
        }
        $this->em->flush();
        return $user;
    }
}
