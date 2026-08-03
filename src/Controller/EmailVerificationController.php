<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\EmailVerificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class EmailVerificationController extends AbstractController
{
    #[Route('/verify-email/{token}', name: 'app_verify_email', methods: ['GET'])]
    public function verify(string $token, EmailVerificationService $verification): Response
    {
        $user = $verification->verify($token);
        if (!$user) {
            $this->addFlash('error', 'This verification link is invalid or has expired.');
            return $this->redirectToRoute('app_login');
        }
        $this->addFlash(
            'success',
            sprintf(
                'Email verified. Your five-day free trial is active until %s.',
                $user->getTrialEndsAt()?->format('F j, Y \a\t H:i') ?? 'the displayed trial end date',
            ),
        );
        return $this->redirectToRoute('app_login');
    }

    #[Route('/verify-email', name: 'app_verify_email_pending', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function pending(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if ($user->isEmailVerified()) {
            return $this->redirectToRoute('app_dashboard');
        }
        return $this->render('site/verify_email.html.twig');
    }

    #[Route('/verify-email/resend', name: 'app_verify_email_resend', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function resend(
        Request $request,
        EmailVerificationService $verification,
        RateLimiterFactory $verificationResendLimiter,
    ): Response
    {
        if (!$this->isCsrfTokenValid('resend-verification', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        /** @var User $user */
        $user = $this->getUser();
        if (!$user->isEmailVerified()) {
            $limit = $verificationResendLimiter->create((string) $user->getId())->consume();
            if (!$limit->isAccepted()) {
                $this->addFlash('error', 'Too many verification requests. Please wait 15 minutes and try again.');
                return $this->redirectToRoute('app_verify_email_pending');
            }
            try {
                $verification->send($user);
                $this->addFlash('success', 'A new verification link has been sent.');
            } catch (\Throwable) {
                $this->addFlash('error', 'The email could not be sent. Check the mail configuration and try again.');
            }
        }
        return $this->redirectToRoute('app_verify_email_pending');
    }
}
