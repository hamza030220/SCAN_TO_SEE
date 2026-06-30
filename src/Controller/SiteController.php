<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SiteController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function home(): Response
    {
        return $this->render('site/home.html.twig', [
            'features' => [
                [
                    'label' => 'Permanent QR',
                    'title' => 'Print once, update forever',
                    'body' => 'Every menu keeps one fixed public link while owners update items, prices, photos, and availability behind it.',
                ],
                [
                    'label' => 'Live menu editor',
                    'title' => 'Draft safely before publishing',
                    'body' => 'Owners can prepare changes without changing the live customer menu until they are ready.',
                ],
                [
                    'label' => 'Built for venues',
                    'title' => 'Food, drinks, services, and products',
                    'body' => 'Categories, subcategories, item details, variants, and translations work for restaurants, spas, salons, and more.',
                ],
            ],
            'previewItems' => [
                ['name' => 'Iced Latte', 'detail' => 'Oat milk option available', 'price' => '8.50 TND'],
                ['name' => 'Signature Massage', 'detail' => '60 minutes with aromatherapy', 'price' => '90.00 TND'],
                ['name' => 'Seasonal Tart', 'detail' => 'Limited availability today', 'price' => '7.00 TND'],
            ],
        ]);
    }

    #[Route('/about', name: 'app_about')]
    public function about(): Response
    {
        return $this->render('site/about.html.twig');
    }

    #[Route('/pricing', name: 'app_pricing')]
    public function pricing(): Response
    {
        return $this->render('site/pricing.html.twig', [
            'plans' => [
                [
                    'name' => 'Monthly',
                    'period' => 'Flexible billing',
                    'description' => 'For owners who want to start small and keep the option to change period later.',
                    'items' => ['One permanent QR menu', 'Theme presets and branding', 'Menu languages', 'Downloadable QR code'],
                ],
                [
                    'name' => 'Yearly',
                    'period' => 'Best value',
                    'description' => 'For established venues that want the same product with a better long-term rate.',
                    'items' => ['Everything in Monthly', 'Lower effective monthly cost', 'Billing history', 'Easy renewal'],
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