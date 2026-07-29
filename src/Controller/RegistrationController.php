<?php

namespace App\Controller;

use App\Entity\Business;
use App\Entity\User;
use App\Service\EmailBlockService;
use App\Service\EmailVerificationService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class RegistrationController extends AbstractController
{
    #[Route('/sign-up', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
        EmailBlockService $emailBlocks,
        EmailVerificationService $verification,
        RateLimiterFactory $registrationLimiter,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $error    = null;
        $errorField = null;
        $formData = [];

        if ($request->isMethod('POST')) {
            $rateLimit = $registrationLimiter->create($request->getClientIp() ?? 'unknown')->consume();
            if (!$rateLimit->isAccepted()) {
                $error = 'Too many registration attempts. Please wait a few minutes and try again.';
            } elseif (!$this->isCsrfTokenValid('register', $request->request->get('_token'))) {
                $error = 'Invalid security token. Please refresh and try again.';
            } else {
                $email    = $emailBlocks->normalize($request->request->get('email', ''));
                $fullName = trim($request->request->get('full_name', ''));
                $businessName = trim($request->request->get('business_name', ''));
                $password = $request->request->get('password', '');
                $confirm  = $request->request->get('confirm_password', '');

                $formData = [
                    'email' => $email,
                    'full_name' => $fullName,
                    'business_name' => $businessName,
                    'terms_accepted' => $request->request->getBoolean('terms_accepted'),
                ];

                if (!$request->request->getBoolean('terms_accepted')) {
                    $error = 'You must accept the Terms of Service and Privacy Policy to create an account.';
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 180) {
                    $error = 'Please enter a valid email address.';
                    $errorField = 'email';
                } elseif ($emailBlock = $emailBlocks->activeBlock($email)) {
                    $blockedUntil = $emailBlock->getBlockedUntil();
                    $error = sprintf(
                        'This email is temporarily blocked after account deletion. You can use it again on %s, or register with another email.',
                        $blockedUntil?->format('F j, Y') ?? 'the displayed return date',
                    );
                    $errorField = 'email';
                } elseif ($fullName === '' || $businessName === '') {
                    $error = 'Your name and business name are required.';
                } elseif ($password !== $confirm) {
                    $error = 'Passwords do not match.';
                } elseif (
                    strlen($password) < 8
                    || !preg_match('/[A-Z]/', $password)
                    || !preg_match('/\d/', $password)
                    || !preg_match('/[^A-Za-z0-9]/', $password)
                ) {
                    $error = 'Password must contain at least 8 characters, an uppercase letter, a number, and a special character.';
                } else {
                    $existing = $em->getRepository(User::class)->findOneBy(['email' => $email]);
                    if ($existing) {
                        $error = 'Sorry, this email is already used.';
                        $errorField = 'email';
                    } else {
                        $user = new User();
                        $user->setEmail($email);
                        $user->setFullName($fullName ?: null);
                        $user->setPassword($passwordHasher->hashPassword($user, $password));

                        $em->persist($user);
                        $business = (new Business())
                            ->setOwner($user)
                            ->setName($businessName);
                        $em->persist($business);
                        try {
                            $em->flush();
                        } catch (UniqueConstraintViolationException) {
                            $error = 'Sorry, this email is already used.';
                            $errorField = 'email';
                        }
                        if (!$error) {
                            try {
                                $verification->send($user);
                                $this->addFlash('success', 'Account created. Check your inbox to verify your email and start your five-day trial.');
                            } catch (\Throwable) {
                                $this->addFlash('warning', 'Account created, but the verification email could not be sent. Log in and request a new link.');
                            }
                            return $this->redirectToRoute('app_login');
                        }
                    }
                }
            }
        }

        return $this->render('site/register.html.twig', [
            'error'    => $error,
            'errorField' => $errorField,
            'formData' => $formData,
        ]);
    }
}
