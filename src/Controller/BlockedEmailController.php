<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BlockedEmailController extends AbstractController
{
    #[Route('/email-temporarily-blocked', name: 'app_email_blocked', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $value = $request->getSession()->remove('blocked_email_until');
        if (!is_string($value)) {
            return $this->redirectToRoute('app_login');
        }
        try {
            $blockedUntil = new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return $this->redirectToRoute('app_login');
        }
        return $this->render('site/email_blocked.html.twig', ['blockedUntil' => $blockedUntil]);
    }
}
