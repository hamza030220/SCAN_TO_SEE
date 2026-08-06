<?php

namespace App\Controller;

use App\Entity\Subscription;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SiteController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function home(): Response
    {
        return $this->render('site/home.html.twig', [
            'features' => [
                [
                    'icon' => 'qr_code_2',
                    'label' => 'Permanent QR',
                    'title' => 'One code for every update',
                    'body' => 'Keep the same printed QR while changing items, prices, photos, availability, languages, and design behind it.',
                ],
                [
                    'icon' => 'document_scanner',
                    'label' => 'AI Menu Scanner',
                    'title' => 'Turn a photo into editable data',
                    'body' => 'Upload a handwritten or printed menu and let the beta scanner identify categories, items, and prices for owner review.',
                ],
                [
                    'icon' => 'edit_note',
                    'label' => 'Controlled publishing',
                    'title' => 'Review in draft, publish with confidence',
                    'body' => 'Prepare changes privately and decide exactly when customers see the new version.',
                ],
                [
                    'icon' => 'translate',
                    'label' => 'Guest experience',
                    'title' => 'Fast, multilingual and app-free',
                    'body' => 'Customers scan and browse in their phone browser without an account or an app download.',
                ],
                [
                    'icon' => 'palette',
                    'label' => 'Live Menu Designer',
                    'title' => 'Design every detail visually',
                    'body' => 'Style cards, fonts, spacing, imagery, and responsive promotional heroes with draggable layers and scheduled countdowns.',
                ],
                [
                    'icon' => 'database',
                    'label' => 'Data ownership',
                    'title' => 'Paused when needed, never rebuilt',
                    'body' => 'Subscription changes pause public access without erasing menu structure or permanent links.',
                ],
            ],
            'previewItems' => [
                ['name' => 'Iced Latte', 'detail' => 'Oat milk option available', 'price' => '8.50 TND'],
                ['name' => 'Signature Massage', 'detail' => '60 minutes with aromatherapy', 'price' => '90.00 TND'],
                ['name' => 'Seasonal Tart', 'detail' => 'Limited availability today', 'price' => '7.00 TND'],
            ],
        ]);
    }

    #[Route('/about', name: 'app_about', methods: ['GET'])]
    public function about(): Response
    {
        return $this->render('site/about.html.twig');
    }

    #[Route('/ai-menu-scanner', name: 'app_ai_scanner', methods: ['GET'])]
    public function aiScanner(): Response
    {
        return $this->render('site/ai_scanner.html.twig');
    }

    #[Route('/menu-designer', name: 'app_menu_designer', methods: ['GET'])]
    public function menuDesigner(): Response
    {
        return $this->render('site/menu_designer.html.twig');
    }

    #[Route('/privacy', name: 'app_privacy', methods: ['GET'])]
    public function privacy(): Response
    {
        return $this->render('site/privacy.html.twig');
    }

    #[Route('/terms', name: 'app_terms', methods: ['GET'])]
    public function terms(): Response
    {
        return $this->render('site/terms.html.twig');
    }

    #[Route('/cookies', name: 'app_cookies', methods: ['GET'])]
    public function cookies(): Response
    {
        return $this->render('site/cookies.html.twig');
    }

    #[Route('/pricing', name: 'app_pricing', methods: ['GET'])]
    public function pricing(): Response
    {
        return $this->render('site/pricing.html.twig', [
            'plans' => [
                [
                    'name' => Subscription::LABELS[Subscription::PLAN_BASIC],
                    'period' => 'For essential menus',
                    'monthly' => Subscription::PRICES[Subscription::PLAN_BASIC][Subscription::PERIOD_MONTHLY],
                    'yearly' => Subscription::PRICES[Subscription::PLAN_BASIC][Subscription::PERIOD_YEARLY],
                    'description' => 'Essential menu capacity across your businesses.',
                    'items' => ['1 published menu', '1 draft menu', 'Permanent QR codes', 'Live menu updates'],
                ],
                [
                    'name' => Subscription::LABELS[Subscription::PLAN_PRO],
                    'period' => 'For larger operations',
                    'monthly' => Subscription::PRICES[Subscription::PLAN_PRO][Subscription::PERIOD_MONTHLY],
                    'yearly' => Subscription::PRICES[Subscription::PLAN_PRO][Subscription::PERIOD_YEARLY],
                    'description' => 'Unlimited menu capacity and priority support.',
                    'items' => ['Unlimited published menus', 'Unlimited drafts', 'Multiple businesses', 'Priority support'],
                    'featured' => true,
                ],
                [
                    'name' => Subscription::LABELS[Subscription::PLAN_PREMIUM],
                    'period' => 'For growing businesses',
                    'monthly' => Subscription::PRICES[Subscription::PLAN_PREMIUM][Subscription::PERIOD_MONTHLY],
                    'yearly' => Subscription::PRICES[Subscription::PLAN_PREMIUM][Subscription::PERIOD_YEARLY],
                    'description' => 'More menus and support for multiple businesses.',
                    'items' => ['3 published menus', '3 draft menus', 'Multiple businesses', 'Permanent QR codes'],
                ],
            ],
        ]);
    }

    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('site/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error'         => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('Intercepted by Symfony Security firewall.');
    }
}
