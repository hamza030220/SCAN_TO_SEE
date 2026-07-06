<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class RegistrationController extends AbstractController
{
    #[Route('/sign-up', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $em,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $error    = null;
        $formData = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('register', $request->request->get('_token'))) {
                $error = 'Invalid security token. Please refresh and try again.';
            } else {
                $email    = trim($request->request->get('email', ''));
                $fullName = trim($request->request->get('full_name', ''));
                $password = $request->request->get('password', '');
                $confirm  = $request->request->get('confirm_password', '');

                $formData = ['email' => $email, 'full_name' => $fullName];

                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Please enter a valid email address.';
                } elseif ($password !== $confirm) {
                    $error = 'Passwords do not match.';
                } elseif (strlen($password) < 8) {
                    $error = 'Password must be at least 8 characters.';
                } else {
                    $existing = $em->getRepository(User::class)->findOneBy(['email' => $email]);
                    if ($existing) {
                        $error = 'An account with this email already exists.';
                    } else {
                        $user = new User();
                        $user->setEmail($email);
                        $user->setFullName($fullName ?: null);
                        $user->setPassword($passwordHasher->hashPassword($user, $password));

                        $em->persist($user);
                        $em->flush();

                        $this->addFlash('success', 'Account created! Please choose a subscription plan to get started.');

                        return $this->redirectToRoute('app_owner_subscription');
                    }
                }
            }
        }

        return $this->render('site/register.html.twig', [
            'error'    => $error,
            'formData' => $formData,
        ]);
    }
}
