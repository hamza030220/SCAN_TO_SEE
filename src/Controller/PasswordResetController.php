<?php

namespace App\Controller;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class PasswordResetController extends AbstractController
{
    /**
     * Step 1 — Owner enters their email address.
     * We send them a reset link if the account exists and is active.
     * We always show the same confirmation message to prevent email enumeration.
     */
    #[Route('/forgot-password', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function request(
        Request $request,
        UserRepository $userRepo,
        EntityManagerInterface $em,
        MailerInterface $mailer,
    ): Response {
        if ($request->isMethod('POST')) {
            $emailAddress = trim((string) $request->request->get('email', ''));

            $user = $userRepo->findOneBy(['email' => $emailAddress, 'role' => 'owner']);

            if ($user && $user->isActive()) {
                $token = bin2hex(random_bytes(32));

                $user->setPasswordResetToken($token);
                $user->setPasswordResetTokenExpiresAt(new \DateTimeImmutable('+30 minutes'));
                $em->flush();

                // Use MAILER_BASE_URL if set (e.g. ngrok), otherwise fall back to the request host
                $baseUrl = rtrim($_ENV['MAILER_BASE_URL'] ?? '', '/');
                if ($baseUrl) {
                    $path    = $this->generateUrl('app_reset_password', ['token' => $token]);
                    $resetUrl = $baseUrl . $path;
                } else {
                    $resetUrl = $this->generateUrl(
                        'app_reset_password',
                        ['token' => $token],
                        UrlGeneratorInterface::ABSOLUTE_URL,
                    );
                }

                $mail = (new Email())
                    ->from(new Address($_ENV['MAILER_FROM'] ?? 'noreply@scantosee.com', 'Scan to See'))
                    ->to($user->getEmail())
                    ->subject('Reset your Scan to See password')
                    ->html($this->renderView('emails/reset_password.html.twig', [
                        'user'     => $user,
                        'resetUrl' => $resetUrl,
                    ]));

                $mailer->send($mail);
            }

            // Always show the same message — never reveal whether the email exists
            $this->addFlash('success', 'If that email is registered, you\'ll receive a reset link within a few minutes.');
            return $this->redirectToRoute('app_forgot_password');
        }

        return $this->render('site/forgot_password.html.twig');
    }

    /**
     * Step 2 — Owner follows the link from the email and sets a new password.
     */
    #[Route('/reset-password/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function reset(
        string $token,
        Request $request,
        UserRepository $userRepo,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): Response {
        $user = $userRepo->findOneBy(['passwordResetToken' => $token]);

        // Invalid or expired token → redirect back with an error
        if (!$user || $user->getPasswordResetTokenExpiresAt() < new \DateTimeImmutable()) {
            $this->addFlash('error', 'This reset link is invalid or has expired. Please request a new one.');
            return $this->redirectToRoute('app_forgot_password');
        }

        if ($request->isMethod('POST')) {
            $password = (string) $request->request->get('password', '');
            $confirm  = (string) $request->request->get('confirm', '');

            if (strlen($password) < 8) {
                $this->addFlash('error', 'Password must be at least 8 characters.');
            } elseif ($password !== $confirm) {
                $this->addFlash('error', 'Passwords do not match.');
            } else {
                $user->setPassword($hasher->hashPassword($user, $password));
                $user->setPasswordResetToken(null);
                $user->setPasswordResetTokenExpiresAt(null);
                $em->flush();

                $this->addFlash('success', 'Password updated! You can now log in with your new password.');
                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('site/reset_password.html.twig', ['token' => $token]);
    }
}
